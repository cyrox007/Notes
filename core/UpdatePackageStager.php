<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/UpdatePath.php';

use RuntimeException;
use Throwable;

final class UpdatePackageStager
{
    private string $appRoot;

    public function __construct(?string $appRoot = null)
    {
        $root = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('Application root cannot be resolved');
        }
        $this->appRoot = $this->normalizePath($root);
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array{sha256:string,size:int,filename:string}
     */
    public function verifyPackage(array $manifest, string $packagePath): array
    {
        $package = $manifest['package'] ?? null;
        if (!is_array($package)) {
            throw new RuntimeException('Verified update manifest does not contain package metadata');
        }

        if ($packagePath === '' || !is_file($packagePath) || is_link($packagePath)) {
            throw new RuntimeException('Update package must be a regular local file, not a symlink');
        }

        $expectedName = (string) ($package['filename'] ?? '');
        if (basename($packagePath) !== $expectedName) {
            throw new RuntimeException('Update package filename does not match the signed manifest');
        }

        $size = filesize($packagePath);
        if (!is_int($size) || $size <= 0 || $size !== (int) ($package['size'] ?? -1)) {
            throw new RuntimeException('Update package size does not match the signed manifest');
        }

        $sha256 = hash_file('sha256', $packagePath);
        if (!is_string($sha256) || !hash_equals((string) ($package['sha256'] ?? ''), $sha256)) {
            throw new RuntimeException('Update package SHA-256 does not match the signed manifest');
        }

        $handle = fopen($packagePath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Update package cannot be opened');
        }
        try {
            $magic = fread($handle, 4);
        } finally {
            fclose($handle);
        }
        if (!in_array($magic, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            throw new RuntimeException('Signed update package is not a ZIP archive');
        }

        return [
            'sha256' => $sha256,
            'size' => $size,
            'filename' => $expectedName,
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     */
    public function assertCompatibility(array $manifest, int $currentVersionCode, string $currentPhpVersion): void
    {
        $target = (int) ($manifest['version_code'] ?? 0);
        $minimumSource = (int) ($manifest['min_source_version_code'] ?? 0);
        $requiredPhp = (string) ($manifest['requires_php'] ?? '');

        if ($target <= $currentVersionCode) {
            throw new RuntimeException('Refusing update: target version_code must be newer than the installed version');
        }
        if ($currentVersionCode < $minimumSource) {
            throw new RuntimeException('Refusing update: installed version is older than this package supports');
        }
        if ($requiredPhp === '' || version_compare($currentPhpVersion, $requiredPhp, '<')) {
            throw new RuntimeException("Refusing update: PHP {$requiredPhp}+ is required");
        }
    }

    /**
     * Copy a verified update artifact set into an external immutable staging directory.
     * This method never extracts files and never modifies the live application tree.
     *
     * @param array<string,mixed> $manifest
     * @return array{stage_dir:string,package:string,manifest:string,signature:string,metadata:string,sha256:string}
     */
    public function stage(
        array $manifest,
        string $manifestBytes,
        string $signatureToken,
        string $packagePath,
        string $stageRoot,
        int $currentVersionCode,
        string $currentPhpVersion
    ): array {
        $this->assertCompatibility($manifest, $currentVersionCode, $currentPhpVersion);
        $verified = $this->verifyPackage($manifest, $packagePath);
        $stageRoot = $this->prepareExternalDirectory($stageRoot);

        $lockPath = $stageRoot . DIRECTORY_SEPARATOR . '.update-stage.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot create updater staging lock');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another update staging operation is already running');
        }

        $targetVersionCode = (int) $manifest['version_code'];
        $stageName = sprintf('%d-%s', $targetVersionCode, substr($verified['sha256'], 0, 16));
        $finalDir = $stageRoot . DIRECTORY_SEPARATOR . $stageName;
        $tempDir = $stageRoot . DIRECTORY_SEPARATOR . '.tmp-' . $stageName . '-' . bin2hex(random_bytes(6));

        try {
            if (is_dir($finalDir)) {
                return $this->verifyExistingStage($finalDir, $manifestBytes, $signatureToken, $verified);
            }
            if (!mkdir($tempDir, 0700, false) && !is_dir($tempDir)) {
                throw new RuntimeException('Cannot create temporary update staging directory');
            }

            $stagedPackage = $tempDir . DIRECTORY_SEPARATOR . $verified['filename'];
            $this->copyFileVerified($packagePath, $stagedPackage, $verified['sha256'], $verified['size']);

            $manifestPath = $tempDir . DIRECTORY_SEPARATOR . 'manifest.json';
            $signaturePath = $tempDir . DIRECTORY_SEPARATOR . 'manifest.sig';
            $metadataPath = $tempDir . DIRECTORY_SEPARATOR . 'stage.json';

            $this->writeExclusive($manifestPath, $manifestBytes);
            $this->writeExclusive($signaturePath, trim($signatureToken) . PHP_EOL);
            $metadata = json_encode([
                'schema' => 1,
                'state' => 'verified_staged',
                'current_version_code' => $currentVersionCode,
                'target_version' => (string) $manifest['version'],
                'target_version_code' => $targetVersionCode,
                'source_commit' => (string) $manifest['source_commit'],
                'package_sha256' => $verified['sha256'],
                'staged_at' => time(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            $this->writeExclusive($metadataPath, $metadata);

            if (!rename($tempDir, $finalDir)) {
                throw new RuntimeException('Cannot atomically finalize update staging directory');
            }

            return [
                'stage_dir' => $finalDir,
                'package' => $finalDir . DIRECTORY_SEPARATOR . $verified['filename'],
                'manifest' => $finalDir . DIRECTORY_SEPARATOR . 'manifest.json',
                'signature' => $finalDir . DIRECTORY_SEPARATOR . 'manifest.sig',
                'metadata' => $finalDir . DIRECTORY_SEPARATOR . 'stage.json',
                'sha256' => $verified['sha256'],
            ];
        } catch (Throwable $e) {
            if (is_dir($tempDir)) {
                $this->removeTree($tempDir);
            }
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array{sha256:string,size:int,filename:string} $verified
     * @return array{stage_dir:string,package:string,manifest:string,signature:string,metadata:string,sha256:string}
     */
    private function verifyExistingStage(
        string $dir,
        string $manifestBytes,
        string $signatureToken,
        array $verified
    ): array {
        $manifestPath = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
        $signaturePath = $dir . DIRECTORY_SEPARATOR . 'manifest.sig';
        $metadataPath = $dir . DIRECTORY_SEPARATOR . 'stage.json';
        $packagePath = $dir . DIRECTORY_SEPARATOR . $verified['filename'];

        $existingManifest = is_file($manifestPath) ? file_get_contents($manifestPath) : false;
        $existingSignature = is_file($signaturePath) ? trim((string) file_get_contents($signaturePath)) : '';
        if (!is_string($existingManifest) || !hash_equals(hash('sha256', $manifestBytes), hash('sha256', $existingManifest))) {
            throw new RuntimeException('Existing stage manifest does not match the requested update');
        }
        if (!hash_equals(trim($signatureToken), $existingSignature)) {
            throw new RuntimeException('Existing stage signature does not match the requested update');
        }
        $this->verifyPackage(['package' => [
            'filename' => $verified['filename'],
            'sha256' => $verified['sha256'],
            'size' => $verified['size'],
        ]], $packagePath);
        if (!is_file($metadataPath)) {
            throw new RuntimeException('Existing update stage metadata is missing');
        }

        return [
            'stage_dir' => $dir,
            'package' => $packagePath,
            'manifest' => $manifestPath,
            'signature' => $signaturePath,
            'metadata' => $metadataPath,
            'sha256' => $verified['sha256'],
        ];
    }

    private function copyFileVerified(string $source, string $destination, string $sha256, int $size): void
    {
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Cannot create staged update package');
        }

        try {
            $copied = stream_copy_to_stream($input, $output);
            if ($copied !== $size || !fflush($output)) {
                throw new RuntimeException('Staged update package copy is incomplete');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        @chmod($destination, 0600);
        $copiedHash = hash_file('sha256', $destination);
        if (!is_string($copiedHash) || !hash_equals($sha256, $copiedHash)) {
            @unlink($destination);
            throw new RuntimeException('Staged update package failed post-copy SHA-256 verification');
        }
    }

    private function writeExclusive(string $path, string $contents): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create updater staging metadata');
        }
        try {
            $written = fwrite($handle, $contents);
            if ($written !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException('Cannot write updater staging metadata');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    private function prepareExternalDirectory(string $path): string
    {
        $path = trim($path);
        if (!$this->isAbsolutePath($path)) {
            throw new RuntimeException('Update staging path must be absolute');
        }
        if (is_link($path)) {
            throw new RuntimeException('Update staging root must not be a symlink');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create update staging root');
        }
        @chmod($path, 0700);

        $real = realpath($path);
        if (!is_string($real) || !is_dir($real) || !is_writable($real)) {
            throw new RuntimeException('Update staging root is not writable');
        }
        $real = $this->normalizePath($real);
        if ($this->pathInside($real, $this->appRoot)) {
            throw new RuntimeException('Update staging root must be outside the live application tree');
        }
        return $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        return UpdatePath::isAbsolute($path);
    }

    private function normalizePath(string $path): string
    {
        return UpdatePath::normalize($path, false);
    }

    private function pathInside(string $path, string $parent): bool
    {
        return UpdatePath::inside($path, $parent);
    }

    private function removeTree(string $dir): void
    {
        UpdatePath::removeTree($dir);
    }
}
