<?php

declare(strict_types=1);

namespace Core;

/**
 * Модульный источник надёжных уведомлений.
 * Обработчик не выбирает внешний канал, а публикует события в NotificationService.
 */
interface ModuleNotificationOutbox
{
    public function moduleId(): string;

    /** Обработать не больше limit накопленных событий; вернуть число завершённых. */
    public function drain(int $limit): int;
}
