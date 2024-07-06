<?php

namespace App\Sockets;

use App\Models\DialogModel;
use App\Models\MessageModel;
use App\Models\UserModel;
use App\Models\UserToDialogsModel;
use Core\DatabaseManager;
use Workerman\Connection\TcpConnection;

class MessangerSocket {
    public function load(array $conns, TcpConnection $conn, string $user_uid, string $dialog_uid) {
        $dialogModel = new DialogModel();

        $dialog = $dialogModel->select(['id'])->where('uid', '=', $dialog_uid)->first();
        
        $messageModel = new MessageModel();
        $messages = $messageModel->select([
            'message', 
            'dialog_id', 
            'from_user_id', 
            'created_at', 
            'updated_at', 
            'message_status'
        ], 'msg')
        ->where('dialog_id', '=', $dialog['dialogs_id'])
        ->innerJoin('users', 'from_user_id', 'id', [
            'firstname',
            'surname',
            'user_image'
        ], 'u')->order_by('msg_created_at')->get();
        
        $conn->send(json_encode([
            'action' => 'get_messages',
            'messages' => $messages
        ]));
        return;
    }

    public function user_typing(array $conns, TcpConnection $conn, string $user_uid, string $dialog_uid) {
        $dialogModel = new DialogModel();
        $userModel = new UserModel();

        $dialog = $dialogModel->select()->where('uid', '=', $dialog_uid)->first(true);
        $user = $userModel->select()->where('uid', '=', $user_uid)->first(true);

        $userToDialogsModel = new UserToDialogsModel();
        $userToDialogs = $userToDialogsModel->select()
            ->where('dialog_id', '=', $dialog->id)
            ->where('user_id', '!=', $user->id)
            ->innerJoin('users', 'user_id', 'id', ['uid'])
            ->get();

        $notification = json_encode([
            'action' => 'user_typing',
            'dialog_uid' => $dialog_uid,
            'user_uid' => $user_uid
        ]);

        // Отправляем уведомление всем участникам диалога
        foreach ($userToDialogs as $participant) {
            if (isset($conns[$participant['users_uid']]) && $participant['users_uid'] !== $user_uid) {
                $conns[$participant['users_uid']]->send($notification);
            }
        }
        
        return;
    }

    public function stop_typing(array $conns, TcpConnection $conn, string $user_uid, string $dialog_uid) {
        $dialogModel = new DialogModel();
        $userModel = new UserModel();
    
        $dialog = $dialogModel->select()->where('uid', '=', $dialog_uid)->first(true);
        $user = $userModel->select()->where('uid', '=', $user_uid)->first(true);
    
        $userToDialogsModel = new UserToDialogsModel();
        $userToDialogs = $userToDialogsModel->select()
            ->where('dialog_id', '=', $dialog->id)
            ->where('user_id', '!=', $user->id)
            ->innerJoin('users', 'user_id', 'id', ['uid'])
            ->get();
    
        $notification = json_encode([
            'action' => 'typing_stop',
            'dialog_uid' => $dialog_uid,
            'user_uid' => $user_uid
        ]);
    
        // Отправляем уведомление всем участникам диалога
        foreach ($userToDialogs as $participant) {
            if (isset($conns[$participant['users_uid']]) && $participant['users_uid'] !== $user_uid) {
                $conns[$participant['users_uid']]->send($notification);
            }
        }
        
        return;
    }

    public function message_send(array $conns, TcpConnection $conn, string $user_uid, string $dialog_uid, string $message) {
        $dialogModel = new DialogModel();
        $userModel = new UserModel();

        $dialog = $dialogModel->select()->where('uid', '=', $dialog_uid)->first(true);
        $user = $userModel->select()->where('uid', '=', $user_uid)->first(true);

        $newMessage = new MessageModel();

        $current_date = date('Y-m-d H:i:s');

        $newMessage->from_user_id = $user->id;
        $newMessage->dialog_id = $dialog->id;
        $newMessage->message = $message;
        $newMessage->created_at = $current_date;
        $newMessage->updated_at = $current_date;
        $newMessage->message_status = 'sent';

        $dbManager = new DatabaseManager();
        $dbManager->queueInsert($newMessage);
        $insertedIds = $dbManager->commit();
        var_dump($insertedIds);
        // Получить добавленное сообщение
        $addedMessage = $newMessage->select([
            'message', 
            'dialog_id', 
            'from_user_id', 
            'created_at', 
            'updated_at', 
            'message_status'
        ], 'msg')->where('msg.id', '=', $insertedIds[0])
        ->innerJoin('users', 'from_user_id', 'id', [
            'firstname',
            'surname',
            'user_image'
        ], 'u')
        ->first();

        $userToDialogsModel = new UserToDialogsModel();
        $users = $userToDialogsModel->select(['id'], 'utd')
        ->where('dialog_id', '=', $dialog->id)
        ->innerJoin('users', 'user_id', 'id', ['uid'], 'u')
        ->get();
        foreach ($users as $user) {
            if (!empty($user['u_uid']) && $conns[$user['u_uid']]) {
                $conns[$user['u_uid']]->send(json_encode([
                    'action' => 'send_message',
                    'message' => $addedMessage
                ]));
            }
        }
        return;
    }
}