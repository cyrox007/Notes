<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\CryptMethods;
use App\Services\ProfilePublicationService;
use App\Services\TwoFactorService;
use App\Services\UserAvatarService;
use Core\AccountDeactivationGuard;
use Core\Controller;
use Core\DatabaseManager;
use Core\ModuleRuntimeLoader;
use Core\Request;
use Core\RequestOrigin;
use Core\Router;
use Core\SecurityEventLog;
use Core\SessionSecurity;
use DomainException;
use InvalidArgumentException;

final class ProfileController extends Controller
{
    public function index(Request $request): void
    {
        $user = $this->currentUser($request);
        $recoveryCodes = $request->session('two_factor_recovery_codes', []);
        $recoveryCodes = is_array($recoveryCodes)
            ? array_values(array_filter($recoveryCodes, 'is_string'))
            : [];
        if ($recoveryCodes !== []) {
            $request->unsetSession('two_factor_recovery_codes');
            $this->noStoreSensitivePage();
        }

        $enrollment = $this->twoFactorEnrollment($request, $user);
        if ($enrollment !== null) {
            $this->noStoreSensitivePage();
        }

        $this->renderProfile($user, [], 200, $enrollment, $recoveryCodes);
    }

    public function setPublication(Request $request): void
    {
        $user = $this->currentUser($request);

        try {
            $type = (string) $request->post('type', '');
            $uid = (string) $request->post('uid', '');
            $isPublic = (string) $request->post('public', '0') === '1';
            (new ProfilePublicationService())
                ->setVisibility((int) $user->id, $type, $uid, $isPublic);

            Router::getInstance()->redirect('profile');
        } catch (InvalidArgumentException $e) {
            $this->renderProfile($user, [[
                'CODE' => 'profile_publication_invalid',
                'MESSAGE' => $e->getMessage(),
            ]], 422);
        }
    }

