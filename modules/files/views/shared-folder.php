<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$root = isset($share_root) && is_array($share_root) ? $share_root : [];
$current = isset($current_folder) && is_array($current_folder) ? $current_folder : [];
$items = isset($files) && is_array($files) ? $files : [];
$crumbs = isset($breadcrumb) && is_array($breadcrumb) ? $breadcrumb : [];
$token = (string) ($share_token ?? '');
$title = (string) ($current['name'] ?? $root['name'] ?? 'Общая папка');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= $view->e($title) ?> — общий доступ</title>
    <link rel="stylesheet" href="<?= $view->e(($base_url ?? '') . '/assets/font-awesome/css/font-awesome.min.css') ?>">
    <link rel="stylesheet" href="<?= $view->e($view->moduleAsset('files', 'public-share.css')) ?>">
</head>
<body>
<main class="public-share">
    <header class="public-share__header">
        <div>
            <span>Workspace Organizer · общий доступ</span>
            <h1><?= $view->e($root['name'] ?? 'Папка') ?></h1>
            <?php if (!empty($root['expires_at'])): ?><p>Ссылка действует до <?= $view->e($root['expires_at']) ?></p><?php endif; ?>
        </div>
    </header>

    <nav class="public-share__breadcrumb" aria-label="Путь">
        <?php foreach ($crumbs as $index => $crumb): ?>
            <?php if (!is_array($crumb)) { continue; } ?>
            <?php if ($index > 0): ?><span>/</span><?php endif; ?>
            <?php if ($index === array_key_last($crumbs)): ?>
                <strong><?= $view->e($crumb['name'] ?? '') ?></strong>
            <?php else: ?>
                <a href="<?= $view->e($view->route('files_shared_folder', ['token' => $token, 'uid' => (string) ($crumb['uid'] ?? '')])) ?>"><?= $view->e($crumb['name'] ?? '') ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php if ($items === []): ?>
        <section class="public-share__empty"><i class="fa fa-folder-open-o" aria-hidden="true"></i><strong>Папка пуста</strong></section>
    <?php else: ?>
        <section class="public-share__grid">
            <?php foreach ($items as $item): ?>
                <?php if (!is_array($item)) { continue; } ?>
                <?php
                    $type = (string) ($item['type'] ?? 'file');
                    $name = (string) ($item['name'] ?? '');
                    $extension = (string) ($item['extension'] ?? '');
                    $displayName = $name . ($type !== 'folder' && $extension !== '' ? '.' . $extension : '');
                    $href = $type === 'folder'
                        ? $view->route('files_shared_folder', ['token' => $token, 'uid' => (string) ($item['uid'] ?? '')])
                        : $view->route('files_shared_file', ['token' => $token, 'uid' => (string) ($item['uid'] ?? '')]);
                ?>
                <a class="public-share__item" href="<?= $view->e($href) ?>"<?= $type !== 'folder' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>
                    <i class="fa <?= $type === 'folder' ? 'fa-folder' : 'fa-file-o' ?>" aria-hidden="true"></i>
                    <span><strong><?= $view->e($displayName) ?></strong><small><?= $type === 'folder' ? 'Папка' : $view->e((string) ($item['size'] ?? 0)) . ' Б' ?></small></span>
                </a>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
