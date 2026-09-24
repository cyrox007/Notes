<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdatePath.php';

use Core\UpdatePath;

function windowsPathAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] Windows path contract: {$message}\n");
        exit(1);
    }
}

windowsPathAssert(UpdatePath::isAbsolute('C:\\workspace\\notes'), 'drive-letter backslash path was not absolute');
windowsPathAssert(UpdatePath::isAbsolute('c:/workspace/notes'), 'drive-letter slash path was not absolute');
windowsPathAssert(UpdatePath::isAbsolute('\\\\server\\share\\notes'), 'UNC path was not absolute');
windowsPathAssert(UpdatePath::isAbsolute('/srv/workspace'), 'POSIX absolute path support regressed');
windowsPathAssert(!UpdatePath::isAbsolute('workspace/notes'), 'relative path was accepted as absolute');

windowsPathAssert(UpdatePath::safeRelative('modules/notes/runtime.php'), 'safe relative path was rejected');
windowsPathAssert(!UpdatePath::safeRelative('../escape.php'), 'parent traversal was accepted');
windowsPathAssert(!UpdatePath::safeRelative('modules\\notes\\runtime.php'), 'backslash relative path was accepted');
windowsPathAssert(!UpdatePath::safeRelative('/absolute.php'), 'absolute relative-path candidate was accepted');

windowsPathAssert(UpdatePath::safeTopLevel('candidate-0123456789abcdef'), 'safe top-level name was rejected');
windowsPathAssert(!UpdatePath::safeTopLevel('../candidate'), 'top-level traversal was accepted');
windowsPathAssert(!UpdatePath::safeTopLevel('nested/candidate'), 'nested top-level name was accepted');

if (PHP_OS_FAMILY === 'Windows') {
    $normalized = UpdatePath::normalize('C:\\Workspace\\Notes\\');
    windowsPathAssert($normalized === 'c:/Workspace/Notes', 'Windows drive normalization changed');

    windowsPathAssert(
        UpdatePath::inside('C:\\Workspace\\Notes\\candidate', 'c:\\workspace\\notes'),
        'Windows inside() did not compare drive/path case-insensitively'
    );
    windowsPathAssert(
        UpdatePath::inside('c:/workspace/notes', 'C:\\WORKSPACE\\NOTES'),
        'Windows inside() did not accept same path with different case/slashes'
    );
    windowsPathAssert(
        !UpdatePath::inside('C:\\Workspace\\Notes-Evil', 'C:\\Workspace\\Notes'),
        'Windows prefix sibling escaped inside() boundary'
    );
    windowsPathAssert(
        !UpdatePath::inside('D:\\Workspace\\Notes', 'C:\\Workspace\\Notes'),
        'Windows different drive was accepted inside parent'
    );
}

fwrite(STDOUT, "[OK] Windows updater path boundary contract\n");
