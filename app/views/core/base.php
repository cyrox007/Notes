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
$paginationData = isset($pagination) && is_array($pagination) ? $pagination : null;
$socketTicket = isset($socket_ticket) ? (string) $socket_ticket : '';
$socketUrl = isset($socket_url) ? (string) $socket_url : '';

$styleFiles = [
    'core/common.css',
    'core/accessibility.css',
    '^elements/UI/FormInput/style.css',
    '^shared/sidebar/style.css',
    '^shared/header/style.css',
    'profile_page/style.css',
    'notes_page/style.css',
    'tasks_page/style.css',
    'tasks_page/hardening.css',
    'file_manager/style.css',
    'messager_page/style.css',
    'admin-page/style.css',
    'admin-page/license.css',
    '^shared/footer/style.css',
    'core/theme-refresh.css',
    'core/product-ux-013.css',
    'notes_page/editor-013.css',
    'tasks_page/kanban.css',
    'tasks_page/kanban-handle.css',
    'profile_page/hub.css',
    'profile_page/metrics.css',
];
$viewRoot = dirname(__DIR__);
$runtimeConfig = json_encode([
    'socketTicket' => $socketTicket,
    'socketUrl' => $socketUrl,
    'basePath' => $basePath,
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
    <meta name="theme-color" content="#0f172a">
    <meta name="color-scheme" content="light">
    <title><?= $view->e(trim($siteName . ' ' . $workspaceVersion)) ?> | <?= $view->e($pageTitle) ?></title>

    <style>
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
    <link rel="stylesheet" href="<?= $view->e($baseUrl) ?>/assets/css/file-manager-polish.css">
    <link rel="stylesheet" href="<?= $view->e($baseUrl) ?>/assets/css/messenger-connection-ux.css">
    <link rel="stylesheet" href="<?= $view->e($baseUrl) ?>/assets/css/live-qa-fixes.css?v=<?= rawurlencode($workspaceVersion) ?>">
    <link rel="stylesheet" href="<?= $view->e($baseUrl) ?>/assets/css/live-qa-final.css?v=<?= rawurlencode($workspaceVersion) ?>">
    <link rel="icon" href="<?= $view->e($baseUrl) ?>/favicon.ico" type="image/x-icon">
    <template id="csrf-token-template"><?= $view->csrfInput() ?></template>
    <script>window.wspaceRuntime = <?= $runtimeConfig ?>; window.wspace = window.wspace || {};</script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/common.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/findability.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/feedback.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/notes-draft.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/usability-actions.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/release-polish.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/file-manager-polish.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/file-manager-drop-upload.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/tasks-kanban.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/task-boards-nav.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/messenger-connection-ux.js" defer></script>
    <script src="<?= $view->e($baseUrl) ?>/assets/js/admin-settings-nav.js" defer></script>
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
            <main id="main-content" class="content-wrapper" tabindex="-1">
                <?= $content ?>
            </main>
            <?= $view->partial('^shared/footer/index', $partialData) ?>
        </div>
    </div>
</body>
</html>