<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach (['Version', 'UpdateManifestVerifier', 'UpdatePackageStager', 'UpdateArchiveInspector',
    'UpdateRemoteTransport', 'UpdateRemoteDelivery'] as $name) {
    require_once $root . '/core/' . $name . '.php';
}
$directory = sys_get_temp_dir() . '/update-attempt-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$path = $directory . '/events.jsonl';
$previousPath = getenv('SERVICE_LOG_PATH');
putenv('SERVICE_LOG_PATH=' . $path);
try {
    $pair = sodium_crypto_sign_keypair();
    $key = Core\UpdateManifestVerifier::base64UrlEncode(sodium_crypto_sign_publickey($pair));
    $transport = new class implements Core\UpdateRemoteTransport {
        public function fetchText(string $url, int $maxBytes): string {
            throw new RuntimeException('Authorization: Bearer SHOULD_NOT_LEAK', 403);
        }
        public function downloadExact(string $url, string $destination, int $expectedBytes, string $expectedSha256): array {
            throw new RuntimeException('Загрузка не должна вызываться');
        }
    };
    $delivery = new Core\UpdateRemoteDelivery($root, new Core\UpdateManifestVerifier(['test' => $key]), $transport);
    $rejected = false;
    try { $delivery->check('https://updates.example.test/stable/feed.json', 'stable', 10014, '8.1.33'); }
    catch (RuntimeException $error) { $rejected = $error->getCode() === 403; }
    if (!$rejected) throw new RuntimeException('Первичная ошибка доставки не сохранена');
    $bytes = file_get_contents($path);
    $events = array_map(static fn (string $line): array => json_decode($line, true, 16, JSON_THROW_ON_ERROR),
        array_filter(explode("\n", (string) $bytes)));
    if (count($events) !== 2
        || $events[0]['event'] !== 'updater.attempt_started'
        || $events[1]['event'] !== 'updater.attempt_failed'
        || $events[1]['context']['phase'] !== 'feed'
        || $events[1]['context']['http_status'] !== 403
        || $events[0]['context']['attempt_id'] !== $events[1]['context']['attempt_id']
        || str_contains((string) $bytes, 'SHOULD_NOT_LEAK')) {
        throw new RuntimeException('Нарушен контракт безопасной ранней диагностики');
    }
    echo "[OK] Отказ до транзакции сохраняется без секретов, с этапом и HTTP-кодом\n";
} finally {
    putenv($previousPath === false ? 'SERVICE_LOG_PATH' : 'SERVICE_LOG_PATH=' . $previousPath);
    if (is_file($path)) unlink($path);
    rmdir($directory);
}
