<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function notificationCenterUiAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$layout = file_get_contents($root . '/app/views/core/base.php');
$script = file_get_contents($root . '/assets/js/notification-center.js');
$style = file_get_contents($root . '/assets/css/notification-center.css');
$updates = file_get_contents($root . '/assets/js/update-notifications.js');

notificationCenterUiAssert(is_string($layout), 'Не удалось прочитать общий layout');
notificationCenterUiAssert(is_string($script), 'Не удалось прочитать runtime центра уведомлений');
notificationCenterUiAssert(is_string($style), 'Не удалось прочитать стили центра уведомлений');
notificationCenterUiAssert(is_string($updates), 'Не удалось прочитать интеграцию уведомлений обновлятора');

foreach ([
    '/assets/css/notification-center.css',
    '/assets/js/notification-center.js',
    "\$view->session('auth')",
] as $fragment) {
    notificationCenterUiAssert(
        str_contains($layout, $fragment),
        'Общая оболочка обязана подключать центр только авторизованному пользователю: ' . $fragment
    );
}

foreach ([
    "const API_ROOT = '/api/notifications'",
    "'/unread-count'",
    "'/preferences'",
    "'/read-all'",
    "'/read'",
    "'/unread'",
    'dataset.notificationCenter',
    "icon('fa-inbox')",
    "status: state.status",
    "query.set('category', state.category)",
    'DEFAULT_CATEGORIES',
    "'calendar.reminders'",
    'renderPreferences',
    'safeTarget',
    'url.origin === window.location.origin',
    'DELIVERY_LABELS',
    "failed: 'Ошибка внешней доставки'",
    "partial: 'Доставлено не по всем каналам'",
    'document.createElement',
    'textContent',
] as $fragment) {
    notificationCenterUiAssert(
        str_contains($script, $fragment),
        'Runtime центра уведомлений обязан сохранять контракт: ' . $fragment
    );
}

foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'eval('] as $forbidden) {
    notificationCenterUiAssert(
        !str_contains($script, $forbidden),
        'Runtime центра уведомлений не должен использовать небезопасную вставку: ' . $forbidden
    );
}

foreach ([
    '.workspace-notifications[data-update-notifications]{display:none!important}',
    '.workspace-notification-center__panel.is-open',
    '@media(max-width:620px)',
    '@media(prefers-reduced-motion:reduce)',
] as $fragment) {
    notificationCenterUiAssert(
        str_contains($style, $fragment),
        'Стили центра уведомлений обязаны сохранять контракт: ' . $fragment
    );
}
notificationCenterUiAssert(
    !str_contains($script, 'fa-bell') && !str_contains($script, 'fa-bell-o'),
    'Новый центр не должен возвращать старую иконку колокольчика'
);

foreach ([
    "'wspace:update-notification'",
    "'wspace:update-install-request'",
    "'wspace:update-install-state'",
    'host.hidden = true',
] as $fragment) {
    notificationCenterUiAssert(
        str_contains($updates, $fragment),
        'Уведомление обновлятора должно быть встроено в общий центр: ' . $fragment
    );
}

fwrite(STDOUT, "[OK] контракт UI центра уведомлений 1.1.0\n");
