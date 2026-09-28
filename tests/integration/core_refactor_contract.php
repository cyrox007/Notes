<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/core/RouteTemplate.php';
require_once $root . '/core/DatabaseSqlInspector.php';
require_once $root . '/core/UpdateProcessRunner.php';
require_once $root . '/core/UpdatePath.php';
require_once $root . '/core/Uuid.php';

use Core\DatabaseSqlInspector;
use Core\RouteTemplate;
use Core\UpdatePath;
use Core\UpdateProcessRunner;
use Core\Uuid;

function coreRefactorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] core refactor contract: {$message}\n");
        exit(1);
    }
}


/**
 * @return list<string>
 */
function coreRefactorLegacyUuidReferences(string $root): array
{
    $paths = [$root . '/core.php'];

    foreach (['app', 'modules', 'core'] as $directory) {
        $absoluteDirectory = $root . '/' . $directory;
        if (!is_dir($absoluteDirectory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDirectory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $paths[] = $file->getPathname();
        }
    }

    $patterns = [
        '/\\buse\\s+UUID\\s*;/',
        '/\\bUUID\\s*::/',
        '/\\bnew\\s+UUID\\b/',
        '/class_exists\\s*\\(\\s*["\\']UUID["\\']/',
    ];

    $references = [];
    foreach (array_values(array_unique($paths)) as $path) {
        $source = file_get_contents($path);
        if (!is_string($source)) {
            $references[] = str_replace($root . DIRECTORY_SEPARATOR, '', $path) . ':unreadable';
            continue;
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source) !== 1) {
                continue;
            }
            $references[] = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
            break;
        }
    }

    sort($references, SORT_STRING);
    return array_values(array_unique($references));
}

$normalized = RouteTemplate::normalize('//notes///{int:id}//');
coreRefactorAssert($normalized === '/notes/{int:id}/', 'route normalization changed');

$pattern = RouteTemplate::compile('/notes/{int:id}/{str:uid}/');
coreRefactorAssert(
    preg_match($pattern, '/notes/42/abc_DEF-9/', $matches) === 1,
    'compiled route does not match valid typed parameters'
);
$params = RouteTemplate::typedParams($matches, '/notes/{int:id}/{str:uid}/');
coreRefactorAssert(($params['id'] ?? null) === 42, 'integer route parameter lost its type');
coreRefactorAssert(($params['uid'] ?? null) === 'abc_DEF-9', 'string route parameter changed');

$built = RouteTemplate::bind('/notes/{str:uid}/attachment/{int:id}/', [
    'uid' => 'abc_DEF-9',
    'id' => 17,
]);
coreRefactorAssert(
    $built === '/notes/abc_DEF-9/attachment/17/',
    'route binding changed'
);

$duplicateRejected = false;
try {
    RouteTemplate::compile('/duplicate/{int:id}/{str:id}/');
} catch (RuntimeException) {
    $duplicateRejected = true;
}
coreRefactorAssert($duplicateRejected, 'duplicate route parameter was accepted');

coreRefactorAssert(
    DatabaseSqlInspector::queryType("  SELECT 1") === 'SELECT',
    'query classification changed'
);
coreRefactorAssert(
    DatabaseSqlInspector::queryType("/* comment */ SELECT 1") === 'UNKNOWN',
    'query classification unexpectedly parses leading comments'
);

$diagnostic = DatabaseSqlInspector::diagnosticQuery(
    'SELECT * FROM t WHERE id=:id AND id2=:id2 AND flag=:flag',
    [':id' => 7, ':id2' => 42, ':flag' => true]
);
coreRefactorAssert(
    str_contains($diagnostic, 'id=7')
        && str_contains($diagnostic, 'id2=42')
        && !str_contains($diagnostic, '72'),
    'diagnostic placeholder rendering corrupted overlapping names'
);

$unsafeIdentifierRejected = false;
try {
    DatabaseSqlInspector::assertIdentifier('users;DROP TABLE users');
} catch (Throwable) {
    $unsafeIdentifierRejected = true;
}
coreRefactorAssert($unsafeIdentifierRejected, 'unsafe SQL identifier was accepted');

