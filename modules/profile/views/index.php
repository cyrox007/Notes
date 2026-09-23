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
$twoFactorEnrollment = isset($two_factor_enrollment) && is_array($two_factor_enrollment) ? $two_factor_enrollment : null;
$twoFactorRecoveryCodes = isset($two_factor_recovery_codes) && is_array($two_factor_recovery_codes) ? $two_factor_recovery_codes : [];
$twoFactorEnabled = !empty($currentUser['totp_enabled']);
$twoFactorRequired = !empty($two_factor_required);
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
            <h1>Профиль</h1>
        </div>
        <a class="profile__public-preview" aria-label="Посмотреть как другой пользователь" href="<?= $view->e($view->route('profile-public', ['uid' => $currentUser['uid'] ?? ''])) ?>">
            <i class="fa fa-eye" aria-hidden="true"></i>
            Публичный профиль
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
            <div class="profile__identity-copy"><h2><?= $view->e($fullName !== '' ? $fullName : $username) ?></h2><p class="profile__user-login">@<?= $view->e($username) ?></p><span>Личный аккаунт</span></div>

            <img src="<?= $view->e($avatarPath !== '' ? $baseUrl . '/' . $avatarPath : $baseUrl . '/assets/img/default_avatar.png') ?>"
                 alt="<?= $view->e($fullName) ?>" class="img-circle elevation-2" width="256" height="256">

            <button class="profile__edit_user-info" type="button" aria-controls="profile-account-settings" aria-expanded="false">
                <i class="fa fa-pencil" aria-hidden="true"></i>
                Редактировать профиль
            </button>

            <?php if ($avatarUrl !== ''): ?>
                <form class="profile__avatar-delete" action="<?= $view->e($view->route('profile-avatar-delete')) ?>" method="post" data-confirm-message="Удалить фото профиля?" data-confirm-native="true" data-confirm-title="Удаление фото" data-confirm-text="Удалить">
                    <?= $view->csrfInput() ?>
                    <button type="submit" class="profile__secondary-action">Удалить фото</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="profile__column-right">
        <?php if (empty($publicationItems['metrics'])): ?>
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
        <?php endif; ?>

        <div class="profile__card-info">
            <div class="profile__card-info--data visible">
                <div class="profile__info-heading">
                    <span class="ux-kicker">Личная информация</span>
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
                <section class="profile__two-factor" aria-labelledby="profile-two-factor-title">
                    <h3 id="profile-two-factor-title">Двухфакторная аутентификация</h3>
                    <p>
                        Статус:
                        <strong><?= $twoFactorEnabled ? 'включена' : 'выключена' ?></strong>.
                        Используется стандартный TOTP, совместимый с Google Authenticator и другими приложениями-аутентификаторами.
                    </p>
                    <?php if ($twoFactorRequired): ?>
                        <p><strong>Политика Workspace:</strong> администратор сделал 2FA обязательной для всех активных пользователей.</p>
                    <?php else: ?>
                        <p>2FA включается только для вашей учётной записи и не влияет на других пользователей.</p>
                    <?php endif; ?>

                    <?php if ($twoFactorRecoveryCodes !== []): ?>
                        <div class="auth-errors" role="status">
                            <p><strong>Сохраните резервные коды сейчас.</strong> Они показываются только один раз. Каждый код можно использовать только один раз.</p>
                            <ul>
                                <?php foreach ($twoFactorRecoveryCodes as $recoveryCode): ?>
                                    <?php if (!is_string($recoveryCode)) { continue; } ?>
                                    <li><code><?= $view->e($recoveryCode) ?></code></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!$twoFactorEnabled): ?>
                        <?php if ($twoFactorEnrollment !== null): ?>
                            <p>Добавьте аккаунт в приложение-аутентификатор вручную или откройте ссылку настройки на устройстве с установленным приложением.</p>
                            <div class="profile__card-info--edit--form-group">
                                <label>Секретный ключ:</label>
                                <code><?= $view->e($twoFactorEnrollment['secret'] ?? '') ?></code>
                            </div>
                            <p>
                                <a href="<?= $view->e($twoFactorEnrollment['uri'] ?? '') ?>">Открыть в приложении-аутентификаторе</a>
                            </p>
                            <form action="<?= $view->e($view->route('profile-two-factor-confirm')) ?>" method="post" autocomplete="off">
                                <?= $view->csrfInput() ?>
                                <div class="profile__card-info--edit--form-group">
                                    <label for="two-factor-confirm-password">Текущий пароль:</label>
                                    <input class="profile__card-info--edit--set-input" type="password" name="current_password" id="two-factor-confirm-password" required autocomplete="current-password">
                                </div>
                                <div class="profile__card-info--edit--form-group">
                                    <label for="two-factor-confirm-code">Код из приложения:</label>
                                    <input class="profile__card-info--edit--set-input" type="text" name="code" id="two-factor-confirm-code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
                                </div>
                                <button class="profile__card-info--edit--set-save" type="submit">Подтвердить и включить 2FA</button>
                            </form>
                        <?php else: ?>
                            <p>После включения пароль останется первым фактором, а для завершения входа потребуется одноразовый код.</p>
                            <form action="<?= $view->e($view->route('profile-two-factor-start')) ?>" method="post">
                                <?= $view->csrfInput() ?>
                                <div class="profile__card-info--edit--form-group">
                                    <label for="two-factor-start-password">Текущий пароль:</label>
                                    <input class="profile__card-info--edit--set-input" type="password" name="current_password" id="two-factor-start-password" required autocomplete="current-password">
                                </div>
                                <button class="profile__card-info--edit--set-save" type="submit">Настроить 2FA</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (!empty($currentUser['totp_confirmed_at'])): ?>
                            <p>Включена: <?= $view->e((string) $currentUser['totp_confirmed_at']) ?></p>
                        <?php endif; ?>

                        <form action="<?= $view->e($view->route('profile-two-factor-recovery')) ?>" method="post" autocomplete="off">
                            <?= $view->csrfInput() ?>
                            <h4>Новые резервные коды</h4>
                            <p>Старый набор будет немедленно отозван.</p>
                            <div class="profile__card-info--edit--form-group">
                                <label for="two-factor-recovery-password">Текущий пароль:</label>
                                <input class="profile__card-info--edit--set-input" type="password" name="current_password" id="two-factor-recovery-password" required autocomplete="current-password">
                            </div>
                            <div class="profile__card-info--edit--form-group">
                                <label for="two-factor-recovery-code">Код 2FA или резервный код:</label>
                                <input class="profile__card-info--edit--set-input" type="text" name="code" id="two-factor-recovery-code" maxlength="32" required autocomplete="one-time-code">
                            </div>
                            <button class="profile__card-info--edit--set-save" type="submit">Создать новые резервные коды</button>
                        </form>

                        <?php if ($twoFactorRequired): ?>
                            <p>Отключение 2FA недоступно, пока администратор сохраняет обязательную политику.</p>
                        <?php else: ?>
                            <form action="<?= $view->e($view->route('profile-two-factor-disable')) ?>" method="post" autocomplete="off" data-confirm-message="Выключить двухфакторную аутентификацию? Резервные коды также будут отозваны." data-confirm-native="true" data-confirm-title="Отключение 2FA" data-confirm-text="Отключить">
                                <?= $view->csrfInput() ?>
                                <h4>Отключить 2FA</h4>
                                <div class="profile__card-info--edit--form-group">
                                    <label for="two-factor-disable-password">Текущий пароль:</label>
                                    <input class="profile__card-info--edit--set-input" type="password" name="current_password" id="two-factor-disable-password" required autocomplete="current-password">
                                </div>
                                <div class="profile__card-info--edit--form-group">
                                    <label for="two-factor-disable-code">Код 2FA или резервный код:</label>
                                    <input class="profile__card-info--edit--set-input" type="text" name="code" id="two-factor-disable-code" maxlength="32" required autocomplete="one-time-code">
                                </div>
                                <button class="profile__card-info--edit--delete" type="submit">Отключить 2FA</button>
                            </form>
    
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <hr>
                <section class="profile__danger-zone">
                    <h3>Деактивация аккаунта</h3>
                    <p>Данные аккаунта сохранятся, но вход и действия в системе будут заблокированы. Если вы владелец группы, сначала передайте владение другому участнику.</p>
                    <form name="deleteUser" action="<?= $view->e($view->route('profile-delete')) ?>" method="post" data-confirm-message="Деактивировать аккаунт? Вход в систему будет заблокирован." data-confirm-native="true" data-confirm-title="Деактивация аккаунта" data-confirm-text="Деактивировать">
                        <?= $view->csrfInput() ?>
                        <div class="profile__card-info--edit--form-group"><label for="deactivate-password">Текущий пароль:</label><input class="profile__card-info--edit--set-input" type="password" name="current_password" id="deactivate-password" required autocomplete="current-password"></div>
                        <label class="profile__confirm-deactivate"><input type="checkbox" name="confirm_delete" value="yes" required> Я понимаю, что аккаунт будет деактивирован.</label>
                        <button class="profile__card-info--edit--delete" type="submit">Деактивировать аккаунт</button>
                    </form>
                </section>
            </div>
        </div>
        <?= $view->partial('profile_page/publication', [
            'user' => $currentUser,
            'publication_items' => $publicationItems,
            'workspaceAccess' => $access,
        ]) ?>
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
