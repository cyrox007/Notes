<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

/**
 * Extracts an already-audited signed update ZIP into an external release candidate.
 * Never writes to the live application tree.
 */
final class UpdateReleaseCandidate
{
    private string $appRoot;

    public function __construct(?string $appRoot = null)
    {
        $root = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('Application root cannot be resolved for update extraction');
        }
        $this->appRoot = $this->normalize($root);
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{candidate_dir:string,archive_root:string,files:int,total_bytes:int,tree_manifest:string,tree_sha256:string}
     */
    public function extract(string $archivePath, string $candidateRoot, array $manifest): array
    {
        (new UpdateArchiveInspector())->inspect($archivePath);
        if (!function_exists('inflate_init') || !function_exists('inflate_add')) {
            throw new RuntimeException('PHP zlib support is required to extract update packages');
        }

        $candidateRoot = $this->prepareExternalRoot($candidateRoot);
        $entries = $this->readEntries($archivePath);
        $archiveRoot = $this->assertSingleArchiveRoot($entries);
        $packageSha = hash_file('sha256', $archivePath);
        if (!is_string($packageSha)) {
            throw new RuntimeException('Cannot hash update package before extraction');
        }
        $finalDir = $candidateRoot . DIRECTORY_SEPARATOR . 'candidate-' . substr($packageSha, 0, 16);

        if (is_dir($finalDir)) {
            return $this->verifyCandidate($finalDir, $archiveRoot, $manifest);
        }

        $tempDir = $candidateRoot . DIRECTORY_SEPARATOR . '.candidate-' . bin2hex(random_bytes(8)) . '.tmp';
        $oldUmask = umask(0022);
        $made = @mkdir($tempDir, 0755, false);
        umask($oldUmask);
        if (!$made && !is_dir($tempDir)) {
            throw new RuntimeException('Cannot create temporary release candidate directory');
        }

        $handle = fopen($archivePath, 'rb');
        if ($handle === false) {
            $this->removeTree($tempDir);
            throw new RuntimeException('Cannot open update archive for extraction');
        }

        try {
            foreach ($entries as $entry) {
                $relative = $this->relativePath((string) $entry['name'], $archiveRoot);
                if ($relative === '') {
                    continue;
                }
                $target = $tempDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if ($entry['directory']) {
                    $this->ensureDirectory($target);
                    continue;
                }
                $this->ensureDirectory(dirname($target));
                $this->extractEntry($handle, $entry, $target);
            }
            fclose($handle);
            $handle = null;

            $tree = $this->buildTreeManifest($tempDir, $archiveRoot, $manifest);
            $treePath = $tempDir . DIRECTORY_SEPARATOR . '.workspace-release-tree.json';
            $this->writeExclusive($treePath, $tree['bytes']);

            if (!@rename($tempDir, $finalDir)) {
                throw new RuntimeException('Cannot atomically publish verified release candidate');
            }
            return $this->verifyCandidate($finalDir, $archiveRoot, $manifest);
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->removeTree($tempDir);
            throw $e;
        }
    }

