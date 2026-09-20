<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('SITEPATH')) {
    define('SITEPATH', $root);
}
putenv('BASE_PATH=/');

require_once $root . '/core/RuntimeAutoloader.php';
\Core\RuntimeAutoloader::register($root);
require_once $root . '/core/Version.php';
require_once $root . '/core/ModuleManifest.php';
require_once $root . '/core/ModuleRegistry.php';
require_once $root . '/core/ModuleRuntimeProvider.php';
require_once $root . '/core/ModuleCapabilityRegistry.php';
require_once $root . '/core/ModuleRuntimeLoader.php';
require_once $root . '/core/Router.php';
require_once $root . '/core/ORM.php';
require_once $root . '/core/request.php';
require_once $root . '/core/ViewRenderer.php';
require_once $root . '/core/ViewContext.php';
require_once $root . '/core/controller.php';
require_once $root . '/app/handlers/UUID.php';

use Core\AccountDeactivationGuard;
use Core\ModuleRegistry;
use Core\ModuleRuntimeLoader;
use Core\ProfileContentProvider;
use Core\Router;
use Core\RuntimeAutoloader;
use Core\Version;

function runtimeCompositionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] module runtime composition: {$message}\n");
        exit(1);
    }
}

$expected = ['admin', 'files', 'messenger', 'notes', 'profile', 'tasks'];
$providerClasses = [
    'admin' => 'Modules\\Admin\\AdminRuntimeProvider',
    'files' => 'Modules\\Files\\FilesRuntimeProvider',
    'messenger' => 'Modules\\Messenger\\MessengerRuntimeProvider',
    'notes' => 'Modules\\Notes\\NotesRuntimeProvider',
    'profile' => 'Modules\\Profile\\ProfileRuntimeProvider',
    'tasks' => 'Modules\\Tasks\\TasksRuntimeProvider',
];
$routeControllerClasses = [
    'admin' => 'App\\Controllers\\Admin\\AdminController',
    'files' => 'App\\Controllers\\FileController',
    'messenger' => 'App\\Controllers\\MessagerController',
    'notes' => 'App\\Controllers\\NoteController',
    'profile' => 'App\\Controllers\\ProfileController',
    'tasks' => 'App\\Controllers\\TaskController',
];

$coreSource = (string) file_get_contents($root . '/core.php');
runtimeCompositionAssert(!str_contains($coreSource, 'loadDirectoryFiles'), 'recursive app loader function returned');
runtimeCompositionAssert(!str_contains($coreSource, 'RecursiveDirectoryIterator'), 'recursive app directory scan returned');
runtimeCompositionAssert(str_contains($coreSource, 'RuntimeAutoloader::register'), 'deterministic runtime autoloader is not registered');
runtimeCompositionAssert(str_contains($coreSource, '/app/handlers/UUID.php'), 'global UUID compatibility include is not explicit');

foreach ([
    'Core\\Version' => '/core/Version.php',
    'App\\Controllers\\AuthController' => '/app/controllers/AuthController.php',
    'App\\Helpers\\CryptMethods' => '/app/handlers/CryptMethods.php',
    'App\\Helpers\\CryptographicFailure' => '/app/handlers/CryptMethods.php',
    'App\\Middlewares\\LoginRequared' => '/app/middlewares/LoginRequared.php',
    'App\\Models\\UserModel' => '/app/models/UserModel.php',
    'App\\Services\\PermissionService' => '/app/services/PermissionService.php',
] as $class => $suffix) {
    $resolved = RuntimeAutoloader::resolve($root, $class);
    runtimeCompositionAssert(is_string($resolved) && str_ends_with(str_replace('\\', '/', $resolved), $suffix), "shared class {$class} resolves outside its owner");
}

foreach (array_values($routeControllerClasses) as $moduleClass) {
    runtimeCompositionAssert(RuntimeAutoloader::resolve($root, $moduleClass) === null, "module class {$moduleClass} leaked into shared autoload");
}
runtimeCompositionAssert(RuntimeAutoloader::resolve($root, 'App\\Sockets\\NativeMessengerServer') === null, 'Messenger socket runtime leaked into shared autoload');

$registry = ModuleRegistry::discover($root . '/modules', Version::VERSION);
runtimeCompositionAssert(array_keys($registry->all()) === $expected, 'bundled module set drifted');
runtimeCompositionAssert($registry->resolveComposition([]) === [], 'core-only composition must remain empty');
runtimeCompositionAssert($registry->resolveComposition($expected) === $expected, 'full composition drifted');

