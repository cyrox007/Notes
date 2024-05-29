<?php 

// Подключаем библиотеку Workerman
require_once __DIR__.'/../vendor/autoload.php';

use Workerman\Lib\Timer;
use Workerman\Worker;

function connect_db() {
    $db = new SQLite3(__DIR__."/../wspace.db");
    return $db;
}

function get_messages($db, $user_id) {
    $sql = "SELECT utd.id, utd.user_id, 
                utd.dialog_id, d.hash
            FROM user_to_dialog AS utd
            INNER JOIN dialoges AS d
                ON utd.dialog_id = d.id
            WHERE utd.user_id = {$user_id}";
    
    $raw = $db->query($sql);
    
    $result = array();
    while ($row = $raw->fetchArray()) {
        $result[] = $row;
    }
    
    $new_result = array();
    if ($result != null) {
        foreach ($result as $dbrow) {
            $dialog_id = $dbrow["dialog_id"];
            $sql = "SELECT msg.message, msg.dialog_id,
                        msg.sender_id, msg.send_date, p.first_name,
                        p.patronymic, p.user_photo
                    FROM messages AS msg
                    INNER JOIN profile AS p 
                        ON msg.sender_id = p.user_id
                    WHERE msg.dialog_id = '{$dialog_id}'";
            $raw = $db->query($sql);
            while ($row = $raw->fetchArray()) {

                $row["send_date"] = date("Y-m-d H:i:s", $row["send_date"]); // преобразуем метку времени из бд обратно в строку
                $messages[] = $row;
            }
            $new_result[$dialog_id] = $messages;
        }
    }
    return $new_result;
}

$connections = []; // сюда будем складывать все подключения

// Стартуем WebSocket-сервер на порту 27800
$worker = new Worker("websocket://0.0.0.0:27800");

$worker->onConnect = function($connection) use(&$connections) {
    // Эта функция выполняется при подключении пользователя к WebSocket-серверу
    $connection->onWebSocketConnect = function($connection) use(&$connections) {
        $db = connect_db();
        if (isset($_GET["user_id"]) && is_numeric($_GET["user_id"]) && isset($_GET["user_token"])) {
            $userID = $_GET["user_id"];
            // нужно запомнить все диалоги которые начаты пользователем
            $msgArray = get_messages($db, $_GET["user_id"]);
            
        }
        $connection->id = $userID;
        $connection->dialoges = $msgArray;
        $connection->pingWithoutResponseCount = 0;

        $connections[$connection->id] = $connection;

        $users = array();
        foreach ($connections as $c) {
            // TcpConnection::id - уникальный идентификатор соединения, 
            // присваивается автоматически. Будем использовать его как 
            // идентификатор пользователя 'userId'.
            $users[] = [
                'userId' => $c->id,
                'userDialoges' => $connection->dialoges,
            ];
        }
        $messageData = [
            'action' => 'Authorized',
            'userId' => $connection->id,
            'userDialoges' => $connection->dialoges,
        ];
        $connection->send(json_encode($messageData, JSON_UNESCAPED_UNICODE));
        $db->close();
    };
};

$worker->onClose = function($connection) use(&$connections)
{
    // Эта функция выполняется при закрытии соединения
    if (!isset($connections[$connection->id])) {
        return;
    }
    
    // Удаляем соединение из списка
    unset($connections[$connection->id]);

    foreach ($connections as $c) {
        // здесь мы отправим в браузер параметр статуса оффлайн
    }
};

$worker->onWorkerStart = function($worker) use (&$connections)
{
    $interval = 5; // пингуем каждые 5 секунд

    Timer::add($interval, function() use(&$connections) {
        foreach ($connections as $c) {
            // Если ответ от клиента не пришел 3 раза, то удаляем соединение из списка
            // и оповещаем всех участников об "отвалившемся" пользователе
            if ($c->pingWithoutResponseCount >= 3) {
                echo $c->id." отвалился\n";
                unset($connections[$c->id]); 
                $c->destroy(); // уничтожаем соединение
                
                // рассылаем оповещение
                foreach ($connections as $c) {
                    // здесь мы отправим в браузер параметр статуса оффлайн
                }
            } else {
                $pingMsg = [
                    'action' => 'Ping'
                ];
                $c->send(json_encode($pingMsg));
                $c->pingWithoutResponseCount++; // увеличиваем счетчик пингов
            }
        }
    });
};

$worker->onMessage = function($connection, $message) use (&$connections) {
    // распаковываем json
    $messageData = json_decode($message, true);
    
    // проверяем наличие ключа 'toDialogId', который используется для отправки приватных сообщений
    $toDialogId = isset($messageData['toDialogId']) ? (int)$messageData['toDialogId'] : null;
    $action = isset($messageData['action']) ? $messageData['action'] : '';
    
    if ($action == 'Pong') {
        // При получении сообщения "Pong", обнуляем счетчик пингов
        $connection->pingWithoutResponseCount = 0;
    }

    if ($action == 'PrivateMessage') {
        $db = connect_db(); // подключаемся к базе
        
        $text = $messageData["text"]; // получаем текст сообщения
        $sender = $connection->id; // id от правителя
        $dialog = $messageData["toDialogId"]; // связанный с сообщением диалог
        $to = $messageData["to"]; // кому предназначается сообщение
        $datetime = strtotime(date("Y-m-d H:i:s"));

        $sql = "INSERT INTO messages (message, dialog_id, sender_id, send_date) VALUES ('{$text}', {$dialog}, {$sender}, {$datetime})";
        
        $db->query($sql); // отправляем в БД
        
        $newMsgArray = get_messages($db, $connection->id); // получаем новый массив сообщений

        $messageData = [
            'action'=> "Mess",
            'userDialoges' => $newMsgArray
        ]; // формируем данные для отправки пользователю
        $connection->send(json_encode($messageData)); // отправляем тому кто отправил
        if (isset($connections[$to])) { // отправляем тому кому отправили
            $connections[$to]->send(json_encode($messageData));
        }

        $db->close(); // закрываем соединение с БД
    }
};

Worker::runAll();