<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\CryptMethods;
use App\Models\FieldModel;
use App\Models\NoteModel;
use App\Models\UserModel;
use Core\Controller;
use Core\DatabaseManager;
use Core\Images;
use Core\Request;
use Core\Router;

final class ProfileController extends Controller
{
    public function index(Request $request): void
    {
        $user = $this->currentUser($request);
        $this->render_template('profile_page/index', [
            'user' => $user,
            'notes' => NoteModel::select()->where('user_id', '=', $user->id)->get(),
            'fields' => FieldModel::select()->get(),
        ]);
    }

    public function update(Request $request): void
    {
        $user = $this->currentUser($request);
        $post = $request->post();

        $email = filter_var((string) ($post['set-user-email'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            throw new \InvalidArgumentException('Некорректный формат email адреса');
        }

        $phone = preg_replace('/[^0-9+]/', '', (string) ($post['set-user-phone'] ?? '')) ?: '';
        if ($phone !== '' && strlen($phone) < 10) {
            throw new \InvalidArgumentException('Некорректный формат телефона');
        }

        $update = [
            'firstname' => trim((string) ($post['set-user-name'] ?? '')),
            'patronymic' => trim((string) ($post['set-user-patronymic'] ?? '')) ?: null,
            'lastname' => trim((string) ($post['set-user-surname'] ?? '')),
            'phone' => $phone !== '' ? $phone : null,
            'email' => $email,
            'property' => json_encode($this->customFields($post['custom'] ?? []), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($avatar = $this->handleAvatarUpload($user)) {
            $update['avatar'] = $avatar;
        }

        DatabaseManager::getInstance()->queueUpdate($update, 'users', (int) $user->id);
        DatabaseManager::getInstance()->commit();
        Router::getInstance()->redirect('profile');
    }

    public function changeUserPass(Request $request): void
    {
        $user = $this->currentUser($request);
        $newPassword = (string) $request->post('new-password');
        $repeatPassword = (string) $request->post('repeat-new-password');
        $oldPassword = (string) $request->post('old-password');

        $errors = [];
        if ($newPassword !== $repeatPassword) {
            $errors[] = ['CODE' => 'password_mismatch', 'MESSAGE' => 'Новые пароли не совпадают'];
        } elseif (strlen($newPassword) < 8) {
            $errors[] = ['CODE' => 'password_too_short', 'MESSAGE' => 'Пароль должен быть не менее 8 символов'];
        } elseif (!CryptMethods::verifyPassword($oldPassword, $user->password_hash)) {
            $errors[] = ['CODE' => 'login_error', 'MESSAGE' => 'Неверный текущий пароль'];
        }

        if ($errors !== []) {
            $this->render_template('profile_page/index', [
                'user' => $user,
                'notes' => NoteModel::select()->where('user_id', '=', $user->id)->get(),
                'fields' => FieldModel::select()->get(),
                'errors' => $errors,
            ]);
            return;
        }

        DatabaseManager::getInstance()->queueUpdate([
            'password_hash' => CryptMethods::hashPassword($newPassword),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'users', (int) $user->id);
        DatabaseManager::getInstance()->commit();

        $request->unsetSession('auth');
        $request->unsetSession('user_id');
        $request->unsetSession('user_uid');
        session_regenerate_id(true);
        Router::getInstance()->redirect('authpage');
    }

    public function deleteUser(Request $request): void
    {
        $user = $this->currentUser($request);
        if ((string) $request->post('confirm_delete') !== 'yes') {
            $this->render_template('profile_page/index', [
                'user' => $user,
                'notes' => NoteModel::select()->where('user_id', '=', $user->id)->get(),
                'fields' => FieldModel::select()->get(),
                'errors' => [[
                    'CODE' => 'delete_not_confirmed',
                    'MESSAGE' => 'Удаление аккаунта не подтверждено',
                ]],
            ]);
            return;
        }

        DatabaseManager::getInstance()->queueDelete('users', (int) $user->id);
        DatabaseManager::getInstance()->commit();
        $request->unsetSession('auth');
        $request->unsetSession('user_id');
        $request->unsetSession('user_uid');
        session_regenerate_id(true);
        Router::getInstance()->redirect('authpage');
    }

    private function currentUser(Request $request): UserModel
    {
        $user = UserModel::select()->where('id', '=', (int) $request->session('user_id'))->first();
        if (!$user) {
            throw new \DomainException('Пользователь не найден');
        }
        return $user;
    }

    private function customFields(mixed $custom): array
    {
        if (!is_array($custom)) {
            return [];
        }
        $result = [];
        foreach ($custom as $name => $data) {
            if (!is_array($data) || !isset($data['label'], $data['value'])) {
                continue;
            }
            $result[] = [
                'label' => (string) $data['label'],
                'name' => (string) $name,
                'value' => (string) $data['value'],
            ];
        }
        return $result;
    }

    private function handleAvatarUpload(UserModel $user): ?string
    {
        if (empty($_FILES['set-user-avatar']['tmp_name'])) {
            return null;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($_FILES['set-user-avatar']['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \InvalidArgumentException('Неподдерживаемый формат аватара');
        }

        $image = Images::loadImage($_FILES['set-user-avatar']['tmp_name'])->processImage(150, 150);
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'webp',
        };
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        $relative = '/uploads/users/' . (int) $user->id . '/avatars/' . $filename;
        $savePath = SITEPATH . $relative;
        $directory = dirname($savePath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог аватара');
        }

        $image->saveImage($savePath);
        return $relative;
    }
}
