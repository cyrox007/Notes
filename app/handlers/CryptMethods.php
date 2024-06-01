<?php
namespace App\Helpers;

class CryptMethods {
    public static function createHashFromPassword(string $password) {
        $salt = "secretKEY";
        $inputBytes =  mb_convert_encoding($password, 'UTF-8', 'auto');
        $saltBytes = mb_convert_encoding($salt, 'UTF-8', 'auto');
        $data = $inputBytes . $saltBytes;
        $sha256 = hash('sha256', $data, true);
        return base64_encode($sha256);
    }
}