    public function update(Request $request): void
    {
        $user = $this->currentUser($request);

        try {
            $post = $request->post();
            if (!is_array($post)) {
                throw new InvalidArgumentException('Некорректные данные профиля');
            }

            $firstname = $this->personName((string) ($post['set-user-name'] ?? ''), 'Имя');
            $lastname = $this->personName((string) ($post['set-user-surname'] ?? ''), 'Фамилия');
            $patronymicRaw = trim((string) ($post['set-user-patronymic'] ?? ''));
            $patronymic = $patronymicRaw === '' ? null : $this->personName($patronymicRaw, 'Отчество');

            $email = strtolower(trim((string) ($post['set-user-email'] ?? '')));
            if (mb_strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Некорректный формат email адреса');
            }

            $phone = $this->phoneOrNull((string) ($post['set-user-phone'] ?? ''));
            $db = DatabaseManager::getInstance();
            $duplicateEmail = $db->fetchValue(
                'SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1',
                [':email' => $email, ':id' => (int) $user->id]
            );
            if ($duplicateEmail !== null) {
                throw new InvalidArgumentException('Этот email уже используется другим пользователем');
            }

            $custom = $this->validatedCustomFields($post['custom'] ?? []);
            $update = [
                'firstname' => $firstname,
                'patronymic' => $patronymic,
                'lastname' => $lastname,
                'phone' => $phone,
                'email' => $email,
                'property' => json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $oldAvatar = (string) ($user->avatar ?? '');
            $avatarFile = $_FILES['set-user-avatar'] ?? null;
            if (is_array($avatarFile) && (int) ($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $avatarService = new UserAvatarService($db);
                $update['avatar'] = $avatarService->upload((int) $user->id, (string) $user->uid, $avatarFile);
            }

            $db->execute(
                'UPDATE users
                 SET firstname = :firstname,
                     patronymic = :patronymic,
                     lastname = :lastname,
                     phone = :phone,
                     email = :email,
                     property = :property,
                     avatar = COALESCE(:avatar, avatar),
                     updated_at = :updated_at
                 WHERE id = :id AND is_active = 1 AND account_status = \'active\'',
                [
                    ':firstname' => $update['firstname'],
                    ':patronymic' => $update['patronymic'],
                    ':lastname' => $update['lastname'],
                    ':phone' => $update['phone'],
                    ':email' => $update['email'],
                    ':property' => $update['property'],
                    ':avatar' => $update['avatar'] ?? null,
                    ':updated_at' => $update['updated_at'],
                    ':id' => (int) $user->id,
                ]
            );

            if (isset($update['avatar']) && $oldAvatar !== '' && !str_starts_with($oldAvatar, '/profile/avatar/')) {
                (new UserAvatarService($db))->removeStoredAvatar([
                    'id' => (int) $user->id,
                    'avatar' => $oldAvatar,
                ]);
            }

            Router::getInstance()->redirect('profile');
        } catch (InvalidArgumentException $e) {
            $this->renderProfile($user, [[
                'CODE' => 'profile_validation',
                'MESSAGE' => $e->getMessage(),
            ]], 422);
        } catch (\Throwable $e) {
            error_log('Profile update failed: ' . $e->getMessage());
            $this->renderProfile($user, [[
                'CODE' => 'profile_update_failed',
                'MESSAGE' => 'Не удалось сохранить профиль',
            ]], 500);
        }
    }

    public function startTwoFactorSetup(Request $request): void
    {
        $user = $this->currentUser($request, true);
        $password = (string) $request->rawPost('current_password', '');

        if ((int) ($user->totp_enabled ?? 0) === 1) {
            $this->renderProfile($user, [[
                'CODE' => 'two_factor_already_enabled',
                'MESSAGE' => 'Двухфакторная аутентификация уже включена',
            ]], 409);
            return;
        }
        if (!CryptMethods::verifyPassword($password, (string) $user->password_hash)) {
            $this->renderProfile($user, [[
                'CODE' => 'two_factor_password_invalid',
                'MESSAGE' => 'Неверный текущий пароль',
            ]], 422);
            return;
        }

        $service = new TwoFactorService();
        $secret = $service->generateSecret();
        $request->setSession('two_factor_enrollment', [
            'user_id' => (int) $user->id,
            'secret' => $service->encryptSecret($secret, (string) $user->uid),
            'started_at' => time(),
        ]);

        SecurityEventLog::emit(
            'auth.two_factor_enrollment_started',
            'info',
            'auth',
            'user',
            (int) $user->id,
            ['client_ip' => RequestOrigin::clientIp($_SERVER)]
        );
        Router::getInstance()->redirect('profile', 'name');
    }

    public function confirmTwoFactorSetup(Request $request): void
    {
        $user = $this->currentUser($request, true);
        $enrollment = $this->twoFactorEnrollment($request, $user);
        if ($enrollment === null) {
            $this->renderProfile($user, [[
                'CODE' => 'two_factor_setup_expired',
                'MESSAGE' => 'Настройка двухфакторной аутентификации истекла. Начните заново.',
            ]], 409);
            return;
        }

        $password = (string) $request->rawPost('current_password', '');
        $code = trim((string) $request->rawPost('code', ''));
        if (!CryptMethods::verifyPassword($password, (string) $user->password_hash)) {
            $this->noStoreSensitivePage();
            $this->renderProfile($user, [[
                'CODE' => 'two_factor_password_invalid',
                'MESSAGE' => 'Неверный текущий пароль',
            ]], 422, $enrollment);
            return;
        }

        $service = new TwoFactorService();
        $counter = $service->matchingCounter((string) $enrollment['secret'], $code);
        if ($counter === null) {
            SecurityEventLog::emit(
                'auth.two_factor_enrollment_failed',
                'warning',
                'auth',
                'user',
                (int) $user->id,
                ['client_ip' => RequestOrigin::clientIp($_SERVER)]
            );
            $this->noStoreSensitivePage();
            $this->renderProfile($user, [[
                'CODE' => 'two_factor_code_invalid',
                'MESSAGE' => 'Код аутентификатора неверен или уже устарел',
            ]], 422, $enrollment);
            return;
        }

        $recoveryCodes = $service->generateRecoveryCodes();
        $db = DatabaseManager::getInstance();
        $db->execute(
            'UPDATE users SET totp_enabled = 1, totp_secret = :secret, '
            . 'totp_last_counter = :counter, totp_recovery_codes = :recovery_codes, '
            . 'totp_confirmed_at = :confirmed_at, updated_at = :updated_at '
            . 'WHERE id = :id AND is_active = 1 AND account_status = \'active\'',
            [
                ':secret' => (string) $enrollment['encrypted_secret'],
                ':counter' => $counter,
                ':recovery_codes' => $service->hashRecoveryCodes($recoveryCodes),
                ':confirmed_at' => date('Y-m-d H:i:s'),
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => (int) $user->id,
            ]
        );

        $request->unsetSession('two_factor_enrollment');
        $request->setSession('two_factor_recovery_codes', $recoveryCodes);
        $this->rotateAuthenticatedSession($request);

        SecurityEventLog::emit(
            'auth.two_factor_enabled',
            'info',
            'auth',
            'user',
            (int) $user->id,
            ['client_ip' => RequestOrigin::clientIp($_SERVER)]
        );
        Router::getInstance()->redirect('profile', 'name');
    }

    public function regenerateTwoFactorRecoveryCodes(Request $request): void
    {
        $user = $this->currentUser($request, true);
        $password = (string) $request->rawPost('current_password', '');
        $code = trim((string) $request->rawPost('code', ''));

        if (
            (int) ($user->totp_enabled ?? 0) !== 1
            || !CryptMethods::verifyPassword($password, (string) $user->password_hash)
        ) {
            $this->renderProfile($user, [[
                'CODE' => 'two_factor_recovery_denied',
                'MESSAGE' => 'Не удалось подтвердить учётную запись',
            ]], 422);
            return;
        }

        $service = new TwoFactorService();
        $verification = $service->verifyAndConsume(DatabaseManager::getInstance(), (int) $user->id, $code);
        if (!$verification['ok']) {
            $this->renderProfile($user, [[
                'CODE' => 'two_factor_code_invalid',
                'MESSAGE' => 'Неверный или уже использованный код подтверждения',
            ]], 422);
            return;
        }

        $recoveryCodes = $service->generateRecoveryCodes();
        DatabaseManager::getInstance()->execute(
            'UPDATE users SET totp_recovery_codes = :codes, updated_at = :updated_at WHERE id = :id',
            [
                ':codes' => $service->hashRecoveryCodes($recoveryCodes),
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => (int) $user->id,
            ]
        );
        $request->setSession('two_factor_recovery_codes', $recoveryCodes);
        $this->rotateAuthenticatedSession($request);

        SecurityEventLog::emit(
            'auth.two_factor_recovery_codes_regenerated',
            'warning',
            'auth',
            'user',
            (int) $user->id,
            [
                'client_ip' => RequestOrigin::clientIp($_SERVER),
                'used_recovery_code' => (bool) $verification['used_recovery'],
            ]
        );
        Router::getInstance()->redirect('profile', 'name');
    }

    public function disableTwoFactor(Request $request): void
    {
        $user = $this->currentUser($request, true);
        $password = (string) $request->rawPost('current_password', '');
        $code = trim((string) $request->rawPost('code', ''));

        if (
            (int) ($user->totp_enabled ?? 0) !== 1
            || !CryptMethods::verifyPassword($password, (string) $user->password_hash)
        ) {
            $this->renderProfile($user, [[
                'CODE' => 'two_factor_disable_denied',
                'MESSAGE' => 'Не удалось подтвердить учётную запись',
            ]], 422);
            return;
        }

        $service = new TwoFactorService();
        $verification = $service->verifyAndConsume(DatabaseManager::getInstance(), (int) $user->id, $code);
        if (!$verification['ok']) {
            $this->renderProfile($user, [[
                'CODE' => 'two_factor_code_invalid',
                'MESSAGE' => 'Неверный или уже использованный код подтверждения',
            ]], 422);
            return;
        }

        DatabaseManager::getInstance()->execute(
            'UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_last_counter = NULL, '
            . 'totp_recovery_codes = NULL, totp_confirmed_at = NULL, updated_at = :updated_at '
            . 'WHERE id = :id',
            [
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => (int) $user->id,
            ]
        );
        $request->unsetSession('two_factor_enrollment');
        $request->unsetSession('two_factor_recovery_codes');
        $this->rotateAuthenticatedSession($request);

        SecurityEventLog::emit(
            'auth.two_factor_disabled',
            'warning',
            'auth',
            'user',
            (int) $user->id,
            [
                'client_ip' => RequestOrigin::clientIp($_SERVER),
                'used_recovery_code' => (bool) $verification['used_recovery'],
            ]
        );
        Router::getInstance()->redirect('profile', 'name');
    }

    public function changeUserPass(Request $request): void
    {
        $user = $this->currentUser($request, true);
        $newPassword = (string) $request->rawPost('new-password', '');
        $repeatPassword = (string) $request->rawPost('repeat-new-password', '');
        $oldPassword = (string) $request->rawPost('old-password', '');

        $errors = [];
        if (!CryptMethods::verifyPassword($oldPassword, (string) $user->password_hash)) {
            $errors[] = ['CODE' => 'current_password_invalid', 'MESSAGE' => 'Неверный текущий пароль'];
        } elseif ($newPassword !== $repeatPassword) {
            $errors[] = ['CODE' => 'password_mismatch', 'MESSAGE' => 'Новые пароли не совпадают'];
        } elseif (strlen($newPassword) < 10) {
            $errors[] = ['CODE' => 'password_too_short', 'MESSAGE' => 'Пароль должен быть не менее 10 символов'];
        } elseif ($oldPassword === $newPassword) {
            $errors[] = ['CODE' => 'password_not_changed', 'MESSAGE' => 'Новый пароль должен отличаться от текущего'];
        }

        if ($errors !== []) {
            $this->renderProfile($user, $errors, 422);
            return;
        }

        DatabaseManager::getInstance()->execute(
            'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at
             WHERE id = :id AND is_active = 1 AND account_status = \'active\'',
            [
                ':password_hash' => CryptMethods::hashPassword($newPassword),
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => (int) $user->id,
            ]
        );

        $this->invalidateSession($request);
        Router::getInstance()->redirect('authpage');
    }

    public function removeAvatar(Request $request): void
    {
        $user = $this->currentUser($request);
        $db = DatabaseManager::getInstance();

        (new UserAvatarService($db))->removeStoredAvatar($user);
        $db->execute(
            'UPDATE users SET avatar = NULL, updated_at = :updated_at '
            . 'WHERE id = :id AND is_active = 1 AND account_status = \'active\'',
            [':updated_at' => date('Y-m-d H:i:s'), ':id' => (int) $user->id]
        );

        Router::getInstance()->redirect('profile');
    }

    public function deleteUser(Request $request): void
    {
        $user = $this->currentUser($request, true);
        $confirmation = (string) $request->post('confirm_delete', '');
        $password = (string) $request->rawPost('current_password', '');

        if ($confirmation !== 'yes' || !CryptMethods::verifyPassword($password, (string) $user->password_hash)) {
            $this->renderProfile($user, [[
                'CODE' => 'deactivate_not_confirmed',
                'MESSAGE' => 'Для деактивации подтвердите действие текущим паролем',
            ]], 422);
            return;
        }

        $blocker = $this->accountDeactivationBlocker((int) $user->id);
        if ($blocker !== null) {
            $this->renderProfile($user, [[
                'CODE' => $blocker['code'],
                'MESSAGE' => $blocker['message'],
            ]], 409);
            return;
        }

        $db = DatabaseManager::getInstance();
        $db->execute(
            'UPDATE users
             SET is_active = 0, account_status = \'inactive\', avatar = NULL, updated_at = :updated_at
             WHERE id = :id AND is_active = 1 AND account_status = \'active\'',
            [
                ':updated_at' => date('Y-m-d H:i:s'),
                ':id' => (int) $user->id,
            ]
        );

        (new UserAvatarService($db))->removeStoredAvatar($user);
        $this->invalidateSession($request);
        Router::getInstance()->redirect('authpage');
    }

    public function avatar(Request $request, string $uid): void
    {
        (new UserAvatarService(DatabaseManager::getInstance()))->stream($uid);
    }

    private function currentUser(Request $request, bool $withPassword = false): object
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            throw new DomainException('Пользователь не найден');
        }

        $columns = $withPassword
            ? 'id,uid,username,email,password_hash,firstname,patronymic,lastname,phone,avatar,property,role,is_active,account_status,totp_enabled,totp_confirmed_at,created_at,updated_at'
            : 'id,uid,username,email,firstname,patronymic,lastname,phone,avatar,property,role,is_active,account_status,totp_enabled,totp_confirmed_at,created_at,updated_at';

        $user = DatabaseManager::getInstance()->fetchOne(
            "SELECT {$columns} FROM users WHERE id = :id AND is_active = 1 AND account_status = 'active' LIMIT 1",
            [':id' => $userId]
        );
        if (!$user) {
            throw new DomainException('Пользователь не найден или заблокирован');
        }

        return (object) $user;
    }

