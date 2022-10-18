<?php
class Helper {
    public function login_requared($user_session) {
        // принимает логин пользователя в сессии пока что а вернет его id
        if (!$user_session) {
            header("Location: /Auth/login");
        }
    }
}