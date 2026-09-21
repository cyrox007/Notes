<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/bin/update_run.php';
$source = is_file($path) ? (string) file_get_contents($path) : '';

function updateRunContract(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

updateRunContract($source !== '', 'bin/update_run.php is missing');
updateRunContract(str_contains($source, "PHP_SAPI !== 'cli'"), 'operator flow must remain CLI-only');
updateRunContract(str_contains($source, "'yes'"), 'operator flow lost explicit destructive confirmation');
updateRunContract(str_contains($source, 'confirmation_required'), 'operator flow does not fail closed without --yes');
updateRunContract(str_contains($source, 'UpdateProcessRunner'), 'operator flow must use bounded argv subprocess runner');
updateRunContract(str_contains($source, "update_remote.php"), 'operator flow lost signed remote staging');
updateRunContract(str_contains($source, "update_backup.php"), 'operator flow lost rollback backup checkpoint');
updateRunContract(str_contains($source, "update_candidate.php"), 'operator flow lost verified release candidate');
updateRunContract(str_contains($source, "update_apply.php"), 'operator flow lost transactional live apply');
updateRunContract(str_contains($source, 'MaintenanceModeService'), 'operator flow lost maintenance ownership');
updateRunContract(str_contains($source, '$applyInvoked = true;'), 'operator flow lost destructive-boundary marker');
updateRunContract(
    str_contains($source, 'if ($maintenanceEntered && !$applyInvoked)'),
    'pre-mutation maintenance cleanup boundary is missing'
);
updateRunContract(
    str_contains($source, 'The wrapper must never force-open writes on failure'),
    'operator wrapper lost fail-closed apply ownership invariant'
);
foreach (['shell_exec(', 'passthru(', 'system('] as $forbidden) {
    updateRunContract(!str_contains($source, $forbidden), 'operator flow uses forbidden shell execution primitive: ' . $forbidden);
}

$help = [];
$exit = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' --help', $help, $exit);
updateRunContract($exit === 0, 'update_run --help failed');
$helpText = implode("\n", $help);
updateRunContract(str_contains($helpText, 'end-to-end signed updater operator flow'), 'update_run help contract changed');
updateRunContract(str_contains($helpText, '--yes'), 'update_run help must document confirmation');

echo "[OK] updater operator flow contract\n";
