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
    // Технический предел-флаг: при его пересечении немедленно прерываем
    // дочернюю команду, не дожидаясь общего тайм-аута.
    private const int TEMP_OUTPUT_LIMIT_BYTES = 8388608;
    private const int TEMP_TOTAL_LIMIT_BYTES = 16777216;
    private const int TEMP_DISK_RESERVE_BYTES = 134217728;
    private const int TEMP_SAMPLE_INTERVAL_MICROSECONDS = 10000;
    private const int TEMP_LIMIT_EXIT_CODE = 125;
    private const int STALE_OUTPUT_SECONDS = 86400;

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

        // На Windows каналы proc_open нередко остаются блокирующими даже
        // после stream_set_blocking(false). Чтение по очереди может навсегда
        // заблокировать stdout и stderr друг другом.
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->runWithTemporaryFiles($command, $cwd, $timeoutSeconds);
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
     * Windows: вывод дочерней команды направляется в защищённые временные
     * файлы, поскольку неблокирующий ввод-вывод из proc_open там ненадёжен.
     * Файлы удаляются при любом штатном исходе. При превышении бюджета
     * дочерняя команда завершается с кодом 125. Случайный неконтролируемый
     * всплеск между проверками может ненадолго превысить установленную границу;
     * механизм не является файловой квотой ОС.
     *
     * @param list<string> $command
     * @return array{code:int,stdout:string,stderr:string}
     */
    private function runWithTemporaryFiles(array $command, string $cwd, int $timeoutSeconds): array
    {
        $temporaryDirectory = sys_get_temp_dir();
        self::removeStaleTemporaryOutput($temporaryDirectory);
        self::assertTemporaryDiskReserve($temporaryDirectory);
        $stdoutPath = self::createTemporaryOutputFile($temporaryDirectory, 'notes-up-out-');
        if (!is_string($stdoutPath)) {
            throw new RuntimeException('Не удалось создать временный файл stdout обновлятора');
        }

        $stderrPath = self::createTemporaryOutputFile($temporaryDirectory, 'notes-up-err-');
        if (!is_string($stderrPath)) {
            @unlink($stdoutPath);
            throw new RuntimeException('Не удалось создать временный файл stderr обновлятора');
        }

        $process = null;
        try {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $stdoutPath, 'w'],
                2 => ['file', $stderrPath, 'w'],
            ];
            $process = @proc_open($command, $descriptors, $pipes, $cwd, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                throw new RuntimeException('Не удалось запустить внутреннюю команду обновлятора');
            }

            fclose($pipes[0]);

            $deadline = microtime(true) + $timeoutSeconds;
            $exit = -1;
            $timedOut = false;
            $limitReason = null;
            while (true) {
                $limitReason = self::temporaryOutputLimitReason(
                    $stdoutPath,
                    $stderrPath,
                    $temporaryDirectory
                );
                $status = proc_get_status($process);
                if ($limitReason !== null) {
                    if ($status['running'] ?? false) {
                        self::terminateProcess($process, true);
                    }
                    $exit = self::TEMP_LIMIT_EXIT_CODE;
                    break;
                }
                if (!($status['running'] ?? false)) {
                    $exit = (int) ($status['exitcode'] ?? -1);
                    break;
                }

                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    self::terminateProcess($process);
                    $exit = 124;
                    break;
                }

                usleep(self::TEMP_SAMPLE_INTERVAL_MICROSECONDS);
            }

            $closeCode = proc_close($process);
            $process = null;
            if (!$timedOut && $limitReason === null && $exit < 0 && is_int($closeCode)) {
                $exit = $closeCode;
            }

            // Проверяем размер и после завершения: очень быстрый ребёнок
            // может успеть превысить бюджет между двумя опросами состояния.
            $limitReason ??= self::temporaryOutputLimitReason(
                $stdoutPath,
                $stderrPath,
                $temporaryDirectory
            );
            $stdout = self::readTemporaryOutput($stdoutPath);
            $stderr = self::readTemporaryOutput($stderrPath);
            if ($limitReason !== null) {
                $exit = self::TEMP_LIMIT_EXIT_CODE;
                $stderr = substr($limitReason . "\n" . $stderr, 0, self::MAX_CAPTURE_BYTES);
            }

            return ['code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            @unlink($stdoutPath);
            @unlink($stderrPath);
        }
    }

    /**
     * Windows может обрезать prefix в tempnam до трёх символов.
     * Создаём файл с полным предсказуемым префиксом и случайным суффиксом.
     * Эксклюзивный режим x не позволяет открыть существующий файл.
     */
    private static function createTemporaryOutputFile(string $directory, string $prefix): string
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR
                . $prefix . bin2hex(random_bytes(10));
            $handle = @fopen($path, 'x');
            if (is_resource($handle)) {
                fclose($handle);
                return $path;
            }
        }

        throw new RuntimeException('Не удалось создать защищённый временный файл обновлятора');
    }

    /** @param resource $process */
    private static function terminateProcess($process, bool $urgent = false): void
    {
        // При исчерпании бюджета нельзя ещё 200 мс записывать вывод на диск.
        if ($urgent) {
            proc_terminate($process, 9);
            usleep(10_000);
            if (!(proc_get_status($process)['running'] ?? false)) {
                return;
            }
        }

        proc_terminate($process);
        usleep($urgent ? 20_000 : 200_000);
        if (proc_get_status($process)['running'] ?? false) {
            proc_terminate($process, 9);
        }
    }

    private static function assertTemporaryDiskReserve(string $directory): void
    {
        $free = @disk_free_space($directory);
        if (!is_int($free) && !is_float($free)) {
            throw new RuntimeException('Не удалось проверить свободное место временного каталога обновлятора');
        }
        if ($free < self::TEMP_DISK_RESERVE_BYTES + self::TEMP_TOTAL_LIMIT_BYTES) {
            throw new RuntimeException(
                'Недостаточно свободного места для ограниченного вывода обновлятора'
            );
        }
    }

    private static function temporaryOutputLimitReason(
        string $stdoutPath,
        string $stderrPath,
        string $temporaryDirectory
    ): ?string {
        clearstatcache(true, $stdoutPath);
        clearstatcache(true, $stderrPath);
        $stdoutSize = @filesize($stdoutPath);
        $stderrSize = @filesize($stderrPath);
        if (!is_int($stdoutSize) || !is_int($stderrSize)) {
            return 'Не удалось определить размер временного вывода обновлятора';
        }

        if (
            $stdoutSize > self::TEMP_OUTPUT_LIMIT_BYTES
            || $stderrSize > self::TEMP_OUTPUT_LIMIT_BYTES
            || $stdoutSize + $stderrSize > self::TEMP_TOTAL_LIMIT_BYTES
        ) {
            return 'Превышен безопасный бюджет временного вывода обновлятора';
        }

        $free = @disk_free_space($temporaryDirectory);
        if (!is_int($free) && !is_float($free)) {
            return 'Не удалось проверить остаток места на диске обновлятора';
        }
        if ($free < self::TEMP_DISK_RESERVE_BYTES) {
            return 'Свободное место временного каталога обновлятора ниже безопасного остатка';
        }

        return null;
    }

    /**
     * Удаляем остатки только своих файлов старше суток.
     * Файлы недавно запущенных параллельных команд не затрагиваются.
     */
    private static function removeStaleTemporaryOutput(string $directory): void
    {
        foreach (['notes-up-out-', 'notes-up-err-'] as $prefix) {
            foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $prefix . '*') ?: [] as $path) {
                $name = basename($path);
                if (
                    preg_match('/^notes-up-(?:out|err)-[a-z0-9._-]{6,24}$/i', $name) !== 1
                    || !is_file($path)
                    || is_link($path)
                ) {
                    continue;
                }
                clearstatcache(true, $path);
                $mtime = @filemtime($path);
                if (is_int($mtime) && $mtime < time() - self::STALE_OUTPUT_SECONDS) {
                    @unlink($path);
                }
            }
        }
    }

    private static function readTemporaryOutput(string $path): string
    {
        $content = @file_get_contents($path, false, null, 0, self::MAX_CAPTURE_BYTES);
        if (!is_string($content)) {
            throw new RuntimeException('Не удалось прочитать вывод дочерней команды обновлятора');
        }

        return $content;
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
