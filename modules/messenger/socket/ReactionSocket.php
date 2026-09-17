<?php

declare(strict_types=1);

namespace App\Sockets;

use App\Services\MessengerReactionService;
use DomainException;
use InvalidArgumentException;

final class ReactionSocket
{
    public function __construct(private ?MessengerReactionService $reactions = null)
    {
        $this->reactions ??= new MessengerReactionService();
    }

    public function list(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connection, $userUid, $payload): void {
            $messageUids = is_array($payload['message_uids'] ?? null)
                ? $payload['message_uids']
                : [];

            $this->send($connection, [
                'action' => 'reaction_state',
                'reactions' => $this->reactions->listForUser($userUid, $messageUids),
            ]);
        });
    }

    public function toggle(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connections, $userUid, $payload): void {
            $messageUid = $this->requiredString($payload, 'message_uid');
            $reactionCode = $this->requiredString($payload, 'reaction_code');
            $changed = $this->reactions->toggle($userUid, $messageUid, $reactionCode);

            foreach ($this->reactions->participantUids($messageUid) as $participantUid) {
                $summary = $this->reactions->summaryForUser($participantUid, $messageUid);
                if ($summary === null) {
                    continue;
                }
                $this->sendToUser($connections, $participantUid, [
                    'action' => 'reaction_update',
                    'dialog_uid' => $changed['dialog_uid'],
                    'message_uid' => $messageUid,
                    'reactions' => $summary,
                ]);
            }
        });
    }

    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException("Не указан параметр {$key}");
        }
        return $value;
    }

    private function sendToUser(array $connections, string $userUid, array $payload): void
    {
        foreach ($connections[$userUid] ?? [] as $userConnection) {
            if ($userConnection instanceof SocketConnection) {
                $this->send($userConnection, $payload);
            }
        }
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
            error_log('Reaction socket failure: ' . $e->getMessage());
            $this->error($connection, 'server_error', 'Не удалось изменить реакцию');
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
}
