<?php

declare(strict_types=1);

// Compatibility entry: the two-file repair is superseded by the full bridge.
// Never silently deploy only staging/backup fixes while leaving Windows apply broken.
require __DIR__ . '/repair-updater-full.php';
