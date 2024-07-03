<?php 

// Подключаем библиотеку Workerman
require_once __DIR__.'/../vendor/autoload.php';

use Workerman\Lib\Timer;
use Workerman\Worker;

use Dotenv\Dotenv;

// Загружаем переменные окружения из .env
$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/..');
$dotenv->load();

class ServerWS {
    // Функция для подключения к MySQL с использованием PDO
    public static function connect_db() {
        try {
            $config = [
                'hostname' => getenv("DBHOST") ?: 'localhost',
                'port' => getenv("DBPORT") ?: 3306,
                'username' => getenv("DBUSER") ?: 'root',
                'password' => getenv("DBPASS") ?: '',
                'database' => getenv("DBNAME") ?: 'workspace'
            ];

            $dsn = "mysql:host={$config['hostname']};port={$config['port']};dbname={$config['database']}";
            $db = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            return $db;
        } catch (PDOException $e) {
            echo "Error connecting to database: " . $e->getMessage();
            return null;
        }
    }
}

function get_dialog_messages($conn, $data) {
    $db = ServerWS::connect_db();

    $get_dialog_id = "SELECT id FROM dialogs WHERE uid = :dialog_uid";
    $stmt = $db->prepare($get_dialog_id);
    $stmt->bindParam(':dialog_uid', $data['dialog_uid'], PDO::PARAM_STR);
    $stmt->execute();


    $dialog = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dialog) {return;}
    $dialog_id = $dialog['id'];
    
    $sql = "SELECT msg.message, msg.dialog_id, 
                    msg.from_user_id, msg.created_at, 
                    msg.updated_at, msg.message_status, 
                    u.firstname, u.surname, u.user_image
            FROM messages AS msg
            INNER JOIN users AS u ON msg.from_user_id = u.id
            WHERE msg.dialog_id = :dialog_id";

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':dialog_id', $dialog_id, PDO::PARAM_INT);
    $stmt->execute();
    $rawMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Обработка и группировка сообщений по dialog_id
    $messages = [];
    foreach ($rawMessages as $row) {
        $messages[] = $row;
    }

    $messageData = [
        'messages' => $messages
    ];
    
    if (isset($conn)) {
        $conn->send(json_encode($messageData));
    }
}

$connections = []; // сюда будем складывать все подключения

// Стартуем WebSocket-сервер на порту 27800
$worker = new Worker("websocket://0.0.0.0:27800");

$worker->onConnect = function($connection) use(&$connections) {
    // Эта функция выполняется при подключении пользователя к WebSocket-серверу
    $connection->onWebSocketConnect = function($connection) use(&$connections) {
        $db = ServerWS::connect_db();
        
        $sql = "SELECT id FROM users WHERE uid = :user_uid";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':user_uid', $_GET["user_uid"], PDO::PARAM_STR);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        
        $connection->id = $user['id'];
        //$connection->dialoges = $msgArray;
        $connection->pingWithoutResponseCount = 0;

        $connections[$connection->id] = $connection;

        $users = array();
        foreach ($connections as $c) {
            // TcpConnection::id - уникальный идентификатор соединения, 
            // присваивается автоматически. Будем использовать его как 
            // идентификатор пользователя 'userId'.
            $users[] = [
                'userId' => $c->id,
                //'userDialoges' => $connection->dialoges,
            ];
        }
        $messageData = [
            'action' => 'Authorized'
        ];
        $connection->send(json_encode($messageData, JSON_UNESCAPED_UNICODE));
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
    // Распаковываем JSON
    $messageData = json_decode($message, true);
    
    $request = isset($messageData['action']) ? $messageData['action'] : '';

    switch ($request) {
        case 'Pong':
            $connection->pingWithoutResponseCount = 0;
            break;

        case '':
            get_dialog_messages($connections[$messageData['user_id']], $messageData);
            break;
        
        default:
            break;
    }

    /* if ($action == 'PrivateMessage') { */
        /* $db = ServerWS::connect_db(); // Подключаемся к базе
    
        $text = $messageData["text"]; // Получаем текст сообщения
        $sender = $connection->id; // id отправителя
        $dialog = $messageData["toDialogId"]; // Связанный с сообщением диалог
        $to = $messageData["to"]; // Кому предназначается сообщение
        $datetime = date("Y-m-d H:i:s"); // Форматируем текущую дату и время
    
        try {
            // Вставляем новое сообщение в базу данных
            $sql = "INSERT INTO messages (message, dialog_id, from_user_id, created_at, updated_at, message_status) VALUES (:message, :dialog_id, :from_user_id, :created_at, :updated_at, :message_status)";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':message' => $text,
                ':dialog_id' => $dialog,
                ':from_user_id' => $sender,
                ':created_at' => $datetime,
                ':updated_at' => $datetime,
                ':message_status' => "Send"
            ]);
    
            // Получаем обновленный массив сообщений
            $newMsgArray = ServerWS::get_messages($db, $connection->id);
    
            // Формируем данные для отправки пользователям
            $messageData = [
                'action' => "NewMessage",
                'userDialoges' => $newMsgArray
            ];
    
            // Отправляем обновленные данные пользователю, который отправил сообщение
            $connection->send(json_encode($messageData));
    
            // Отправляем обновленные данные пользователю, которому было отправлено сообщение (если он подключен)
            if (isset($connections[$to])) {
                $connections[$to]->send(json_encode($messageData));
            }
        } catch (PDOException $e) {
            // Обработка ошибок при работе с базой данных
            $errorMsg = [
                'action' => 'Error',
                'message' => 'Failed to send message: ' . $e->getMessage()
            ];
            $connection->send(json_encode($errorMsg));
        } */
    /* } */
};

Worker::runAll();