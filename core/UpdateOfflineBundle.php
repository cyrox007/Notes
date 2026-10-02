<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

require_once __DIR__ . '/UpdateArchiveInspector.php';
require_once __DIR__ . '/UpdateManifestVerifier.php';
require_once __DIR__ . '/UpdatePackageStager.php';
require_once __DIR__ . '/UpdatePath.php';

final class UpdateOfflineBundle
{
    public const MAX_BUNDLE_BYTES = 536_870_912; // 512 MiB

    private string $appRoot;

    public function __construct(
        ?string $appRoot = null,
        private ?UpdateManifestVerifier $verifier = null
    ) {
        $root = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('Не удалось определить корень приложения для ручного обновления');
        }
        $this->appRoot = UpdatePath::normalize($root, false);
        $this->verifier ??= new UpdateManifestVerifier();
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    public function stageUploaded(
        array $file,
        string $stageRoot,
        int $currentVersionCode,
        string $currentPhpVersion
    ): array {
        $this->validateUpload($file);
        $tmpName = (string) ($file['tmp_name'] ?? '');

        return $this->stageFile(
            $tmpName,
            $stageRoot,
            $currentVersionCode,
            $currentPhpVersion
        );
    }

    /** @return array<string,mixed> */
    public function stageFile(
        string $bundlePath,
        string $stageRoot,
        int $currentVersionCode,
        string $currentPhpVersion
    ): array {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Для ручного offline-пакета требуется PHP extension zip');
        }
        if ($bundlePath === '' || !is_file($bundlePath) || is_link($bundlePath)) {
            throw new InvalidArgumentException('Offline-пакет обновления недоступен');
        }

