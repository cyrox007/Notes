<?php

namespace App\Sockets;

use App\Models\DialogModel;
use App\Models\MessageModel;
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
}