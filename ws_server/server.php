<?php 

// Подключаем библиотеку Workerman
require_once './vendor/autoload.php';

use Workerman\Lib\Timer;
use Workerman\Worker;

function connect_db() {
    $db = new SQLite3("wspace.db");
    return $db;
}

$connections = []; // сюда будем складывать все подключения

// Стартуем WebSocket-сервер на порту 27800
$worker = new Worker("websocket://0.0.0.0:27800");

$worker->onConnect = function($connection) use(&$connections) {
    // Эта функция выполняется при подключении пользователя к WebSocket-серверу
    $connection->onWebSocketConnect = function($connection) use(&$connections) {
        if (isset($_GET["user_id"]) && is_numeric($_GET["user_id"]) && isset($_GET["user_token"])) {
            $userID = $_GET["user_id"];
            // нужно запомнить все диалоги которые начаты пользователем
            
            $sql = "SELECT utd.id, utd.user_id, 
                        utd.dialog_id, d.hash
                    FROM user_to_dialog AS utd
                    INNER JOIN dialoges AS d
                        ON utd.dialog_id = d.id
                    WHERE utd.user_id = {$userID}";
            
            $db = connect_db();
            $raw = $db->query($sql);
        
            $result = [];
            while ($row = $raw->fetchArray()) {
                $result[] = $row;
            }
            if ($result) {
                $new_res = [];
                foreach ($result as $value) {
                    $sql = "SELECT p.first_name, p.surname
                            FROM user_to_dialog AS utd
                            INNER JOIN profile AS p
                                ON p.user_id = utd.user_id
                            WHERE utd.dialog_id = {$value['dialog_id']} AND utd.user_id != {$userID}";
                    $res = $db->query($sql);
                    $value['profile'] = $res->fetchArray(SQLITE3_ASSOC);
                    $new_res[] = $value;
                }
            }
            
        }
        $connection->userID = $userID;
        $connection->dialoges = $new_res;
        $connection->pingWithoutResponseCount = 0;

        $connections[$connection->id] = $connection;

        $users = [];
        foreach ($connections as $c) {
            // TcpConnection::id - уникальный идентификатор соединения, 
            // присваивается автоматически. Будем использовать его как 
            // идентификатор пользователя 'userId'.
            $users[] = [
                'userId' => $c->id,
                'userID' => $c->userID,
                'userDialoges' => $connection->dialoges,
            ];
        }

        $connection->send(json_encode($new_res, JSON_UNESCAPED_UNICODE));
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
};

$worker->onWorkerStart = function($worker) use (&$connections)
{
    $interval = 5; // пингуем каждые 5 секунд

    Timer::add($interval, function() use(&$connections) {
        foreach ($connections as $c) {
            // Если ответ от клиента не пришел 3 раза, то удаляем соединение из списка
            // и оповещаем всех участников об "отвалившемся" пользователе
            if ($c->pingWithoutResponseCount >= 3) {
                echo $c->id." отвалился";
                unset($connections[$c->id]); 
                $c->destroy(); // уничтожаем соединение
                
                // рассылаем оповещение
                foreach ($connections as $c) {
                    /* $c->send($message); */
                }
            } else {
                $c->send('{"action":"Ping"}');
                $c->pingWithoutResponseCount++; // увеличиваем счетчик пингов
            }
        }
    });
};

$worker->onMessage = function($connection, $message) use (&$connections) {
    // распаковываем json
    $messageData = json_decode($message, true);

    // проверяем наличие ключа 'toDialogId', который используется для отправки приватных сообщений
    $toDialogId = (int)$messageData['toDialogId'];
    $action = isset($messageData['action']) ? $messageData['action'] : '';
    
    if ($action == 'Pong') {
        // При получении сообщения "Pong", обнуляем счетчик пингов
        $connection->pingWithoutResponseCount = 0;
    }

    if ($action == 'PrivateMessage') {
        var_dump($connection->dialoges);

        // ну допустим я могу записать сообщение в БД
        // а как их получать 
        // 
    }
};

Worker::runAll();