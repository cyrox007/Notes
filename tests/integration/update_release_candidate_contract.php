<?php

declare(strict_types=1);

use Core\UpdateReleaseCandidate;

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateArchiveInspector.php';
require_once $root . '/core/UpdateReleaseCandidate.php';

function candidateAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function candidateRemoveTree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            candidateRemoveTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** @param list<array{name:string,data?:string,method?:int,directory?:bool}> $entries */
function candidateBuildZip(string $path, array $entries): void
{
    $body = '';
    $central = '';
    $count = 0;

    foreach ($entries as $spec) {
        $name = $spec['name'];
        $directory = (bool) ($spec['directory'] ?? str_ends_with($name, '/'));
        $data = $directory ? '' : (string) ($spec['data'] ?? '');
        $method = $directory ? 0 : (int) ($spec['method'] ?? 8);
        $compressedData = $method === 8 ? gzdeflate($data, 6) : $data;
        candidateAssert(is_string($compressedData), 'unable to deflate ZIP fixture');
        $crc = crc32($data);
        if ($crc < 0) {
            $crc += 4294967296;
        }
        $compressed = strlen($compressedData);
        $uncompressed = strlen($data);
        $offset = strlen($body);
        $external = (($directory ? 0040755 : 0100644) << 16);
        $versionMade = ((3 << 8) | 20);

        $body .= "PK\x03\x04" . pack(
            'vvvvvVVVvv',
            20,
            0,
            $method,
            0,
            0,
            $crc,
            $compressed,
            $uncompressed,
            strlen($name),
            0
        ) . $name . $compressedData;

        $central .= "PK\x01\x02" . pack(
            'vvvvvvVVVvvvvvVV',
            $versionMade,
            20,
            0,
            $method,
            0,
            0,
            $crc,
            $compressed,
            $uncompressed,
            strlen($name),
            0,
            0,
            0,
            0,
            $external,
            $offset
        ) . $name;
        $count++;
    }

    $centralOffset = strlen($body);
    $bytes = $body . $central . "PK\x05\x06" . pack(
        'vvvvVVv',
        0,
        0,
        $count,
        $count,
        strlen($central),
        $centralOffset,
        0
    );
    candidateAssert(file_put_contents($path, $bytes) === strlen($bytes), 'unable to write candidate ZIP fixture');
}

/** @return list<array{name:string,data?:string,method?:int,directory?:bool}> */
function candidateBundleEntries(string $version, int $versionCode): array
{
    $prefix = 'workspace-organizer-v-test/';
    return [
        ['name' => $prefix, 'directory' => true],
        ['name' => $prefix . 'core/', 'directory' => true],
        ['name' => $prefix . 'bin/', 'directory' => true],
        ['name' => $prefix . 'config/', 'directory' => true],
        ['name' => $prefix . 'assets/', 'directory' => true],
        ['name' => $prefix . 'index.php', 'data' => "<?php echo 'index';\n", 'method' => 0],
        ['name' => $prefix . 'install.php', 'data' => "<?php echo 'install';\n", 'method' => 8],
        ['name' => $prefix . 'core.php', 'data' => "<?php echo 'core';\n", 'method' => 8],
        ['name' => $prefix . 'core/Version.php', 'data' => "<?php\ndeclare(strict_types=1);\nnamespace Core;\nclass Version { public const VERSION = '{$version}'; public const VERSION_CODE = {$versionCode}; }\n", 'method' => 8],
        ['name' => $prefix . 'bin/migrate.php', 'data' => "<?php echo 'migrate';\n", 'method' => 8],
        ['name' => $prefix . 'bin/healthcheck.php', 'data' => "<?php echo 'health';\n", 'method' => 8],
        ['name' => $prefix . 'config/update_trusted_keys.php', 'data' => "<?php return [];\n", 'method' => 8],
        ['name' => $prefix . 'assets/payload.txt', 'data' => str_repeat('release-candidate-', 1024), 'method' => 8],
    ];
}

$temp = sys_get_temp_dir() . '/wo-release-candidate-' . bin2hex(random_bytes(6));
candidateAssert(mkdir($temp, 0700, true), 'unable to create release candidate temp root');

