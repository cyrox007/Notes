<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$targetProfile = isset($profile) && is_array($profile) ? $profile : null;
$publicContent = isset($public_content) && is_array($public_content) ? $public_content : [];
$publicTotal = max(0, (int) ($public_total ?? 0));
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$avatarUrl = isset($avatar_url) && is_string($avatar_url) ? $avatar_url : '';
$statusLabels = [
    'completed' => 'Готово',
    'in_progress' => 'В работе',
    'cancelled' => 'Отменено',
    'pending' => 'Новая',
];
$title = 'Профиль не найден';
if ($targetProfile !== null) {
    $title = trim((string) ($targetProfile['firstname'] ?? '') . ' ' . (string) ($targetProfile['lastname'] ?? ''));
    if ($title === '') {
        $title = (string) ($targetProfile['username'] ?? 'Профиль');
    }
}

ob_start();
?>
<section class="profile-public">
    <?php if ($targetProfile === null): ?>
        <div class="ux-empty">
            <div>
                <i class="fa fa-user-times" aria-hidden="true"></i>
                <h1>Профиль не найден</h1>
                <p>Пользователь не существует или больше недоступен.</p>
            </div>
        </div>
    <?php else: ?>
        <?php
            $profileName = trim((string) ($targetProfile['firstname'] ?? '') . ' ' . (string) ($targetProfile['lastname'] ?? ''));
            $publicAvatar = $avatarUrl !== ''
                ? $baseUrl . '/' . ltrim($avatarUrl, '/')
                : $baseUrl . '/assets/img/default_avatar.png';
        ?>
        <header class="profile-public__hero ux-section">
            <div class="profile-public__identity">
                <div class="profile-public__avatar-wrap">
                    <img class="profile-public__avatar" src="<?= $view->e($publicAvatar) ?>" alt="<?= $view->e($profileName) ?>" width="112" height="112">
                </div>
                <div class="profile-public__name">
                    <span class="ux-kicker">Профиль пользователя</span>
                    <h1><?= $view->e($profileName) ?></h1>
                    <p>@<?= $view->e($targetProfile['username'] ?? '') ?></p>
                </div>
            </div>
        </header>

        <section class="profile-public__content ux-section" aria-labelledby="public-content-title">
            <div class="ux-section__heading">
                <div>
                    <span class="ux-kicker">Публично</span>
                    <h2 id="public-content-title">Материалы пользователя</h2>
                    <p>Здесь показываются только объекты, которые владелец явно опубликовал в своём профиле.</p>
                </div>
                <?php if ($publicTotal > 0): ?><span class="profile-public__counter"><?= $view->e($publicTotal) ?> опубликовано</span><?php endif; ?>
            </div>

            <?php if ($publicTotal > 0): ?>
                <div class="profile-public__collections">
                    <?php $notes = isset($publicContent['notes']) && is_array($publicContent['notes']) ? $publicContent['notes'] : []; ?>
                    <?php if ($notes !== []): ?>
                        <section class="profile-public__collection" aria-labelledby="public-notes-title">
                            <div class="profile-public__collection-title"><i class="fa fa-sticky-note-o" aria-hidden="true"></i><h3 id="public-notes-title">Заметки</h3></div>
                            <div class="profile-public__items">
                                <?php foreach ($notes as $item): ?>
                                    <?php if (!is_array($item)) { continue; } ?>
                                    <article class="profile-public__item"><strong><?= $view->e($item['title'] ?? '') ?></strong><span>Публичная заметка</span></article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php $tasks = isset($publicContent['tasks']) && is_array($publicContent['tasks']) ? $publicContent['tasks'] : []; ?>
                    <?php if ($tasks !== []): ?>
                        <section class="profile-public__collection" aria-labelledby="public-tasks-title">
                            <div class="profile-public__collection-title"><i class="fa fa-check-square-o" aria-hidden="true"></i><h3 id="public-tasks-title">Задачи</h3></div>
                            <div class="profile-public__items">
                                <?php foreach ($tasks as $item): ?>
                                    <?php if (!is_array($item)) { continue; } ?>
                                    <?php $status = (string) ($item['status'] ?? 'pending'); ?>
                                    <article class="profile-public__item">
                                        <strong><?= $view->e($item['title'] ?? '') ?></strong>
                                        <span><?= $view->e($statusLabels[$status] ?? 'Новая') ?> · <?= $view->e($item['priority'] ?? '') ?></span>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                    <?php $files = isset($publicContent['files']) && is_array($publicContent['files']) ? $publicContent['files'] : []; ?>
                    <?php if ($files !== []): ?>
                        <section class="profile-public__collection" aria-labelledby="public-files-title">
                            <div class="profile-public__collection-title"><i class="fa fa-folder-open-o" aria-hidden="true"></i><h3 id="public-files-title">Файлы</h3></div>
                            <div class="profile-public__items">
                                <?php foreach ($files as $item): ?>
                                    <?php if (!is_array($item)) { continue; } ?>
                                    <?php $fileName = (string) ($item['name'] ?? '') . (!empty($item['extension']) ? '.' . (string) $item['extension'] : ''); ?>
                                    <article class="profile-public__item"><strong><?= $view->e($fileName) ?></strong><span>Публичный файл · <?= $view->e($item['size'] ?? 0) ?> Б</span></article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="ux-empty profile-public__empty">
                    <div>
                        <i class="fa fa-lock" aria-hidden="true"></i>
                        <strong>Пользователь пока ничего не публиковал</strong>
                        <p>Share-ссылки и приватные заметки, задачи и файлы здесь автоматически не раскрываются.</p>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => $title,
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
], $content);
