<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$profileFields = isset($fields) && is_array($fields) ? $fields : [];
$profileErrors = isset($errors) && is_array($errors) ? $errors : [];
$publicationItems = isset($publication_items) && is_array($publication_items) ? $publication_items : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$avatarUrl = isset($avatar_url) && is_string($avatar_url) ? $avatar_url : '';
$avatarPath = ltrim($avatarUrl, '/');
$fullName = trim((string) ($currentUser['firstname'] ?? '') . ' ' . (string) ($currentUser['lastname'] ?? ''));
$username = (string) ($currentUser['username'] ?? '');

$customData = [];
$property = (string) ($currentUser['property'] ?? '');
if ($property !== '') {
    $decoded = json_decode($property, true);
    if (is_array($decoded)) {
        $customData = $decoded;
    }
}
$userCustomData = [];
foreach ($customData as $item) {
    if (!is_array($item)) {
        continue;
    }
    $name = (string) ($item['name'] ?? '');
    if ($name !== '') {
        $userCustomData[$name] = $item;
    }
}

ob_start();
?>
<section class="profile profile--hub">
    <header class="profile__hub-heading">
        <div>
            <span class="ux-kicker">Мой workspace</span>
            <h1><?= $view->e($fullName) ?></h1>
            <p>Профиль, быстрый доступ к рабочим разделам и настройки аккаунта.</p>
        </div>
        <a class="profile__public-preview" href="<?= $view->e($view->route('profile-public', ['uid' => $currentUser['uid'] ?? ''])) ?>">
            <i class="fa fa-eye" aria-hidden="true"></i>
            Посмотреть как другой пользователь
        </a>
    </header>

    <?php if ($profileErrors !== []): ?>
        <div class="profile__errors" role="alert">
            <?php foreach ($profileErrors as $error): ?>
                <?php if (!is_array($error)) { continue; } ?>
                <div class="profile__error"><?= $view->e($error['MESSAGE'] ?? '') ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="profile__column-left">
        <div class="profile__card-avatar">
            <span class="ux-kicker">Аккаунт</span>
            <p class="profile__user-login">@<?= $view->e($username) ?></p>

            <img src="<?= $view->e($avatarPath !== '' ? $baseUrl . '/' . $avatarPath : $baseUrl . '/assets/img/default_avatar.png') ?>"
                 alt="<?= $view->e($fullName) ?>" class="img-circle elevation-2" width="256" height="256">

            <button class="profile__edit_user-info" type="button" aria-controls="profile-account-settings" aria-expanded="false">
                <i class="fa fa-pencil" aria-hidden="true"></i>
                Редактировать профиль
            </button>

            <?php if ($avatarUrl !== ''): ?>
                <form class="profile__avatar-delete" action="<?= $view->e($view->route('profile-avatar-delete')) ?>" method="post" data-confirm-message="Удалить фото профиля?" data-confirm-title="Удаление фото" data-confirm-text="Удалить">
                    <?= $view->csrfInput() ?>
                    <button type="submit" class="profile__secondary-action">Удалить фото</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="profile__column-right">
        <nav class="profile__workspace-links" aria-label="Мои разделы">
            <?php if (!empty($access['notes'])): ?>
                <a class="profile__workspace-link profile__workspace-link--notes" href="<?= $view->e($view->route('notes')) ?>">
                    <span class="profile__workspace-icon"><i class="fa fa-sticky-note-o" aria-hidden="true"></i></span>
                    <span><strong>Мои заметки</strong><small>Идеи, записи, вложения и общий доступ</small></span>
                    <i class="fa fa-angle-right" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
            <?php if (!empty($access['tasks'])): ?>
                <a class="profile__workspace-link profile__workspace-link--tasks" href="<?= $view->e($view->route('tasks')) ?>">
                    <span class="profile__workspace-icon"><i class="fa fa-check-square-o" aria-hidden="true"></i></span>
                    <span><strong>Мои задачи</strong><small>Планы, дедлайны и подзадачи</small></span>
                    <i class="fa fa-angle-right" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
            <?php if (!empty($access['files'])): ?>
                <a class="profile__workspace-link profile__workspace-link--files" href="<?= $view->e($view->route('files')) ?>">
                    <span class="profile__workspace-icon"><i class="fa fa-folder-open-o" aria-hidden="true"></i></span>
                    <span><strong>Мои файлы</strong><small>Приватное хранилище и загрузки</small></span>
                    <i class="fa fa-angle-right" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </nav>

        <?= $view->partial('profile_page/publication', [
            'user' => $currentUser,
            'publication_items' => $publicationItems,
            'workspaceAccess' => $access,
        ]) ?>

        <div class="profile__card-info">
            <div class="profile__card-info--data visible">
                <div class="profile__info-heading">
                    <span class="ux-kicker">Профиль</span>
                    <div class="profile__user-fio"><?= $view->e($fullName) ?></div>
                </div>

                <?php if (!empty($currentUser['phone'])): ?>
                    <div class="profile__user-other-info">
                        <span class="profile__detail-label">Телефон</span>
                        <p class="profile__user-detals"><?= $view->e($currentUser['phone']) ?></p>
                    </div>
                <?php endif; ?>
                <div class="profile__user-other-info">
                    <span class="profile__detail-label">Email</span>
                    <p class="profile__user-detals"><?= $view->e($currentUser['email'] ?? '') ?></p>
                </div>

                <?php foreach ($customData as $props): ?>
                    <?php if (!is_array($props)) { continue; } ?>
                    <div class="profile__user-other-info">
                        <span class="profile__detail-label"><?= $view->e($props['label'] ?? '') ?></span>
                        <p class="profile__user-detals"><?= $view->e($props['value'] ?? '') ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="profile__card-info--edit" id="profile-account-settings">
                <button id="close" type="button" aria-label="Закрыть редактирование">
                    <i class="fa fa-times" aria-hidden="true"></i>
                </button>

                <form name="changeProfile" action="<?= $view->e($view->route('profile-set')) ?>" method="post" enctype="multipart/form-data">
                    <?= $view->csrfInput() ?>
                    <div class="profile__card-info--edit--form-group">
                        <label for="user-name">Имя:</label>
                        <input class="profile__card-info--edit--set-input" type="text" name="set-user-name" id="user-name" value="<?= $view->e($currentUser['firstname'] ?? '') ?>" maxlength="80" required autocomplete="given-name">
                    </div>
                    <div class="profile__card-info--edit--form-group">
                        <label for="user-patronymic">Отчество:</label>
                        <input class="profile__card-info--edit--set-input" type="text" name="set-user-patronymic" id="user-patronymic" value="<?= $view->e($currentUser['patronymic'] ?? '') ?>" maxlength="80" autocomplete="additional-name">
                    </div>
                    <div class="profile__card-info--edit--form-group">
                        <label for="user-surname">Фамилия:</label>
                        <input class="profile__card-info--edit--set-input" type="text" name="set-user-surname" id="user-surname" value="<?= $view->e($currentUser['lastname'] ?? '') ?>" maxlength="80" required autocomplete="family-name">
                    </div>
                    <div class="profile__card-info--edit--form-group">
                        <label for="user-phone">Телефон:</label>
                        <input class="profile__card-info--edit--set-input" type="tel" name="set-user-phone" id="user-phone" value="<?= $view->e($currentUser['phone'] ?? '') ?>" maxlength="20" autocomplete="tel">
                    </div>
                    <div class="profile__card-info--edit--form-group">
                        <label for="user-email">Email:</label>
                        <input class="profile__card-info--edit--set-input" type="email" name="set-user-email" id="user-email" value="<?= $view->e($currentUser['email'] ?? '') ?>" maxlength="190" required autocomplete="email">
                    </div>
                    <div class="profile__card-info--edit--form-group">
                        <label for="user-avatar">Изменить изображение пользователя:</label>
                        <input class="set-files" type="file" name="set-user-avatar" id="user-avatar" accept="image/jpeg,image/png,image/webp">
                        <small>JPEG, PNG или WebP. Максимальный размер задаётся администратором.</small>
                    </div>

                    <?php foreach ($profileFields as $field): ?>
                        <?php if (!is_array($field)) { continue; } ?>
                        <?php
                            $fieldName = (string) ($field['field_name'] ?? '');
                            $fieldType = (string) ($field['field_type'] ?? 'text');
                            $fieldLabel = (string) ($field['field_label'] ?? $fieldName);
                            $fieldValue = (string) ($userCustomData[$fieldName]['value'] ?? '');
                            $required = !empty($field['is_required']);
                        ?>
                        <div class="profile__card-info--edit--form-group">
                            <label for="<?= $view->e($fieldName) ?>"><?= $view->e($fieldLabel) ?>:</label>
                            <?php if ($fieldType === 'textarea'): ?>
                                <textarea class="profile__card-info--edit--set-input" name="custom[<?= $view->e($fieldName) ?>][value]" id="<?= $view->e($fieldName) ?>" maxlength="5000"<?= $required ? ' required' : '' ?>><?= $view->e($fieldValue) ?></textarea>
                            <?php elseif ($fieldType === 'checkbox'): ?>
                                <input type="checkbox" name="custom[<?= $view->e($fieldName) ?>][value]" id="<?= $view->e($fieldName) ?>" value="1"<?= $fieldValue === '1' ? ' checked' : '' ?>>
                            <?php else: ?>
                                <?php $inputType = $fieldType === 'number' ? 'number' : ($fieldType === 'date' ? 'date' : 'text'); ?>
                                <input class="profile__card-info--edit--set-input" type="<?= $view->e($inputType) ?>" name="custom[<?= $view->e($fieldName) ?>][value]" id="<?= $view->e($fieldName) ?>" value="<?= $view->e($fieldValue) ?>"<?= $required ? ' required' : '' ?>>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <button class="profile__card-info--edit--set-save" type="submit">Сохранить изменения</button>
                </form>

                <hr>
                <form name="changePassword" action="<?= $view->e($view->route('profile-password-set')) ?>" method="post">
                    <?= $view->csrfInput() ?>
                    <div class="profile__card-info--edit--form-group"><label for="old-password">Текущий пароль:</label><input class="profile__card-info--edit--set-input" type="password" name="old-password" id="old-password" required autocomplete="current-password"></div>
                    <div class="profile__card-info--edit--form-group"><label for="new-password">Новый пароль:</label><input class="profile__card-info--edit--set-input" type="password" name="new-password" id="new-password" minlength="10" required autocomplete="new-password"></div>
                    <div class="profile__card-info--edit--form-group"><label for="repeat-new-password">Повторите пароль:</label><input class="profile__card-info--edit--set-input" type="password" name="repeat-new-password" id="repeat-new-password" minlength="10" required autocomplete="new-password"></div>
                    <span id="error-repeat" aria-live="polite"></span>
                    <button id="change-password-btn" class="profile__card-info--edit--set-save" type="submit">Изменить пароль</button>
                </form>

                <hr>
                <section class="profile__danger-zone">
                    <h3>Деактивация аккаунта</h3>
                    <p>Данные аккаунта сохранятся, но вход и действия в системе будут заблокированы. Если вы владелец группы, сначала передайте владение другому участнику.</p>
                    <form name="deleteUser" action="<?= $view->e($view->route('profile-delete')) ?>" method="post" data-confirm-message="Деактивировать аккаунт? Вход в систему будет заблокирован." data-confirm-title="Деактивация аккаунта" data-confirm-text="Деактивировать">
                        <?= $view->csrfInput() ?>
                        <div class="profile__card-info--edit--form-group"><label for="deactivate-password">Текущий пароль:</label><input class="profile__card-info--edit--set-input" type="password" name="current_password" id="deactivate-password" required autocomplete="current-password"></div>
                        <label class="profile__confirm-deactivate"><input type="checkbox" name="confirm_delete" value="yes" required> Я понимаю, что аккаунт будет деактивирован.</label>
                        <button class="profile__card-info--edit--delete" type="submit">Деактивировать аккаунт</button>
                    </form>
                </section>
            </div>
        </div>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => $fullName !== '' ? $fullName : 'Профиль',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
], $content);
