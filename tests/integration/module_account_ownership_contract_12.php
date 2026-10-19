<?php

declare(strict_types=1);

// Проверка межмодульного контракта без рабочей БД и без запуска модулей.
$root = dirname(__DIR__, 2);
require_once $root . '/core/AccountDeactivationGuard.php';
require_once $root . '/core/ModuleCapabilityRegistry.php';
require_once $root . '/modules/admin/services/AdminUserService.php';

use App\Services\AdminUserService;
use Core\AccountDeactivationGuard;
use Core\ModuleCapabilityRegistry;

final class AccountGuardFixture implements AccountDeactivationGuard
{
    public int $calls = 0;

    public function __construct(private readonly ?array $blocker = null)
    {
    }

    public function accountDeactivationBlocker(int $userId): ?array
    {
        if ($userId !== 17) {
            throw new RuntimeException('В интерфейс передан неправильный пользователь');
        }
        $this->calls++;
        return $this->blocker;
    }
}

function guardAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function guardRegistry(?array $messengerBlock, ?array $taskBlock, bool $withTasks = true): array
{
    $registry = new ModuleCapabilityRegistry();
    $messenger = new AccountGuardFixture($messengerBlock);
    $tasks = new AccountGuardFixture($taskBlock);
    $registry->register('messenger', 'workspace.messenger', $messenger);
    if ($withTasks) {
        $registry->register('tasks', 'workspace.tasks', $tasks);
    }
    $registry->seal();
    return [$registry, $messenger, $tasks];
}

$serviceClass = new ReflectionClass(AdminUserService::class);
$method = $serviceClass->getMethod('requireReleasedModuleOwnership');
$capabilities = $serviceClass->getProperty('capabilities');
$check = static function (ModuleCapabilityRegistry $registry) use ($serviceClass, $method, $capabilities): void {
    $service = $serviceClass->newInstanceWithoutConstructor();
    $capabilities->setValue($service, $registry);
    $method->invoke($service, 17);
};

[$registry, $messenger, $tasks] = guardRegistry(null, null);
$check($registry);
guardAssert($messenger->calls === 1 && $tasks->calls === 1, 'Оба модуля должны проверяться через интерфейс');

foreach (['messenger', 'tasks'] as $owner) {
    $message = 'Перед удалением передайте владение объектом';
    [$registry] = guardRegistry(
        $owner === 'messenger' ? ['code' => 'group', 'message' => $message] : null,
        $owner === 'tasks' ? ['code' => 'board', 'message' => $message] : null
    );
    try {
        $check($registry);
        throw new RuntimeException('Проверка ошибочно разрешила удаление владельца ' . $owner);
    } catch (DomainException $error) {
        guardAssert($error->getCode() === 409 && str_contains($error->getMessage(), $message),
            'Должно возвращаться сообщение модуля о владении');
    }
}

[$registry] = guardRegistry(null, null, false);
try {
    $check($registry);
    throw new RuntimeException('Удаление разрешено без проверки отключённого Tasks');
} catch (DomainException $error) {
    guardAssert($error->getCode() === 409, 'Если проверка недоступна, необходимо отказать');
}

echo "Контракт проверки владения через активные модули пройден\n";
