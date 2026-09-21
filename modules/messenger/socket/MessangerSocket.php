<?php

declare(strict_types=1);

namespace App\Sockets;

use App\Models\DialogModel;
use App\Services\MessengerService;
use App\Services\MessengerRealtimePublisher;
use App\Services\RolePolicyService;
use Core\DatabaseManager;
use DomainException;
use InvalidArgumentException;

final class MessangerSocket
{
    private const ACTIVITY_TYPES = [
        'typing',
        'recording_voice',
        'recording_video',
        'uploading_image',
        'uploading_voice',
        'uploading_audio',
        'uploading_video',
        'uploading_document',
        'uploading_file',
    ];

    private RolePolicyService $policies;
    private DatabaseManager $db;
    private MessengerRealtimePublisher $publisher;

    public function __construct(
        private ?MessengerService $messenger = null,
        ?RolePolicyService $policies = null,
        ?DatabaseManager $db = null,
        ?MessengerRealtimePublisher $publisher = null
    ) {
        $this->db = $db ?? DatabaseManager::getInstance();
        $this->messenger ??= new MessengerService($this->db);
        $this->policies = $policies ?? new RolePolicyService($this->db);
        $this->publisher = $publisher ?? new MessengerRealtimePublisher();
    }

    public function get_dialogs(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->guard($connection, function () use ($connections, $connection, $userUid): void {
            $dialogs = $this->messenger->listDialogs($userUid);

            foreach ($dialogs as &$dialog) {
                $onlineCount = 0;
                foreach ($dialog['participants'] ?? [] as $participant) {
                    $participantUid = (string) ($participant['uid'] ?? '');
                    if ($participantUid !== '' && !empty($connections[$participantUid])) {
                        $onlineCount++;
                    }
                }
                $dialog['online_count'] = $onlineCount;
                if (!empty($dialog['partner']['uid'])) {
                    $dialog['partner']['online'] = !empty($connections[$dialog['partner']['uid']]);
                }
            }
            unset($dialog);

            $this->send($connection, [
                'action' => 'get_dialogs',
                'dialogs' => $dialogs,
            ]);
        });
    }

    public function load(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->guard($connection, function () use ($connection, $userUid, $payload): void {
            $dialogUid = $this->requiredString($payload, 'dialog_uid');
            $beforeId = isset($payload['before_id']) && (int) $payload['before_id'] > 0
                ? (int) $payload['before_id']
                : null;

            $result = $this->messenger->getMessages($userUid, $dialogUid, $beforeId);
            $this->send($connection, [
                'action' => 'get_messages',
                'dialog_uid' => $dialogUid,
                'dialog' => $result['dialog'],
                'messages' => $result['messages'],
                'has_more' => $result['has_more'],
                'prepend' => $beforeId !== null,
            ]);
        });
    }

    public function create_dialog(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->guard($connection, function () use ($connections, $connection, $userUid, $payload): void {
            $type = (string) ($payload['type'] ?? 'private');
            $participants = is_array($payload['participants'] ?? null) ? $payload['participants'] : [];
            $name = isset($payload['name']) ? (string) $payload['name'] : null;

            if ($type === 'group') {
                $this->assertGroupCreationPolicy($connection, $participants);
            }

            $dialog = $this->messenger->createDialog($userUid, $type, $participants, $name);
            $dialogUid = (string) $dialog['uid'];

            $this->send($connection, [
                'action' => 'dialog_created',
                'dialog' => $dialog,
            ]);

            foreach ($this->messenger->participantUids($userUid, $dialogUid) as $participantUid) {
                if ($participantUid === $userUid || empty($connections[$participantUid])) {
                    continue;
                }

                $participantDialog = $this->messenger->getDialogInfo($participantUid, $dialogUid);
                $this->sendToUser($connections, $participantUid, [
                    'action' => 'new_dialog',
                    'dialog' => $participantDialog,
                ]);
            }
        });
    }

    public function message_send(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->guard($connection, function () use ($connections, $connection, $userUid, $payload): void {
            $this->assertMessageRatePolicy($connection);
            $dialogUid = $this->requiredString($payload, 'dialog_uid');
            $message = $this->requiredString($payload, 'message', allowWhitespace: true);
            $replyToUid = isset($payload['reply_to_uid']) ? trim((string) $payload['reply_to_uid']) : null;

            $stored = $this->messenger->sendMessage($userUid, $dialogUid, $message, $replyToUid);
            $this->broadcast($connections, $userUid, $dialogUid, [
                'action' => 'send_message',
                'dialog_uid' => $dialogUid,
                'message' => $stored,
            ]);
        });
    }

    public function edit_message(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->guard($connection, function () use ($connections, $userUid, $payload): void {
            $messageUid = $this->requiredString($payload, 'message_uid');
            $newText = $this->requiredString($payload, 'new_text', allowWhitespace: true);
            $message = $this->messenger->editMessage($userUid, $messageUid, $newText);

            $dialog = DialogModel::select('uid')
                ->where('id', '=', (int) $message['dialog_id'])
                ->first();
            if (!$dialog || empty($dialog->uid)) {
                throw new DomainException('Диалог сообщения не найден');
            }

            $dialogUid = (string) $dialog->uid;
            $this->broadcast($connections, $userUid, $dialogUid, [
                'action' => 'message_edited',
                'dialog_uid' => $dialogUid,
                'message' => $message,
            ]);
        });
    }

