<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
?>
<footer class="main-footer">
    <div class="main-footer__brand">
        <strong><?= $view->e($siteName) ?></strong>
        <span>Рабочее пространство для заметок, задач, файлов и общения.</span>
    </div>
    <div class="main-footer__meta">
        <span>Версия <?= $view->e($workspaceVersion) ?></span>
        <span aria-hidden="true">•</span>
        <span>&copy; <?= $view->e(date('Y')) ?></span>
    </div>
</footer>
