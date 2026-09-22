<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/LicenseServer.php';

// This is the ONLY public file. Route all requests here; never serve the private artifact directory.
ini_set('display_errors', '0');
ini_set('zlib.output_compression', '0');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

try {
    // Behind a reverse proxy set HTTPS=on in trusted server configuration, not from client headers.
    if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['HTTPS'] ?? '') !== '1') {
        throw new RuntimeException('HTTPS required', 400);
    }
    $base = (string) getenv('LICENSE_SERVER_BASE_URL');
    \Core\UpdateDownloadCredentials::validateBaseUrl($base);
    $prefix = (string) parse_url($base, PHP_URL_PATH);
    $path = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (!str_starts_with($path, $prefix) || str_contains($path, '?')) {
        throw new RuntimeException('Not found', 404);
    }
    $path = substr($path, strlen($prefix));
    $server = new \NotesVendor\LicenseServer(
        (string) getenv('LICENSE_SERVER_DB'),
        new \App\Services\LicenseVerifier(),
        new \Core\UpdateManifestVerifier()
    );
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($path === 'health' && $method === 'GET') {
        $health = $server->health();
        $response = ['body' => json_encode($health, JSON_THROW_ON_ERROR), 'type' => 'application/json'];
        if (($health['status'] ?? '') !== 'ok') {
            http_response_code(503);
        }
    } elseif ($path === 'activate' && $method === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 1024) {
            throw new RuntimeException('Request too large', 413);
        }
        $body = (string) file_get_contents('php://input', false, null, 0, 1025);
        if (strlen($body) > 1024) {
            throw new RuntimeException('Request too large', 413);
        }
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Invalid request', 400);
        }
        if (!is_array($data) || !is_string($data['installation_id'] ?? null) || !is_string($data['activation_code'] ?? null)) {
            throw new RuntimeException('Invalid request', 400);
        }
        $result = $server->activate($data['installation_id'], $data['activation_code']);
        $result['base_url'] = $base;
        $response = ['body' => json_encode($result, JSON_THROW_ON_ERROR), 'type' => 'application/json'];
    } elseif ($method === 'GET' && preg_match('~^(alpha|beta|stable)/([A-Za-z0-9][A-Za-z0-9._+-]{0,200})$~D', $path, $match) === 1) {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer ([0-9a-f]{64})$/D', $auth, $credential) !== 1) {
            throw new RuntimeException('Authentication required', 401);
        }
        $response = $server->artifact((string) ($_SERVER['HTTP_X_NOTES_INSTALLATION'] ?? ''), $credential[1], $match[1], $match[2]);
    } else {
        throw new RuntimeException('Not found', 404);
    }
    header('Content-Type: ' . $response['type']);
    header('Content-Length: ' . (isset($response['body']) ? strlen($response['body']) : $response['size']));
    if (isset($response['body'])) {
        echo $response['body'];
    } else {
        fpassthru($response['stream']);
        fclose($response['stream']);
    }
} catch (Throwable $e) {
    $status = in_array($e->getCode(), [400, 401, 403, 404, 413, 503], true) ? $e->getCode() : 503;
    http_response_code($status);
    $body = json_encode(['error' => match ($status) {
        401 => 'authentication_required', 403 => 'update_access_denied', 404 => 'not_found',
        400, 413 => 'invalid_request', default => 'service_unavailable',
    }], JSON_THROW_ON_ERROR);
    header('Content-Type: application/json');
    header('Content-Length: ' . strlen($body));
    echo $body;
    // Never log credentials, tokens, request bodies or database exception contents.
    error_log('notes-license-server status=' . $status);
}
