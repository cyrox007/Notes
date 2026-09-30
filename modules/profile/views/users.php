<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$people = isset($people) && is_array($people) ? $people : [];
$queryValue = (string) ($query ?? '');
$currentPage = max(1, (int) ($page ?? 1));
$pageCount = max(1, (int) ($pages ?? 1));
$totalCount = max(0, (int) ($total ?? 0));
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';

ob_start();
?>
<section class="profile-directory">
    <header class="profile-directory__header">
        <div>
            <span class="ux-kicker">Пользователи</span>
            <h1>Люди в Workspace</h1>
            <p>Найдите пользователя и откройте его профиль. В профиле отображается только то, что владелец разрешил показывать другим пользователям.</p>
        </div>
        <a href="<?= $view->e($view->route('profile')) ?>" class="profile-directory__back"><i class="fa fa-user" aria-hidden="true"></i> Мой профиль</a>
    </header>

    <form class="profile-directory__search" method="get" action="<?= $view->e($view->route('profile-users')) ?>">
        <label class="visually-hidden" for="profile-user-search">Поиск пользователей</label>
        <i class="fa fa-search" aria-hidden="true"></i>
        <input id="profile-user-search" name="q" type="search" maxlength="100" value="<?= $view->e($queryValue) ?>" placeholder="Имя, фамилия или логин">
        <button type="submit">Найти</button>
    </form>

    <div class="profile-directory__summary"><?= $view->e($totalCount) ?> пользователей<?= $queryValue !== '' ? ' по запросу «' . $view->e($queryValue) . '»' : '' ?></div>

    <?php if ($people === []): ?>
        <div class="ux-empty">
            <div><i class="fa fa-users" aria-hidden="true"></i><strong>Никого не найдено</strong><p>Измените поисковый запрос.</p></div>
        </div>
    <?php else: ?>
        <div class="profile-directory__grid">
            <?php foreach ($people as $person): ?>
                <?php
                    if (!is_array($person)) { continue; }
                    $name = trim((string) ($person['firstname'] ?? '') . ' ' . (string) ($person['lastname'] ?? ''));
                    if ($name === '') { $name = (string) ($person['username'] ?? 'Пользователь'); }
                    $avatar = !empty($person['avatar'])
                        ? $view->route('profile-avatar', ['uid' => (string) ($person['uid'] ?? '')])
                        : $baseUrl . '/assets/img/default_avatar.png';
                ?>
                <a class="profile-directory__person" href="<?= $view->e($view->route('profile-public', ['uid' => (string) ($person['uid'] ?? '')])) ?>">
                    <img src="<?= $view->e($avatar) ?>" alt="" width="54" height="54" loading="lazy">
                    <span><strong><?= $view->e($name) ?></strong><small>@<?= $view->e($person['username'] ?? '') ?></small></span>
                    <i class="fa fa-angle-right" aria-hidden="true"></i>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($pageCount > 1): ?>
            <nav class="profile-directory__pagination" aria-label="Страницы пользователей">
                <?php for ($number = 1; $number <= $pageCount; $number++): ?>
                    <a class="<?= $number === $currentPage ? 'is-current' : '' ?>"
                       href="<?= $view->e($view->route('profile-users') . '?q=' . rawurlencode($queryValue) . '&page=' . $number) ?>"
                       <?= $number === $currentPage ? 'aria-current="page"' : '' ?>><?= $view->e($number) ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Пользователи',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'module_styles' => [
        $view->moduleAsset('profile', 'style.css'),
        $view->moduleAsset('profile', 'hub.css'),
        $view->moduleAsset('profile', 'directory.css'),
    ],
], $content);
