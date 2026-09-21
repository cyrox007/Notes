<?php

declare(strict_types=1);

namespace App\Models;

use Core\ORM;

class UserModel extends ORM
{
    protected ?string $_tablename = 'users';

    public int $id = 0;
    public string $uid = '';
    public string $username = '';
    public string $email = '';
    public string $password_hash = '';
    public string $firstname = '';
    public ?string $patronymic = null;
    public string $lastname = '';
    public ?string $phone = null;
    public ?string $avatar = null;
    public mixed $property = null;
    public int $role = 0;
    public int $is_active = 0;
    public string $account_status = 'active';
    public int $totp_enabled = 0;
    public ?string $totp_secret = null;
    public ?int $totp_last_counter = null;
    public mixed $totp_recovery_codes = null;
    public ?string $totp_confirmed_at = null;
    public string $created_at = '';
    public string $updated_at = '';
}
