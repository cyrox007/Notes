<?php

declare(strict_types=1);

use Core\UpdateHttpsTransport;

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateRemoteTransport.php';

function remoteAddressAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

remoteAddressAssert(extension_loaded('openssl'), 'openssl is required');

$transport = new UpdateHttpsTransport(1, 1);
$reflection = new ReflectionClass(UpdateHttpsTransport::class);
$addressMethod = $reflection->getMethod('isPublicAddress');
$addressMethod->setAccessible(true);
$urlMethod = $reflection->getMethod('parseHttpsUrl');
$urlMethod->setAccessible(true);

foreach ([
    '8.8.8.8',
    '1.1.1.1',
    '2606:4700:4700::1111',
    '2001:4860:4860::8888',
] as $ip) {
    remoteAddressAssert(
        $addressMethod->invoke($transport, $ip) === true,
        "public updater address was rejected: {$ip}"
    );
}

foreach ([
    '0.0.0.0',
    '10.0.0.1',
    '100.64.0.1',
    '127.0.0.1',
    '169.254.169.254',
    '172.16.0.1',
    '192.0.0.1',
    '192.0.2.1',
    '192.168.1.1',
    '198.18.0.1',
    '198.51.100.1',
    '203.0.113.1',
    '224.0.0.1',
    '255.255.255.255',
    '::1',
    '::ffff:127.0.0.1',
    '::ffff:10.0.0.1',
    '64:ff9b::1',
    '64:ff9b:1::1',
    '100::1',
    '2001::1',
    '2001:db8::1',
    '2002:c000:0201::1',
    '3fff::1',
    '5f00::1',
    'fc00::1',
    'fe80::1',
    'fec0::1',
    'ff02::1',
] as $ip) {
    remoteAddressAssert(
        $addressMethod->invoke($transport, $ip) === false,
        "special/private updater address was accepted: {$ip}"
    );
}

remoteAddressAssert(
    $addressMethod->invoke($transport, 'not-an-ip') === false,
    'invalid updater network address was accepted'
);

$validUrl = $urlMethod->invoke($transport, 'https://updates.example.test/stable/feed.json?channel=stable');
remoteAddressAssert(
    is_array($validUrl)
        && ($validUrl['host'] ?? '') === 'updates.example.test'
        && ($validUrl['request_target'] ?? '') === '/stable/feed.json?channel=stable',
    'valid updater HTTPS URL did not preserve a safe request target'
);

foreach ([
    "https://updates.example.test/stable/feed json",
    "https://updates.example.test/stable/feed.json?x=hello world",
    "https://updates.example.test/stable/feed.json?x=ok\r\nX-Evil: yes",
    "https://updates.example.test/stable/line\nfeed.json",
] as $unsafeUrl) {
    $rejected = false;
    try {
        $urlMethod->invoke($transport, $unsafeUrl);
    } catch (Throwable $e) {
        $rejected = str_contains(strtolower($e->getMessage()), 'unsafe')
            || str_contains(strtolower($e->getMessage()), 'invalid');
    }
    remoteAddressAssert($rejected, 'unsafe updater request target was accepted');
}

echo "[OK] updater remote address policy contract\n";