    /** @return list<array{name:string,label:string,value:string}> */
    private function validatedCustomFields(mixed $submitted): array
    {
        $submitted = is_array($submitted) ? $submitted : [];
        $definitions = DatabaseManager::getInstance()->fetchAll(
            'SELECT field_name,field_type,field_label,is_required FROM user_fields ORDER BY id ASC'
        );

        $result = [];
        foreach ($definitions as $field) {
            $name = (string) $field['field_name'];
            $raw = $submitted[$name]['value'] ?? '';
            $value = is_scalar($raw) ? trim((string) $raw) : '';

            if ((int) $field['is_required'] === 1 && $value === '') {
                throw new InvalidArgumentException('Поле «' . (string) $field['field_label'] . '» обязательно');
            }
            if ($value === '') {
                continue;
            }

            $value = $this->validateCustomValue((string) $field['field_type'], $value, (string) $field['field_label']);
            $result[] = [
                'label' => (string) $field['field_label'],
                'name' => $name,
                'value' => $value,
            ];
        }

        return $result;
    }

    private function validateCustomValue(string $type, string $value, string $label): string
    {
        return match ($type) {
            'number' => is_numeric($value) && mb_strlen($value) <= 128
                ? $value
                : throw new InvalidArgumentException('Поле «' . $label . '» должно быть числом'),
            'date' => $this->validDate($value)
                ? $value
                : throw new InvalidArgumentException('Поле «' . $label . '» содержит некорректную дату'),
            'checkbox' => in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true) ? '1' : '0',
            'textarea' => mb_strlen($value) <= 5000
                ? $value
                : throw new InvalidArgumentException('Поле «' . $label . '» слишком длинное'),
            default => mb_strlen($value) <= 500
                ? $value
                : throw new InvalidArgumentException('Поле «' . $label . '» слишком длинное'),
        };
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }

    private function personName(string $value, string $label): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '' || mb_strlen($value) > 80) {
            throw new InvalidArgumentException($label . ' должно содержать от 1 до 80 символов');
        }

        return $value;
    }

    private function phoneOrNull(string $phone): ?string
    {
        $phone = preg_replace('/[^0-9+]/', '', trim($phone)) ?: '';
        if ($phone === '') {
            return null;
        }
        if (preg_match('/^\+?[0-9]{10,15}$/', $phone) !== 1) {
            throw new InvalidArgumentException('Некорректный формат телефона');
        }

        return $phone;
    }

    /** @param list<array{CODE:string,MESSAGE:string}> $errors */
    private function renderProfile(
        object $user,
        array $errors = [],
        int $status = 200,
        ?array $twoFactorEnrollment = null,
        array $twoFactorRecoveryCodes = []
    ): void
    {
        if ($status !== 200) {
            http_response_code($status);
        }

        $db = DatabaseManager::getInstance();
        $fields = $db->fetchAll(
            'SELECT id,field_name,field_type,field_label,is_required FROM user_fields ORDER BY id ASC'
        );
        $avatarUrl = !empty($user->avatar)
            ? '/profile/avatar/' . rawurlencode((string) $user->uid) . '?v=' . rawurlencode(substr(hash('sha256', (string) $user->avatar), 0, 12))
            : null;

        $this->render_template('profile_page/index', [
            'user' => $user,
            'fields' => $fields,
            'avatar_url' => $avatarUrl,
            'errors' => $errors,
            'publication_items' => (new ProfilePublicationService())->ownerItems((int) $user->id),
            'two_factor_enrollment' => $twoFactorEnrollment,
            'two_factor_recovery_codes' => $twoFactorRecoveryCodes,
        ]);
    }

    /**
     * @return array{secret:string,encrypted_secret:string,uri:string}|null
     */
    private function twoFactorEnrollment(Request $request, object $user): ?array
    {
        $state = $request->session('two_factor_enrollment');
        if (!is_array($state)) {
            return null;
        }

        $startedAt = (int) ($state['started_at'] ?? 0);
        $encryptedSecret = (string) ($state['secret'] ?? '');
        if (
            (int) ($state['user_id'] ?? 0) !== (int) $user->id
            || $startedAt <= 0
            || (time() - $startedAt) > 600
            || $encryptedSecret === ''
        ) {
            $request->unsetSession('two_factor_enrollment');
            return null;
        }

        try {
            $service = new TwoFactorService();
            $secret = $service->decryptSecret($encryptedSecret, (string) $user->uid);
            return [
                'secret' => $secret,
                'encrypted_secret' => $encryptedSecret,
                'uri' => $service->provisioningUri(
                    (string) $user->username,
                    'Workspace Organizer',
                    $secret
                ),
            ];
        } catch (\Throwable $e) {
            $request->unsetSession('two_factor_enrollment');
            error_log('Two-factor enrollment state could not be decrypted: ' . $e->getMessage());
            return null;
        }
    }

    private function rotateAuthenticatedSession(Request $request): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !session_regenerate_id(true)) {
            throw new \RuntimeException('Не удалось обновить идентификатор сессии');
        }
        $request->setSession('_csrf_token', bin2hex(random_bytes(32)));
        SessionSecurity::refreshCurrentSessionCookie();
    }

    private function noStoreSensitivePage(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /** @return array{code:string,message:string}|null */
    private function accountDeactivationBlocker(int $userId): ?array
    {
        if (!ModuleRuntimeLoader::isBooted()) {
            return null;
        }

        $capabilities = ModuleRuntimeLoader::getInstance()->capabilities();
        if (!$capabilities->has('workspace.messenger')) {
            return null;
        }

        $guard = $capabilities->require('workspace.messenger', AccountDeactivationGuard::class);
        if (!$guard instanceof AccountDeactivationGuard) {
            throw new \RuntimeException('Messenger capability does not implement account deactivation guard');
        }

        return $guard->accountDeactivationBlocker($userId);
    }

    private function invalidateSession(Request $request): void
    {
        $request->unsetSession('auth');
        $request->unsetSession('user_id');
        $request->unsetSession('user_uid');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}
