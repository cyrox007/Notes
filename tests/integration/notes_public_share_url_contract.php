<?php

declare(strict_types=1);

// Проверка формирования публичной ссылки без веб-сервера и базы данных.
require_once dirname(__DIR__, 2) . '/core/Config.php';
require_once dirname(__DIR__, 2) . '/modules/notes/NotePublicUrl.php';

use Core\Config;
use Modules\Notes\NotePublicUrl;

function publicNoteUrlCheck(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "Нарушен контракт публичной ссылки заметки: {$message}\n");
        exit(1);
    }
}

$oldBasePath = getenv('BASE_PATH');
$oldSiteUrl = Config::get('SITEURL');
try {
    Config::set('SITEURL', 'https://notes.example.test');
    putenv('BASE_PATH');
    $url = NotePublicUrl::shared('token_123');
    publicNoteUrlCheck(
        $url === 'https://notes.example.test/notes/shared/token_123',
        'корневая установка должна включать домен и HTTPS'
    );

    Config::set('SITEURL', 'https://notes.example.test:8443');
    putenv('BASE_PATH=workspace');
    publicNoteUrlCheck(
        NotePublicUrl::shared('with space') === 'https://notes.example.test:8443/workspace/notes/shared/with%20space',
        'должны сохраняться порт и вложенный путь приложения'
    );

    Config::set('SITEURL', 'http://notes.local/notes-app');
    putenv('BASE_PATH');
    publicNoteUrlCheck(
        NotePublicUrl::shared('abc') === 'http://notes.local/notes-app/notes/shared/abc',
        'путь установки из SITEURL должен использоваться без двойного добавления'
    );

    Config::set('SITEURL', '/notes-app');
    $rejected = false;
    try {
        NotePublicUrl::shared('abc');
    } catch (\RuntimeException) {
        $rejected = true;
    }
    publicNoteUrlCheck($rejected, 'относительный SITEURL нельзя выдавать как публичную ссылку');

    $editor = file_get_contents(dirname(__DIR__, 2) . '/modules/notes/views/editor-013.php');
    $controller = file_get_contents(dirname(__DIR__, 2) . '/modules/notes/controllers/NoteShareController.php');
    publicNoteUrlCheck(
        is_string($editor) && str_contains($editor, 'NotePublicUrl::shared($shareToken)'),
        'отображаемая и копируемая ссылка должна использовать общий генератор'
    );
    publicNoteUrlCheck(
        is_string($controller) && str_contains($controller, 'NotePublicUrl::shared($token)'),
        'ответ создания ссылки должен использовать тот же генератор'
    );

    echo "Контракт публичных ссылок Notes 1.2 выполнен\n";
} finally {
    Config::set('SITEURL', $oldSiteUrl);
    if ($oldBasePath === false) {
        putenv('BASE_PATH');
    } else {
        putenv('BASE_PATH=' . $oldBasePath);
    }
}
