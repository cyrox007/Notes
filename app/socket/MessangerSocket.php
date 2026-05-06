<?php

namespace App\Sockets;

use App\Models\DialogModel;
use App\Models\MessageModel;
use App\Models\UserModel;
use App\Models\UserToDialogsModel;
use Core\DatabaseManager;
use Workerman\Connection\TcpConnection;
use UUID;

class MessangerSocket {
    public function load(array $conns, TcpConnection $conn, string $user_uid, string $dialog_uid) {
        $dialog = DialogModel::select('id')->where('uid', '=', $dialog_uid)->first();
        if (!$dialog) {
            return;
        }
        
        $messages = MessageModel::select(
            'messages.uid',
            'messages.message', 
            'messages.dialog_id', 
            'messages.from_user_id', 
            'messages.created_at', 
            'messages.updated_at', 
            'messages.message_status',
            'messages.message_type',
            'messages.media_url',
            'users.uid',
            'users.firstname',
            'users.surname',
            'users.user_image'
        )
        ->where('messages.dialog_id', '=', $dialog->id)
        ->innerJoin([UserModel::class, 'users'], 'messages.from_user_id', '=', 'users.id')
        ->orderBy('messages.created_at')
        ->get();
        
        $conn->send(json_encode([
            'action' => 'get_messages',
            'messages' => $messages
        ]));
        return;
    }

    public function get_dialogs(array $conns, TcpConnection $conn, string $user_uid) {
        $user = UserModel::select()->where('uid', '=', $user_uid)->first();
        if (!$user) {
            return;
        }

        $userToDialogs = UserToDialogsModel::select(
            'dialogs.uid',
            'dialogs.id as dialog_id',
            'users.firstname',
            'users.surname',
            'users.user_image',
            'users.uid as user_uid'
        )
        ->innerJoin([DialogModel::class, 'dialogs'], 'user_to_dialogs.dialog_id', '=', 'dialogs.id')
        ->innerJoin([UserModel::class, 'users'], 'user_to_dialogs.user_id', '!=', 'users.id')
        ->where('user_to_dialogs.user_id', '=', $user->id)
        ->get();

        $conn->send(json_encode([
            'action' => 'get_dialogs',
            'dialogs' => $userToDialogs
        ]));
        return;
    }

    public function create_dialog(array $conns, TcpConnection $conn, string $user_uid, string $interlocutor_uid) {
        $user = UserModel::select()->where('uid', '=', $user_uid)->first();
        $interlocutor = UserModel::select()->where('uid', '=', $interlocutor_uid)->first();

        if (!$user || !$interlocutor) {
            $conn->send(json_encode([
                'action' => 'error',
                'message' => 'Пользователи не найдены'
            ]));
            return;
        }

        $existingDialog = UserToDialogsModel::select('dialogs.uid')
            ->innerJoin([DialogModel::class, 'dialogs'], 'user_to_dialogs.dialog_id', '=', 'dialogs.id')
            ->where('user_to_dialogs.user_id', '=', $user->id)
            ->first();

        if ($existingDialog) {
            $conn->send(json_encode([
                'action' => 'dialog_exists',
                'dialog_uid' => $existingDialog->uid
            ]));
            return;
        }

        $dialogUid = UUID::v4();
        $current_date = date('Y-m-d H:i:s');

        $newDialog = new DialogModel();
        $newDialog->uid = $dialogUid;
        $newDialog->created_at = $current_date;
        $newDialog->updated_at = $current_date;

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueInsert([
            'uid' => $newDialog->uid,
            'created_at' => $newDialog->created_at,
            'updated_at' => $newDialog->updated_at
        ], 'dialogs');
        $insertedIds = $dbManager->commit();

        if (empty($insertedIds)) {
            $conn->send(json_encode([
                'action' => 'error',
                'message' => 'Ошибка создания диалога'
            ]));
            return;
        }

        $dialogId = $insertedIds[0];

        $dbManager->queueInsert([
            'dialog_id' => $dialogId,
            'user_id' => $user->id
        ], 'user_to_dialogs');

        $dbManager->queueInsert([
            'dialog_id' => $dialogId,
            'user_id' => $interlocutor->id
        ], 'user_to_dialogs');

        $dbManager->commit();

        $conn->send(json_encode([
            'action' => 'dialog_created',
            'dialog' => [
                'uid' => $dialogUid,
                'interlocutor_firstname' => $interlocutor->firstname,
                'interlocutor_surname' => $interlocutor->surname,
                'interlocutor_uid' => $interlocutor->uid
            ]
        ]));

        if (isset($conns[$interlocutor_uid])) {
            $conns[$interlocutor_uid]->send(json_encode([
                'action' => 'new_dialog',
                'dialog' => [
                    'uid' => $dialogUid,
                    'interlocutor_firstname' => $user->firstname,
                    'interlocutor_surname' => $user->surname,
                    'interlocutor_uid' => $user->uid
                ]
            ]));
        }

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
    
        foreach ($userToDialogs as $participant) {
            if (isset($conns[$participant['users_uid']]) && $participant['users_uid'] !== $user_uid) {
                $conns[$participant['users_uid']]->send($notification);
            }
        }
        
        return;
    }

