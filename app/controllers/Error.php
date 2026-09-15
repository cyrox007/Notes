<?php

declare(strict_types=1);

namespace App\Controller;

use Core\Controller;

/**
 * Compatibility error controller retained for legacy callers.
 *
 * All rendering goes through the same native PHP view pipeline as the rest of
 * the application; the old Core\View/Smarty-style wrapper path is no longer used.
 */
final class Error extends Controller
{
    public function action_404(): void
    {
        http_response_code(404);
        $this->renderError('Страница не найдена', 'Запрошенная страница не существует или была перемещена.');
    }

    public function action_invate_error(): void
    {
        http_response_code(400);
        $this->renderError('Приглашение недействительно', 'Ссылка приглашения недействительна или больше не может быть использована.');
    }

    public function action_noteError(): void
    {
        http_response_code(404);
        $this->renderError('Заметка не найдена', 'Такой записи не существует или она больше недоступна.');
    }

    public function action_accessDenied(): void
    {
        http_response_code(403);
        $this->renderError('Доступ запрещён', 'У вашей учётной записи нет прав для просмотра этой страницы.');
    }

    private function renderError(string $title, string $message): void
    {
        header('Cache-Control: no-store');
        $this->render_template('error_page/index', [
            'title' => $title,
            'message' => $message,
        ]);
    }
}
