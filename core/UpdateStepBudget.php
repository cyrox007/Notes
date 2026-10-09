<?php

declare(strict_types=1);

namespace Core;

/** Ограничивает работу одного HTTP-запроса между долговечными контрольными точками. */
final class UpdateStepBudget
{
    private float $deadline;
    private int $units = 0;

    public function __construct(private readonly int $maxUnits = 64, float $seconds = 8.0)
    {
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0) {
            $seconds = min($seconds, max(0.1, $limit / 4));
        }
        $this->deadline = microtime(true) + max(0.001, $seconds);
    }

    /** Вызывается только после атомарного сохранения результата завершённой операции. */
    public function checkpoint(string $phase): void
    {
        ++$this->units;
        if ($this->units >= $this->maxUnits || microtime(true) >= $this->deadline) {
            throw new UpdateStepPending($phase);
        }
    }
}

/** Штатная пауза: запрещено трактовать её как ошибку применения или отката. */
final class UpdateStepPending extends \RuntimeException
{
    public function __construct(public readonly string $phase)
    {
        parent::__construct('Шаг сохранён. Работа продолжится следующим запросом.');
    }

    /** @return array<string,mixed> */
    public function result(string $transactionId): array
    {
        return [
            'status' => 'in_progress', 'phase' => $this->phase,
            'transaction_id' => $transactionId,
            'message' => $this->getMessage(),
            'progress' => match ($this->phase) {
                'backup' => 30, 'candidate' => 55, 'switch' => 80, default => 90,
            },
        ];
    }
}
