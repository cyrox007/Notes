<?php

namespace App\Models;

use Core\ORM;

class UserNModel extends ORM {
    protected $_tablename = "users";
    
    public $id;
    protected $uid;
    public string $username;
    public string $email;
    public string $password;
    public string $firstname;
    public string $surname;
    public string $patronymic;
    public string $phone;
    public string $property;
    public int $role;
    public string $reg_date;
    public string $service_token;
    public string $user_status;
    public string $user_image;
    public int $socket_connection_id;

    
}