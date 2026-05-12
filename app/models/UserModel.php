<?php
namespace App\Models;

use Core\ORM;

class UserModel extends ORM {
    protected ?string $_tablename = "users";
    
    public int $id = 0;
    public string $username = '';
    public string $email = '';
    public string $password_hash = '';
    public string $firstname = '';
    public string $lastname = '';
    public string $avatar = '';
    public int $role = 0;
    public int $is_active = 0;
    public string $created_at = '';
    public string $updated_at = '';
}