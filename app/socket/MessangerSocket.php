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
        $dialog = DialogModel::select('id')->where('uid', '=', $dialog_uid)->first();
        if (!$dialog) {
            // обработка ошибки, например, отправка сообщения об ошибке клиенту
            return;
        }
        //print_r($dialog);
        $messages = MessageModel::select(
            'messages.uid',
            'messages.message', 
            'messages.dialog_id', 
            'messages.from_user_id', 
            'messages.created_at', 
            'messages.updated_at', 
            'messages.message_status',
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

    public function message_send(
        array $conns, 
        TcpConnection $conn, 
        string $user_uid, 
        string $dialog_uid, 
        string $message, 
        bool $files, 
        string $status
    ) {
        $dialog = DialogModel::select()->where('uid', '=', $dialog_uid)->first();
        $user = UserModel::select()->where('uid', '=', $user_uid)->first();

        $newMessage = new MessageModel();

        $current_date = date('Y-m-d H:i:s');

        $newMessage->from_user_id = $user->id;
        $newMessage->dialog_id = $dialog->id;
        $newMessage->message = $message;
        $newMessage->created_at = $current_date;
        $newMessage->updated_at = $current_date;
        $newMessage->message_status = $status;

        $dbManager = new DatabaseManager();
        $dbManager->queueInsert($newMessage);
        $insertedIds = $dbManager->commit();
        
        // Получить добавленное сообщение
        $addedMessage = $newMessage->select(
            'messages.message', 
            'messages.dialog_id', 
            'messages.from_user_id', 
            'messages.created_at', 
            'messages.updated_at', 
            'messages.message_status',
            'users.firstname',
            'users.surname',
            'users.user_image'
        )->where('messages.id', '=', $insertedIds[0])
        ->innerJoin([UserModel::class, 'users'], 'messages.from_user_id', '=','users.id')
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

	public function update_message_status(array $conns, TcpConnection $conn, string $user_uid, array $msg_uid_array, string $status) {
		// Instantiate the necessary models
		$messageModel = new MessageModel();
		$userModel = new UserModel();
		$userToDialogsModel = new UserToDialogsModel();
		$dbManager = new DatabaseManager();

		// Check if the msg_uid_array is empty
		if (empty($msg_uid_array)) {
			error_log("msgUidsArray: " . json_encode($msg_uid_array));
			return;
		}

		// Fetch the messages based on the msg_uid_array
		$messages = [];
		foreach ($msg_uid_array as $msg_uid) {
			$message = $messageModel->select()->where('messages.uid', '=', $msg_uid)->first(true);
			$messages[$message->uid] = $message;
		}

		// Check if no messages were found
		if (empty($messages)) {
			error_log("messages: " . json_encode($messages));
			return;
		}

		// Update the message status and queue the updates
		foreach ($messages as $message) {
			$message->message_status = $status;
			$dbManager->queueUpdate($message);
		}

		// Commit the updates to the database
		if (!$dbManager->commit()) {
			return;
		}

		// Prepare the notification payload
		$notification = json_encode([
			'action' => 'update_message_status',
			'messages' => $messages,
			'status' => $status
		]);

		// Fetch the user based on the user_uid
		$user = $userModel->select()->where('uid', '=', $user_uid)->first(true);

		// Fetch the userToDialogs based on the dialog_id and user_id
		$userToDialogs = $userToDialogsModel->select()
			->where('dialog_id', '=', $message->dialog_id)
			->where('user_id', '!=', $user->id)
			->innerJoin('users', 'user_id', 'id', ['uid'])
			->get();

		// Send the notification to the participants
		foreach ($userToDialogs as $participant) {
			if (isset($conns[$participant['users_uid']]) && $participant['users_uid'] !== $user_uid) {
				$conns[$participant['users_uid']]->send($notification);
			}
		}

		// Send the notification to the current user
		$conn->send($notification);
	}

}