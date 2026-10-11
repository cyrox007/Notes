<?php

declare(strict_types=1);

namespace Core;

use App\Services\MaintenanceModeService;
use RuntimeException;
use Throwable;

/**
 * Машинная ошибка дочерней команды updater с сохранённым JSON-ответом.
 */
final class UpdateRunSubprocessException extends RuntimeException
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        string $message,
        int $exitCode,
        public readonly array $payload = []
    ) {
        parent::__construct($message, $exitCode);
    }
}

/**
 * Служебные операции верхнеуровневого сценария обновления.
 *
 * Класс не меняет рабочие файлы сам: он только запускает уже существующие
 * проверенные команды, собирает их JSON-ответы и помогает восстановлению.
 */
final class UpdateRunSupport
{
    public function __construct(
        private readonly string $root,
        private readonly UpdateProcessRunner $runner
    ) {
    }

    /** @return array<string,mixed> */
    public function runJsonCommand(
        array $command,
        string $cwd,
        int $timeout,
        string $label
    ): array {
        $result = $this->runner->run($command, $cwd, $timeout);
        $payload = $this->decodePayload((string) $result['stdout']);

        if ($result['code'] !== 0) {
            $message = is_string($payload['message'] ?? null)
                ? $payload['message']
                : $this->runner->failureDetails($result);
            $code = is_string($payload['code'] ?? null)
                ? $payload['code']
                : 'subprocess_failed';

            throw new UpdateRunSubprocessException(
                $label . ': ' . $message,
                $result['code'] > 0 ? $result['code'] : 1,
                $payload
            );
        }

        if ($payload === []) {
            throw new RuntimeException($label . ': команда вернула некорректный JSON');
        }

        return $payload;
    }

    /** @return list<string> */
    public function baseCommand(string $script): array
    {
        return [PHP_BINARY, $this->root . '/bin/' . $script];
    }

    /** @return list<string> */
    public function appendOption(array $command, array $options, string $name): array
    {
        $value = trim((string) ($options[$name] ?? ''));
        if ($value !== '') {
            $command[] = '--' . $name . '=' . $value;
        }

        return $command;
    }

    /** @return list<string> */
    public function externalApplyCommand(
        array $runtime,
        string $transactionId,
        array $options,
        bool $recover,
        ?string $candidateDir = null
    ): array {
        $entrypoint = trim((string) ($runtime['entrypoint'] ?? ''));
        if ($entrypoint === '' || !is_file($entrypoint) || is_link($entrypoint)) {
            throw new RuntimeException('Проверенная внешняя точка входа updater недоступна');
        }

        $command = [
            PHP_BINARY,
            $entrypoint,
            '--app-root=' . $this->root,
            '--transaction=' . $transactionId,
            $recover ? '--recover' : '--apply',
            '--json',
        ];

        if (!$recover) {
            $candidateDir = trim((string) $candidateDir);
            if ($candidateDir === '') {
                throw new RuntimeException('Для внешнего применения нужен проверенный кандидат');
            }
            $command[] = '--candidate-dir=' . $candidateDir;
        }

        foreach (['state-root', 'backup-root'] as $option) {
            $command = $this->appendOption($command, $options, $option);
        }

        return $command;
    }

    /** @return array<string,mixed>|null */
    public function recordedExternalRuntime(array $options, string $transactionId): ?array
    {
        $stateRoot = trim((string) ($options['state-root'] ?? ''));
        if ($stateRoot === '') {
            $maintenance = new MaintenanceModeService(null, $this->root);
            $configured = $maintenance->configuredStateRoot();
            $stateRoot = is_string($configured) ? trim($configured) : '';
        }
        if ($stateRoot === '') {
            return null;
        }

        $journal = new UpdateTransactionJournal($stateRoot, $this->root);
        $state = $journal->load($transactionId);
        $recorded = $state['external_runtime'] ?? null;
        if (!is_array($recorded)) {
            return null;
        }

        return (new UpdateExternalRuntime($this->root))->verifyRecorded(
            $recorded,
            (int) ($state['installed_version_code'] ?? 0)
        );
    }

    /**
     * Возобновляемое восстановление после ошибки применения.
     *
     * @return array<string,mixed>
     */
    public function automaticRecover(
        array $options,
        string $transactionId,
        ?array $externalRuntime = null,
        int $attempts = 3
    ): array {
        $errors = [];

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $runtime = $externalRuntime ?? $this->recordedExternalRuntime($options, $transactionId);
                $command = $runtime !== null
                    ? $this->externalApplyCommand($runtime, $transactionId, $options, true)
                    : array_merge(
                        $this->baseCommand('update_apply.php'),
                        ['--transaction=' . $transactionId, '--recover', '--json']
                    );

                if ($runtime === null) {
                    $command = $this->appendOption($command, $options, 'state-root');
                    $command = $this->appendOption($command, $options, 'backup-root');
                }

                $result = $this->runJsonCommand(
                    $command,
                    $runtime !== null ? (string) $runtime['runtime_root'] : $this->root,
                    1200,
                    'автоматическое восстановление'
                );
                $result['automatic_recovery_attempt'] = $attempt;
                return $result;
            } catch (Throwable $error) {
                $errors[] = 'попытка ' . $attempt . ': ' . $error->getMessage();
                if ($attempt < $attempts) {
                    sleep(2);
                }
            }
        }

        throw new RuntimeException(
            'Автоматическое восстановление не завершилось после '
            . $attempts
            . ' попыток: '
            . implode('; ', $errors)
        );
    }

    /** @return array<string,mixed> */
    private function decodePayload(string $stdout): array
    {
        try {
            $decoded = json_decode(trim($stdout), true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }
}
