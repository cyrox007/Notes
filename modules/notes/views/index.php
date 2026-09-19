<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$personal = isset($personalNotes) && is_array($personalNotes) ? $personalNotes : [];
$all = isset($allNotes) && is_array($allNotes) ? $allNotes : [];
$isAdmin = !empty($isAdmin);
$pager = isset($pagination) && is_array($pagination) ? $pagination : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$currentSort = (string) ($pager['sort'] ?? 'created_note');
$currentDirection = strtolower((string) ($pager['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

$sortUrl = static function (string $sort) use ($view, $pager, $currentSort, $currentDirection): string {
    $direction = $currentSort === $sort && $currentDirection === 'asc' ? 'desc' : 'asc';
    $query = ['sort' => $sort, 'direction' => $direction];
    foreach (['q', 'limit', 'filter'] as $key) {
        if (isset($pager[$key]) && $pager[$key] !== '' && $pager[$key] !== null) {
            $query[$key] = (string) $pager[$key];
        }
    }
    return $view->route('notes') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
};
$sortMarker = static function (string $sort) use ($currentSort, $currentDirection): string {
    if ($currentSort !== $sort) {
        return '▼';
    }
    return $currentDirection === 'asc' ? '▲' : '▼';
};

ob_start();
?>
<section class="module-page-header module-page-header--notes">
    <div><span class="module-page-header__eyebrow">Рабочее пространство</span><h1>Заметки</h1><p>Личные записи, вложения и общие ссылки.</p></div>
</section>
<section class="notes">
    <form class="notes__create" action="<?= $view->e($view->route('note_create')) ?>" method="post">
        <?= $view->csrfInput() ?>
        <div class="notes__input"><input type="text" name="notename" maxlength="255" placeholder="Название новой заметки..." aria-label="Название новой заметки"></div>
        <div class="notes__submit"><button type="submit"><i class="fa fa-plus" aria-hidden="true"></i> Новая заметка</button></div>
    </form>

    <?php if ($isAdmin): ?>
        <div id="show-all-notes">
            <p>Показать метаданные заметок всех пользователей</p>
            <button class="switch-btn" type="button" aria-pressed="false" aria-label="Показать все заметки"></button>
        </div>
    <?php endif; ?>

    <div class="notes__content">
        <div class="notes__section-heading"><h2 class="notes__title">Все заметки</h2><span class="notes__count"><?= count($personal) ?></span></div>
        <div class="notes__list_head">
            <div class="notes__list_head--name"><a href="<?= $view->e($sortUrl('notename')) ?>">Название <?= $view->e($sortMarker('notename')) ?></a></div>
            <?php if ($isAdmin): ?><div class="notes__list_head--author" hidden>Автор</div><?php endif; ?>
            <div class="notes__list_head--date"><a href="<?= $view->e($sortUrl('created_note')) ?>">Дата создания <?= $view->e($sortMarker('created_note')) ?></a></div>
            <div class="notes__list_head--btn"></div>
        </div>

        <div id="personal" class="notes__list visible">
            <?php if ($personal !== []): ?>
                <?php foreach ($personal as $noteRow): ?>
                    <?php if (!is_array($noteRow)) { continue; } ?>
                    <?= $view->partial('@notes/note_item/index', ['note' => $noteRow, 'user' => $currentUser, 'readOnly' => false, 'showAuthor' => false]) ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="notes__list_item"><p>Здесь ничего нет</p></div>
            <?php endif; ?>
        </div>

        <?php if ($isAdmin): ?>
            <div id="all-user" class="notes__list">
                <?php if ($all !== []): ?>
                    <?php foreach ($all as $noteRow): ?>
                        <?php if (!is_array($noteRow)) { continue; } ?>
                        <?= $view->partial('@notes/note_item/index', ['note' => $noteRow, 'user' => $currentUser, 'readOnly' => true, 'showAuthor' => true]) ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notes__list_item"><p>Заметок нет</p></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Блокнот',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'pagination' => $pager,
    'module_styles' => [$view->moduleAsset('notes', 'style.css')],
    'module_scripts' => [$view->moduleAsset('notes', 'notes-list.js')],
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
], $content);
