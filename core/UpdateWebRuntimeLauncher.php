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
        return rtrim($base, '/') . '/update-continuations/' . $name;
    }
}