coreRefactorAssert(UpdatePath::safeRelative('core/Version.php'), 'safe updater relative path was rejected');
coreRefactorAssert(!UpdatePath::safeRelative('../escape.php'), 'updater traversal path was accepted');
coreRefactorAssert(!UpdatePath::safeRelative('core\\escape.php'), 'updater backslash path was accepted');
coreRefactorAssert(UpdatePath::safeTopLevel('core'), 'safe updater top-level name was rejected');
coreRefactorAssert(!UpdatePath::safeTopLevel('../core'), 'unsafe updater top-level name was accepted');
coreRefactorAssert(
    UpdatePath::inside('/srv/workspace/core', '/srv/workspace'),
    'updater child path relation changed'
);
coreRefactorAssert(
    !UpdatePath::inside('/srv/workspace-other', '/srv/workspace'),
    'updater sibling path was treated as inside'
);


$uuid = Uuid::v4(str_repeat("\0", 16));
coreRefactorAssert(
    $uuid === '00000000-0000-4000-8000-000000000000',
    'Core\\Uuid нарушил детерминированный контракт UUID v4'
);

$invalidUuidRejected = false;
try {
    Uuid::v4('short');
} catch (InvalidArgumentException) {
    $invalidUuidRejected = true;
}
coreRefactorAssert($invalidUuidRejected, 'Core\\Uuid принял значение длиной не 16 байт');

coreRefactorAssert(
    !is_file($root . '/app/handlers/UUID.php'),
    'глобальный legacy UUID helper всё ещё присутствует в app/handlers'
);
coreRefactorAssert(
    coreRefactorLegacyUuidReferences($root) === [],
    'в рабочем PHP-коде остались обращения к глобальному UUID: '
        . implode(', ', coreRefactorLegacyUuidReferences($root))
);

coreRefactorAssert(!is_file($root . '/core/model.php'), 'unused legacy Core\\Model implementation still exists');
$coreBootstrap = file_get_contents($root . '/core.php');
coreRefactorAssert(
    is_string($coreBootstrap) && !str_contains($coreBootstrap, '/core/model.php'),
    'core bootstrap still loads the removed legacy Model'
);
$noteModel = file_get_contents($root . '/modules/notes/models/NoteModel.php');
coreRefactorAssert(
    is_string($noteModel) && !str_contains($noteModel, 'use Core\\Model;'),
    'NoteModel still imports the removed legacy Model'
);

$routerSource = file_get_contents($root . '/core/Router.php');
coreRefactorAssert(
    is_string($routerSource)
        && str_contains($routerSource, 'compiledPatterns')
        && str_contains($routerSource, 'routeNameIndex'),
    'Router does not retain compiled-pattern and named-route indexes'
);

$databaseSource = file_get_contents($root . '/core/DatabaseManager.php');
coreRefactorAssert(
    is_string($databaseSource)
        && str_contains($databaseSource, 'if ($this->enableLogging)')
        && str_contains($databaseSource, 'DatabaseSqlInspector::diagnosticQuery'),
    'DatabaseManager logging fast path is not wired to the extracted inspector'
);

$liveApplierSource = file_get_contents($root . '/core/UpdateLiveApplier.php');
coreRefactorAssert(
    is_string($liveApplierSource)
        && str_contains($liveApplierSource, 'UpdateCandidateVerifier')
        && str_contains($liveApplierSource, 'UpdateCodeSwitcher')
        && str_contains($liveApplierSource, 'UpdateDatabaseRestorer'),
    'UpdateLiveApplier responsibilities were not split into dedicated boundaries'
);

$runner = new UpdateProcessRunner();
$result = $runner->run(
    [PHP_BINARY, '-r', 'fwrite(STDOUT, "runner-ok");'],
    $root,
    10
);
coreRefactorAssert($result['code'] === 0, 'process runner returned non-zero for successful command');
coreRefactorAssert($result['stdout'] === 'runner-ok', 'process runner lost stdout');

$failed = $runner->run(
    [PHP_BINARY, '-r', 'fwrite(STDERR, "runner-failed"); exit(3);'],
    $root,
    10
);
coreRefactorAssert($failed['code'] === 3, 'process runner lost child exit code');
coreRefactorAssert(
    $runner->failureDetails($failed) === 'runner-failed',
    'process failure detail selection changed'
);

echo "[OK] core refactor boundaries contract\n";
