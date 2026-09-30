<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$items = isset($publication_items) && is_array($publication_items) ? $publication_items : [];
$metrics = isset($items['metrics']) && is_array($items['metrics']) ? $items['metrics'] : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$hasWorkspaceContent = !empty($access['notes']) || !empty($access['tasks']) || !empty($access['files']);
?>
<?php if ($metrics !== [] && $hasWorkspaceContent): ?>
<section class="profile-metrics ux-section" aria-labelledby="profile-metrics-title">
    <div class="profile-metrics__heading">
        <div>
            <span class="ux-kicker">Сводка</span>
            <h2 id="profile-metrics-title">Моё пространство</h2>
            <p>Доступные разделы вашего рабочего пространства.</p>
        </div>
        <button type="button" class="profile-metrics__settings" data-profile-edit aria-controls="profile-account-settings" aria-expanded="false">
            <i class="fa fa-cog" aria-hidden="true"></i>
            Настройки аккаунта
        </button>
    </div>

    <div class="profile-metrics__grid">
        <?php if (!empty($access['notes'])): ?>
            <a class="profile-metric profile-metric--notes" aria-label="Мои заметки: <?= $view->e($metrics['notes_count'] ?? 0) ?>" href="<?= $view->e($view->route('notes')) ?>">
                <span class="profile-metric__icon"><i class="fa fa-sticky-note-o" aria-hidden="true"></i></span>
                <span class="profile-metric__body">
                    <span class="profile-metric__value"><?= $view->e($metrics['notes_count'] ?? 0) ?></span>
                    <span class="profile-metric__label">Заметок</span>
                </span>
                <i class="fa fa-angle-right profile-metric__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['tasks'])): ?>
            <a class="profile-metric profile-metric--tasks" aria-label="Мои задачи: <?= $view->e($metrics['tasks_count'] ?? 0) ?>" href="<?= $view->e($view->route('tasks')) ?>">
                <span class="profile-metric__icon"><i class="fa fa-check-square-o" aria-hidden="true"></i></span>
                <span class="profile-metric__body">
                    <span class="profile-metric__value"><?= $view->e($metrics['tasks_count'] ?? 0) ?></span>
                    <span class="profile-metric__label">Задач · <?= $view->e($metrics['open_tasks_count'] ?? 0) ?> активных</span>
                </span>
                <i class="fa fa-angle-right profile-metric__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>

        <?php if (!empty($access['files'])): ?>
            <a class="profile-metric profile-metric--files" aria-label="Мои файлы: <?= $view->e($metrics['files_count'] ?? 0) ?>" href="<?= $view->e($view->route('files')) ?>">
                <span class="profile-metric__icon"><i class="fa fa-folder-open-o" aria-hidden="true"></i></span>
                <span class="profile-metric__body">
                    <span class="profile-metric__value"><?= $view->e($metrics['files_count'] ?? 0) ?></span>
                    <span class="profile-metric__label">Файлов</span>
                </span>
                <i class="fa fa-angle-right profile-metric__arrow" aria-hidden="true"></i>
            </a>
            <?php $storage = isset($metrics['storage']) && is_array($metrics['storage']) ? $metrics['storage'] : []; ?>
            <a class="profile-metric profile-metric--storage" href="<?= $view->e($view->route('files')) ?>">
                <span class="profile-metric__icon"><i class="fa fa-hdd-o" aria-hidden="true"></i></span>
                <span class="profile-metric__body">
                    <span class="profile-metric__value"><?= $view->e($storage['percent'] ?? 0) ?>%</span>
                    <span class="profile-metric__label"><?= $view->e($storage['used_label'] ?? '0 Б') ?> из <?= $view->e($storage['quota_label'] ?? '—') ?></span>
                    <progress class="profile-metric__progress" value="<?= $view->e($storage['percent'] ?? 0) ?>" max="100" aria-label="Использовано <?= $view->e($storage['percent'] ?? 0) ?>% хранилища"></progress>
                </span>
                <i class="fa fa-angle-right profile-metric__arrow" aria-hidden="true"></i>
            </a>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php endif; ?>

