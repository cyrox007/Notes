<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$title = isset($title) ? (string) $title : '';
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$base = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$content = isset($content) ? (string) $content : '';
$pageStyles = isset($page_styles) && is_array($page_styles)
    ? array_values(array_filter($page_styles, static fn ($value): bool => is_string($value) && $value !== ''))
    : [];
$pageScripts = isset($page_scripts) && is_array($page_scripts)
    ? array_values(array_filter($page_scripts, static fn ($value): bool => is_string($value) && $value !== ''))
    : [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Workspace Organizer — защищённое рабочее пространство">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <meta name="color-scheme" content="light">
    <title><?= $view->e($siteName) ?> — <?= $view->e($title) ?></title>
    <link rel="stylesheet" href="<?= $view->e($base . '/assets/css/auth_page/style.css') ?>">
    <link rel="stylesheet" href="<?= $view->e($base . '/assets/font-awesome/css/font-awesome.min.css') ?>">
    <link rel="stylesheet" href="<?= $view->e($base . '/assets/css/workspace.css') ?>">
<?php foreach ($pageStyles as $pageStyle): ?>
    <link rel="stylesheet" href="<?= $view->e($base . '/' . ltrim($pageStyle, '/')) ?>">
<?php endforeach; ?>
    <link rel="icon" href="<?= $view->e($base . '/assets/img/workspace-brand-mark.svg') ?>" type="image/svg+xml">
    <link rel="alternate icon" href="<?= $view->e($base . '/favicon.ico') ?>" type="image/x-icon">
</head>
<body class="workspace-auth">
    <a href="<?= $view->e($base . '/.well-known/workspace-crawl-trap') ?>" hidden aria-hidden="true" tabindex="-1" rel="nofollow">.</a>
    <?php // $content is trusted HTML rendered by an internal native child template. ?>
    <?= $content ?>
    <script src="<?= $view->e($base . '/assets/js/reg-script.js') ?>" defer></script>
<?php foreach ($pageScripts as $pageScript): ?>
    <script src="<?= $view->e($base . '/' . ltrim($pageScript, '/')) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
