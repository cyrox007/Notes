<?php

declare(strict_types=1);

namespace Core;

use JsonException;

class Request
{
    private const DEFAULT_MAX_JSON_BYTES = 1048576;
    private const MIN_MAX_JSON_BYTES = 1024;
    private const MAX_MAX_JSON_BYTES = 10485760;
    private const JSON_DEPTH = 64;

    private array $get = [];
    private array $post = [];
    private array $files = [];
    private array $server = [];
    private mixed $json = null;
    private ?string $jsonError = null;
    private array $session = [];

    public function __construct()
    {
        $this->initialize();
        $this->parseJson();
    }

    private function initialize(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->get = $_GET;
        $this->post = $_POST;
        $this->files = $_FILES;
        $this->server = $_SERVER;
        $this->session = &$_SESSION;
    }

    public function session($key = null, $default = null)
    {
        return $key === null ? $this->session : ($this->session[$key] ?? $default);
    }

    public function setSession($key, $value): void
    {
        $_SESSION[$key] = $value;
        $this->session[$key] = $value;
    }

    public function unsetSession($key): void
    {
        unset($_SESSION[$key], $this->session[$key]);
    }

    private function parseJson(): void
    {
        if (!$this->expectsJsonBody()) {
            $this->json = null;
            $this->jsonError = null;
            return;
        }

        $limit = self::maxJsonBodyBytes();
        $input = file_get_contents('php://input', false, null, 0, $limit + 1);
        if ($input === false) {
            $this->json = null;
            $this->jsonError = 'json_body_unreadable';
            return;
        }

        $this->decodeJsonBody($input, $limit);
    }

    private function expectsJsonBody(): bool
    {
        $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
        if ($contentType === '') {
            return false;
        }

        $contentType = trim(explode(';', $contentType, 2)[0]);
        return $contentType === 'application/json' || str_ends_with($contentType, '+json');
    }

    private function decodeJsonBody(string $input, int $limit): void
    {
        $this->json = null;
        $this->jsonError = null;

        if (strlen($input) > $limit) {
            $this->jsonError = 'json_body_too_large';
            return;
        }
        if ($input === '') {
            $this->jsonError = 'json_body_empty';
            return;
        }

        try {
            $this->json = json_decode($input, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->json = null;
            $this->jsonError = 'json_body_invalid';
        }
    }

    public function jsonError(): ?string
    {
        return $this->jsonError;
    }

    public function hasInvalidJson(): bool
    {
        return $this->jsonError !== null;
    }

    public static function maxJsonBodyBytes(): int
    {
        $raw = trim((string) (getenv('MAX_JSON_BODY_BYTES') ?: ''));
        $value = ctype_digit($raw) ? (int) $raw : self::DEFAULT_MAX_JSON_BYTES;
        return max(self::MIN_MAX_JSON_BYTES, min(self::MAX_MAX_JSON_BYTES, $value));
    }

    private function sanitize($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->sanitize($value);
            }
            return $data;
        }

        return is_string($data) ? htmlspecialchars($data, ENT_QUOTES, 'UTF-8') : $data;
    }

    public function get($key = null, $default = null)
    {
        $data = $key === null ? $this->get : ($this->get[$key] ?? $default);

        // ORDER BY identifiers cannot be bound as SQL parameters. Until every
        // screen owns an explicit column allowlist, reject anything that is not
        // a simple SQL identifier (optionally table-qualified).
        if ($key === 'sort' && is_string($data)) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/', $data)) {
                return $default;
            }
            return $data;
        }

        if ($key === 'direction' && is_string($data)) {
            $direction = strtolower($data);
            return in_array($direction, ['asc', 'desc'], true) ? $direction : $default;
        }

        return $this->sanitize($data);
    }

    public function post($key = null, $default = null)
    {
        $data = $key === null ? $this->post : ($this->post[$key] ?? $default);
        return $this->sanitize($data);
    }

    /**
     * Return the original POST value without HTML escaping.
     *
     * Use this only when exact input bytes are part of the application contract
     * (for example passwords). Callers must escape the value at the output
     * boundary before rendering it into HTML.
     */
    public function rawPost($key = null, $default = null)
    {
        return $key === null ? $this->post : ($this->post[$key] ?? $default);
    }

    public function files($key = null, $default = null)
    {
        return $key === null ? $this->files : ($this->files[$key] ?? $default);
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key])
            && is_array($this->files[$key])
            && ($this->files[$key]['error'] ?? null) === UPLOAD_ERR_OK;
    }

    public function file(string $key, $default = null)
    {
        return $this->hasFile($key) ? $this->files[$key] : $default;
    }

    public function json($key = null, $default = null)
    {
        if ($this->jsonError !== null) {
            return $default;
        }

        $data = $key === null
            ? $this->json
            : (is_array($this->json) ? ($this->json[$key] ?? $default) : $default);

        return $this->sanitize($data);
    }

    public function server($key = null, $default = null)
    {
        $data = $key === null ? $this->server : ($this->server[$key] ?? $default);
        return $this->sanitize($data);
    }

    public function all(): array
    {
        return [
            'get' => $this->get,
            'post' => $this->post,
            'files' => $this->files,
            'json' => $this->json,
            'server' => $this->server,
        ];
    }
}