    /** @return list<array{name:string,method:int,crc:int,compressed:int,uncompressed:int,data_offset:int,directory:bool}> */
    private function readEntries(string $archivePath): array
    {
        $size = filesize($archivePath);
        if (!is_int($size) || $size < 22) {
            throw new RuntimeException('Update ZIP is unreadable');
        }
        $handle = fopen($archivePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Update ZIP cannot be opened');
        }
        try {
            $search = min($size, 65557);
            fseek($handle, $size - $search, SEEK_SET);
            $tail = $this->readExact($handle, $search);
            $pos = strrpos($tail, "PK\x05\x06");
            if ($pos === false || strlen($tail) - $pos < 22) {
                throw new RuntimeException('ZIP end record is missing during extraction');
            }
            $eocd = unpack('vdisk/vcentral_disk/ventries_disk/ventries/Vcentral_size/Vcentral_offset/vcomment_length', substr($tail, $pos + 4, 18));
            if (!is_array($eocd) || (int) $eocd['disk'] !== 0 || (int) $eocd['central_disk'] !== 0) {
                throw new RuntimeException('ZIP extraction metadata is invalid');
            }
            $entriesCount = (int) $eocd['entries'];
            $centralOffset = (int) $eocd['central_offset'];
            if (fseek($handle, $centralOffset, SEEK_SET) !== 0) {
                throw new RuntimeException('Cannot seek ZIP central directory for extraction');
            }

            $entries = [];
            for ($i = 0; $i < $entriesCount; $i++) {
                $fixed = $this->readExact($handle, 46);
                if (substr($fixed, 0, 4) !== "PK\x01\x02") {
                    throw new RuntimeException('ZIP central entry changed after preflight');
                }
                $central = unpack(
                    'vversion_made/vversion_needed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcompressed/Vuncompressed/'
                    . 'vname_length/vextra_length/vcomment_length/vdisk_start/vinternal/Vexternal/Vlocal_offset',
                    substr($fixed, 4)
                );
                if (!is_array($central)) {
                    throw new RuntimeException('Cannot decode ZIP central entry');
                }
                $name = $this->readExact($handle, (int) $central['name_length']);
                $this->readExact($handle, (int) $central['extra_length']);
                $this->readExact($handle, (int) $central['comment_length']);
                $next = ftell($handle);
                if (!is_int($next)) {
                    throw new RuntimeException('Cannot track ZIP extraction position');
                }

                $localOffset = (int) $central['local_offset'];
                if (fseek($handle, $localOffset, SEEK_SET) !== 0) {
                    throw new RuntimeException('Cannot seek ZIP local header: ' . $name);
                }
                $localFixed = $this->readExact($handle, 30);
                if (substr($localFixed, 0, 4) !== "PK\x03\x04") {
                    throw new RuntimeException('ZIP local header changed after preflight: ' . $name);
                }
                $local = unpack('vversion_needed/vflags/vmethod/vmtime/vmdate/Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length', substr($localFixed, 4));
                if (!is_array($local)) {
                    throw new RuntimeException('Cannot decode ZIP local header: ' . $name);
                }
                $localName = $this->readExact($handle, (int) $local['name_length']);
                $this->readExact($handle, (int) $local['extra_length']);
                if (!hash_equals($name, $localName)
                    || (int) $local['method'] !== (int) $central['method']
                    || (int) $local['crc'] !== (int) $central['crc']
                    || (int) $local['compressed'] !== (int) $central['compressed']
                    || (int) $local['uncompressed'] !== (int) $central['uncompressed']) {
                    throw new RuntimeException('ZIP local/central metadata changed after preflight: ' . $name);
                }
                $dataOffset = ftell($handle);
                if (!is_int($dataOffset)) {
                    throw new RuntimeException('Cannot determine ZIP payload offset');
                }
                $entries[] = [
                    'name' => $name,
                    'method' => (int) $central['method'],
                    'crc' => (int) $central['crc'],
                    'compressed' => (int) $central['compressed'],
                    'uncompressed' => (int) $central['uncompressed'],
                    'data_offset' => $dataOffset,
                    'directory' => str_ends_with($name, '/'),
                ];
                if (fseek($handle, $next, SEEK_SET) !== 0) {
                    throw new RuntimeException('Cannot return to ZIP central directory');
                }
            }
            return $entries;
        } finally {
            fclose($handle);
        }
    }

    /** @param list<array{name:string,directory:bool}> $entries */
    private function assertSingleArchiveRoot(array $entries): string
    {
        $root = null;
        foreach ($entries as $entry) {
            $trimmed = rtrim((string) $entry['name'], '/');
            $parts = explode('/', $trimmed);
            if (count($parts) < 2) {
                if ($entry['directory'] && $root === null) {
                    $root = $parts[0];
                    continue;
                }
                throw new RuntimeException('Update archive must contain one top-level bundle directory');
            }
            $candidate = $parts[0];
            if ($root === null) {
                $root = $candidate;
            } elseif (!hash_equals($root, $candidate)) {
                throw new RuntimeException('Update archive contains multiple top-level roots');
            }
        }
        if (!is_string($root) || $root === '' || $root === '.' || $root === '..') {
            throw new RuntimeException('Update archive root is invalid');
        }
        return $root;
    }

    /** @param array{name:string,method:int,crc:int,compressed:int,uncompressed:int,data_offset:int,directory:bool} $entry @param resource $handle */
    private function extractEntry($handle, array $entry, string $target): void
    {
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('Release candidate target already exists: ' . $entry['name']);
        }
        if (fseek($handle, $entry['data_offset'], SEEK_SET) !== 0) {
            throw new RuntimeException('Cannot seek ZIP payload: ' . $entry['name']);
        }
        $old = umask(0022);
        $output = @fopen($target, 'xb');
        umask($old);
        if ($output === false) {
            throw new RuntimeException('Cannot create release candidate file: ' . $entry['name']);
        }