    public function message_send(
        array $conns, 
        TcpConnection $conn, 
        string $user_uid, 
        string $dialog_uid, 
        string $message, 
        bool $files = false, 
        string $status = 'unread',
        string $message_type = 'text',
        string $media_url = ''
    ) {
        $dialog = DialogModel::select()->where('uid', '=', $dialog_uid)->first();
        $user = UserModel::select()->where('uid', '=', $user_uid)->first();

        if (!$dialog || !$user) {
            return;
        }

        $newMessage = new MessageModel();

        $current_date = date('Y-m-d H:i:s');
        $messageUid = UUID::v4();

        $newMessage->uid = $messageUid;
        $newMessage->from_user_id = $user->id;
        $newMessage->dialog_id = $dialog->id;
        $newMessage->message = $message;
        $newMessage->created_at = $current_date;
        $newMessage->updated_at = $current_date;
        $newMessage->message_status = $status;
        $newMessage->message_type = $message_type;
        $newMessage->media_url = $media_url;

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueInsert([
            'uid' => $newMessage->uid,
            'from_user_id' => $newMessage->from_user_id,
            'dialog_id' => $newMessage->dialog_id,
            'message' => $newMessage->message,
            'created_at' => $newMessage->created_at,
            'updated_at' => $newMessage->updated_at,
            'message_status' => $newMessage->message_status,
            'message_type' => $newMessage->message_type,
            'media_url' => $newMessage->media_url
        ], 'messages');
        $insertedIds = $dbManager->commit();
        
        $addedMessage = $newMessage->select(
            'messages.uid', 
            'messages.message', 
            'messages.dialog_id', 
            'messages.from_user_id', 
            'messages.created_at', 
            'messages.updated_at', 
            'messages.message_status',
            'messages.message_type',
            'messages.media_url',
            'users.firstname',
            'users.surname',
            'users.user_image',
            'users.uid as user_uid'
        )->where('messages.id', '=', $insertedIds[0])
        ->innerJoin([UserModel::class, 'users'], 'messages.from_user_id', '=','users.id')
        ->first();

        $userToDialogsModel = new UserToDialogsModel();
        $users = $userToDialogsModel::select(['id'], 'utd')
        ->where('dialog_id', '=', $dialog->id)
        ->innerJoin('users', 'user_id', 'id', ['uid'], 'u')
        ->get();
        foreach ($users as $user) {
            if (!empty($user['u_uid']) && isset($conns[$user['u_uid']])) {
                $conns[$user['u_uid']]->send(json_encode([
                    'action' => 'send_message',
                    'message' => $addedMessage
                ]));
            }
        }
        return;
    }

