<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Small process boundary for updater CLI probes and migration commands.
 *
 * UpdateApplyCommand owns orchestration/state transitions; this class owns
 * proc_open lifecycle, bounded output collection and timeout termination.
 */
final class UpdateProcessRunner
{
    /**
     * @param list<string> $command
     * @return array{code:int,stdout:string,stderr:string}
     */
    public function run(array $command, string $cwd, int $timeoutSeconds): array
    {
        if ($command === []) {
            throw new RuntimeException('Updater command cannot be empty');
        }
        if ($timeoutSeconds < 1) {
            throw new RuntimeException('Updater command timeout must be positive');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start updater command');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;
        $exit = -1;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                $exit = (int) ($status['exitcode'] ?? -1);
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                usleep(200_000);

                $status = proc_get_status($process);
                if ($status['running'] ?? false) {
                    proc_terminate($process, 9);
                }

                $exit = 124;
                break;
            }

            usleep(50_000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $closeCode = proc_close($process);
        if (!$timedOut && $exit < 0 && is_int($closeCode)) {
            $exit = $closeCode;
        }

        return ['code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param array{code:int,stdout:string,stderr:string} $result */
    public function failureDetails(array $result): string
    {
        $details = trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']);
        return $details !== '' ? $details : 'exit code ' . $result['code'];
    }
}
