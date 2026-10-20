<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

require_once __DIR__ . '/UpdateCommandRunner.php';

/**
 * Исполнитель внутренних команд обновлятора в отдельном процессе.
 *
 * Оркестрация и переходы транзакции остаются в UpdateApplyCommand. Этот класс
 * отвечает только за proc_open, ограниченный сбор вывода и timeout процесса.
 */
final class UpdateProcessRunner implements UpdateCommandRunner
{
    private const int MAX_CAPTURE_BYTES = 1048576;
    private const int READ_CHUNK_BYTES = 65536;

    public static function available(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }

        $disabled = array_filter(
            array_map('trim', explode(',', (string) ini_get('disable_functions')))
        );

        return !in_array('proc_open', $disabled, true)
            && function_exists('proc_get_status')
            && function_exists('proc_close');
    }

    /**
     * @param list<string> $command
     * @return array{code:int,stdout:string,stderr:string}
     */
    public function run(array $command, string $cwd, int $timeoutSeconds): array
    {
        if (!self::available()) {
            throw new RuntimeException('Запуск дочерних процессов недоступен в текущем PHP');
        }
        if ($command === []) {
            throw new RuntimeException('Команда обновлятора не может быть пустой');
        }
        if ($timeoutSeconds < 1) {
            throw new RuntimeException('Timeout команды обновлятора должен быть положительным');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Не удалось запустить внутреннюю команду обновлятора');
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
            // Читаем небольшими порциями, а не весь накопленный буфер канала.
            // Даже если дочерняя команда печатает гигабайты, память PHP
            // ограничена размером порции и сохранёнными первыми 1 МиБ.
            self::readChunk($pipes[1], $stdout);
            self::readChunk($pipes[2], $stderr);

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

        // После завершения ребёнка в каналах ещё могут оставаться данные.
        // Дочитываем их до пустого чтения даже после достижения лимита.
        self::drain($pipes[1], $stdout);
        self::drain($pipes[2], $stderr);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $closeCode = proc_close($process);
        if (!$timedOut && $exit < 0 && is_int($closeCode)) {
            $exit = $closeCode;
        }

        return ['code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * Прочитать только ограниченную порцию, независимо от скорости вывода.
     *
     * @param resource $stream
     */
    private static function readChunk($stream, string &$output): string
    {
        $chunk = stream_get_contents($stream, self::READ_CHUNK_BYTES);
        if (!is_string($chunk) || $chunk === '') {
            return '';
        }

        self::capture($output, $chunk);
        return $chunk;
    }

    /** @param resource $stream */
    private static function drain($stream, string &$output): void
    {
        while (self::readChunk($stream, $output) !== '') {
            // Освобождаем pipe даже если лимит сохранения вывода достигнут.
        }
    }

    /**
     * Ограничиваем объём сохранённого вывода, продолжая читать канал процесса.
     * Это предотвращает переполнение памяти и блокировку дочернего процесса.
     */
    private static function capture(string &$output, string $chunk): void
    {
        $remaining = self::MAX_CAPTURE_BYTES - strlen($output);
        if ($remaining <= 0 || $chunk === '') {
            return;
        }

        $output .= substr($chunk, 0, $remaining);
    }

    /** @param array{code:int,stdout:string,stderr:string} $result */
    public function failureDetails(array $result): string
    {
        $details = trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']);
        return $details !== '' ? $details : 'код завершения ' . $result['code'];
    }
}