    public function edit_message(array $conns, TcpConnection $conn, string $user_uid, string $message_uid, string $new_text) {
        $messageModel = new MessageModel();
        $userModel = new UserModel();

        $message = $messageModel->select()->where('uid', '=', $message_uid)->first();
        $user = $userModel->select()->where('uid', '=', $user_uid)->first();

        if (!$message || !$user) {
            return;
        }

        if ($message->from_user_id != $user->id) {
            $conn->send(json_encode([
                'action' => 'error',
                'message' => 'Вы можете редактировать только свои сообщения'
            ]));
            return;
        }

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate([
            'message' => $new_text,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'messages', $message->id);
        $dbManager->commit();

        $userToDialogsModel = new UserToDialogsModel();
        $users = $userToDialogsModel->select(['id'], 'utd')
            ->where('dialog_id', '=', $message->dialog_id)
            ->innerJoin('users', 'user_id', 'id', ['uid'], 'u')
            ->get();

        foreach ($users as $user) {
            if (!empty($user['u_uid']) && isset($conns[$user['u_uid']])) {
                $conns[$user['u_uid']]->send(json_encode([
                    'action' => 'message_edited',
                    'message_uid' => $message_uid,
                    'new_text' => $new_text,
                    'updated_at' => date('Y-m-d H:i:s')
                ]));
            }
        }
    }

    public function delete_message(array $conns, TcpConnection $conn, string $user_uid, string $message_uid, bool $for_all = false) {
        $messageModel = new MessageModel();
        $userModel = new UserModel();

        $message = $messageModel->select()->where('uid', '=', $message_uid)->first();
        $user = $userModel->select()->where('uid', '=', $user_uid)->first();

        if (!$message || !$user) {
            return;
        }

        $dbManager = DatabaseManager::getInstance();
        
        if ($for_all || $message->from_user_id == $user->id) {
            $dbManager->queueUpdate([
                'message' => '[Сообщение удалено]',
                'updated_at' => date('Y-m-d H:i:s'),
                'message_status' => 'deleted'
            ], 'messages', $message->id);
        } else {
            $dbManager->queueUpdate([
                'updated_at' => date('Y-m-d H:i:s')
            ], 'messages', $message->id);
        }
        
        $dbManager->commit();

        $userToDialogsModel = new UserToDialogsModel();
        $users = $userToDialogsModel->select(['id'], 'utd')
            ->where('dialog_id', '=', $message->dialog_id)
            ->innerJoin('users', 'user_id', 'id', ['uid'], 'u')
            ->get();

        foreach ($users as $user) {
            if (!empty($user['u_uid']) && isset($conns[$user['u_uid']])) {
                $conns[$user['u_uid']]->send(json_encode([
                    'action' => 'message_deleted',
                    'message_uid' => $message_uid,
                    'for_all' => $for_all
                ]));
            }
        }
    }

    public function update_message_status(array $conns, TcpConnection $conn, string $user_uid, array $msg_uid_array, string $status) {
        $messageModel = new MessageModel();
        $userModel = new UserModel();
        $userToDialogsModel = new UserToDialogsModel();
        $dbManager = DatabaseManager::getInstance();

        if (empty($msg_uid_array)) {
            error_log("msgUidsArray: " . json_encode($msg_uid_array));
            return;
        }

        $messages = [];
        foreach ($msg_uid_array as $msg_uid) {
            $message = $messageModel->select()->where('messages.uid', '=', $msg_uid)->first(true);
            $messages[$message->uid] = $message;
        }

        if (empty($messages)) {
            error_log("messages: " . json_encode($messages));
            return;
        }

        foreach ($messages as $message) {
            $message->message_status = $status;
            $dbManager->queueUpdate([
                'message_status' => $message->message_status
            ], 'messages', $message->id);
        }

        if (!$dbManager->commit()) {
            return;
        }

        $notification = json_encode([
            'action' => 'update_message_status',
            'messages' => $messages,
            'status' => $status
        ]);

        $user = $userModel->select()->where('uid', '=', $user_uid)->first(true);

        $userToDialogs = $userToDialogsModel->select()
            ->where('dialog_id', '=', $message->dialog_id)
            ->where('user_id', '!=', $user->id)
            ->innerJoin('users', 'user_id', 'id', ['uid'])
            ->get();

        foreach ($userToDialogs as $participant) {
            if (isset($conns[$participant['users_uid']]) && $participant['users_uid'] !== $user_uid) {
                $conns[$participant['users_uid']]->send($notification);
            }
        }

        $conn->send($notification);
    }

}
