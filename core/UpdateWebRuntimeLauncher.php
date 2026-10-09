<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

require_once __DIR__ . '/UpdateExternalRuntime.php';

/** Публикует неизменяемый HTTP-вход, не зависящий от index.php и live-классов. */
final class UpdateWebRuntimeLauncher
{
    /** @param array<string,mixed> $runtime */
    public function publish(string $appRoot, string $stateRoot, string $transactionId, array $runtime): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/D', $transactionId) !== 1) {
            throw new RuntimeException('Некорректная транзакция браузерного runtime');
        }
        $runtime = (new UpdateExternalRuntime($appRoot))->verifyRecorded($runtime, (int) $runtime['source_version_code']);
        $directory = $appRoot . '/update-continuations';
        if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
            throw new RuntimeException('Небезопасный каталог HTTP-продолжения');
        }
        if (!is_dir($directory) && !mkdir($directory, 0755)) {
            throw new RuntimeException('Не удалось создать каталог HTTP-продолжения');
        }
        $template = file_get_contents($runtime['runtime_root'] . '/bin/update_web_entry.php');
        if (!is_string($template) || substr_count($template, '/* UPDATE_WEB_CONFIG */ []') !== 1) {
            throw new RuntimeException('Повреждён шаблон HTTP-продолжения');
        }
        $config = [
            'app_root' => $appRoot,
            'state_root' => $stateRoot,
            'transaction_id' => $transactionId,
            'runtime_root' => $runtime['runtime_root'],
            'manifest_sha256' => $runtime['manifest_sha256'],
            'site_url' => (string) (getenv('SITEURL') ?: ''),
        ];
        $bytes = str_replace('/* UPDATE_WEB_CONFIG */ []', var_export($config, true), $template);
        $name = bin2hex(random_bytes(16)) . '.php';
        $temporary = $directory . '/.' . $name . '.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('Не удалось записать HTTP-продолжение');
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Неполная запись HTTP-продолжения');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Не удалось синхронизировать HTTP-продолжение');
            }
        } finally {
            fclose($handle);
        }
        // Вход не содержит токен или секреты; Apache должен прочитать его и при отдельном FPM uid.
        chmod($temporary, 0644);
        if (!rename($temporary, $directory . '/' . $name)) {
            throw new RuntimeException('Не удалось опубликовать HTTP-продолжение');
        }
        $base = '/' . trim((string) (getenv('BASE_PATH') ?: ''), '/');
        $url = rtrim($base, '/') . '/update-continuations/' . $name;
        $mapRoot = $stateRoot . '/web-endpoints';
        if (is_link($mapRoot) || (!is_dir($mapRoot) && !mkdir($mapRoot, 0700))) {
            throw new RuntimeException('Не удалось сохранить адрес HTTP-продолжения');
        }
        $mapPath = $mapRoot . '/' . $transactionId . '.json';
        $map = json_encode(['transaction_id' => $transactionId, 'url' => $url], JSON_THROW_ON_ERROR);
        if (file_exists($mapPath) || is_link($mapPath)
            || file_put_contents($mapPath, $map, LOCK_EX) !== strlen($map)) {
            throw new RuntimeException('Не удалось привязать адрес HTTP-продолжения');
        }
        chmod($mapPath, 0600);
        return $url;
    }

    public function recordedUrl(string $stateRoot, string $transactionId): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/D', $transactionId) !== 1) {
            throw new RuntimeException('Некорректная транзакция HTTP-продолжения');
        }
        $path = $stateRoot . '/web-endpoints/' . $transactionId . '.json';
        if (!file_exists($path)) return '';
        if (is_link(dirname($path)) || is_link($path) || !is_file($path) || filesize($path) > 4096) {
            throw new RuntimeException('Небезопасный адрес HTTP-продолжения');
        }
        $map = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        $url = $map['url'] ?? '';
        if (($map['transaction_id'] ?? '') !== $transactionId || !is_string($url)
            || !str_starts_with($url, '/') || str_starts_with($url, '//')
            || !str_contains($url, '/update-continuations/') || str_contains($url, '\\')) {
            throw new RuntimeException('Повреждён адрес HTTP-продолжения');
        }
        return $url;
    }
}
