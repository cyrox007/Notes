<?php

declare(strict_types=1);

namespace App\Sockets;

use App\Services\MessengerDialogStateService;
use DomainException;
use InvalidArgumentException;

final class DialogStateSocket
{
    public function __construct(private ?MessengerDialogStateService $states = null)
    {
        $this->states ??= new MessengerDialogStateService();
    }

    public function list(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connection, $userUid): void {
            $this->send($connection, [
                'action' => 'dialog_states',
                'states' => $this->states->listStates($userUid),
            ]);
        });
    }

    public function pin(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connection, $userUid, $payload): void {
            $dialogUid = $this->dialogUid($payload);
            $result = array_key_exists('pinned', $payload)
                ? $this->states->setPinned($userUid, $dialogUid, filter_var($payload['pinned'], FILTER_VALIDATE_BOOLEAN))
                : $this->states->togglePinned($userUid, $dialogUid);
            $this->send($connection, ['action' => 'dialog_state', 'state' => 'pinned', ...$result]);
        });
    }

    public function archive(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connection, $userUid, $payload): void {
            $dialogUid = $this->dialogUid($payload);
            $result = array_key_exists('archived', $payload)
                ? $this->states->setArchived($userUid, $dialogUid, filter_var($payload['archived'], FILTER_VALIDATE_BOOLEAN))
                : $this->states->toggleArchived($userUid, $dialogUid);
            $this->send($connection, ['action' => 'dialog_state', 'state' => 'archived', ...$result]);
        });
    }

    public function mute(array $connections, SocketConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connection, $userUid, $payload): void {
            $dialogUid = $this->dialogUid($payload);
            if (array_key_exists('seconds', $payload)) {
                $seconds = $payload['seconds'] === null ? null : (int) $payload['seconds'];
                $result = $this->states->setMuted($userUid, $dialogUid, $seconds);
            } else {
                $result = $this->states->toggleMuted($userUid, $dialogUid);
            }
            $this->send($connection, ['action' => 'dialog_state', 'state' => 'muted', ...$result]);
        });
    }

    private function dialogUid(array $payload): string
    {
        $uid = trim((string) ($payload['dialog_uid'] ?? ''));
        if ($uid === '') {
            throw new InvalidArgumentException('Не указан диалог');
        }
        return $uid;
    }

    private function guard(SocketConnection $connection, callable $callback): void
    {
        try {
            $callback();
        } catch (InvalidArgumentException | DomainException $e) {
            $this->send($connection, ['action' => 'error', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('Dialog state socket failure: ' . $e->getMessage());
            $this->send($connection, ['action' => 'error', 'message' => 'Ошибка изменения состояния диалога']);
        }
    }

    private function send(SocketConnection $connection, array $payload): void
    {
        $connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
