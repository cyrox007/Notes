<?php 

class MessagerController {
    private $db;

    public function __construct($pdo) {
        $this->db = $pdo;
    }

    /**
     * Базовая функция шифрования (для примера защиты в БД)
     * В продакшене ключи должны храниться в env, а лучше использовать E2EE на клиенте
     */
    private function encryptContent($content) {
        $key = getenv('MSG_SECRET_KEY') ?: 'default_secret_key_change_me';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($content, 'aes-256-cbc', $key, 0, $iv);
        // Сохраняем вектор инициализации вместе с данными
        return base64_encode($iv . $encrypted);
    }

    private function decryptContent($data) {
        $key = getenv('MSG_SECRET_KEY') ?: 'default_secret_key_change_me';
        $data = base64_decode($data);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);
    }

    /**
     * Получение списка диалогов пользователя
     */
    public function getDialogs($userId) {
        $stmt = $this->db->prepare("
            SELECT d.*, du.role as my_role, du.last_read_message_id,
                   (SELECT COUNT(*) FROM messages m
                    WHERE m.dialog_id = d.id AND m.sender_id != ? AND m.id > du.last_read_message_id AND m.is_deleted = 0) as unread_count
            FROM dialogs d
            JOIN dialog_users du ON d.id = du.dialog_id
            WHERE du.user_id = ? AND du.is_deleted = 0
            ORDER BY d.updated_at DESC
        ");
        $stmt->execute([$userId, $userId]);
        $dialogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Дополняем информацией о собеседниках или участниках
        foreach ($dialogs as &$dialog) {
            if ($dialog['type'] === 'private') {
                // Находим собеседника
                $stmtUser = $this->db->prepare("
                    SELECT u.id, u.firstname, u.lastname, u.avatar
                    FROM dialog_users du
                    JOIN users u ON u.id = du.user_id
                    WHERE du.dialog_id = ? AND du.user_id != ?
                    LIMIT 1
                ");
                $stmtUser->execute([$dialog['id'], $userId]);
                $partner = $stmtUser->fetch(PDO::FETCH_ASSOC);
                $dialog['partner'] = $partner;
                $dialog['title'] = $partner ? ($partner['firstname'] . ' ' . $partner['lastname']) : 'Неизвестный';
                $dialog['avatar'] = $partner['avatar'] ?? null;
            } else {
                // Для группы берем название или список участников
                $dialog['title'] = $dialog['name'] ?? 'Групповой чат';
            }
        }

        return $dialogs;
    }

    /**
     * Создание диалога (приватного или группы)
     * $data: ['type' => 'private'|'group', 'users' => [ids], 'name' => string (для группы)]
     */
    public function createDialog($creatorId, $data) {
        $type = $data['type'] ?? 'private';
        $users = $data['users'] ?? [];

        if (empty($users)) {
            return ['success' => false, 'message' => 'Нет участников'];
        }

        // Для приватного чата проверяем, не существует ли уже такой
        if ($type === 'private' && count($users) == 2) {
            sort($users); // Сортируем для проверки
            $stmt = $this->db->prepare("
                SELECT d.id FROM dialogs d
                JOIN dialog_users du1 ON d.id = du1.dialog_id AND du1.user_id = ?
                JOIN dialog_users du2 ON d.id = du2.dialog_id AND du2.user_id = ?
                WHERE d.type = 'private' AND du1.is_deleted = 0 AND du2.is_deleted = 0
            ");
            $stmt->execute([$users[0], $users[1]]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                return ['success' => true, 'dialog_id' => $existing['id'], 'exists' => true];
            }
        }

        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("
                INSERT INTO dialogs (type, name, created_by, updated_at, created_at)
                VALUES (?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$type, $data['name'] ?? null, $creatorId]);
            $dialogId = $this->db->lastInsertId();

            // Добавляем участников
            $stmtUser = $this->db->prepare("
                INSERT INTO dialog_users (dialog_id, user_id, role, joined_at)
                VALUES (?, ?, ?, NOW())
            ");

            foreach ($users as $uid) {
                $role = ($uid == $creatorId) ? 'admin' : 'member';
                // Если группа, создатель тоже админ, остальные просто члены (пока)
                $stmtUser->execute([$dialogId, $uid, $role]);
            }

            $this->db->commit();
            return ['success' => true, 'dialog_id' => $dialogId, 'exists' => false];

        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Загрузка истории сообщений
     */
    public function loadMessages($dialogId, $userId, $limit = 50, $offset = 0) {
        // Проверка доступа
        $check = $this->db->prepare("SELECT id FROM dialog_users WHERE dialog_id = ? AND user_id = ? AND is_deleted = 0");
        $check->execute([$dialogId, $userId]);
        if (!$check->fetch()) {
            return ['success' => false, 'message' => 'Доступ запрещен'];
        }

        $stmt = $this->db->prepare("
            SELECT m.*, u.firstname, u.lastname, u.avatar
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.dialog_id = ? AND m.is_deleted = 0
            ORDER BY m.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$dialogId, $limit, $offset]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Расшифровка контента
        foreach ($messages as &$msg) {
            if ($msg['content_type'] === 'text') {
                $msg['content'] = $this->decryptContent($msg['content']);
            }
            // Медиа файлы могут быть зашифрованы отдельно или храниться как ссылки
        }

        return array_reverse($messages); // Возвращаем в хронологическом порядке
    }

    /**
     * Отправка сообщения
     */
    public function sendMessage($dialogId, $senderId, $content, $type = 'text', $extra = []) {
        // Проверка участия
        $check = $this->db->prepare("SELECT id FROM dialog_users WHERE dialog_id = ? AND user_id = ? AND is_deleted = 0");
        $check->execute([$dialogId, $senderId]);
        if (!$check->fetch()) {
            return ['success' => false, 'message' => 'Вы не участник этого чата'];
        }

        $encryptedContent = ($type === 'text') ? $this->encryptContent($content) : $content;

        $stmt = $this->db->prepare("
            INSERT INTO messages (dialog_id, sender_id, content, content_type, meta_data, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");

        $meta = json_encode($extra);
        $stmt->execute([$dialogId, $senderId, $encryptedContent, $type, $meta]);
        $messageId = $this->db->lastInsertId();

        // Обновляем время диалога
        $this->db->prepare("UPDATE dialogs SET updated_at = NOW() WHERE id = ?")->execute([$dialogId]);

        // Получаем полные данные сообщения для отправки клиентам
        $msgData = $this->loadMessages($dialogId, $senderId, 1, 0);
        $newMessage = end($msgData);

        // Получаем список всех участников диалога для рассылки
        $stmtUsers = $this->db->prepare("SELECT user_id FROM dialog_users WHERE dialog_id = ? AND is_deleted = 0");
        $stmtUsers->execute([$dialogId]);
        $targetUsers = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);

        return [
            'success' => true,
            'message' => $newMessage,
            'targets' => $targetUsers
        ];
    }

    /**
     * Редактирование сообщения
     */
    public function editMessage($messageId, $userId, $newContent) {
        $check = $this->db->prepare("SELECT dialog_id, sender_id FROM messages WHERE id = ?");
        $check->execute([$messageId]);
        $msg = $check->fetch(PDO::FETCH_ASSOC);

        if (!$msg || $msg['sender_id'] != $userId) {
            return ['success' => false, 'message' => 'Нельзя редактировать чужое сообщение'];
        }

        $encrypted = $this->encryptContent($newContent);
        $stmt = $this->db->prepare("UPDATE messages SET content = ?, edited_at = NOW() WHERE id = ?");
        $stmt->execute([$encrypted, $messageId]);

        // Рассылка обновления всем участникам диалога
        $stmtUsers = $this->db->prepare("SELECT user_id FROM dialog_users WHERE dialog_id = ? AND is_deleted = 0");
        $stmtUsers->execute([$msg['dialog_id']]);
        $targets = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);

        return ['success' => true, 'targets' => $targets, 'message_id' => $messageId, 'content' => $newContent];
    }

    /**
     * Safe-удаление (помечаем как удаленное, но не стираем из БД)
     */
    public function deleteMessage($messageId, $userId) {
        $check = $this->db->prepare("SELECT dialog_id, sender_id FROM messages WHERE id = ?");
        $check->execute([$messageId]);
        $msg = $check->fetch(PDO::FETCH_ASSOC);

        if (!$msg) {
            return ['success' => false, 'message' => 'Сообщение не найдено'];
        }

        // Удалять может только отправитель (или админ в группе - можно доработать)
        if ($msg['sender_id'] != $userId) {
             return ['success' => false, 'message' => 'Нельзя удалить чужое сообщение'];
        }

        $stmt = $this->db->prepare("UPDATE messages SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
        $stmt->execute([$messageId]);

        $stmtUsers = $this->db->prepare("SELECT user_id FROM dialog_users WHERE dialog_id = ? AND is_deleted = 0");
        $stmtUsers->execute([$msg['dialog_id']]);
        $targets = $stmtUsers->fetchAll(PDO::FETCH_COLUMN);

        return ['success' => true, 'targets' => $targets, 'message_id' => $messageId];
    }

    /**
     * Обновление статуса прочтения
     */
    public function markAsRead($dialogId, $userId, $maxMessageId) {
        $stmt = $this->db->prepare("
            UPDATE dialog_users SET last_read_message_id = ?
            WHERE dialog_id = ? AND user_id = ?
        ");
        $stmt->execute([$maxMessageId, $dialogId, $userId]);
        return ['success' => true];
    }

    /**
     * Поиск пользователей (для создания диалога)
     */
    public function searchUsers($query, $excludeId) {
        $stmt = $this->db->prepare("
            SELECT id, firstname, lastname, avatar
            FROM users
            WHERE (firstname LIKE ? OR lastname LIKE ? OR CONCAT(firstname, ' ', lastname) LIKE ?)
            AND id != ?
            LIMIT 10
        ");
        $q = "%$query%";
        $stmt->execute([$q, $q, $q, $excludeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}