<?php

declare(strict_types=1);

namespace Core;

use App\Middlewares\CSRFMiddleware;
use App\Services\LicenseRuntimePolicy;
use App\Services\PermissionService;

/**
 * Базовый HTTP-контроллер.
 *
 * Начиная с 1.0 представления приложения формируются только внутренним
 * NativeViewRenderer. Запасного пути через Smarty или старый renderer нет.
 */
class Controller
{
    protected Request $request;
    protected ViewRenderer $renderer;
    protected ViewContext $viewContext;

    public function __construct()
    {
        ob_start();
        $this->request = new Request();
        $this->viewContext = new ViewContext($this->request);
        $moduleViewRoots = ModuleRuntimeLoader::isBooted()
            ? ModuleRuntimeLoader::getInstance()->viewRoots()
            : [];
        $this->renderer = new NativeViewRenderer(
            SITEPATH . '/app/views',
            $this->viewContext,
            $moduleViewRoots,
        );

        // Проверка CSRF остаётся общей для изменяющих HTTP-запросов и не
        // зависит от механизма формирования представления.
        (new CSRFMiddleware())->handle();
    }

    public function __destruct()
    {
        $output = ob_get_clean();
        if ($output !== '' && $output !== false) {
            if (is_string($output)) {
                echo $output;
            } elseif (is_array($output) || is_object($output)) {
                $this->responseJson((array) $output);
            }
        }
    }

    /**
     * Совместимый публичный метод для старых вызовов и проверок. Формирование
     * маршрута при этом принадлежит ViewContext.
     *
     * @param array<string,mixed> $params
     */
    public function getRoutePath(array $params): string
    {
        return $this->viewContext->routePath($params);
    }

    public function getCSRFInputTag(): string
    {
        return $this->viewContext->csrfInput();
    }

    /** @param array<string,mixed> $params */
    public function getSession(array $params): ?string
    {
        return $this->viewContext->sessionPlugin($params);
    }

    /**
     * Сформировать логическое представление через внутренний PHP-renderer.
     * Представления изолированных модулей используют пространство
     * `@module-id/path` и разрешаются только из корней, зарегистрированных
     * активным ModuleRuntimeLoader.
     *
     * `base_url` намеренно хранит same-origin префикс пути, а не SITEURL.
     * Статические ресурсы и внутренние ссылки должны наследовать протокол и
     * адрес фактического HTTP-запроса. SITEURL остаётся каноническим адресом
     * установки только для сервисов, которым он действительно нужен.
     *
     * @param array<string,mixed>|null $data
     */
    protected function render_template(string $template, ?array $data = null): void
    {
        $basePathSegment = trim((string) getenv('BASE_PATH'), '/');
        $basePath = $basePathSegment !== '' ? '/' . $basePathSegment : '';
        $baseUrl = $basePath;
        $workspaceAccess = $this->workspaceAccess();
        $socketUrl = '';
        if (!empty($workspaceAccess['messenger'])) {
            try {
                $socketUrl = WebSocketEndpoint::browserUrl();
            } catch (\Throwable $e) {
                error_log('Global Messenger endpoint is unavailable: ' . $e->getMessage());
            }
        }

        $viewData = [
            'base_url' => $baseUrl,
            'base_path' => $basePath,
            'sitename' => getenv('SITENAME') ?: 'Workspace Organizer',
            'version' => Version::VERSION,
            'product_name' => Version::PRODUCT_NAME,
            'workspaceAccess' => $workspaceAccess,
            'licenseRuntime' => $this->licenseRuntimeState($workspaceAccess),
            'socket_url' => $socketUrl,
        ];

        if ($data !== null) {
            $normalized = $this->convertObjectsToArray($data);
            if (is_array($normalized)) {
                // Сохраняем прежний контракт контроллера: явно переданные
                // переменные представления могут переопределять общие значения.
                $viewData = array_merge($viewData, $normalized);
            }
        }

        $this->renderer->render($template, $viewData);
    }

    /** @return array{notes:bool,tasks:bool,files:bool,messenger:bool,profile:bool,admin:bool,admin_audit:bool,license_manage:bool} */
    private function workspaceAccess(): array
    {
        $access = [
            'notes' => false,
            'tasks' => false,
            'files' => false,
            'messenger' => false,
            'profile' => false,
            'admin' => false,
            'admin_audit' => false,
            'license_manage' => false,
        ];

        $viewerId = (int) $this->request->session('user_id', 0);
        if ($viewerId <= 0) {
            return $access;
        }

        try {
            $permissions = (new PermissionService())->permissionsForUser($viewerId);
            return [
                'notes' => in_array('notes.use', $permissions, true),
                'tasks' => in_array('tasks.use', $permissions, true),
                'files' => in_array('files.use', $permissions, true),
                'messenger' => in_array('messenger.use', $permissions, true),
                'profile' => in_array('profile.use', $permissions, true),
                'admin' => in_array('admin.access', $permissions, true),
                'admin_audit' => in_array('admin.audit.view', $permissions, true),
                'license_manage' => in_array('admin.settings.manage', $permissions, true),
            ];
        } catch (\Throwable $e) {
            error_log('Navigation RBAC evaluation failed: ' . $e->getMessage());
            return $access;
        }
    }

    /**
     * @param array{notes:bool,tasks:bool,files:bool,messenger:bool,profile:bool,admin:bool,admin_audit:bool,license_manage:bool} $access
     * @return array{enforced:bool,writable:bool,code:string,message:string,can_manage:bool}
     */
    private function licenseRuntimeState(array $access): array
    {
        if ((int) $this->request->session('user_id', 0) <= 0) {
            return [
                'enforced' => false,
                'writable' => true,
                'code' => 'anonymous',
                'message' => '',
                'can_manage' => false,
            ];
        }

        $state = (new LicenseRuntimePolicy())->state();
        $state['can_manage'] = (bool) ($access['license_manage'] ?? false);
        return $state;
    }

    private function convertObjectsToArray(mixed $data): mixed
    {
        if (is_object($data)) {
            $data = get_object_vars($data);
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->convertObjectsToArray($value);
            }
        }

        return $data;
    }

    /** @param array<string,mixed> $data */
    protected function responseJson(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
