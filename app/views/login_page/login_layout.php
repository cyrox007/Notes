<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$title = isset($title) ? (string) $title : '';
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$base = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$content = isset($content) ? (string) $content : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Workspace Organizer — защищённое рабочее пространство">
    <meta name="color-scheme" content="light">
    <title><?= $view->e($siteName) ?> — <?= $view->e($title) ?></title>
    <link rel="stylesheet" href="<?= $view->e($base . '/assets/css/auth_page/style.css') ?>">
    <link rel="stylesheet" href="<?= $view->e($base . '/assets/font-awesome/css/font-awesome.min.css') ?>">
    <link rel="icon" href="<?= $view->e($base . '/favicon.ico') ?>" type="image/x-icon">
</head>
<body>
    <?php // $content is trusted HTML rendered by an internal native child template. ?>
    <?= $content ?>
    <script src="<?= $view->e($base . '/assets/js/reg-script.js') ?>" defer></script>
</body>
</html>
