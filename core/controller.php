<?php

declare(strict_types=1);

namespace Core;

use App\Middlewares\CSRFMiddleware;
use App\Services\PermissionService;

/**
 * Base HTTP controller.
 *
 * From 1.0 onward application views are rendered exclusively by the internal
 * NativeViewRenderer. There is no Smarty/legacy fallback in the HTTP runtime.
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
        $this->renderer = new NativeViewRenderer(SITEPATH . '/app/views', $this->viewContext);

        // CSRF validation remains global for mutating HTTP requests and is
        // independent from the selected presentation engine.
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
     * Backward-compatible public helper retained for callers/tests while route
     * generation is owned by ViewContext.
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
     * Render a logical application view through the internal native PHP engine.
     *
     * @param array<string,mixed>|null $data
     */
    protected function render_template(string $template, ?array $data = null): void
    {
        $siteUrl = rtrim((string) getenv('SITEURL'), '/');
        $basePathSegment = trim((string) getenv('BASE_PATH'), '/');
        $basePath = $basePathSegment !== '' ? '/' . $basePathSegment : '';
        $baseUrl = $siteUrl . $basePath;

        $viewData = [
            'base_url' => $baseUrl,
            'base_path' => $basePath,
            'sitename' => getenv('SITENAME') ?: 'Workspace Organizer',
            'version' => Version::VERSION,
            'product_name' => Version::PRODUCT_NAME,
            'workspaceAccess' => $this->workspaceAccess(),
        ];

        if ($data !== null) {
            $normalized = $this->convertObjectsToArray($data);
            if (is_array($normalized)) {
                // Preserve the legacy controller contract where explicitly
                // supplied view variables can override common defaults.
                $viewData = array_merge($viewData, $normalized);
            }
        }

        $this->renderer->render($template, $viewData);
    }

    /** @return array{notes:bool,tasks:bool,files:bool,messenger:bool,profile:bool,admin:bool} */
    private function workspaceAccess(): array
    {
        $access = [
            'notes' => false,
            'tasks' => false,
            'files' => false,
            'messenger' => false,
            'profile' => false,
            'admin' => false,
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
            ];
        } catch (\Throwable $e) {
            error_log('Navigation RBAC evaluation failed: ' . $e->getMessage());
            return $access;
        }
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
