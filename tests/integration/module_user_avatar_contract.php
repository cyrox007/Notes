<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function userAvatarContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] module user avatar contract: {$message}\n");
        exit(1);
    }
}

$noteController = file_get_contents($root . '/modules/notes/controllers/NoteController.php');
$taskController = file_get_contents($root . '/modules/tasks/controllers/TaskController.php');
$header = file_get_contents($root . '/app/views/^shared/header/index.php');

userAvatarContractAssert(is_string($noteController), 'cannot read NoteController');
userAvatarContractAssert(is_string($taskController), 'cannot read TaskController');
userAvatarContractAssert(is_string($header), 'cannot read shared header');

userAvatarContractAssert(
    str_contains(
        $noteController,
        "UserModel::select('id', 'uid', 'username', 'firstname', 'lastname', 'avatar', 'role', 'is_active')"
    ),
    'Notes current user query must include avatar for the shared layout'
);

userAvatarContractAssert(
    str_contains($taskController, 'SELECT id,uid,username,firstname,lastname,avatar,role,is_active'),
    'Tasks current user query must include avatar for the shared layout'
);

userAvatarContractAssert(
    str_contains($header, "\$currentUser['avatar']")
        && str_contains($header, '/assets/img/default_avatar.png'),
    'shared header must render a configured avatar with a default fallback'
);

fwrite(STDOUT, "[OK] Notes/Tasks preserve current user avatar in shared layout context\n");