        $remaining = $entry['compressed'];
        $written = 0;
        $crc = hash_init('crc32b');
        $inflate = $entry['method'] === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
        if ($entry['method'] === 8 && $inflate === false) {
            fclose($output);
            @unlink($target);
            throw new RuntimeException('Cannot initialize deflate stream: ' . $entry['name']);
        }

        try {
            while ($remaining > 0) {
                $chunkSize = min(65536, $remaining);
                $chunk = $this->readExact($handle, $chunkSize);
                $remaining -= strlen($chunk);
                if ($entry['method'] === 8) {
                    $decoded = inflate_add($inflate, $chunk, ZLIB_NO_FLUSH);
                    if (!is_string($decoded)) {
                        throw new RuntimeException('Deflate decoding failed: ' . $entry['name']);
                    }
                } else {
                    $decoded = $chunk;
                }
                if ($decoded !== '') {
                    if (fwrite($output, $decoded) !== strlen($decoded)) {
                        throw new RuntimeException('Release candidate file write failed: ' . $entry['name']);
                    }
                    hash_update($crc, $decoded);
                    $written += strlen($decoded);
                    if ($written > $entry['uncompressed']) {
                        throw new RuntimeException('ZIP entry expanded beyond declared size: ' . $entry['name']);
                    }
                }
            }
            if ($entry['method'] === 8) {
                $decoded = inflate_add($inflate, '', ZLIB_FINISH);
                if (!is_string($decoded)) {
                    throw new RuntimeException('Deflate finalization failed: ' . $entry['name']);
                }
                if ($decoded !== '') {
                    if (fwrite($output, $decoded) !== strlen($decoded)) {
                        throw new RuntimeException('Release candidate final write failed: ' . $entry['name']);
                    }
                    hash_update($crc, $decoded);
                    $written += strlen($decoded);
                }
            }
            if (!fflush($output)) {
                throw new RuntimeException('Release candidate file flush failed: ' . $entry['name']);
            }
        } catch (Throwable $e) {
            fclose($output);
            @unlink($target);
            throw $e;
        }
        fclose($output);
        @chmod($target, 0644);

