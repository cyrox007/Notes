<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MessengerMediaService;
use App\Services\MessengerService;
use Core\Controller;
use Core\ModuleRuntimeLoader;
use Core\Request;
use Core\WorkspaceFileProvider;
use Core\WorkspaceNoteCreator;
use Core\WorkspaceTaskCreator;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class MessengerWorkspaceController extends Controller
{
    public function createNote(Request $request): void
    {
        $userId = $this->userId($request);

        try {
            $source = $this->sourceContext($request, $userId);
            $title = trim((string) $request->post('title', ''));
            $content = trim((string) $request->post('content', ''));

            if ($source !== null && $content === '') {
                $content = $this->sourceText($source);
            }
            if ($title === '') {
                $title = $this->suggestTitle($content !== '' ? $content : ($source !== null ? $this->sourceText($source) : ''), 'Новая заметка');
            }
            if ($source !== null) {
                $content = $this->appendSourceReference($content, $source);
            }

            $registry = ModuleRuntimeLoader::getInstance()->capabilities();
            if (!$registry->has('workspace.notes')) {
                throw new DomainException('Модуль заметок недоступен', 409);
            }
            $creator = $registry->require('workspace.notes', WorkspaceNoteCreator::class);
            /** @var WorkspaceNoteCreator $creator */
            $note = $creator->createWorkspaceNote($userId, $title, $content);

            $this->responseJson([
                'success' => true,
                'kind' => 'note',
                'message' => 'Заметка создана',
                'entity' => $note,
                'open_url' => $this->viewContext->route('edit_page', ['uid' => $note['uid']]),
            ]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), $this->statusFrom($e, 403));
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('Messenger workspace note create failed: ' . $e->getMessage());
            $this->jsonError('Не удалось создать заметку', 500);
        }
    }

    public function createTask(Request $request): void
    {
        $userId = $this->userId($request);

        try {
            $source = $this->sourceContext($request, $userId);
            $title = trim((string) $request->post('title', ''));
            $description = trim((string) $request->post('description', ''));
            $priority = trim((string) $request->post('priority', 'medium'));
            $dueDateRaw = trim((string) $request->post('due_date', ''));
            $dueDate = $dueDateRaw !== '' ? $dueDateRaw : null;

            if ($source !== null && $description === '') {
                $description = $this->sourceText($source);
            }
            if ($title === '') {
                $title = $this->suggestTitle(
                    $description !== '' ? $description : ($source !== null ? $this->sourceText($source) : ''),
                    'Новая задача'
                );
            }
            if ($source !== null) {
                $description = $this->appendSourceReference($description, $source);
            }

            $registry = ModuleRuntimeLoader::getInstance()->capabilities();
            if (!$registry->has('workspace.tasks')) {
                throw new DomainException('Модуль задач недоступен', 409);
            }
            $creator = $registry->require('workspace.tasks', WorkspaceTaskCreator::class);
            /** @var WorkspaceTaskCreator $creator */
            $task = $creator->createWorkspaceTask($userId, $title, $description, $priority, $dueDate);

            $openUrl = $this->viewContext->route('tasks');
            if ($openUrl !== '') {
                $openUrl .= (str_contains($openUrl, '?') ? '&' : '?') . http_build_query(['q' => $task['title']]);
            }

            $this->responseJson([
                'success' => true,
                'kind' => 'task',
                'message' => 'Задача создана',
                'entity' => $task,
                'open_url' => $openUrl,
            ]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), $this->statusFrom($e, 403));
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('Messenger workspace task create failed: ' . $e->getMessage());
            $this->jsonError('Не удалось создать задачу', 500);
        }
    }

    public function files(Request $request): void
    {
        try {
            $userId = $this->userId($request);
            $query = trim((string) $request->get('q', ''));
            $provider = $this->fileProvider();
            $this->responseJson([
                'success' => true,
                'files' => $provider->listWorkspaceFiles($userId, $query, 100),
                'can_share' => $provider->canShareWorkspaceFiles($userId),
            ]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), $this->statusFrom($e, 403));
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('Messenger workspace file list failed: ' . $e->getMessage());
            $this->jsonError('Не удалось загрузить личное хранилище', 500);
        }
    }

    public function attachFile(Request $request): void
    {
        try {
            $userId = $this->userId($request);
            $dialogUid = trim((string) $request->post('dialog_uid', ''));
            $fileUid = trim((string) $request->post('file_uid', ''));
            if ($dialogUid === '' || $fileUid === '') {
                throw new InvalidArgumentException('Не выбран диалог или файл');
            }

            $file = $this->fileProvider()->exportWorkspaceFile($userId, $fileUid);
            $attachment = (new MessengerMediaService())->importWorkspaceFile($userId, $dialogUid, $file);

            $this->responseJson([
                'success' => true,
                'attachment' => $attachment,
            ]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), $this->statusFrom($e, 403));
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('Messenger workspace file attach failed: ' . $e->getMessage());
            $this->jsonError('Не удалось подготовить файл для отправки', 500);
        }
    }

    public function shareFile(Request $request): void
    {
        try {
            $userId = $this->userId($request);
            $fileUid = trim((string) $request->post('file_uid', ''));
            $expiresHours = (int) $request->post('expires_hours', 0);
            if ($fileUid === '') {
                throw new InvalidArgumentException('Файл не выбран');
            }
            if ($expiresHours < 0 || $expiresHours > 8760) {
                throw new InvalidArgumentException('Некорректный срок действия ссылки');
            }

            $share = $this->fileProvider()->createWorkspaceFileShare($userId, $fileUid, $expiresHours);
            $this->responseJson([
                'success' => true,
                'share_url' => $this->viewContext->route('files_shared', ['token' => $share['token']]),
                'expires_at' => $share['expires_at'],
                'file' => $share['file'],
            ]);
        } catch (DomainException $e) {
            $this->jsonError($e->getMessage(), $this->statusFrom($e, 403));
        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('Messenger workspace file share failed: ' . $e->getMessage());
            $this->jsonError('Не удалось создать ссылку на файл', 500);
        }
    }

    private function fileProvider(): WorkspaceFileProvider
    {
        $registry = ModuleRuntimeLoader::getInstance()->capabilities();
        if (!$registry->has('workspace.files')) {
            throw new DomainException('Модуль файлов недоступен', 409);
        }
        /** @var WorkspaceFileProvider $provider */
        $provider = $registry->require('workspace.files', WorkspaceFileProvider::class);
        return $provider;
    }

    private function userId(Request $request): int
    {
        $userId = (int) $request->session('user_id', 0);
        if ($userId <= 0) {
            throw new DomainException('Требуется авторизация', 401);
        }
        return $userId;
    }

    /** @return array{dialog:array<string,mixed>,message:array<string,mixed>}|null */
    private function sourceContext(Request $request, int $userId): ?array
    {
        $dialogUid = trim((string) $request->post('dialog_uid', ''));
        $messageUid = trim((string) $request->post('message_uid', ''));

        if ($dialogUid === '' && $messageUid === '') {
            return null;
        }
        if ($dialogUid === '' || $messageUid === '') {
            throw new InvalidArgumentException('Источник сообщения указан не полностью');
        }

        return (new MessengerService())->messageForWorkspaceAction($userId, $dialogUid, $messageUid);
    }

    /** @param array{dialog:array<string,mixed>,message:array<string,mixed>} $source */
    private function sourceText(array $source): string
    {
        $message = $source['message'];
        $text = trim((string) ($message['message'] ?? ''));
        if ($text !== '') {
            return $text;
        }

        return match ((string) ($message['message_type'] ?? 'text')) {
            'voice' => 'Голосовое сообщение',
            'image' => 'Изображение',
            'audio' => 'Аудиозапись',
            'video' => 'Видео',
            'file' => 'Файл',
            default => 'Сообщение из Messenger',
        };
    }

    /** @param array{dialog:array<string,mixed>,message:array<string,mixed>} $source */
    private function appendSourceReference(string $body, array $source): string
    {
        $message = $source['message'];
        $dialog = $source['dialog'];
        $user = is_array($message['user'] ?? null) ? $message['user'] : [];
        $author = trim((string) ($user['firstname'] ?? '') . ' ' . (string) ($user['lastname'] ?? ''));
        if ($author === '') {
            $author = (string) ($user['username'] ?? 'Пользователь');
        }

        $messengerUrl = $this->viewContext->route('messenger');
        $messengerUrl .= (str_contains($messengerUrl, '?') ? '&' : '?') . http_build_query([
            'dialog' => (string) ($dialog['uid'] ?? ''),
            'message' => (string) ($message['uid'] ?? ''),
        ]);

        $reference = implode("\n", [
            'Источник: Messenger',
            'Диалог: ' . (string) ($dialog['title'] ?? $dialog['name'] ?? 'Диалог'),
            'Автор: ' . $author,
            'Дата: ' . (string) ($message['created_at'] ?? ''),
            $messengerUrl,
        ]);

        $body = rtrim($body);
        return ($body !== '' ? $body . "\n\n---\n" : '') . $reference;
    }

    private function suggestTitle(string $value, string $fallback): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return $fallback;
        }

        return mb_strlen($value) > 90 ? mb_substr($value, 0, 89) . '…' : $value;
    }

    private function statusFrom(DomainException $e, int $fallback): int
    {
        $code = (int) $e->getCode();
        return $code >= 400 && $code <= 599 ? $code : $fallback;
    }

    private function jsonError(string $message, int $status): void
    {
        http_response_code($status);
        $this->responseJson([
            'success' => false,
            'message' => $message,
        ]);
    }
}
