<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use InvalidArgumentException;

/**
 * Ограниченный приём ответа внешнего HTTP-сервиса.
 *
 * cURL передаёт части ответа по мере поступления. После превышения общего
 * бюджета приём останавливается, поэтому ответ не накапливается в памяти PHP.
 */
final class LimitedHttpResponse
{
    private int $received = 0;
    private string $preview = '';
    private bool $exceeded = false;

    public function __construct(
        private int $captureBytes = 4096,
        private int $limitBytes = 65536
    ) {
        if ($captureBytes < 0 || $limitBytes < 1 || $captureBytes > $limitBytes) {
            throw new InvalidArgumentException('Неверный бюджет приёма HTTP-ответа');
        }
    }

    /**
     * Возвращает число принятых байтов, как требует CURLOPT_WRITEFUNCTION.
     * Ноль при превышении лимита прерывает передачу с ошибкой записи cURL.
     */
    public function accept(string $chunk): int
    {
        $size = strlen($chunk);
        if ($this->exceeded || $size > $this->limitBytes - $this->received) {
            $this->exceeded = true;
            return 0;
        }

        $this->received += $size;
        $remaining = $this->captureBytes - strlen($this->preview);
        if ($remaining > 0) {
            $this->preview .= substr($chunk, 0, $remaining);
        }

        return $size;
    }

    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    public function preview(): string
    {
        return $this->preview;
    }

    public function receivedBytes(): int
    {
        return $this->received;
    }
}