try {
    $version = '1.0.0-test.7';
    $versionCode = 10007;
    $manifest = [
        'version' => $version,
        'version_code' => $versionCode,
    ];
    $archive = $temp . '/update.zip';
    candidateBuildZip($archive, candidateBundleEntries($version, $versionCode));

    $candidateRoot = $temp . '/releases';
    $extractor = new UpdateReleaseCandidate($root);
    $result = $extractor->extract($archive, $candidateRoot, $manifest);
    candidateAssert(is_dir($result['candidate_dir']), 'release candidate directory was not created');
    candidateAssert(is_file($result['tree_manifest']), 'release candidate tree manifest missing');
    candidateAssert(preg_match('/^[0-9a-f]{64}$/', $result['tree_sha256']) === 1, 'candidate tree SHA-256 invalid');
    candidateAssert($result['archive_root'] === 'workspace-organizer-v-test', 'archive root changed');
    candidateAssert(
        file_get_contents($result['candidate_dir'] . '/assets/payload.txt') === str_repeat('release-candidate-', 1024),
        'deflated candidate payload changed during extraction'
    );
    candidateAssert(
        file_get_contents($result['candidate_dir'] . '/index.php') === "<?php echo 'index';\n",
        'stored candidate payload changed during extraction'
    );

    $again = $extractor->extract($archive, $candidateRoot, $manifest);
    candidateAssert($again['candidate_dir'] === $result['candidate_dir'], 'candidate extraction is not idempotent');
    candidateAssert($again['tree_sha256'] === $result['tree_sha256'], 'candidate tree hash changed on idempotent verification');

    $tamperedPath = $result['candidate_dir'] . '/index.php';
    candidateAssert(file_put_contents($tamperedPath, "tampered\n") !== false, 'unable to tamper candidate fixture');
    $tamperRejected = false;
    try {
        $extractor->extract($archive, $candidateRoot, $manifest);
    } catch (Throwable $e) {
        $tamperRejected = str_contains(strtolower($e->getMessage()), 'verification');
    }
    candidateAssert($tamperRejected, 'tampered existing candidate was accepted');

    $multi = $temp . '/multi-root.zip';
    candidateBuildZip($multi, [
        ['name' => 'root-a/index.php', 'data' => 'a', 'method' => 0],
        ['name' => 'root-b/index.php', 'data' => 'b', 'method' => 0],
    ]);
    $multiRejected = false;
    try {
        $extractor->extract($multi, $temp . '/multi-candidates', $manifest);
    } catch (Throwable $e) {
        $multiRejected = str_contains(strtolower($e->getMessage()), 'multiple top-level');
    }
    candidateAssert($multiRejected, 'multiple archive roots were accepted');

    $mismatchArchive = $temp . '/version-mismatch.zip';
    candidateBuildZip($mismatchArchive, candidateBundleEntries('1.0.0-other', $versionCode + 1));
    $versionRejected = false;
    try {
        $extractor->extract($mismatchArchive, $temp . '/version-candidates', $manifest);
    } catch (Throwable $e) {
        $versionRejected = str_contains($e->getMessage(), 'Version.php');
    }
    candidateAssert($versionRejected, 'candidate version mismatch was accepted');

    $insideRejected = false;
    try {
        $extractor->extract($archive, $root . '/cache/update-release-candidate-contract', $manifest);
    } catch (Throwable $e) {
        $insideRejected = str_contains($e->getMessage(), 'outside the live application tree');
    }
    candidateAssert($insideRejected, 'candidate extraction inside application tree was accepted');
    candidateRemoveTree($root . '/cache/update-release-candidate-contract');

    $forbiddenArchive = $temp . '/forbidden-env.zip';
    $entries = candidateBundleEntries($version, $versionCode);
    $entries[] = ['name' => 'workspace-organizer-v-test/.env', 'data' => 'SECRET=x', 'method' => 8];
    candidateBuildZip($forbiddenArchive, $entries);
    $forbiddenRejected = false;
    try {
        $extractor->extract($forbiddenArchive, $temp . '/forbidden-candidates', $manifest);
    } catch (Throwable $e) {
        $forbiddenRejected = str_contains($e->getMessage(), 'forbidden customer artifact');
    }
    candidateAssert($forbiddenRejected, 'candidate containing .env was accepted');

    echo "[OK] update release candidate contract\n";
} finally {
    candidateRemoveTree($temp);
    candidateRemoveTree($root . '/cache/update-release-candidate-contract');
}