        if ($written !== $entry['uncompressed']) {
            @unlink($target);
            throw new RuntimeException('ZIP entry uncompressed size mismatch: ' . $entry['name']);
        }
        $actualCrc = strtolower(hash_final($crc));
        $expectedCrc = sprintf('%08x', $entry['crc'] & 0xffffffff);
        if (!hash_equals($expectedCrc, $actualCrc)) {
            @unlink($target);
            throw new RuntimeException('ZIP entry CRC32 mismatch: ' . $entry['name']);
        }
    }

    /** @param array<string,mixed> $manifest @return array{bytes:string,files:int,total_bytes:int,sha256:string} */
    private function buildTreeManifest(string $dir, string $archiveRoot, array $manifest): array
    {
        $required = [
            'index.php', 'install.php', 'core.php', 'core/Version.php',
            'bin/migrate.php', 'bin/healthcheck.php', 'config/update_trusted_keys.php',
        ];
        foreach ($required as $path) {
            if (!is_file($dir . '/' . $path) || is_link($dir . '/' . $path)) {
                throw new RuntimeException('Release candidate is missing required runtime file: ' . $path);
            }
        }
        foreach (['.env', 'tools/vendor-license', 'tools/vendor-update'] as $forbidden) {
            if (file_exists($dir . '/' . $forbidden)) {
                throw new RuntimeException('Release candidate contains forbidden customer artifact: ' . $forbidden);
            }
        }

        $versionSource = file_get_contents($dir . '/core/Version.php');
        if (!is_string($versionSource)) {
            throw new RuntimeException('Cannot read candidate version contract');
        }
        if (!preg_match("/public const VERSION = '([^']+)'/", $versionSource, $versionMatch)
            || !preg_match('/public const VERSION_CODE = ([0-9]+);/', $versionSource, $codeMatch)) {
            throw new RuntimeException('Candidate version contract cannot be parsed');
        }
        if (!hash_equals((string) ($manifest['version'] ?? ''), $versionMatch[1])
            || (int) ($manifest['version_code'] ?? 0) !== (int) $codeMatch[1]) {
            throw new RuntimeException('Candidate Version.php does not match signed update manifest');
        }

        $files = [];
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                throw new RuntimeException('Release candidate contains unsupported filesystem entry');
            }
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            if ($path === '.workspace-release-tree.json') {
                continue;
            }
            $size = $file->getSize();
            $hash = hash_file('sha256', $file->getPathname());
            if (!is_int($size) || !is_string($hash)) {
                throw new RuntimeException('Cannot hash release candidate file: ' . $path);
            }
            $files[$path] = ['sha256' => $hash, 'size' => $size];
            $total += $size;
        }
        ksort($files, SORT_STRING);
        $payload = [
            'schema' => 1,
            'archive_root' => $archiveRoot,
            'target_version' => (string) $manifest['version'],
            'target_version_code' => (int) $manifest['version_code'],
            'files' => $files,
            'file_count' => count($files),
            'total_bytes' => $total,
        ];
        $bytes = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        return ['bytes' => $bytes, 'files' => count($files), 'total_bytes' => $total, 'sha256' => hash('sha256', $bytes)];
    }

    /** @param array<string,mixed> $manifest @return array{candidate_dir:string,archive_root:string,files:int,total_bytes:int,tree_manifest:string,tree_sha256:string} */
    private function verifyCandidate(string $dir, string $archiveRoot, array $manifest): array
    {
        if (is_link($dir) || !is_dir($dir)) {
            throw new RuntimeException('Release candidate directory is unsafe');
        }
        $treePath = $dir . '/.workspace-release-tree.json';
        if (!is_file($treePath) || is_link($treePath)) {
            throw new RuntimeException('Release candidate tree manifest is missing');
        }
        $expected = file_get_contents($treePath);
        if (!is_string($expected)) {
            throw new RuntimeException('Cannot read release candidate tree manifest');
        }
        $rebuilt = $this->buildTreeManifest($dir, $archiveRoot, $manifest);
        if (!hash_equals(hash('sha256', $expected), $rebuilt['sha256'])) {
            throw new RuntimeException('Existing release candidate tree verification failed');
        }
        return [
            'candidate_dir' => $dir,
            'archive_root' => $archiveRoot,
            'files' => $rebuilt['files'],
            'total_bytes' => $rebuilt['total_bytes'],
            'tree_manifest' => $treePath,
            'tree_sha256' => $rebuilt['sha256'],
        ];
    }

    private function relativePath(string $name, string $root): string
    {
        $trimmed = rtrim($name, '/');
        if ($trimmed === $root) {
            return '';
        }
        $prefix = $root . '/';
        if (!str_starts_with($name, $prefix)) {
            throw new RuntimeException('Archive entry escaped expected bundle root');
        }
        return rtrim(substr($name, strlen($prefix)), '/');
    }

    private function prepareExternalRoot(string $path): string
    {
        $path = trim($path);
        if (!$this->isAbsolute($path) || is_link($path)) {
            throw new RuntimeException('Release candidate root must be an absolute non-symlink path');
        }
        if (!is_dir($path)) {
            $old = umask(0077);
            $ok = @mkdir($path, 0700, true);
            umask($old);
            if (!$ok && !is_dir($path)) {
                throw new RuntimeException('Cannot create release candidate root');
            }
        }
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real) || !is_writable($real)) {
            throw new RuntimeException('Release candidate root is unavailable');
        }
        $real = $this->normalize($real);
        if ($this->inside($real, $this->appRoot)) {
            throw new RuntimeException('Release candidate root must be outside the live application tree');
        }
        return $real;
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            return;
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Release candidate directory path collides with existing entry');
        }
        $old = umask(0022);
        $ok = @mkdir($path, 0755, true);
        umask($old);
        if (!$ok && !is_dir($path)) {
            throw new RuntimeException('Cannot create release candidate directory');
        }
    }

    private function writeExclusive(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write release candidate tree manifest');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Cannot write complete release candidate tree manifest');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0644);
    }

    /** @param resource $handle */
    private function readExact($handle, int $length): string
    {
        if ($length === 0) {
            return '';
        }
        $bytes = fread($handle, $length);
        if (!is_string($bytes) || strlen($bytes) !== $length) {
            throw new RuntimeException('Update ZIP is truncated during extraction');
        }
        return $bytes;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private function inside(string $path, string $parent): bool
    {
        $path = $this->normalize($path);
        $parent = $this->normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    private function removeTree(string $dir): void
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
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
