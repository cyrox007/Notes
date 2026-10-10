<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/StorageCapacityService.php';

use App\Services\StorageCapacityService;

function capacityAssert(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

$rows = [
    ['used_bytes' => 20, 'effective_quota' => 100],
    ['used_bytes' => 150, 'effective_quota' => 100],
    ['used_bytes' => -10, 'effective_quota' => -10],
];
$unknown = (new StorageCapacityService(__FILE__ . '/missing'))->snapshot($rows);
capacityAssert($unknown['total_bytes'] === null && $unknown['free_bytes'] === null, 'Unavailable capacity must not appear as zero');
capacityAssert($unknown['used_bytes'] === 170 && $unknown['assigned_bytes'] === 200, 'Aggregate usage and assigned ceilings');
capacityAssert($unknown['potential_bytes'] === 80, 'Over-quota accounts cannot offset remaining space of other accounts');
capacityAssert($unknown['overcommitted'] === false && $unknown['shortfall_bytes'] === null, 'Unknown capacity cannot claim a safe or deficient balance');

$known = (new StorageCapacityService(sys_get_temp_dir()))->snapshot($rows);
if (function_exists('disk_free_space') && function_exists('disk_total_space')) {
    capacityAssert($known['free_bytes'] !== null && $known['total_bytes'] >= $known['free_bytes'], 'Read actual storage volume');
    $large = (new StorageCapacityService(sys_get_temp_dir()))->snapshot([
        ['used_bytes' => 0, 'effective_quota' => PHP_INT_MAX],
    ]);
    capacityAssert($large['overcommitted'] === true && $large['shortfall_bytes'] > 0, 'Warn when assigned capacity exceeds available disk');
}
fwrite(STDOUT, "[OK] storage capacity, unknown filesystem and quota overcommit\n");
