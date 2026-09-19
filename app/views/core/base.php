<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$pageTitle = isset($title) ? (string) $title : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$basePath = isset($base_path) ? (string) $base_path : '';
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$licenseState = isset($licenseRuntime) && is_array($licenseRuntime) ? $licenseRuntime : [];
$licenseReadOnly = !empty($licenseState['enforced']) && empty($licenseState['writable']);
$canManageLicense = !empty($licenseState['can_manage']);
$paginationData = isset($pagination) && is_array($pagination) ? $pagination : null;
$socketTicket = isset($socket_ticket) ? (string) $socket_ticket : '';
$socketUrl = isset($socket_url) ? (string) $socket_url : '';
$moduleStyles = isset($module_styles) && is_array($module_styles)
    ? array_values(array_filter($module_styles, static fn ($value): bool => is_string($value) && $value !== ''))
    : [];
$moduleScripts = isset($module_scripts) && is_array($module_scripts)
    ? array_values(array_filter($module_scripts, static fn ($value): bool => is_string($value) && $value !== ''))
    : [];
$cspNonce = \Core\SecurityHeaders::nonce();

$styleFiles = [
    'core/common.css',
    'core/accessibility.css',
    'core/license-readonly.css',
    '^shared/sidebar/style.css',
    '^shared/header/style.css',
    '^shared/footer/style.css',
];
$viewRoot = dirname(__DIR__);
$runtimeConfig = json_encode([
    'socketTicket' => $socketTicket,
    'socketUrl' => $socketUrl,
    'basePath' => $basePath,
    'licenseReadOnly' => $licenseReadOnly,
    'licenseCode' => isset($licenseState['code']) ? (string) $licenseState['code'] : '',
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$partialData = [
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'user' => $currentUser,
    'workspaceAccess' => $access,
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Workspace Organizer — заметки, задачи, файлы и коммуникация в одном рабочем пространстве">
    <meta name="theme-color" content="#f4f6fb">
    <meta name="color-scheme" content="light dark">
    <script nonce="<?= $view->e($cspNonce) ?>">
    (() => {
        try {
            const preference = localStorage.getItem('workspace.theme') || 'light';
            const resolved = preference === 'system'
                ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : (preference === 'dark' ? 'dark' : 'light');
            document.documentElement.dataset.themePreference = preference;
            document.documentElement.dataset.theme = resolved;
        } catch (error) {
            document.documentElement.dataset.themePreference = 'light';
            document.documentElement.dataset.theme = 'light';
        }
    })();
    </script>
    <title><?= $view->e(trim($siteName . ' ' . $workspaceVersion)) ?> | <?= $view->e($pageTitle) ?></title>

    <style nonce="<?= $view->e($cspNonce) ?>">
<?php foreach ($styleFiles as $styleFile): ?>
<?php
    $stylePath = $viewRoot . '/' . $styleFile;
    if (is_file($stylePath) && is_readable($stylePath)) {
        $css = file_get_contents($stylePath);
        if (is_string($css)) {
            echo $css . "\n";
        }
    }
?>
<?php endforeach; ?>
    </style>
    <link rel="stylesheet" href="<?= $view->e($baseUrl) ?>/assets/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="<?= $view->e($baseUrl) ?>/assets/css/findability.css">
    <link rel="stylesheet" href="<?= $view->e($baseUrl) ?>/assets/css/feedback.css">
    <link rel="stylesheet" href="<?= $view->e($baseUrl) ?>/assets/css/messenger-connection-ux.css">
<?php foreach ($moduleStyles as $moduleStyle): ?>
    <link rel="stylesheet" href="<?= $view->e($moduleStyle) ?>">
<?php endforeach; ?>
    <style nonce="<?= $view->e($cspNonce) ?>">
<?php
$controlsPath = $viewRoot . '/core/controls.css';
if (is_file($controlsPath) && is_readable($controlsPath)) {
    $controlsCss = file_get_contents($controlsPath);
    if (is_string($controlsCss)) {
        echo $controlsCss . "\n";
    }
}
?>
    </style>
    <link rel="stylesheet" href="<?= $view->e($baseUrl) ?>/assets/css/workspace-ui-1.0.css?v=<?= rawurlencode($workspaceVersion) ?>">
    <link rel="icon" href="<?= $view->e($baseUrl) ?>/favicon.ico" type="image/x-icon">
    <template id="csrf-token-template"><?= $view->csrfInput() ?></template>
    <script nonce="<?= $view->e($cspNonce) ?>">window.wspaceRuntime = <?= $runtimeConfig ?>; window.wspace = window.wspace || {};</script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/common.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/theme-mode.js?v=<?= rawurlencode($workspaceVersion) ?>" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/findability.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/feedback.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/usability-actions.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/release-polish.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/messenger-connection-ux.js" defer></script>
<?php foreach ($moduleScripts as $moduleScript): ?>
    <script src="<?= $view->e($moduleScript) ?>" defer></script>
<?php endforeach; ?>
</head>
<body<?php if ($paginationData !== null):
    $attributes = [
        'data-list-q' => $paginationData['q'] ?? '',
        'data-list-page' => $paginationData['page'] ?? 1,
        'data-list-limit' => $paginationData['limit'] ?? 20,
        'data-list-total' => $paginationData['total'] ?? 0,
        'data-list-total-pages' => $paginationData['total_pages'] ?? 1,
        'data-list-sort' => $paginationData['sort'] ?? '',
        'data-list-direction' => $paginationData['direction'] ?? 'desc',
        'data-list-filter' => $paginationData['filter'] ?? '',
    ];
    foreach ($attributes as $attribute => $value) {
        echo ' ' . $attribute . '="' . $view->e($value) . '"';
    }
endif; ?>>
    <a class="skip-link" href="#main-content">Перейти к содержимому</a>
    <div class="wrapper">
        <?= $view->partial('^shared/sidebar/index', $partialData) ?>
        <div class="wrapper__content">
            <?= $view->partial('^shared/header/index', $partialData) ?>
            <?php if ($licenseReadOnly): ?>
                <aside class="license-readonly-banner" role="status" aria-live="polite">
                    <div class="license-readonly-banner__inner">
                        <div class="license-readonly-banner__copy">
                            <span class="license-readonly-banner__icon" aria-hidden="true"><i class="fa fa-lock"></i></span>
                            <div class="license-readonly-banner__text">
                                <strong class="license-readonly-banner__title">Режим только для чтения</strong>
                                <p class="license-readonly-banner__message">Просмотр существующих данных доступен, но изменения временно заблокированы до подтверждения лицензии установки.</p>
                                <?php if (!$canManageLicense): ?>
                                    <p class="license-readonly-banner__hint">Обратитесь к администратору Workspace.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($canManageLicense): ?>
                            <a class="license-readonly-banner__action" href="<?= $view->e($view->route('system_license')) ?>">Проверить лицензию</a>
                        <?php endif; ?>
                    </div>
                </aside>
            <?php endif; ?>
            <main id="main-content" class="content-wrapper" tabindex="-1">
                <?= $content ?>
            </main>
            <?= $view->partial('^shared/footer/index', $partialData) ?>
        </div>
    </div>
</body>
</html>