        $size = filesize($bundlePath);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_BUNDLE_BYTES) {
            throw new InvalidArgumentException('Offline-пакет пустой или превышает 512 МБ');
        }

        // Прежде чем ZipArchive увидит содержимое, применяем те же ограничения
        // путей/типов/размеров, что и для обычного update ZIP.
        (new UpdateArchiveInspector())->inspect($bundlePath);

        $stageRoot = $this->prepareStageRoot($stageRoot);
        $zip = new ZipArchive();
        $opened = $zip->open($bundlePath, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw new InvalidArgumentException('Не удалось открыть offline-пакет обновления');
        }

        $packageTemp = null;
        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
                    throw new InvalidArgumentException('Offline-пакет должен содержать только файлы верхнего уровня');
                }
                $names[] = $name;
            }

            if (count($names) !== 3
                || !in_array('manifest.json', $names, true)
                || !in_array('manifest.sig', $names, true)) {
                throw new InvalidArgumentException(
                    'Offline-пакет должен содержать manifest.json, manifest.sig и один ZIP релиза'
                );
            }

            $manifestBytes = $zip->getFromName('manifest.json');
            $signature = $zip->getFromName('manifest.sig');
            if (!is_string($manifestBytes) || !is_string($signature)) {
                throw new InvalidArgumentException('Offline-пакет не содержит читаемые подписанные метаданные');
            }

            $verified = $this->verifier->verify($manifestBytes, trim($signature));
            if (!($verified['valid'] ?? false) || !is_array($verified['manifest'] ?? null)) {
                throw new InvalidArgumentException(
                    (string) ($verified['message'] ?? 'Подпись offline-пакета недействительна')
                );
            }
            $manifest = $verified['manifest'];

            $packageName = (string) ($manifest['package']['filename'] ?? '');
            if ($packageName === ''
                || !str_ends_with(strtolower($packageName), '.zip')
                || !in_array($packageName, $names, true)) {
                throw new InvalidArgumentException(
                    'Offline-пакет не содержит ZIP, указанный в подписанном manifest'
                );
            }

            $packageEntryCount = 0;
            foreach ($names as $name) {
                if ($name !== 'manifest.json' && $name !== 'manifest.sig') {
                    $packageEntryCount++;
                }
            }
            if ($packageEntryCount !== 1) {
                throw new InvalidArgumentException('Offline-пакет содержит лишние файлы');
            }

            $stager = new UpdatePackageStager($this->appRoot);
            $stager->assertCompatibility($manifest, $currentVersionCode, $currentPhpVersion);

            $packageTemp = $stageRoot . DIRECTORY_SEPARATOR
                . '.offline-package-' . bin2hex(random_bytes(8)) . '-' . basename($packageName);
            $input = $zip->getStream($packageName);
            if (!is_resource($input)) {
                throw new RuntimeException('Не удалось открыть ZIP релиза внутри offline-пакета');
            }

            $oldUmask = umask(0077);
            $output = @fopen($packageTemp, 'xb');
            umask($oldUmask);
            if ($output === false) {
                fclose($input);
                throw new RuntimeException('Не удалось создать временный файл offline-обновления');
            }

            try {
                $copied = stream_copy_to_stream($input, $output);
                if (!is_int($copied) || $copied <= 0 || !fflush($output)) {
                    throw new RuntimeException('Offline ZIP релиза скопирован не полностью');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            @chmod($packageTemp, 0600);

            $staged = $stager->stage(
                $manifest,
                $manifestBytes,
                trim($signature),
                $packageTemp,
                $stageRoot,
                $currentVersionCode,
                $currentPhpVersion
            );

            $archive = (new UpdateArchiveInspector())->inspect((string) $staged['package']);

            return [
                'status' => 'staged',
                'source' => 'offline_bundle',
                'channel' => (string) ($manifest['channel'] ?? ''),
                'target_version' => (string) ($manifest['version'] ?? ''),
                'target_version_code' => (int) ($manifest['version_code'] ?? 0),
                'source_commit' => (string) ($manifest['source_commit'] ?? ''),
                'key_id' => (string) ($verified['key_id'] ?? ''),
                'package_filename' => $packageName,
                'package_sha256' => strtolower((string) ($manifest['package']['sha256'] ?? '')),
                'stage_dir' => (string) ($staged['stage_dir'] ?? ''),
                'archive' => $archive,
                'live_files_changed' => false,
            ];
        } finally {
            $zip->close();
            if (is_string($packageTemp) && $packageTemp !== '' && is_file($packageTemp)) {
                @unlink($packageTemp);
            }
        }
    }

    /** @param array<string,mixed> $file */
    private function validateUpload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Offline-пакет превышает лимит загрузки PHP',
                UPLOAD_ERR_PARTIAL => 'Offline-пакет загружен не полностью',
                UPLOAD_ERR_NO_FILE => 'Выберите offline-пакет обновления',
                UPLOAD_ERR_NO_TMP_DIR => 'На сервере отсутствует временный каталог PHP',
                UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать загруженный пакет',
                default => 'Ошибка загрузки offline-пакета',
            });
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('Некорректная загрузка offline-пакета');
        }

        $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? '')));
        if ($name === '' || strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            throw new InvalidArgumentException('Offline-пакет должен быть ZIP-файлом');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BUNDLE_BYTES) {
            throw new InvalidArgumentException('Offline-пакет пустой или превышает 512 МБ');
        }
    }

    private function prepareStageRoot(string $path): string
    {
        $path = trim($path);
        if (!UpdatePath::isAbsolute($path) || is_link($path)) {
            throw new RuntimeException('Каталог staging для offline-пакета должен быть абсолютным и не symlink');
        }
        if (!is_dir($path)) {
            $oldUmask = umask(0077);
            $created = @mkdir($path, 0700, true);
            umask($oldUmask);
            if (!$created && !is_dir($path)) {
                throw new RuntimeException('Не удалось создать каталог staging для offline-пакета');
            }
        }

        $real = realpath($path);
        if (!is_string($real) || !is_dir($real) || !is_writable($real)) {
            throw new RuntimeException('Каталог staging для offline-пакета недоступен');
        }
        $real = UpdatePath::normalize($real, false);
        if (UpdatePath::inside($real, $this->appRoot)) {
            throw new RuntimeException('Каталог staging должен находиться вне дерева приложения');
        }
        return $real;
    }
}
