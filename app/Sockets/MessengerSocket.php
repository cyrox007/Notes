<?php

namespace App\Sockets;

use Workerman\Connection\TcpConnection;
use App\Models\DialogModel;
use App\Models\MessageModel;
use App\Models\UserToDialogsModel;
use App\Models\UserModel;
use Core\DatabaseManager;

/**
 * WebSocket контроллер для работы с мессенджером
 */
class MessengerSocket {
    
    /**
     * Отправка сообщения в диалог
     */
    public function sendMessage(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            $this->sendError($connection, 'Пользователь не авторизован');
            return;
        }
        
        $dialogId = $data['dialog_id'] ?? null;
        $content = $data['content'] ?? '';
        $contentType = $data['content_type'] ?? 'text';
        $metaData = $data['meta_data'] ?? [];
        
        if (!$dialogId) {
            $this->sendError($connection, 'Не указан ID диалога');
            return;
        }
        
        // Проверка участия пользователя в диалоге
        $userDialog = UserToDialogsModel::select()
            ->where('dialog_id', '=', $dialogId)
            ->where('user_id', '=', $userId)
            ->where('is_deleted', '=', 0)
            ->first();
        
        if (!$userDialog) {
            $this->sendError($connection, 'Вы не участник этого диалога');
            return;
        }
        
        // Получаем настройки максимального размера сообщения
        $maxSizeSetting = \App\Models\SystemSettingModel::select()
            ->where('setting_key', '=', 'messenger_max_message_size')
            ->first();
        $maxMessageSize = $maxSizeSetting ? (int)$maxSizeSetting->setting_value : 10485760; // 10MB по умолчанию
        
        // Проверка размера контента для текста
        if ($contentType === 'text' && strlen($content) > $maxMessageSize) {
            $this->sendError($connection, 'Размер сообщения превышает лимит');
            return;
        }
        
        // Проверка разрешенных типов файлов
        if ($contentType !== 'text') {
            $allowedTypesSetting = \App\Models\SystemSettingModel::select()
                ->where('setting_key', '=', 'messenger_allowed_file_types')
                ->first();
            $allowedTypes = $allowedTypesSetting ? json_decode($allowedTypesSetting->setting_value, true) : [];
            
            if (!empty($allowedTypes) && !in_array($contentType, $allowedTypes)) {
                $this->sendError($connection, 'Этот тип файла не разрешен');
                return;
            }
        }
        
