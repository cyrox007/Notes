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
    
    /**
     * Шифрование контента сообщения
     */
    private function encryptContent($content) {
        $key = getenv('MSG_SECRET_KEY') ?: 'default_secret_key_change_me_in_production';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($content, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Расшифровка контента сообщения
     */
    private function decryptContent($data) {
        $key = getenv('MSG_SECRET_KEY') ?: 'default_secret_key_change_me_in_production';
        $data = base64_decode($data);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }

    public function load(array $conns, TcpConnection $conn, string $user_uid, string $dialog_uid) {
        $dialog = DialogModel::select('id', 'type')->where('uid', '=', $dialog_uid)->first();
        if (!$dialog) {
            return;
        }
        
        // Проверка доступа пользователя к диалогу
        $user = UserModel::select('id')->where('uid', '=', $user_uid)->first();
        $access = UserToDialogsModel::select()
            ->where('dialog_id', '=', $dialog->id)
            ->where('user_id', '=', $user->id)
            ->first();
            
        if (!$access) {
            $conn->send(json_encode([
                'action' => 'error',
                'message' => 'Доступ запрещен'
            ]));
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
            'messages.is_deleted',
            'messages.edited_at',
            'users.uid',
            'users.firstname',
            'users.lastname',
            'users.user_image'
        )
        ->where('messages.dialog_id', '=', $dialog->id)
        ->where('messages.is_deleted', '=', 0)
        ->innerJoin([UserModel::class, 'users'], 'messages.from_user_id', '=', 'users.id')
        ->orderBy('messages.created_at', 'ASC')
        ->get();
        
        // Расшифровка текстовых сообщений
        foreach ($messages as &$msg) {
            if ($msg->message_type === 'text' && !empty($msg->message)) {
                $msg->message = $this->decryptContent($msg->message);
            }
        }
        
        $conn->send(json_encode([
            'action' => 'get_messages',
            'dialog_uid' => $dialog_uid,
            'messages' => array_map(fn($m) => (array)$m, $messages)
        ]));
        return;
    }

    public function get_dialogs(array $conns, TcpConnection $conn, string $user_uid) {
        $user = UserModel::select('id')->where('uid', '=', $user_uid)->first();
        if (!$user) {
            return;
        }

        $dialogs = DialogModel::select(
            'dialogs.uid',
            'dialogs.id as dialog_id',
            'dialogs.type',
            'dialogs.name',
            'dialogs.updated_at'
        )
        ->innerJoin([UserToDialogsModel::class, 'dialog_users'], 'dialogs.id', '=', 'dialog_users.dialog_id')
        ->where('dialog_users.user_id', '=', $user->id)
        ->where('dialog_users.is_deleted', '=', 0)
        ->orderBy('dialogs.updated_at', 'DESC')
        ->get();
        
        // Дополняем информацией о собеседниках/участниках
        foreach ($dialogs as &$dialog) {
            // Считаем непрочитанные
            $lastRead = UserToDialogsModel::select('last_read_message_id')
                ->where('dialog_id', '=', $dialog->dialog_id)
                ->where('user_id', '=', $user->id)
                ->first();
            
            $unreadCount = MessageModel::selectRaw('COUNT(*) as count')
                ->where('dialog_id', '=', $dialog->dialog_id)
                ->where('from_user_id', '!=', $user->id)
                ->where('id', '>', $lastRead->last_read_message_id ?? 0)
                ->where('is_deleted', '=', 0)
                ->first();
            
            $dialog->unread_count = $unreadCount->count ?? 0;
            
            if ($dialog->type === 'private') {
                // Находим собеседника
                $partner = UserToDialogsModel::select(
                    'users.firstname',
                    'users.lastname',
                    'users.user_image',
                    'users.uid as user_uid'
                )
                ->innerJoin([UserModel::class, 'users'], 'dialog_users.user_id', '=', 'users.id')
                ->where('dialog_users.dialog_id', '=', $dialog->dialog_id)
                ->where('dialog_users.user_id', '!=', $user->id)
                ->first();
                
                $dialog->partner = $partner;
                $dialog->title = $partner ? ($partner->firstname . ' ' . $partner->lastname) : 'Неизвестный';
                $dialog->avatar = $partner->user_image ?? null;
            } else {
                // Для группы - название или "Групповой чат"
                $dialog->title = $dialog->name ?? 'Групповой чат';
                $dialog->avatar = null;
            }
        }

        $conn->send(json_encode([
            'action' => 'get_dialogs',
            'dialogs' => array_map(fn($d) => (array)$d, $dialogs)
        ]));
        return;
    }

    public function create_dialog(array $conns, TcpConnection $conn, string $user_uid, array $params) {
        $user = UserModel::select('id')->where('uid', '=', $user_uid)->first();
        if (!$user) {
            $conn->send(json_encode([
                'action' => 'error',
                'message' => 'Пользователь не найден'
            ]));
            return;
        }

        $type = $params['type'] ?? 'private'; // private или group
        $participantUids = $params['participants'] ?? []; // массив uid участников
        $groupName = $params['name'] ?? null;

        if (empty($participantUids)) {
            $conn->send(json_encode([
                'action' => 'error',
                'message' => 'Нет участников'
            ]));
            return;
        }

        // Получаем ID всех участников
        $participants = [];
        foreach ($participantUids as $puid) {
            $p = UserModel::select('id')->where('uid', '=', $puid)->first();
            if ($p) {
                $participants[] = $p->id;
            }
        }

        if (empty($participants)) {
            $conn->send(json_encode([
                'action' => 'error',
                'message' => 'Участники не найдены'
            ]));
            return;
        }

        // Добавляем создателя если его нет в списке
        if (!in_array($user->id, $participants)) {
            $participants[] = $user->id;
        }

        // Для приватного чата (только 2 участника) проверяем существование
        if ($type === 'private' && count($participants) == 2) {
            sort($participants);
            $existingDialog = DialogModel::select('dialogs.uid')
                ->innerJoin([UserToDialogsModel::class, 'dialog_users'], 'dialogs.id', '=', 'dialog_users.dialog_id')
                ->where('dialogs.type', '=', 'private')
                ->whereIn('dialog_users.user_id', $participants)
                ->groupBy('dialogs.id')
                ->havingRaw('COUNT(DISTINCT dialog_users.user_id) = 2')
                ->first();

            if ($existingDialog) {
                $conn->send(json_encode([
                    'action' => 'dialog_exists',
                    'dialog_uid' => $existingDialog->uid
                ]));
                return;
            }
        }

        $dialogUid = UUID::v4();
        $current_date = date('Y-m-d H:i:s');

        $dbManager = DatabaseManager::getInstance();
        
        // Создаем диалог
        $dbManager->queueInsert([
            'uid' => $dialogUid,
            'type' => $type,
            'name' => $groupName,
            'created_by' => $user->id,
            'created_at' => $current_date,
            'updated_at' => $current_date
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

        // Добавляем всех участников
        foreach ($participants as $pid) {
            $role = ($pid == $user->id) ? 'admin' : 'member';
            $dbManager->queueInsert([
                'dialog_id' => $dialogId,
                'user_id' => $pid,
                'role' => $role,
                'joined_at' => $current_date
            ], 'dialog_users');
        }

        $dbManager->commit();

        // Отправляем результат создателю
        $conn->send(json_encode([
            'action' => 'dialog_created',
            'dialog' => [
                'uid' => $dialogUid,
                'type' => $type,
                'name' => $groupName,
                'title' => $groupName ?? ($type === 'group' ? 'Групповой чат' : ''),
                'participants' => $participantUids
            ]
        ]));

        // Уведомляем остальных участников
        foreach ($participantUids as $puid) {
            if ($puid !== $user_uid && isset($conns[$puid])) {
                $conns[$puid]->send(json_encode([
                    'action' => 'new_dialog',
                    'dialog' => [
                        'uid' => $dialogUid,
                        'type' => $type,
                        'name' => $groupName,
                        'created_by_uid' => $user_uid
                    ]
                ]));
            }
        }

        return;
    }

    public function user_typing(array $conns, TcpConnection $conn, string $user_uid, string $dialog_uid) {
        $dialogModel = new DialogModel();
        $userModel = new UserModel();

        $dialog = $dialogModel->select()->where('uid', '=', $dialog_uid)->first();
        $user = $userModel->select()->where('uid', '=', $user_uid)->first();

        $userToDialogsModel = new UserToDialogsModel();
        $userToDialogs = UserToDialogsModel::select('dialog_users.id', 'users.uid as users_uid')
            ->where('dialog_id', '=', $dialog->id)
            ->where('user_id', '!=', $user->id)
            ->innerJoin([UserModel::class, 'users'], 'dialog_users.user_id', '=', 'users.id')
            ->get();

        $notification = json_encode([
            'action' => 'user_typing',
            'dialog_uid' => $dialog_uid,
            'user_uid' => $user_uid
        ]);

        foreach ($userToDialogs as $participant) {
            if (isset($conns[$participant->users_uid]) && $participant->users_uid !== $user_uid) {
                $conns[$participant->users_uid]->send($notification);
            }
        }
        
        return;
    }

    public function stop_typing(array $conns, TcpConnection $conn, string $user_uid, string $dialog_uid) {
        $dialogModel = new DialogModel();
        $userModel = new UserModel();
    
        $dialog = $dialogModel->select()->where('uid', '=', $dialog_uid)->first();
        $user = $userModel->select()->where('uid', '=', $user_uid)->first();
    
        $userToDialogsModel = new UserToDialogsModel();
        $userToDialogs = UserToDialogsModel::select('dialog_users.id', 'users.uid as users_uid')
            ->where('dialog_id', '=', $dialog->id)
            ->where('user_id', '!=', $user->id)
            ->innerJoin([UserModel::class, 'users'], 'dialog_users.user_id', '=', 'users.id')
            ->get();
    
        $notification = json_encode([
            'action' => 'typing_stop',
            'dialog_uid' => $dialog_uid,
            'user_uid' => $user_uid
        ]);
    
        foreach ($userToDialogs as $participant) {
            if (isset($conns[$participant->users_uid]) && $participant->users_uid !== $user_uid) {
                $conns[$participant->users_uid]->send($notification);
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
        
        // Проверка участия пользователя в диалоге
        $access = UserToDialogsModel::select()
            ->where('dialog_id', '=', $dialog->id)
            ->where('user_id', '=', $user->id)
            ->first();
            
        if (!$access) {
            $conn->send(json_encode([
                'action' => 'error',
                'message' => 'Вы не участник этого чата'
            ]));
            return;
        }

        $newMessage = new MessageModel();

        $current_date = date('Y-m-d H:i:s');
        $messageUid = UUID::v4();

        $newMessage->uid = $messageUid;
        $newMessage->from_user_id = $user->id;
        $newMessage->dialog_id = $dialog->id;
        
        // Шифруем только текстовые сообщения
        if ($message_type === 'text') {
            $newMessage->message = $this->encryptContent($message);
        } else {
            $newMessage->message = $message; // медиа или другие типы не шифруем (или шифруем отдельно)
        }
        
        $newMessage->created_at = $current_date;
        $newMessage->updated_at = $current_date;
        $newMessage->message_status = $status;
        $newMessage->message_type = $message_type;
        $newMessage->media_url = $media_url;
        $newMessage->is_deleted = 0;

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
            'media_url' => $newMessage->media_url,
            'is_deleted' => $newMessage->is_deleted
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
            'messages.is_deleted',
            'users.firstname',
            'users.lastname',
            'users.user_image',
            'users.uid as user_uid'
        )->where('messages.id', '=', $insertedIds[0])
        ->innerJoin([UserModel::class, 'users'], 'messages.from_user_id', '=','users.id')
        ->first();
        
        // Расшифровываем сообщение перед отправкой клиентам
        if ($addedMessage->message_type === 'text' && !empty($addedMessage->message)) {
            $addedMessage->message = $this->decryptContent($addedMessage->message);
        }

        // Обновляем время диалога
        $dbManager->queueUpdate([
            'updated_at' => $current_date
        ], 'dialogs', $dialog->id);
        $dbManager->commit();

        // Получаем всех участников диалога для рассылки
        $userToDialogsModel = new UserToDialogsModel();
        $users = UserToDialogsModel::select('dialog_users.id', 'users.uid as u_uid')
        ->where('dialog_id', '=', $dialog->id)
        ->innerJoin([UserModel::class, 'users'], 'dialog_users.user_id', '=', 'users.id')
        ->get();
        
        foreach ($users as $participant) {
            if (!empty($participant->u_uid) && isset($conns[$participant->u_uid])) {
                $conns[$participant->u_uid]->send(json_encode([
                    'action' => 'send_message',
                    'dialog_uid' => $dialog_uid,
                    'message' => (array)$addedMessage
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

        // Шифруем новый текст
        $encryptedText = $this->encryptContent($new_text);

        $dbManager = DatabaseManager::getInstance();
        $dbManager->queueUpdate([
            'message' => $encryptedText,
            'updated_at' => date('Y-m-d H:i:s'),
            'edited_at' => date('Y-m-d H:i:s')
        ], 'messages', $message->id);
        $dbManager->commit();

        $userToDialogsModel = new UserToDialogsModel();
        $users = UserToDialogsModel::select('dialog_users.id', 'users.uid as u_uid')
            ->where('dialog_id', '=', $message->dialog_id)
            ->innerJoin([UserModel::class, 'users'], 'dialog_users.user_id', '=', 'users.id')
            ->get();

        foreach ($users as $participant) {
            if (!empty($participant->u_uid) && isset($conns[$participant->u_uid])) {
                $conns[$participant->u_uid]->send(json_encode([
                    'action' => 'message_edited',
                    'message_uid' => $message_uid,
                    'new_text' => $new_text, // Отправляем расшифрованный текст
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
        
        // Safe-удаление: помечаем как удаленное, но не стираем
        if ($for_all || $message->from_user_id == $user->id) {
            $dbManager->queueUpdate([
                'is_deleted' => 1,
                'message' => '[Сообщение удалено]',
                'updated_at' => date('Y-m-d H:i:s'),
                'deleted_at' => date('Y-m-d H:i:s'),
                'message_status' => 'deleted'
            ], 'messages', $message->id);
        } else {
            // Удаляем только для себя (пока просто помечаем статус)
            $dbManager->queueUpdate([
                'updated_at' => date('Y-m-d H:i:s')
            ], 'messages', $message->id);
        }
        
        $dbManager->commit();

        $userToDialogsModel = new UserToDialogsModel();
        $users = UserToDialogsModel::select('dialog_users.id', 'users.uid as u_uid')
            ->where('dialog_id', '=', $message->dialog_id)
            ->innerJoin([UserModel::class, 'users'], 'dialog_users.user_id', '=', 'users.id')
            ->get();

        foreach ($users as $participant) {
            if (!empty($participant->u_uid) && isset($conns[$participant->u_uid])) {
                $conns[$participant->u_uid]->send(json_encode([
                    'action' => 'message_deleted',
                    'message_uid' => $message_uid,
                    'for_all' => $for_all,
                    'dialog_uid' => DialogModel::select('uid')->where('id', '=', $message->dialog_id)->first()->uid
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
            $message = $messageModel->select()->where('messages.uid', '=', $msg_uid)->first();
            if ($message) {
                $messages[$message->uid] = $message;
            }
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
            'messages' => array_map(fn($m) => (array)$m, $messages),
            'status' => $status
        ]);

        $user = $userModel->select()->where('uid', '=', $user_uid)->first();

        $userToDialogs = $userToDialogsModel->select()
            ->where('dialog_id', '=', $message->dialog_id)
            ->where('user_id', '!=', $user->id)
            ->innerJoin([UserModel::class, 'users'], 'dialog_users.user_id', '=', 'users.id')
            ->get();

        foreach ($userToDialogs as $participant) {
            if (isset($conns[$participant->users_uid]) && $participant->users_uid !== $user_uid) {
                $conns[$participant->users_uid]->send($notification);
            }
        }

        $conn->send($notification);
    }

}