<?php if ($hasWorkspaceContent): ?>
<section class="profile-publication ux-section" aria-labelledby="profile-publication-title">
    <div class="ux-section__heading profile-publication__heading">
        <div>
            <span class="ux-kicker">Видимость профиля</span>
            <h2 id="profile-publication-title">Что видно другим пользователям</h2>
            <p>Эти настройки определяют содержимое вашего профиля для других пользователей Workspace.<?php if (!empty($access['files'])): ?> Доступ по специальной ссылке на файл или папку настраивается отдельно в File Manager.<?php endif; ?></p>
        </div>
        <a class="profile-publication__preview" href="<?= $view->e($view->route('profile-public', ['uid' => $currentUser['uid'] ?? ''])) ?>">
            <i class="fa fa-eye" aria-hidden="true"></i>
            Предпросмотр
        </a>
    </div>

    <div class="profile-publication__columns">
        <?php
        $groups = [
            'notes' => ['title' => 'Заметки', 'icon' => 'fa-sticky-note-o', 'type' => 'note', 'empty' => 'Нет заметок для настройки видимости.'],
            'tasks' => ['title' => 'Задачи', 'icon' => 'fa-check-square-o', 'type' => 'task', 'empty' => 'Нет задач для настройки видимости.'],
            'files' => ['title' => 'Файлы', 'icon' => 'fa-folder-open-o', 'type' => 'file', 'empty' => 'Нет файлов для настройки видимости.'],
        ];
        ?>
        <?php foreach ($groups as $key => $group): ?>
            <?php if (empty($access[$key])) { continue; } ?>
            <?php $groupItems = isset($items[$key]) && is_array($items[$key]) ? $items[$key] : []; ?>
            <section class="profile-publication__group" aria-labelledby="publication-<?= $view->e($key) ?>-title">
                <div class="profile-publication__group-title">
                    <i class="fa <?= $view->e($group['icon']) ?>" aria-hidden="true"></i>
                    <h3 id="publication-<?= $view->e($key) ?>-title"><?= $view->e($group['title']) ?></h3>
                </div>
                <?php if ($groupItems !== []): ?>
                    <div class="profile-publication__list">
                        <?php foreach ($groupItems as $item): ?>
                            <?php if (!is_array($item)) { continue; } ?>
                            <?php
                                $isPublic = !empty($item['is_profile_public']);
                                $label = $key === 'files'
                                    ? (string) ($item['name'] ?? '') . (!empty($item['extension']) ? '.' . (string) $item['extension'] : '')
                                    : (string) ($item['title'] ?? '');
                                $visibility = $isPublic ? 'Виден в профиле' : 'Приватно';
                                if ($key === 'tasks') {
                                    $visibility .= ' · ' . (string) ($item['status'] ?? '');
                                }
                            ?>
                            <article class="profile-publication__item<?= $isPublic ? ' is-public' : '' ?>">
                                <div>
                                    <strong><?= $view->e($label) ?></strong>
                                    <span><?= $view->e($visibility) ?></span>
                                </div>
                                <form action="<?= $view->e($view->route('profile-publication')) ?>" method="post">
                                    <?= $view->csrfInput() ?>
                                    <input type="hidden" name="type" value="<?= $view->e($group['type']) ?>">
                                    <input type="hidden" name="uid" value="<?= $view->e($item['uid'] ?? '') ?>">
                                    <input type="hidden" name="public" value="<?= $isPublic ? '0' : '1' ?>">
                                    <button type="submit" class="profile-publication__toggle"><?= $isPublic ? 'Скрыть из профиля' : 'Показать в профиле' ?></button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="profile-publication__empty"><?= $view->e($group['empty']) ?></p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
</section>