        try {
            $dbManager = DatabaseManager::getInstance();
            
            // Шифрование текстового контента
            $encryptedContent = $content;
            if ($contentType === 'text') {
                $encryptedContent = $this->encryptContent($content);
            }
            
            // Создание сообщения
            $dbManager->queueInsert([
                'dialog_id' => $dialogId,
                'sender_id' => $userId,
                'content' => $encryptedContent,
                'content_type' => $contentType,
                'meta_data' => json_encode($metaData, JSON_UNESCAPED_UNICODE),
                'message_status' => 'sent',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], 'messages');
            
            // Обновление времени диалога
            $dbManager->queueUpdate([
                'updated_at' => date('Y-m-d H:i:s')
            ], 'dialogs', $dialogId);
            
            $result = $dbManager->commit();
            
            if ($result === false) {
                $this->sendError($connection, 'Ошибка при сохранении сообщения');
                return;
            }
            
            $messageId = $result;
            
            // Получаем полные данные сообщения
            $message = MessageModel::select()
                ->where('id', '=', $messageId)
                ->first();
            
            $sender = UserModel::select()
                ->where('id', '=', $userId)
                ->first();
            
            $messageData = [
                'action' => 'newMessage',
                'data' => [
                    'id' => $message->id,
                    'dialog_id' => $dialogId,
                    'sender_id' => $userId,
                    'sender_name' => $sender->firstname . ' ' . $sender->lastname,
                    'sender_avatar' => $sender->avatar ?? null,
                    'content' => $content, // Отправляем расшифрованный контент
                    'content_type' => $contentType,
                    'meta_data' => $metaData,
                    'created_at' => $message->created_at,
                    'edited_at' => null,
                    'is_deleted' => 0
                ]
            ];
            
            // Рассылка всем участникам диалога
            $this->broadcastToDialog($connections, $dialogId, $messageData, $userId);
            
            // Подтверждение отправителю
            $this->sendSuccess($connection, 'Сообщение отправлено', ['message_id' => $messageId]);
            
        } catch (\Exception $e) {
            error_log('MessengerSocket::sendMessage error: ' . $e->getMessage());
            $this->sendError($connection, 'Ошибка при отправке сообщения: ' . $e->getMessage());
        }
    }
    
    /**
     * Редактирование сообщения
     */
    public function editMessage(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            $this->sendError($connection, 'Пользователь не авторизован');
            return;
        }
        
        $messageId = $data['message_id'] ?? null;
        $newContent = $data['content'] ?? '';
        
        if (!$messageId) {
            $this->sendError($connection, 'Не указан ID сообщения');
            return;
        }
        
        $message = MessageModel::select()
            ->where('id', '=', $messageId)
            ->first();
        
        if (!$message) {
            $this->sendError($connection, 'Сообщение не найдено');
            return;
        }
        
        if ($message->sender_id != $userId) {
            $this->sendError($connection, 'Нельзя редактировать чужое сообщение');
            return;
        }
        
        try {
            $dbManager = DatabaseManager::getInstance();
            
            $encryptedContent = $this->encryptContent($newContent);
            
            $dbManager->queueUpdate([
                'content' => $encryptedContent,
                'edited_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], 'messages', $messageId);
            
            $result = $dbManager->commit();
            
            if ($result === false) {
                $this->sendError($connection, 'Ошибка при редактировании сообщения');
                return;
            }
            
            // Рассылка обновления всем участникам диалога
            $updateData = [
                'action' => 'messageEdited',
                'data' => [
                    'message_id' => $messageId,
                    'dialog_id' => $message->dialog_id,
                    'content' => $newContent,
                    'edited_at' => date('Y-m-d H:i:s')
                ]
            ];
            
            $this->broadcastToDialog($connections, $message->dialog_id, $updateData);
            
            $this->sendSuccess($connection, 'Сообщение отредактировано');
            
        } catch (\Exception $e) {
            error_log('MessengerSocket::editMessage error: ' . $e->getMessage());
            $this->sendError($connection, 'Ошибка при редактировании: ' . $e->getMessage());
        }
    }
    
    /**
     * Удаление сообщения (soft delete)
     */
    public function deleteMessage(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            $this->sendError($connection, 'Пользователь не авторизован');
            return;
        }
        
        $messageId = $data['message_id'] ?? null;
        
        if (!$messageId) {
            $this->sendError($connection, 'Не указан ID сообщения');
            return;
        }
        
        $message = MessageModel::select()
            ->where('id', '=', $messageId)
            ->first();
        
        if (!$message) {
            $this->sendError($connection, 'Сообщение не найдено');
            return;
        }
        
        if ($message->sender_id != $userId) {
            $this->sendError($connection, 'Нельзя удалить чужое сообщение');
            return;
        }
        
        try {
            $dbManager = DatabaseManager::getInstance();
            
            $dbManager->queueUpdate([
                'is_deleted' => 1,
                'deleted_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], 'messages', $messageId);
            
            $result = $dbManager->commit();
            
            if ($result === false) {
                $this->sendError($connection, 'Ошибка при удалении сообщения');
                return;
            }
            
            // Рассылка уведомления об удалении
            $deleteData = [
                'action' => 'messageDeleted',
                'data' => [
                    'message_id' => $messageId,
                    'dialog_id' => $message->dialog_id
                ]
            ];
            
            $this->broadcastToDialog($connections, $message->dialog_id, $deleteData);
            
            $this->sendSuccess($connection, 'Сообщение удалено');
            
        } catch (\Exception $e) {
            error_log('MessengerSocket::deleteMessage error: ' . $e->getMessage());
            $this->sendError($connection, 'Ошибка при удалении: ' . $e->getMessage());
        }
    }
    
    /**
     * Загрузка истории сообщений
     */
    public function loadHistory(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            $this->sendError($connection, 'Пользователь не авторизован');
            return;
        }
        
        $dialogId = $data['dialog_id'] ?? null;
        $limit = (int)($data['limit'] ?? 50);
        $offset = (int)($data['offset'] ?? 0);
        
        if (!$dialogId) {
            $this->sendError($connection, 'Не указан ID диалога');
            return;
        }
        
        // Проверка участия
        $userDialog = UserToDialogsModel::select()
            ->where('dialog_id', '=', $dialogId)
            ->where('user_id', '=', $userId)
            ->where('is_deleted', '=', 0)
            ->first();
        
        if (!$userDialog) {
            $this->sendError($connection, 'Доступ запрещен');
            return;
        }
        
        try {
            $messages = MessageModel::select(
                'messages.*',
                'users.firstname',
                'users.lastname',
                'users.avatar'
            )
            ->innerJoin([UserModel::class, 'users'], 'messages.sender_id', '=', 'users.id')
            ->where('messages.dialog_id', '=', $dialogId)
            ->where('messages.is_deleted', '=', 0)
            ->orderBy('messages.id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
            
            // Расшифровка и форматирование
            $formattedMessages = [];
            foreach (array_reverse($messages) as $msg) {
                $content = $msg->content;
                if ($msg->content_type === 'text') {
                    $content = $this->decryptContent($msg->content);
                }
                
                $formattedMessages[] = [
                    'id' => $msg->id,
                    'dialog_id' => $msg->dialog_id,
                    'sender_id' => $msg->sender_id,
                    'sender_name' => $msg->firstname . ' ' . $msg->lastname,
                    'sender_avatar' => $msg->avatar ?? null,
                    'content' => $content,
                    'content_type' => $msg->content_type,
                    'meta_data' => $msg->meta_data ? json_decode($msg->meta_data, true) : [],
                    'created_at' => $msg->created_at,
                    'edited_at' => $msg->edited_at,
                    'is_deleted' => $msg->is_deleted
                ];
            }
            
            $this->sendSuccess($connection, 'История загружена', [
                'dialog_id' => $dialogId,
                'messages' => $formattedMessages,
                'limit' => $limit,
                'offset' => $offset
            ]);
            
        } catch (\Exception $e) {
            error_log('MessengerSocket::loadHistory error: ' . $e->getMessage());
            $this->sendError($connection, 'Ошибка при загрузке истории: ' . $e->getMessage());
        }
    }
    
    /**
     * Обновление статуса прочтения
     */
    public function markAsRead(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            $this->sendError($connection, 'Пользователь не авторизован');
            return;
        }
        
        $dialogId = $data['dialog_id'] ?? null;
        $lastMessageId = (int)($data['last_message_id'] ?? 0);
        
        if (!$dialogId) {
            $this->sendError($connection, 'Не указан ID диалога');
            return;
        }
        
        try {
            $dbManager = DatabaseManager::getInstance();
            
            $dbManager->queueUpdate([
                'last_read_message_id' => $lastMessageId
            ], 'dialog_users', null, "dialog_id = {$dialogId} AND user_id = {$userId}");
            
            $result = $dbManager->commit();
            
            if ($result !== false) {
                // Уведомляем других участников о прочтении
                $readData = [
                    'action' => 'messagesRead',
                    'data' => [
                        'dialog_id' => $dialogId,
                        'user_id' => $userId,
                        'last_message_id' => $lastMessageId
                    ]
                ];
                
                $this->broadcastToDialog($connections, $dialogId, $readData, $userId);
                
                $this->sendSuccess($connection, 'Статус обновлен');
            } else {
                $this->sendError($connection, 'Ошибка при обновлении статуса');
            }
            
        } catch (\Exception $e) {
            error_log('MessengerSocket::markAsRead error: ' . $e->getMessage());
            $this->sendError($connection, 'Ошибка: ' . $e->getMessage());
        }
    }
    
    /**
     * Набор текста (typing indicator)
     */
    public function typing(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            return;
        }
        
        $dialogId = $data['dialog_id'] ?? null;
        
        if (!$dialogId) {
            return;
        }
        
        // Отправляем индикатор набора текста другим участникам
        $typingData = [
            'action' => 'userTyping',
            'data' => [
                'dialog_id' => $dialogId,
                'user_id' => $userId
            ]
        ];
        
        $this->broadcastToDialog($connections, $dialogId, $typingData, $userId);
    }
    
    /**
     * Отправка медиа-файла
     */
    public function sendMedia(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            $this->sendError($connection, 'Пользователь не авторизован');
            return;
        }
        
        // Проверяем размер файла
        $fileSize = (int)($data['file_size'] ?? 0);
        $fileName = $data['file_name'] ?? '';
        $fileType = $data['file_type'] ?? 'application/octet-stream';
        $fileData = $data['file_data'] ?? ''; // Base64 encoded
        
        if (empty($fileData)) {
            $this->sendError($connection, 'Нет данных файла');
            return;
        }
        
        // Получаем настройки максимального размера файла
        $maxFileSizeSetting = \App\Models\SystemSettingModel::select()
            ->where('setting_key', '=', 'messenger_max_message_size')
            ->first();
        $maxFileSize = $maxFileSizeSetting ? (int)$maxFileSizeSetting->setting_value : 10485760;
        
        if ($fileSize > $maxFileSize) {
            $this->sendError($connection, 'Размер файла превышает лимит (' . $this->formatBytes($maxFileSize) . ')');
            return;
        }
        
        // Сохраняем файл
        $uploadDir = SITEPATH . '/uploads/messenger/' . date('Y/m/d');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileUid = uniqid('msg_', true);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $safeFileName = $fileUid . '.' . $extension;
        $filePath = $uploadDir . '/' . $safeFileName;
        
        $fileContent = base64_decode($fileData);
        if ($fileContent === false) {
            $this->sendError($connection, 'Ошибка декодирования файла');
            return;
        }
        
        if (file_put_contents($filePath, $fileContent) === false) {
            $this->sendError($connection, 'Ошибка сохранения файла');
            return;
        }
        
        // Создаем сообщение с файлом
        $metaData = [
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'file_path' => '/uploads/messenger/' . date('Y/m/d') . '/' . $safeFileName,
            'mime_type' => $fileType
        ];
        
        // Рекурсивно вызываем sendMessage с типом файла
        $this->sendMessage($connections, $connection, [
            'dialog_id' => $data['dialog_id'],
            'content' => $metaData['file_path'],
            'content_type' => $fileType,
            'meta_data' => $metaData
        ]);
    }
    
    /**
     * Создание диалога
     */
    public function createDialog(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            $this->sendError($connection, 'Пользователь не авторизован');
            return;
        }
        
        $type = $data['type'] ?? 'private';
        $participants = $data['participants'] ?? [];
        $name = $data['name'] ?? null;
        
        if (empty($participants)) {
            $this->sendError($connection, 'Не указаны участники');
            return;
        }
        
        // Добавляем создателя в список участников
        if (!in_array($userId, $participants)) {
            $participants[] = $userId;
        }
        
        try {
            // Для приватного чата проверяем существование
            if ($type === 'private' && count($participants) == 2) {
                sort($participants);
                $existingDialog = DialogModel::select('dialogs.*')
                    ->innerJoin([UserToDialogsModel::class, 'dialog_users'], 'dialogs.id', '=', 'dialog_users.dialog_id')
                    ->where('dialogs.type', '=', 'private')
                    ->where('dialog_users.is_deleted', '=', 0)
                    ->get();
                
                // Упрощенная проверка - можно улучшить
                foreach ($existingDialog as $dialog) {
                    $dialogUsers = UserToDialogsModel::select()
                        ->where('dialog_id', '=', $dialog->id)
                        ->where('is_deleted', '=', 0)
                        ->get();
                    
                    $currentParticipants = array_column((array)$dialogUsers, 'user_id');
                    sort($currentParticipants);
                    
                    if ($currentParticipants === $participants) {
                        $this->sendSuccess($connection, 'Диалог уже существует', [
                            'dialog_id' => $dialog->id,
                            'exists' => true
                        ]);
                        return;
                    }
                }
            }
            
            $dbManager = DatabaseManager::getInstance();
            
            // Создание диалога
            $dbManager->queueInsert([
                'type' => $type,
                'name' => $name,
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], 'dialogs');
            
            $result = $dbManager->commit();
            
            if ($result === false) {
                $this->sendError($connection, 'Ошибка при создании диалога');
                return;
            }
            
            $dialogId = $result;
            
            // Добавление участников
            foreach ($participants as $participantId) {
                $role = ($participantId == $userId) ? 'admin' : 'member';
                
                $dbManager->queueInsert([
                    'dialog_id' => $dialogId,
                    'user_id' => $participantId,
                    'role' => $role,
                    'joined_at' => date('Y-m-d H:i:s')
                ], 'dialog_users');
            }
            
            $dbManager->commit();
            
            // Уведомляем всех участников о новом диалоге
            $newDialogData = [
                'action' => 'newDialog',
                'data' => [
                    'dialog_id' => $dialogId,
                    'type' => $type,
                    'name' => $name,
                    'created_by' => $userId,
                    'participants' => $participants
                ]
            ];
            
            foreach ($participants as $participantId) {
                if (isset($connections[$participantId])) {
                    $connections[$participantId]->send(json_encode($newDialogData, JSON_UNESCAPED_UNICODE));
                }
            }
            
            $this->sendSuccess($connection, 'Диалог создан', ['dialog_id' => $dialogId]);
            
        } catch (\Exception $e) {
            error_log('MessengerSocket::createDialog error: ' . $e->getMessage());
            $this->sendError($connection, 'Ошибка при создании диалога: ' . $e->getMessage());
        }
    }
    
    /**
     * Выход из группового чата
     */
    public function leaveDialog(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            $this->sendError($connection, 'Пользователь не авторизован');
            return;
        }
        
        $dialogId = $data['dialog_id'] ?? null;
        
        if (!$dialogId) {
            $this->sendError($connection, 'Не указан ID диалога');
            return;
        }
        
        try {
            $dbManager = DatabaseManager::getInstance();
            
            $dbManager->queueUpdate([
                'is_deleted' => 1
            ], 'dialog_users', null, "dialog_id = {$dialogId} AND user_id = {$userId}");
            
            $result = $dbManager->commit();
            
            if ($result !== false) {
                // Уведомляем остальных участников
                $leaveData = [
                    'action' => 'userLeftDialog',
                    'data' => [
                        'dialog_id' => $dialogId,
                        'user_id' => $userId
                    ]
                ];
                
                $this->broadcastToDialog($connections, $dialogId, $leaveData, $userId);
                
                $this->sendSuccess($connection, 'Вы покинули диалог');
            } else {
                $this->sendError($connection, 'Ошибка при выходе из диалога');
            }
            
        } catch (\Exception $e) {
            error_log('MessengerSocket::leaveDialog error: ' . $e->getMessage());
            $this->sendError($connection, 'Ошибка: ' . $e->getMessage());
        }
    }
    
    /**
     * Добавление участника в групповой чат
     */
    public function addParticipant(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            $this->sendError($connection, 'Пользователь не авторизован');
            return;
        }
        
        $dialogId = $data['dialog_id'] ?? null;
        $newParticipantId = $data['user_id'] ?? null;
        
        if (!$dialogId || !$newParticipantId) {
            $this->sendError($connection, 'Неверные параметры');
            return;
        }
        
        // Проверка прав администратора
        $userDialog = UserToDialogsModel::select()
            ->where('dialog_id', '=', $dialogId)
            ->where('user_id', '=', $userId)
            ->where('is_deleted', '=', 0)
            ->first();
        
        if (!$userDialog || $userDialog->role !== 'admin') {
            $this->sendError($connection, 'Только администратор может добавлять участников');
            return;
        }
        
        try {
            $dbManager = DatabaseManager::getInstance();
            
            $dbManager->queueInsert([
                'dialog_id' => $dialogId,
                'user_id' => $newParticipantId,
                'role' => 'member',
                'joined_at' => date('Y-m-d H:i:s')
            ], 'dialog_users');
            
            $result = $dbManager->commit();
            
            if ($result !== false) {
                // Уведомляем всех участников
                $addData = [
                    'action' => 'participantAdded',
                    'data' => [
                        'dialog_id' => $dialogId,
                        'user_id' => $newParticipantId,
                        'added_by' => $userId
                    ]
                ];
                
                $this->broadcastToDialog($connections, $dialogId, $addData);
                
                // Если новый участник онлайн, отправляем ему уведомление
                if (isset($connections[$newParticipantId])) {
                    $connections[$newParticipantId]->send(json_encode($addData, JSON_UNESCAPED_UNICODE));
                }
                
                $this->sendSuccess($connection, 'Участник добавлен');
            } else {
                $this->sendError($connection, 'Ошибка при добавлении участника');
            }
            
        } catch (\Exception $e) {
            error_log('MessengerSocket::addParticipant error: ' . $e->getMessage());
            $this->sendError($connection, 'Ошибка: ' . $e->getMessage());
        }
    }
    
    /**
     * Поиск пользователей
     */
    public function searchUsers(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            $this->sendError($connection, 'Пользователь не авторизован');
            return;
        }
        
        $query = $data['query'] ?? '';
        
        if (strlen($query) < 2) {
            $this->sendError($connection, 'Введите минимум 2 символа');
            return;
        }
        
        try {
            $users = UserModel::select('id', 'firstname', 'lastname', 'avatar', 'username')
                ->where('id', '!=', $userId)
                ->where(function($q) use ($query) {
                    $q->where('firstname', 'LIKE', "%{$query}%")
                      ->orWhere('lastname', 'LIKE', "%{$query}%")
                      ->orWhere('username', 'LIKE', "%{$query}%");
                })
                ->limit(10)
                ->get();
            
            $results = [];
            foreach ($users as $user) {
                $results[] = [
                    'id' => $user->id,
                    'name' => $user->firstname . ' ' . $user->lastname,
                    'username' => $user->username,
                    'avatar' => $user->avatar ?? null
                ];
            }
            
            $this->sendSuccess($connection, 'Поиск выполнен', ['users' => $results]);
            
        } catch (\Exception $e) {
            error_log('MessengerSocket::searchUsers error: ' . $e->getMessage());
            $this->sendError($connection, 'Ошибка поиска: ' . $e->getMessage());
        }
    }
    
    /**
     * Получение списка диалогов пользователя
     */
    public function getDialogs(array $connections, TcpConnection $connection, $data) {
        $userId = $connection->uid ?? null;
        
        if (!$userId) {
            $this->sendError($connection, 'Пользователь не авторизован');
            return;
        }
        
        try {
            $dialogs = DialogModel::select(
                'dialogs.*',
                'dialog_users.role as my_role',
                'dialog_users.last_read_message_id'
            )
            ->innerJoin([UserToDialogsModel::class, 'dialog_users'], 'dialogs.id', '=', 'dialog_users.dialog_id')
            ->where('dialog_users.user_id', '=', $userId)
            ->where('dialog_users.is_deleted', '=', 0)
            ->orderBy('dialogs.updated_at', 'DESC')
            ->get();
            
            $formattedDialogs = [];
            foreach ($dialogs as $dialog) {
                // Получаем информацию о собеседниках
                $participants = UserToDialogsModel::select(
                    'users.id',
                    'users.firstname',
                    'users.lastname',
                    'users.avatar',
                    'dialog_users.role'
                )
                ->innerJoin([UserModel::class, 'users'], 'dialog_users.user_id', '=', 'users.id')
                ->where('dialog_users.dialog_id', '=', $dialog->id)
                ->where('dialog_users.is_deleted', '=', 0)
                ->get();
                
                $title = $dialog->name;
                $avatar = null;
                
                if ($dialog->type === 'private' && count($participants) >= 1) {
                    foreach ($participants as $p) {
                        if ($p->id != $userId) {
                            $title = $p->firstname . ' ' . $p->lastname;
                            $avatar = $p->avatar;
                            break;
                        }
                    }
                } elseif ($dialog->type === 'group') {
                    $title = $dialog->name ?? 'Групповой чат (' . count($participants) . ')';
                }
                
                // Считаем непрочитанные
                $unreadCount = MessageModel::select()
                    ->where('dialog_id', '=', $dialog->id)
                    ->where('sender_id', '!=', $userId)
                    ->where('id', '>', $dialog->my_role)
                    ->where('is_deleted', '=', 0)
                    ->count();
                
                $formattedDialogs[] = [
                    'id' => $dialog->id,
                    'uid' => $dialog->uid,
                    'type' => $dialog->type,
                    'title' => $title,
                    'avatar' => $avatar,
                    'updated_at' => $dialog->updated_at,
                    'unread_count' => $unreadCount,
                    'participants_count' => count($participants)
                ];
            }
            
            $this->sendSuccess($connection, 'Диалоги получены', ['dialogs' => $formattedDialogs]);
            
        } catch (\Exception $e) {
            error_log('MessengerSocket::getDialogs error: ' . $e->getMessage());
            $this->sendError($connection, 'Ошибка: ' . $e->getMessage());
        }
    }
    
    // ==================== Helper Methods ====================
    
    /**
     * Рассылка сообщения всем участникам диалога
     */
    private function broadcastToDialog(array $connections, int $dialogId, array $message, ?int $excludeUserId = null) {
        $participants = UserToDialogsModel::select('user_id')
            ->where('dialog_id', '=', $dialogId)
            ->where('is_deleted', '=', 0)
            ->get();
        
        foreach ($participants as $participant) {
            if ($excludeUserId && $participant->user_id == $excludeUserId) {
                continue;
            }
            
            if (isset($connections[$participant->user_id])) {
                $connections[$participant->user_id]->send(json_encode($message, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    
    /**
     * Шифрование контента
     */
    private function encryptContent($content) {
        $key = getenv('MSG_SECRET_KEY') ?: 'default_secret_key_change_me';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($content, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Расшифровка контента
     */
    private function decryptContent($data) {
        $key = getenv('MSG_SECRET_KEY') ?: 'default_secret_key_change_me';
        $data = base64_decode($data);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }
    
    /**
     * Отправка успешного ответа
     */
    private function sendSuccess(TcpConnection $connection, string $message, array $data = []) {
        $response = [
            'action' => 'success',
            'message' => $message,
            'data' => $data
        ];
        $connection->send(json_encode($response, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Отправка ошибки
     */
    private function sendError(TcpConnection $connection, string $message) {
        $response = [
            'action' => 'error',
            'message' => $message
        ];
        $connection->send(json_encode($response, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Форматирование размера в байтах
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
