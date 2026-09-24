<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/UpdateDownloadCredentials.php';

interface UpdateRemoteTransport
{
    public function fetchText(string $url, int $maxBytes): string;

    /** @return array{bytes:int,sha256:string} */
    public function downloadExact(
        string $url,
        string $destination,
        int $expectedBytes,
        string $expectedSha256
    ): array;
}

/**
 * Small vendor-free HTTPS transport for signed update artifacts.
 *
 * Deliberate constraints:
 * - HTTPS only, port 443 only;
 * - public DNS host names only (no literal/private/special-use addresses);
 * - DNS is resolved first and the checked address is pinned for the TLS socket;
 * - certificate + peer-name verification is mandatory;
 * - redirects, transfer-encoding and content-encoding are rejected;
 * - Content-Length is mandatory so every response is bounded before reading;
 * - request targets containing raw ASCII controls or spaces are rejected.
 *
 * The updater does not need ext-curl and does not depend on allow_url_fopen.
 */
final class UpdateHttpsTransport implements UpdateRemoteTransport
{
    private const MAX_HEADER_BYTES = 65536;

    /** @var list<string> */
    private const NON_PUBLIC_IPV4_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /** @var list<string> */
    private const NON_PUBLIC_IPV6_CIDRS = [
        '::/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        '3fff::/20',
        '5f00::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    public function __construct(
        private int $connectTimeoutSeconds = 10,
        private int $readTimeoutSeconds = 30,
        private ?UpdateDownloadCredentials $credentials = null
    ) {
        if ($connectTimeoutSeconds < 1 || $connectTimeoutSeconds > 60) {
            throw new RuntimeException('Updater HTTPS connect timeout is invalid');
        }
        if ($readTimeoutSeconds < 1 || $readTimeoutSeconds > 300) {
            throw new RuntimeException('Updater HTTPS read timeout is invalid');
        }
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('PHP openssl extension is required for remote update delivery');
        }
    }

    public static function fromEnvironment(int $connectTimeout = 10, int $readTimeout = 30): self
    {
        return new self($connectTimeout, $readTimeout, UpdateDownloadCredentials::fromEnvironment());
    }

    /** Совместимая активация по одноразовому коду для старых установок. */
    public function activate(string $baseUrl, string $installationId, string $activationCode): array
    {
        return $this->activateRequest(
            $baseUrl,
            [
                'installation_id' => $installationId,
                'activation_code' => $activationCode,
            ],
            1024
        );
    }

    /**
     * Автоматический bootstrap доступа к обновлениям по уже проверенной
     * installation-bound лицензии. Приватный ключ лицензирования не участвует.
     */
    public function activateWithLicense(
        string $baseUrl,
        string $installationId,
        string $licenseToken,
        string $version,
        int $versionCode,
        string $channel
    ): array {
        return $this->activateRequest(
            $baseUrl,
            [
                'installation_id' => $installationId,
                'license_token' => $licenseToken,
                'version' => $version,
                'version_code' => $versionCode,
                'channel' => $channel,
            ],
            24576
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function activateRequest(string $baseUrl, array $payload, int $maxRequestBytes): array
    {
        UpdateDownloadCredentials::validateBaseUrl($baseUrl);
        if ($this->credentials !== null) {
            throw new RuntimeException('Bootstrap updater выполняется только без действующих download credentials');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($body) > $maxRequestBytes) {
            throw new RuntimeException('Запрос активации updater слишком большой');
        }

        [$stream, $length] = $this->openResponse($baseUrl . 'activate', $body);
        try {
            if ($length < 1 || $length > 4096) {
                throw new RuntimeException('Сервер вернул некорректный размер ответа активации updater');
            }

            $result = json_decode($this->readExactString($stream, $length), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($result)) {
                throw new RuntimeException('Сервер вернул некорректный ответ активации updater');
            }
            return $result;
        } finally {
            fclose($stream);
        }
    }

    public function fetchText(string $url, int $maxBytes): string
    {
        if ($maxBytes < 1) {
            throw new RuntimeException('Remote update response limit is invalid');
        }

        [$stream, $length] = $this->openResponse($url);
        try {
            if ($length < 1 || $length > $maxBytes) {
                throw new RuntimeException('Remote update response has an invalid size');
            }
            return $this->readExactString($stream, $length);
        } finally {
            fclose($stream);
        }
    }

    public function downloadExact(
        string $url,
        string $destination,
        int $expectedBytes,
        string $expectedSha256
    ): array {
        if ($expectedBytes < 1) {
            throw new RuntimeException('Expected remote update package size is invalid');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $expectedSha256) !== 1) {
            throw new RuntimeException('Expected remote update package SHA-256 is invalid');
        }
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('Remote update download destination already exists');
        }
        $parent = realpath(dirname($destination));
        if (!is_string($parent) || !is_dir($parent) || !is_writable($parent) || is_link(dirname($destination))) {
            throw new RuntimeException('Remote update download directory is unsafe');
        }

        [$stream, $length] = $this->openResponse($url);
        if ($length !== $expectedBytes) {
            fclose($stream);
            throw new RuntimeException('Remote update package Content-Length does not match the signed manifest');
        }

        $output = @fopen($destination, 'xb');
        if ($output === false) {
            fclose($stream);
            throw new RuntimeException('Cannot create remote update package download');
        }
        @chmod($destination, 0600);

        $hash = hash_init('sha256');
        $total = 0;
        try {
            while ($total < $expectedBytes) {
                $remaining = $expectedBytes - $total;
                $chunk = fread($stream, min(131072, $remaining));
                if ($chunk === false || $chunk === '') {
                    $this->assertStreamHealthy($stream);
                    throw new RuntimeException('Remote update package download ended before Content-Length');
                }

                $offset = 0;
                $chunkLength = strlen($chunk);
                while ($offset < $chunkLength) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if (!is_int($written) || $written < 1) {
                        throw new RuntimeException('Remote update package could not be written completely');
                    }
                    $offset += $written;
                }

                hash_update($hash, $chunk);
                $total += $chunkLength;
            }
            if (!fflush($output)) {
                throw new RuntimeException('Remote update package could not be flushed to disk');
            }
        } catch (Throwable $e) {
            fclose($stream);
            fclose($output);
            @unlink($destination);
            throw $e;
        }
        fclose($stream);
        fclose($output);

        $actualSha256 = hash_final($hash);
        if (!hash_equals($expectedSha256, $actualSha256)) {
            @unlink($destination);
            throw new RuntimeException('Remote update package SHA-256 does not match the signed manifest');
        }

        return ['bytes' => $total, 'sha256' => $actualSha256];
    }

    /** @return array{0:resource,1:int} */
    private function openResponse(string $url, ?string $jsonBody = null): array
    {
        $authHeaders = $this->credentials?->headersFor($url) ?? '';
        $target = $this->parseHttpsUrl($url);
        $ip = $this->resolvePublicAddress($target['host']);
        $endpointIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $target['host'],
                'SNI_enabled' => true,
                'disable_compression' => true,
            ],
        ]);

        $errno = 0;
        $error = '';
        $stream = @stream_socket_client(
            'tls://' . $endpointIp . ':443',
            $errno,
            $error,
            $this->connectTimeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($stream)) {
            throw new RuntimeException('Cannot establish verified HTTPS connection to update server');
        }
        stream_set_timeout($stream, $this->readTimeoutSeconds);

        $method = $jsonBody === null ? 'GET' : 'POST';
        $request = "{$method} {$target['request_target']} HTTP/1.1\r\n"
            . "Host: {$target['host']}\r\n"
            . "User-Agent: Workspace-Organizer-Updater/1.0\r\n"
            . "Accept: application/octet-stream, application/json;q=0.9, text/plain;q=0.8\r\n"
            . "Accept-Encoding: identity\r\n"
            . $authHeaders
            . ($jsonBody === null ? '' : "Content-Type: application/json\r\nContent-Length: " . strlen($jsonBody) . "\r\n")
            . "Connection: close\r\n\r\n" . ($jsonBody ?? '');
        try {
            $this->writeAll($stream, $request);
            [$status, $headers] = $this->readHeaders($stream);
            if ($status !== 200) {
                if ($status >= 300 && $status < 400) {
                    throw new RuntimeException('Remote update server redirects are not allowed');
                }
                if ($status === 401 || $status === 403) {
                    throw new RuntimeException('Сервер отказал в доступе к обновлениям: проверьте активацию и право лицензии на обновления', $status);
                }
                throw new RuntimeException("Remote update server returned HTTP {$status}");
            }
            if (isset($headers['transfer-encoding'])) {
                throw new RuntimeException('Remote update server must not use Transfer-Encoding');
            }
            if (isset($headers['content-encoding']) && strtolower(trim($headers['content-encoding'])) !== 'identity') {
                throw new RuntimeException('Remote update server must return identity content encoding');
            }
            $length = $headers['content-length'] ?? null;
            if (!is_string($length) || preg_match('/^(0|[1-9][0-9]*)$/', trim($length)) !== 1) {
                throw new RuntimeException('Remote update server must provide a valid Content-Length');
            }
            $lengthInt = filter_var(trim($length), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
            ]);
            if (!is_int($lengthInt)) {
                throw new RuntimeException('Remote update Content-Length is outside the supported range');
            }
            return [$stream, $lengthInt];
        } catch (Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }

    /** @return array{host:string,request_target:string} */
    private function parseHttpsUrl(string $url): array
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new RuntimeException('Remote update URL contains unsafe characters');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('Remote updates require HTTPS');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('Remote update URL contains forbidden credentials or fragment');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new RuntimeException('Remote update HTTPS URL must use port 443');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            throw new RuntimeException('Remote update server must use a public DNS host name');
        }
        if (
            strlen($host) > 253
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
        ) {
            throw new RuntimeException('Remote update server host name is invalid');
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, '\\')) {
            throw new RuntimeException('Remote update URL path is invalid');
        }
        $requestTarget = $path;
        if (isset($parts['query']) && (string) $parts['query'] !== '') {
            $requestTarget .= '?' . (string) $parts['query'];
        }
        if (preg_match('/[\x00-\x20\x7f]/', $requestTarget) === 1) {
            throw new RuntimeException('Remote update URL request target contains unsafe characters');
        }

        return ['host' => $host, 'request_target' => $requestTarget];
    }

    private function resolvePublicAddress(string $host): string
    {
        $addresses = [];
        if (function_exists('dns_get_record')) {
            $types = 0;
            if (defined('DNS_A')) {
                $types |= DNS_A;
            }
            if (defined('DNS_AAAA')) {
                $types |= DNS_AAAA;
            }
            if ($types !== 0) {
                $records = @dns_get_record($host, $types);
                if (is_array($records)) {
                    foreach ($records as $record) {
                        if (isset($record['ip']) && is_string($record['ip'])) {
                            $addresses[] = $record['ip'];
                        }
                        if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                            $addresses[] = $record['ipv6'];
                        }
                    }
                }
            }
        }
        if ($addresses === [] && function_exists('gethostbynamel')) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                $addresses = array_merge($addresses, $ipv4);
            }
        }

        foreach (array_values(array_unique($addresses)) as $ip) {
            if ($this->isPublicAddress($ip)) {
                return $ip;
            }
        }
        throw new RuntimeException('Remote update server did not resolve to a public network address');
    }

    /**
     * PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE still accepts several
     * special-use networks (for example CGNAT, TEST-NET and multicast). For an
     * updater SSRF boundary we intentionally use a stricter conservative policy.
     */
    private function isPublicAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if (!is_string($packed)) {
            return false;
        }

        if (strlen($packed) === 4) {
            foreach (self::NON_PUBLIC_IPV4_CIDRS as $cidr) {
                if ($this->addressInCidr($ip, $cidr)) {
                    return false;
                }
            }
            return true;
        }

        if (strlen($packed) !== 16) {
            return false;
        }

        $mappedPrefix = str_repeat("\0", 10) . "\xff\xff";
        if (substr($packed, 0, 12) === $mappedPrefix) {
            $mapped = @inet_ntop(substr($packed, 12, 4));
            return is_string($mapped) && $this->isPublicAddress($mapped);
        }

        foreach (self::NON_PUBLIC_IPV6_CIDRS as $cidr) {
            if ($this->addressInCidr($ip, $cidr)) {
                return false;
            }
        }
        return true;
    }

    private function addressInCidr(string $ip, string $cidr): bool
    {
        [$network, $prefixText] = explode('/', $cidr, 2);
        $addressBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if (!is_string($addressBytes) || !is_string($networkBytes) || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $maxBits = strlen($addressBytes) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            throw new RuntimeException('Updater network policy contains an invalid CIDR prefix');
        }

        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
            return false;
        }
        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($addressBytes[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask);
    }

    /** @param resource $stream */
    private function writeAll($stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                $this->assertStreamHealthy($stream);
                throw new RuntimeException('Cannot send HTTPS request to update server');
            }
            $offset += $written;
        }
    }

    /** @param resource $stream @return array{0:int,1:array<string,string>} */
    private function readHeaders($stream): array
    {
        $statusLine = fgets($stream, 4096);
        if (!is_string($statusLine) || preg_match('/^HTTP\/1\.[01] ([0-9]{3})(?: |\r?$)/', $statusLine, $match) !== 1) {
            $this->assertStreamHealthy($stream);
            throw new RuntimeException('Remote update server returned an invalid HTTP status line');
        }
        $status = (int) $match[1];
        $headers = [];
        $headerBytes = strlen($statusLine);

        while (true) {
            $line = fgets($stream, 8192);
            if (!is_string($line)) {
                $this->assertStreamHealthy($stream);
                throw new RuntimeException('Remote update server ended HTTP headers unexpectedly');
            }
            $headerBytes += strlen($line);
            if ($headerBytes > self::MAX_HEADER_BYTES) {
                throw new RuntimeException('Remote update HTTP headers exceed the safety limit');
            }
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
            if ($line[0] === ' ' || $line[0] === "\t" || !str_contains($line, ':')) {
                throw new RuntimeException('Remote update server returned malformed HTTP headers');
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
                throw new RuntimeException('Remote update server returned an invalid HTTP header name');
            }
            if (isset($headers[$name])) {
                if ($name === 'content-length' && !hash_equals($headers[$name], $value)) {
                    throw new RuntimeException('Remote update server returned conflicting Content-Length headers');
                }
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }

        // A repeated identical Content-Length is legal but our concatenation above
        // would no longer be numeric. Normalize only the identical-value case.
        if (isset($headers['content-length']) && str_contains($headers['content-length'], ',')) {
            $parts = array_map('trim', explode(',', $headers['content-length']));
            if ($parts === [] || count(array_unique($parts)) !== 1) {
                throw new RuntimeException('Remote update server returned ambiguous Content-Length headers');
            }
            $headers['content-length'] = $parts[0];
        }

        return [$status, $headers];
    }

    /** @param resource $stream */
    private function readExactString($stream, int $length): string
    {
        $body = '';
        while (strlen($body) < $length) {
            $chunk = fread($stream, min(65536, $length - strlen($body)));
            if ($chunk === false || $chunk === '') {
                $this->assertStreamHealthy($stream);
                throw new RuntimeException('Remote update response ended before Content-Length');
            }
            $body .= $chunk;
        }
        return $body;
    }

    /** @param resource $stream */
    private function assertStreamHealthy($stream): void
    {
        $meta = stream_get_meta_data($stream);
        if (($meta['timed_out'] ?? false) === true) {
            throw new RuntimeException('Remote update HTTPS request timed out');
        }
    }
}
