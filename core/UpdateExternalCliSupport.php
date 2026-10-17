<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/UpdatePath.php';

/**
 * Минимальная CLI-обвязка автономных сценариев обновления.
 *
 * Класс не зависит от live-приложения и от CliRuntime: внешний обновлятор должен
 * оставаться работоспособным, даже когда live-tree находится между версиями.
 */
final class UpdateExternalCliSupport
{
    private const MINIMUM_PHP_VERSION = '8.3.0';

    public function __construct(
        private readonly string $runnerRoot,
        private readonly bool $json
    ) {
    }

    public static function assertCli(string $message = 'Команда доступна только из CLI.'): void
    {
        if (PHP_SAPI !== 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
            exit(2);
        }

        if (version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '>=')) {
            return;
        }

        fwrite(
            STDERR,
            sprintf(
                "Внешнему обновлятору Workspace Organizer 1.2 требуется PHP %s или новее; запущен PHP %s.\n",
                self::MINIMUM_PHP_VERSION,
                PHP_VERSION
            )
        );
        exit(2);
    }

    public function jsonMode(): bool
    {
        return $this->json;
    }

    public function applicationRoot(
        mixed $value,
        string $layoutCode,
        string $runnerErrorCode,
        string $runnerLabel
    ): string {
        $input = trim((string) $value);
        if ($input === '' || is_link($input)) {
            $this->fail('Нужен существующий безопасный --app-root', 'invalid_app_root', 2);
        }

        $appRoot = realpath($input);
        if (!is_string($appRoot) || !is_dir($appRoot)) {
            $this->fail('Не удалось определить каталог live-приложения', 'invalid_app_root', 2);
        }

        $runnerRoot = realpath($this->runnerRoot);
        if (!is_string($runnerRoot) || !is_dir($runnerRoot) || is_link($this->runnerRoot)) {
            $this->fail($runnerLabel . ' недоступен', $runnerErrorCode, 2);
        }

        if (UpdatePath::inside($appRoot, $runnerRoot) || UpdatePath::inside($runnerRoot, $appRoot)) {
            $this->fail(
                $runnerLabel . ' и live-приложение должны находиться в разных деревьях каталогов',
                $layoutCode,
                2
            );
        }

        return rtrim($appRoot, '/\\');
    }

    public function localFile(mixed $value, string $label, int $maxBytes = 0): string
    {
        $input = trim((string) $value);
        if ($input === '' || str_contains($input, '://')) {
            $this->fail($label . ': нужен явный путь к локальному файлу', 'invalid_input', 2);
        }
        if (!is_file($input) || is_link($input) || !is_readable($input)) {
            $this->fail(
                $label . ': нужен читаемый обычный локальный файл, а не символическая ссылка',
                'invalid_input',
                2
            );
        }

        if ($maxBytes > 0) {
            $size = filesize($input);
            if (!is_int($size) || $size <= 0 || $size > $maxBytes) {
                $this->fail($label . ': недопустимый размер файла', 'invalid_input', 2);
            }
        }

        $real = realpath($input);
        if (!is_string($real)) {
            $this->fail($label . ': не удалось определить реальный путь', 'invalid_input', 2);
        }

        return $real;
    }

    public function configuredRoot(mixed $explicit, string $envName, string $privateSuffix): string
    {
        $explicitPath = trim((string) $explicit);
        if ($explicitPath !== '') {
            return rtrim($explicitPath, '/\\');
        }

        $configured = getenv($envName);
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/\\');
        }

        $private = getenv('PRIVATE_STORAGE_PATH');
        if (is_string($private) && trim($private) !== '') {
            return rtrim(trim($private), '/\\') . DIRECTORY_SEPARATOR . $privateSuffix;
        }

        $this->fail(
            $envName . ' не настроен, а PRIVATE_STORAGE_PATH недоступен',
            'path_not_configured',
            2
        );
    }

    public function transactionId(mixed $value): string
    {
        $transactionId = trim((string) $value);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
            $this->fail('Нужен корректный идентификатор --transaction', 'invalid_transaction', 2);
        }

        return $transactionId;
    }

    /** @param array<string,mixed> $payload */
    public function writeJson(array $payload): void
    {
        echo json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    }

    /** @param array<string,mixed> $details */
    public function fail(
        string $message,
        string $code = 'external_update_failed',
        int $exitCode = 1,
        array $details = []
    ): never {
        if ($this->json) {
            $this->writeJson(['status' => 'fail', 'code' => $code, 'message' => $message] + $details);
        } else {
            fwrite(STDERR, '[ОШИБКА] ' . $message . PHP_EOL);
        }

        exit($exitCode);
    }
}
