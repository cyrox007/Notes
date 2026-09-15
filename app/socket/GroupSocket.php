<?php

declare(strict_types=1);

namespace App\Sockets;

use App\Services\MessengerGroupService;
use App\Services\MessengerService;
use App\Services\RolePolicyService;
use DomainException;
use InvalidArgumentException;
use Workerman\Connection\TcpConnection;

final class GroupSocket
{
    private RolePolicyService $policies;

    public function __construct(
        private ?MessengerGroupService $groups = null,
        private ?MessengerService $messenger = null,
        ?RolePolicyService $policies = null
    ) {
        $this->groups ??= new MessengerGroupService();
        $this->messenger ??= new MessengerService();
        $this->policies = $policies ?? new RolePolicyService();
    }

    public function info(array $connections, TcpConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connection, $userUid, $payload): void {
            $dialogUid = $this->requiredString($payload, 'dialog_uid');
            $this->send($connection, [
                'action' => 'group_info',
                'group' => $this->groups->info($userUid, $dialogUid),
            ]);
        });
    }

    public function refresh(array $connections, TcpConnection $connection, string $userUid, array $payload = []): void
    {
        $this->guard($connection, function () use ($connections, $userUid, $payload): void {
            $dialogUid = $this->requiredString($payload, 'dialog_uid');
            $snapshot = $this->groups->info($userUid, $dialogUid);
            if (!in_array((string) ($snapshot['current_role'] ?? ''), ['owner', 'admin'], true)) {
                throw new DomainException('Недостаточно прав для обновления профиля группы');
            }

            $this->broadcastKnown(
                $connections,
                $this->messenger->participantUids($userUid, $dialogUid),
                [
                    'action' => 'group_changed',
                    'dialog_uid' => $dialogUid,
                    'reason' => 'profile_updated',
                ]
            );
        });
    }

    public function rename(array $connections, TcpConnection $connection, string $userUid, array $payload = []): void
    {
        $this->mutate($connections, $connection, $userUid, $payload, 'renamed', function (string $dialogUid) use ($userUid, $payload): void {
            $this->groups->rename($userUid, $dialogUid, $this->requiredString($payload, 'name'));
        });
    }

    public function add_members(array $connections, TcpConnection $connection, string $userUid, array $payload = []): void
    {
        $this->mutate($connections, $connection, $userUid, $payload, 'members_added', function (string $dialogUid) use ($connection, $userUid, $payload): void {
            $uids = is_array($payload['member_uids'] ?? null) ? $payload['member_uids'] : [];
            $this->assertMemberLimit($connection, $userUid, $dialogUid, $uids);
            $this->groups->addMembers($userUid, $dialogUid, $uids);
        });
    }

    public function remove_member(array $connections, TcpConnection $connection, string $userUid, array $payload = []): void
    {
        $dialogUid = $this->requiredString($payload, 'dialog_uid');
        $memberUid = $this->requiredString($payload, 'member_uid');
        $before = $this->messenger->participantUids($userUid, $dialogUid);

        $this->guard($connection, function () use ($connections, $userUid, $dialogUid, $memberUid, $before): void {
            $this->groups->removeMember($userUid, $dialogUid, $memberUid);
            $this->sendToUser($connections, $memberUid, [
                'action' => 'group_removed',
                'dialog_uid' => $dialogUid,
            ]);
            $this->broadcastKnown($connections, $before, [
                'action' => 'group_changed',
                'dialog_uid' => $dialogUid,
                'reason' => 'member_removed',
            ], excludeUserUid: $memberUid);
        });
    }

    public function set_role(array $connections, TcpConnection $connection, string $userUid, array $payload = []): void
    {
        $this->mutate($connections, $connection, $userUid, $payload, 'role_changed', function (string $dialogUid) use ($userUid, $payload): void {
            $this->groups->setRole(
                $userUid,
                $dialogUid,
                $this->requiredString($payload, 'member_uid'),
                $this->requiredString($payload, 'role')
            );
        });
    }

    public function transfer_owner(array $connections, TcpConnection $connection, string $userUid, array $payload = []): void
    {
        $this->mutate($connections, $connection, $userUid, $payload, 'owner_transferred', function (string $dialogUid) use ($userUid, $payload): void {
            $this->groups->transferOwner(
                $userUid,
                $dialogUid,
                $this->requiredString($payload, 'member_uid')
            );
        });
    }

    public function leave(array $connections, TcpConnection $connection, string $userUid, array $payload = []): void
    {
        $dialogUid = $this->requiredString($payload, 'dialog_uid');
        $before = $this->messenger->participantUids($userUid, $dialogUid);

        $this->guard($connection, function () use ($connections, $userUid, $dialogUid, $before): void {
            $this->groups->leave($userUid, $dialogUid);
            $this->sendToUser($connections, $userUid, [
                'action' => 'group_left',
                'dialog_uid' => $dialogUid,
            ]);
            $this->broadcastKnown($connections, $before, [
                'action' => 'group_changed',
                'dialog_uid' => $dialogUid,
                'reason' => 'member_left',
            ], excludeUserUid: $userUid);
        });
    }

    private function mutate(
        array $connections,
        TcpConnection $connection,
        string $userUid,
        array $payload,
        string $reason,
        callable $callback
    ): void {
        $dialogUid = $this->requiredString($payload, 'dialog_uid');
        $before = $this->messenger->participantUids($userUid, $dialogUid);

        $this->guard($connection, function () use ($connections, $userUid, $dialogUid, $before, $reason, $callback): void {
            $callback($dialogUid);
            $after = $this->messenger->participantUids($userUid, $dialogUid);
            $recipients = array_values(array_unique(array_merge($before, $after)));
            $this->broadcastKnown($connections, $recipients, [
                'action' => 'group_changed',
                'dialog_uid' => $dialogUid,
                'reason' => $reason,
            ]);
        });
    }

    private function assertMemberLimit(TcpConnection $connection, string $userUid, string $dialogUid, array $requested): void
    {
        $userId = (int) ($connection->userId ?? 0);
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация');
        }
        $limit = (int) $this->policies->effectiveValue($userId, 'messenger', 'max_group_members');
        if ($limit <= 0) {
            return;
        }

        $current = $this->messenger->participantUids($userUid, $dialogUid);
        $all = [];
        foreach ($current as $uid) {
            $uid = trim((string) $uid);
            if ($uid !== '') $all[$uid] = true;
        }
        foreach ($requested as $uid) {
            $uid = trim((string) $uid);
            if ($uid !== '') $all[$uid] = true;
        }
        if (count($all) > $limit) {
            throw new DomainException('Количество участников группы превышает лимит вашей роли');
        }
    }

    private function broadcastKnown(
        array $connections,
        array $userUids,
        array $payload,
        ?string $excludeUserUid = null
    ): void {
        foreach (array_values(array_unique($userUids)) as $uid) {
            $uid = (string) $uid;
            if ($uid === '' || ($excludeUserUid !== null && $uid === $excludeUserUid)) {
                continue;
            }
            $this->sendToUser($connections, $uid, $payload);
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

    private function requiredString(array $payload, string $key): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException("Не указан параметр {$key}");
        }
        return $value;
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
            error_log('Group socket failure: ' . $e->getMessage());
            $this->error($connection, 'server_error', 'Не удалось изменить группу');
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
}
