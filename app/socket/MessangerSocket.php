<?php

declare(strict_types=1);

namespace App\Sockets;

use App\Models\DialogModel;
use App\Services\MessengerService;
use DomainException;
use InvalidArgumentException;
use Workerman\Connection\TcpConnection;

final class MessangerSocket
{
    public function __construct(private ?MessengerService $messenger = null)
    {
        $this->messenger ??= new MessengerService();
    }

    public function get_dialogs(
        array $connections,
        TcpConnection $connection,
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
        TcpConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->guard($connection, function () use ($connections, $connection, $userUid, $payload): void {
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

            if ($beforeId === null && $result['messages'] !== []) {
                $lastMessage = end($result['messages']);
                $read = $this->messenger->markRead(
                    $userUid,
                    $dialogUid,
                    (string) $lastMessage['uid']
                );
                $this->broadcast($connections, $userUid, $dialogUid, [
                    'action' => 'read_update',
                    ...$read,
                ]);
            }
        });
    }

    public function create_dialog(
        array $connections,
        TcpConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->guard($connection, function () use ($connections, $connection, $userUid, $payload): void {
            $type = (string) ($payload['type'] ?? 'private');
            $participants = is_array($payload['participants'] ?? null) ? $payload['participants'] : [];
            $name = isset($payload['name']) ? (string) $payload['name'] : null;

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
        TcpConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->guard($connection, function () use ($connections, $userUid, $payload): void {
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
        TcpConnection $connection,
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
        TcpConnection $connection,
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
        TcpConnection $connection,
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
        TcpConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->typing($connections, $connection, $userUid, $payload, true);
    }

    public function stop_typing(
        array $connections,
        TcpConnection $connection,
        string $userUid,
        array $payload = []
    ): void {
        $this->typing($connections, $connection, $userUid, $payload, false);
    }

    private function typing(
        array $connections,
        TcpConnection $connection,
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
        foreach ($connections[$userUid] ?? [] as $userConnection) {
            if ($userConnection instanceof TcpConnection) {
                $this->send($userConnection, $payload);
            }
        }
    }

    private function guard(TcpConnection $connection, callable $callback): void
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

    private function error(TcpConnection $connection, string $code, string $message): void
    {
        $this->send($connection, [
            'action' => 'error',
            'code' => $code,
            'message' => $message,
        ]);
    }

    private function send(TcpConnection $connection, array $payload): void
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
