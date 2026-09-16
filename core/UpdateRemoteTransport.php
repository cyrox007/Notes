<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

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
 * - public DNS host names only (no literal/private/reserved addresses);
 * - DNS is resolved first and the checked address is pinned for the TLS socket;
 * - certificate + peer-name verification is mandatory;
 * - redirects, transfer-encoding and content-encoding are rejected;
 * - Content-Length is mandatory so every response is bounded before reading.
 *
 * The updater does not need ext-curl and does not depend on allow_url_fopen.
 */
final class UpdateHttpsTransport implements UpdateRemoteTransport
{
    private const MAX_HEADER_BYTES = 65536;

    public function __construct(
        private int $connectTimeoutSeconds = 10,
        private int $readTimeoutSeconds = 30
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
                $written = fwrite($output, $chunk);
                if (!is_int($written) || $written !== strlen($chunk)) {
                    throw new RuntimeException('Remote update package could not be written completely');
                }
                hash_update($hash, $chunk);
                $total += strlen($chunk);
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
    private function openResponse(string $url): array
    {
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

        $request = "GET {$target['request_target']} HTTP/1.1\r\n"
            . "Host: {$target['host']}\r\n"
            . "User-Agent: Workspace-Organizer-Updater/1.0\r\n"
            . "Accept: application/octet-stream, application/json;q=0.9, text/plain;q=0.8\r\n"
            . "Accept-Encoding: identity\r\n"
            . "Connection: close\r\n\r\n";
        try {
            $this->writeAll($stream, $request);
            [$status, $headers] = $this->readHeaders($stream);
            if ($status !== 200) {
                if ($status >= 300 && $status < 400) {
                    throw new RuntimeException('Remote update server redirects are not allowed');
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
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            throw new RuntimeException('Remote update URL is invalid');
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

        $host = strtolower(rtrim(trim((string) ($parts['host'] ?? '')), '.'));
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
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new RuntimeException('Remote update URL path is invalid');
        }
        $requestTarget = $path;
        if (isset($parts['query']) && (string) $parts['query'] !== '') {
            $requestTarget .= '?' . (string) $parts['query'];
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
            if (filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) !== false) {
                return $ip;
            }
        }
        throw new RuntimeException('Remote update server did not resolve to a public network address');
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
