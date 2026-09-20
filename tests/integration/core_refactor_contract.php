<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/core/RouteTemplate.php';
require_once $root . '/core/DatabaseSqlInspector.php';
require_once $root . '/core/UpdateProcessRunner.php';

use Core\DatabaseSqlInspector;
use Core\RouteTemplate;
use Core\UpdateProcessRunner;

function coreRefactorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] core refactor contract: {$message}\n");
        exit(1);
    }
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
