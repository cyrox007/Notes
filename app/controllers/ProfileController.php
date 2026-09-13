<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\CryptMethods;
use App\Services\UserAvatarService;
use Core\Config;
use Core\Controller;
use Core\DatabaseManager;
use Core\Request;
use Core\Router;
use DomainException;
use InvalidArgumentException;

final class ProfileController extends Controller
{
    public function index(Request $request): void
    {
        $this->renderProfile($this->currentUser($request));
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
                 WHERE id = :id AND is_active = 1',
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

    public function changeUserPass(Request $request): void
    {
        $user = $this->currentUser($request, true);
        $newPassword = (string) $request->post('new-password', '');
        $repeatPassword = (string) $request->post('repeat-new-password', '');
        $oldPassword = (string) $request->post('old-password', '');

        $errors = [];
        if (!CryptMethods::verifyPassword($oldPassword, (string) $user->password_hash)) {
            $errors[] = ['CODE' => 'current_password_invalid', 'MESSAGE' => 'Неверный текущий пароль'];
        } elseif ($newPassword !== $repeatPassword) {
            $errors[] = ['CODE' => 'password_mismatch', 'MESSAGE' => 'Новые пароли не совпадают'];
        } elseif (strlen($newPassword) < 10) {
            $errors[] = ['CODE' => 'password_too_short', 'MESSAGE' => 'Пароль должен быть не менее 10 символов'];
        } elseif (hash_equals($oldPassword, $newPassword)) {
            $errors[] = ['CODE' => 'password_not_changed', 'MESSAGE' => 'Новый пароль должен отличаться от текущего'];
        }

        if ($errors !== []) {
            $this->renderProfile($user, $errors, 422);
            return;
        }

        DatabaseManager::getInstance()->execute(
            'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at
             WHERE id = :id AND is_active = 1',
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
            'UPDATE users SET avatar = NULL, updated_at = :updated_at WHERE id = :id AND is_active = 1',
            [':updated_at' => date('Y-m-d H:i:s'), ':id' => (int) $user->id]
        );

        Router::getInstance()->redirect('profile');
    }

    public function deleteUser(Request $request): void
    {
        $user = $this->currentUser($request, true);
        $confirmation = (string) $request->post('confirm_delete', '');
        $password = (string) $request->post('current_password', '');

        if ($confirmation !== 'yes' || !CryptMethods::verifyPassword($password, (string) $user->password_hash)) {
            $this->renderProfile($user, [[
                'CODE' => 'deactivate_not_confirmed',
                'MESSAGE' => 'Для деактивации подтвердите действие текущим паролем',
            ]], 422);
            return;
        }

        $db = DatabaseManager::getInstance();
        $ownedGroup = $db->fetchOne(
            "SELECT d.uid, COALESCE(NULLIF(d.name, ''), 'Без названия') AS name
             FROM user_to_dialogs utd
             JOIN dialogs d ON d.id = utd.dialog_id
             WHERE utd.user_id = :user_id
               AND utd.role = 'owner'
               AND utd.is_deleted = 0
               AND d.type = 'group'
             LIMIT 1",
            [':user_id' => (int) $user->id]
        );
        if ($ownedGroup !== null) {
            $this->renderProfile($user, [[
                'CODE' => 'group_owner_transfer_required',
                'MESSAGE' => 'Перед деактивацией передайте владение группой «' . (string) $ownedGroup['name'] . '» другому участнику.',
            ]], 409);
            return;
        }

        $db->execute(
            'UPDATE users
             SET is_active = 0, role = :inactive_role, avatar = NULL, updated_at = :updated_at
             WHERE id = :id AND is_active = 1',
            [
                ':inactive_role' => Config::USER_ROLE_INACTIVE,
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
        new UserAvatarService(DatabaseManager::getInstance())->stream($uid);
    }

    private function currentUser(Request $request, bool $withPassword = false): object
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            throw new DomainException('Пользователь не найден');
        }

        $columns = $withPassword
            ? 'id,uid,username,email,password_hash,firstname,patronymic,lastname,phone,avatar,property,role,is_active,created_at,updated_at'
            : 'id,uid,username,email,firstname,patronymic,lastname,phone,avatar,property,role,is_active,created_at,updated_at';

        $user = DatabaseManager::getInstance()->fetchOne(
            'SELECT ' . $columns . ' FROM users WHERE id = :id AND is_active = 1 LIMIT 1',
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
    private function renderProfile(object $user, array $errors = [], int $status = 200): void
    {
        if ($status !== 200) {
            http_response_code($status);
        }

        $fields = DatabaseManager::getInstance()->fetchAll(
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
        ]);
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