$exclude = trim((string) (getenv('MODULE_EXCLUDE') ?: ''));
if ($exclude === 'core-only') {
    $composition = [];
} elseif ($exclude === '') {
    $composition = $expected;
} else {
    runtimeCompositionAssert(in_array($exclude, $expected, true), 'unknown MODULE_EXCLUDE value');
    $requested = array_values(array_filter($expected, static fn (string $id): bool => $id !== $exclude));
    $composition = $registry->resolveComposition($requested);
    runtimeCompositionAssert(!in_array($exclude, $composition, true), "excluded module {$exclude} was restored by composition resolution");
}

$runtime = ModuleRuntimeLoader::boot($registry, $composition);
runtimeCompositionAssert(array_keys($runtime->providers()) === $composition, 'loaded provider set does not equal requested composition');

$capabilities = $runtime->capabilities();
$capabilityOwners = array_values($capabilities->providers());
sort($capabilityOwners, SORT_STRING);
$sortedComposition = $composition;
sort($sortedComposition, SORT_STRING);
runtimeCompositionAssert($capabilityOwners === $sortedComposition, 'capability ownership does not equal active composition');

foreach ([
    'notes' => 'workspace.notes',
    'tasks' => 'workspace.tasks',
    'files' => 'workspace.files',
] as $moduleId => $capability) {
    $active = in_array($moduleId, $composition, true);
    runtimeCompositionAssert($capabilities->has($capability) === $active, "Profile capability availability drifted for {$moduleId}");
    if ($active) {
        runtimeCompositionAssert(
            $capabilities->require($capability, ProfileContentProvider::class) instanceof ProfileContentProvider,
            "{$moduleId} does not implement ProfileContentProvider"
        );
    }
}

$filesActive = in_array('files', $composition, true);
if ($filesActive) {
    require_once $root . '/core/WorkspaceFileProvider.php';
    runtimeCompositionAssert(
        $capabilities->require('workspace.files', \Core\WorkspaceFileProvider::class) instanceof \Core\WorkspaceFileProvider,
        'Files runtime capability does not implement WorkspaceFileProvider'
    );
}

$messengerActive = in_array('messenger', $composition, true);
runtimeCompositionAssert($capabilities->has('workspace.messenger') === $messengerActive, 'Messenger capability availability drifted');
if ($messengerActive) {
    runtimeCompositionAssert(
        $capabilities->require('workspace.messenger', AccountDeactivationGuard::class) instanceof AccountDeactivationGuard,
        'Messenger does not implement AccountDeactivationGuard'
    );
}

$viewRoots = $runtime->viewRoots();
$assetRoots = $runtime->assetRoots();
foreach ($expected as $moduleId) {
    $active = in_array($moduleId, $composition, true);
    runtimeCompositionAssert(class_exists($providerClasses[$moduleId], false) === $active, "entrypoint load state drifted for {$moduleId}");

    $hasViews = is_dir($root . '/modules/' . $moduleId . '/views');
    runtimeCompositionAssert(isset($viewRoots[$moduleId]) === ($active && $hasViews), "view ownership drifted for {$moduleId}");

    $hasAssets = is_dir($root . '/modules/' . $moduleId . '/assets');
    runtimeCompositionAssert(isset($assetRoots[$moduleId]) === ($active && $hasAssets), "asset ownership drifted for {$moduleId}");
}

$router = Router::getInstance();
$runtime->registerRoutes($router);
$reflection = new ReflectionProperty($router, 'routes');
$reflection->setAccessible(true);
$routes = $reflection->getValue($router);
runtimeCompositionAssert(is_array($routes), 'router route table is unavailable');
if ($composition === []) {
    runtimeCompositionAssert($routes === [], 'core-only runtime registered module routes');
} else {
    runtimeCompositionAssert($routes !== [], 'active module composition registered no routes');
}

$routeControllers = [];
foreach ($routes as $route) {
    $controller = $route['controller'][0] ?? null;
    if (is_string($controller)) {
        $routeControllers[$controller] = true;
    }
}
foreach ($routeControllerClasses as $moduleId => $controllerClass) {
    $active = in_array($moduleId, $composition, true);
    runtimeCompositionAssert(
        isset($routeControllers[$controllerClass]) === $active,
        "route registration state drifted for {$moduleId}"
    );
}

fwrite(STDOUT, '[OK] module runtime composition ' . ($exclude === '' ? 'full' : $exclude) . "\n");
