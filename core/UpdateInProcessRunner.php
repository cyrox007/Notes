<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/UpdateCommandRunner.php';
require_once __DIR__ . '/UpdateDatabaseMigrator.php';
require_once __DIR__ . '/UpdateWebHealthProbe.php';

/**
 * Внутрипроцессный исполнитель для ограниченного shared hosting.
 *
 * Разрешён только фиксированный набор внутренних updater-команд. Shell,
 * exec/system/passthru/popen и другие способы запуска процессов не используются.
 */
final class UpdateInProcessRunner implements UpdateCommandRunner
{
    /**
     * @param list<string> $command
     * @return array{code:int,stdout:string,stderr:string}
     */
    public function run(array $command, string $cwd, int $timeoutSeconds): array
    {
        if ($command === []) {
            throw new RuntimeException('Команда обновлятора не может быть пустой');
        }
        if ($timeoutSeconds < 1) {
            throw new RuntimeException('Timeout команды обновлятора должен быть положительным');
        }

        $script = isset($command[1]) ? realpath((string) $command[1]) : false;
        if (!is_string($script) || !is_file($script) || is_link((string) $command[1])) {
            return $this->failed('Не удалось безопасно определить внутреннюю updater-команду');
        }

        $normalized = str_replace('\\', '/', $script);
        $arguments = array_slice($command, 2);

        try {
            if (str_ends_with($normalized, '/bin/migrate.php')) {
                $root = dirname($script, 2);
                $statusOnly = in_array('--status', $arguments, true);
                $baselineVersionCode = $this->baselineVersionCode($arguments);
                $result = (new UpdateDatabaseMigrator($root))->run(
                    $statusOnly,
                    $baselineVersionCode
                );
                return [
                    'code' => 0,
                    'stdout' => $result['stdout'],
                    'stderr' => '',
                ];
            }

            if (str_ends_with($normalized, '/bin/healthcheck.php')) {
                $root = dirname($script, 2);
                $health = (new UpdateWebHealthProbe($root))->inspect();
                $stdout = json_encode(
                    $health,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ) . PHP_EOL;

                return [
                    'code' => ($health['status'] ?? '') === 'ok' ? 0 : 1,
                    'stdout' => $stdout,
                    'stderr' => '',
                ];
            }

            if (str_ends_with($normalized, '/ws_server/server.php')) {
                $action = strtolower(trim((string) ($arguments[0] ?? '')));
                if ($action === 'status') {
                    // На shared hosting отдельный WebSocket-процесс не управляется
                    // web-updater. Messenger продолжает работать через Long Poll.
                    return [
                        'code' => 1,
                        'stdout' => "WebSocket не управляется web-режимом updater; используется Long Poll\n",
                        'stderr' => '',
                    ];
                }

                if ($action === 'restart') {
                    return $this->failed(
                        'WebSocket нельзя перезапустить без process API; переключение должно использовать Long Poll'
                    );
                }
            }

            return $this->failed('Команда не разрешена во внутрипроцессном режиме обновлятора');
        } catch (Throwable $e) {
            return $this->failed($e->getMessage());
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function baselineVersionCode(array $arguments): ?int
    {
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--baseline-version-code=')) {
                continue;
            }

            $value = substr($argument, strlen('--baseline-version-code='));
            if (preg_match('/^[1-9][0-9]{0,8}$/D', $value) !== 1) {
                throw new RuntimeException('Некорректный код baseline-версии миграций');
            }

            return (int) $value;
        }

        return null;
    }

    /** @param array{code:int,stdout:string,stderr:string} $result */
    public function failureDetails(array $result): string
    {
        $details = trim($result['stderr']) !== ''
            ? trim($result['stderr'])
            : trim($result['stdout']);

        return $details !== '' ? $details : 'код завершения ' . $result['code'];
    }

    /** @return array{code:int,stdout:string,stderr:string} */
    private function failed(string $message): array
    {
        return [
            'code' => 1,
            'stdout' => '',
            'stderr' => trim($message),
        ];
    }
}