    public function delete_message(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->guard($connection, function () use ($connections, $connection, $userUid, $payload): void {
            $messageUid = $this->requiredString($payload, 'message_uid');
            $forAll = filter_var($payload['for_all'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $result = $this->messenger->deleteMessage($userUid, $messageUid, $forAll);

            if ($forAll) {
                $this->broadcast($connections, $userUid, $result['dialog_uid'], [
                    'action' => 'message_deleted',
                    ...$result,
                ]);
                return;
            }

            $this->send($connection, [
                'action' => 'message_deleted',
                ...$result,
            ]);
        });
    }

    public function mark_read(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->guard($connection, function () use ($connections, $userUid, $payload): void {
            $dialogUid = $this->requiredString($payload, 'dialog_uid');
            $messageUid = isset($payload['message_uid']) ? trim((string) $payload['message_uid']) : null;
            $read = $this->messenger->markRead($userUid, $dialogUid, $messageUid);

            $this->broadcast($connections, $userUid, $dialogUid, [
                'action' => 'read_update',
                ...$read,
            ]);
        });
    }

    public function user_typing(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->typing($connections, $connection, $userUid, $payload, true);
    }

    public function stop_typing(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->typing($connections, $connection, $userUid, $payload, false);
    }

    /**
     * Broadcast an ephemeral activity signal to the other participants of the
     * current dialog. Activity is deliberately not persisted and is safe while
     * the installation is in license read-only mode.
     */
    public function activity(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->guard($connection, function () use ($connections, $userUid, $payload): void {
            $dialogUid = $this->requiredString($payload, 'dialog_uid');
            $activity = $this->requiredString($payload, 'activity');
            if (!in_array($activity, self::ACTIVITY_TYPES, true)) {
                throw new InvalidArgumentException('Неизвестный тип активности');
            }
            if (!array_key_exists('active', $payload)
                || (!is_bool($payload['active']) && !in_array($payload['active'], [0, 1, '0', '1'], true))) {
                throw new InvalidArgumentException('Параметр active должен быть boolean');
            }
            $active = filter_var($payload['active'], FILTER_VALIDATE_BOOLEAN);

            $this->broadcast($connections, $userUid, $dialogUid, [
                'action' => 'activity',
                'dialog_uid' => $dialogUid,
                'user_uid' => $userUid,
                'activity' => $activity,
                'active' => $active,
            ], excludeUserUid: $userUid);
        });
    }

    private function typing(
        array $connections,
        SocketConnection $connection,
        string $userUid,
        array $payload,
        bool $typing
    ): void {
        $this->guard($connection, function () use ($connections, $userUid, $payload, $typing): void {
            $dialogUid = $this->requiredString($payload, 'dialog_uid');
            $this->broadcast($connections, $userUid, $dialogUid, [
                'action' => $typing ? 'user_typing' : 'typing_stop',
                'dialog_uid' => $dialogUid,
                'user_uid' => $userUid,
            ], excludeUserUid: $userUid);
        });
    }

    private function assertGroupCreationPolicy(SocketConnection $connection, array $participants): void
    {
        $userId = (int) ($connection->userId ?? 0);
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация');
        }
        if (!(bool) $this->policies->effectiveValue($userId, 'messenger', 'can_create_groups')) {
            throw new DomainException('Создание групп отключено для вашей роли');
        }

        $maxMembers = (int) $this->policies->effectiveValue($userId, 'messenger', 'max_group_members');
        if ($maxMembers <= 0) {
            return;
        }
        $unique = [];
        foreach ($participants as $participant) {
            $uid = trim((string) $participant);
            if ($uid !== '') {
                $unique[$uid] = true;
            }
        }
        if (count($unique) + 1 > $maxMembers) {
            throw new DomainException('Количество участников группы превышает лимит вашей роли');
        }
    }

    private function assertMessageRatePolicy(SocketConnection $connection): void
    {
        $userId = (int) ($connection->userId ?? 0);
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация');
        }
        $limit = (int) $this->policies->effectiveValue($userId, 'messenger', 'messages_per_minute');
        if ($limit <= 0) {
            return;
        }

        $sent = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM messages WHERE from_user_id = :user_id AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)',
            [':user_id' => $userId]
        );
        if ($sent >= $limit) {
            throw new DomainException('Превышен лимит сообщений в минуту для вашей роли');
        }
    }

    private function broadcast(
        array $connections,
        string $actorUid,
        string $dialogUid,
        array $payload,
        ?string $excludeUserUid = null
    ): void {
        foreach ($this->messenger->participantUids($actorUid, $dialogUid) as $participantUid) {
            if ($excludeUserUid !== null && $participantUid === $excludeUserUid) {
                continue;
            }
            $this->sendToUser($connections, $participantUid, $payload);
        }
    }

    private function sendToUser(array $connections, string $userUid, array $payload): void
    {
        $this->publisher->publishToUser($connections, $userUid, $payload);
    }

    private function guard(SocketConnection $connection, callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException $e) {
            $this->error($connection, 'validation_error', $e->getMessage());
        } catch (DomainException $e) {
            $this->error($connection, 'forbidden', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Messenger socket failure: ' . $e->getMessage());
            $this->error($connection, 'server_error', 'Не удалось выполнить операцию мессенджера');
        }
    }

    private function error(SocketConnection $connection, string $code, string $message): void
    {
        $this->send($connection, [
            'action' => 'error',
            'code' => $code,
            'message' => $message,
        ]);
    }

    private function send(SocketConnection $connection, array $payload): void
    {
        $connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function requiredString(array $payload, string $key, bool $allowWhitespace = false): string
    {
        if (!array_key_exists($key, $payload) || !is_scalar($payload[$key])) {
            throw new InvalidArgumentException("Отсутствует параметр {$key}");
        }

        $value = (string) $payload[$key];
        if (!$allowWhitespace) {
            $value = trim($value);
        }
        if (trim($value) === '') {
            throw new InvalidArgumentException("Параметр {$key} не может быть пустым");
        }

        return $value;
    }
}
