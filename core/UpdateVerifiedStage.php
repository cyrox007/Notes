<?php

declare(strict_types=1);

namespace Core;

use JsonException;
use RuntimeException;

require_once __DIR__ . '/UpdateManifestVerifier.php';
require_once __DIR__ . '/UpdatePackageStager.php';
require_once __DIR__ . '/UpdateArchiveInspector.php';
require_once __DIR__ . '/Version.php';

/**
 * Повторная проверка уже подготовленного подписанного пакета.
 *
 * Используется одинаково CLI- и web-контуром перед резервным копированием,
 * извлечением кандидата и destructive-фазой.
 */
final class UpdateVerifiedStage
{
    private string $appRoot;
    private UpdateManifestVerifier $verifier;

    public function __construct(
        ?string $appRoot = null,
        ?UpdateManifestVerifier $verifier = null
    ) {
        $resolved = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolved) || !is_dir($resolved) || is_link($resolved)) {
            throw new RuntimeException('Не удалось безопасно определить корень приложения');
        }

        $this->appRoot = $this->normalize($resolved);
        $this->verifier = $verifier ?? new UpdateManifestVerifier();
    }

    /**
     * @return array{
     *   stage_dir:string,
     *   manifest:array<string,mixed>,
     *   package_path:string,
     *   package_sha256:string,
     *   target_version:string,
     *   target_version_code:int,
     *   archive:array<string,mixed>
     * }
     */
    public function inspect(
        string $stageDir,
        ?int $expectedTargetVersionCode = null,
        ?string $expectedPackageSha256 = null
    ): array {
        $stageDir = $this->safeStage($stageDir);

        foreach (['manifest.json', 'manifest.sig', 'stage.json'] as $required) {
            $path = $stageDir . DIRECTORY_SEPARATOR . $required;
            if (!is_file($path) || is_link($path) || !is_readable($path)) {
                throw new RuntimeException("Проверенный staging не содержит безопасный {$required}");
            }
        }

        $manifestBytes = file_get_contents($stageDir . DIRECTORY_SEPARATOR . 'manifest.json');
        $signatureToken = file_get_contents($stageDir . DIRECTORY_SEPARATOR . 'manifest.sig');
        $stageBytes = file_get_contents($stageDir . DIRECTORY_SEPARATOR . 'stage.json');
        if (!is_string($manifestBytes) || !is_string($signatureToken) || !is_string($stageBytes)) {
            throw new RuntimeException('Не удалось прочитать метаданные проверенного staging');
        }

        if (!$this->verifier->hasTrustedKeys()) {
            throw new RuntimeException('Не настроен доверенный публичный ключ обновлений');
        }

        $verified = $this->verifier->verify($manifestBytes, trim($signatureToken));
        if (!($verified['valid'] ?? false) || !is_array($verified['manifest'] ?? null)) {
            throw new RuntimeException(
                (string) ($verified['message'] ?? 'Подпись staged-пакета недействительна')
            );
        }
        $manifest = $verified['manifest'];

        try {
            $stageMetadata = json_decode($stageBytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Метаданные staging содержат некорректный JSON', 0, $e);
        }
        if (!is_array($stageMetadata)
            || array_is_list($stageMetadata)
            || ($stageMetadata['state'] ?? null) !== 'verified_staged') {
            throw new RuntimeException('Метаданные staging не подтверждают проверенный пакет');
        }

        $packageName = (string) ($manifest['package']['filename'] ?? '');
        if ($packageName === '' || basename($packageName) !== $packageName) {
            throw new RuntimeException('Manifest содержит небезопасное имя пакета');
        }

        $packagePath = $stageDir . DIRECTORY_SEPARATOR . $packageName;
        $stager = new UpdatePackageStager($this->appRoot);
        $stager->assertCompatibility($manifest, Version::VERSION_CODE, PHP_VERSION);
        $package = $stager->verifyPackage($manifest, $packagePath);
        $archive = (new UpdateArchiveInspector())->inspect($packagePath);

        $targetVersion = trim((string) ($manifest['version'] ?? ''));
        $targetVersionCode = (int) ($manifest['version_code'] ?? 0);
        $packageSha = strtolower((string) ($package['sha256'] ?? ''));

        if ((int) ($stageMetadata['current_version_code'] ?? -1) !== Version::VERSION_CODE
            || (int) ($stageMetadata['target_version_code'] ?? -1) !== $targetVersionCode
            || (string) ($stageMetadata['target_version'] ?? '') !== $targetVersion
            || !hash_equals(
                strtolower((string) ($stageMetadata['package_sha256'] ?? '')),
                $packageSha
            )) {
            throw new RuntimeException(
                'Метаданные staging больше не соответствуют установленной версии или подписанному пакету'
            );
        }

        if ($expectedTargetVersionCode !== null && $targetVersionCode !== $expectedTargetVersionCode) {
            throw new RuntimeException(
                'Подписанный релиз изменился после подтверждения. Повторите проверку обновлений.'
            );
        }

        if ($expectedPackageSha256 !== null) {
            $expectedPackageSha256 = strtolower(trim($expectedPackageSha256));
            if (preg_match('/^[0-9a-f]{64}$/D', $expectedPackageSha256) !== 1
                || !hash_equals($expectedPackageSha256, $packageSha)) {
                throw new RuntimeException(
                    'Подписанный пакет изменился после подтверждения. Повторите проверку обновлений.'
                );
            }
        }

        return [
            'stage_dir' => $stageDir,
            'manifest' => $manifest,
            'package_path' => $packagePath,
            'package_sha256' => $packageSha,
            'target_version' => $targetVersion,
            'target_version_code' => $targetVersionCode,
            'archive' => $archive,
        ];
    }

    private function safeStage(string $path): string
    {
        $path = trim($path);
        if ($path === '' || is_link($path)) {
            throw new RuntimeException('Staging должен быть локальным каталогом без symlink');
        }

        $real = realpath($path);
        if (!is_string($real) || !is_dir($real)) {
            throw new RuntimeException('Не удалось разрешить каталог staging');
        }

        $real = $this->normalize($real);
        if ($this->pathInside($real, $this->appRoot)) {
            throw new RuntimeException('Staging должен находиться вне дерева приложения');
        }

        return $real;
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private function pathInside(string $path, string $parent): bool
    {
        $path = $this->normalize($path);
        $parent = $this->normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }
}
