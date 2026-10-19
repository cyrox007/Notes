<?php

declare(strict_types=1);

// Выполняется автономно без загрузки маршрутов и runtime модуля.
require_once dirname(__DIR__) . '/services/MessengerRetentionService.php';

return new \Modules\Messenger\MessengerRetentionService();
