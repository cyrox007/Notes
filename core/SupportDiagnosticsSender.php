<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/SupportDiagnostics.php';
require_once __DIR__ . '/UpdateDownloadCredentials.php';
require_once __DIR__ . '/Version.php';

use RuntimeException;

/**
 * Отправляет обезличенный сервисный ZIP в доверенный Notes control plane.
 *
 * Использует только уже активированные updater credentials и не принимает
 * произвольный URL назначения.
 */
final class SupportDiagnosticsSender
{
    /**
     * @return array{diagnostic_id:string,status:string,bundle_id:string,sha256:string,size:int}
     */
    public function send(int $actorId): array
    {
        $credentials = UpdateDownloadCredentials::fromEnvironment();
        if ($credentials === null) {
            throw new RuntimeException(
                'Для отправки диагностики нужен активированный online-доступ к серверу обновлений'
            );
        }

        $bundle = (new SupportDiagnostics(dirname(__DIR__)))->createUploadBundle($actorId);
        $endpoint = $credentials->baseUrl() . 'diagnostics';
        $boundary = '----NotesDiagnostic' . bin2hex(random_bytes(12));

        $metadata = json_encode(
            [
                'schema' => 1,
                'bundle_schema' => 2,
                'bundle_id' => $bundle['bundle_id'],
                'bundle_sha256' => $bundle['sha256'],
                'product' => Version::PRODUCT_NAME,
                'version' => Version::VERSION,
                'version_code' => Version::VERSION_CODE,
                'generated_by_user_id' => $actorId,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $filename = 'notes-diagnostics-' . $bundle['bundle_id'] . '.zip';
        $body = ''
            . $this->field($boundary, 'reason', 'manual_admin')
            . $this->field(
                $boundary,
                'summary',
                'Диагностика отправлена администратором Workspace Organizer'
            )
            . $this->field($boundary, 'metadata', $metadata)
            . '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="diagnostic"; filename="' . $filename . '"' . "\r\n"
            . 'Content-Type: application/zip' . "\r\n\r\n"
            . $bundle['bytes'] . "\r\n"
            . '--' . $boundary . '--' . "\r\n";

        $headers = $credentials->headersForDiagnostics($endpoint)
            . 'Content-Type: multipart/form-data; boundary=' . $boundary . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . 'Accept: application/json' . "\r\n"
            . 'User-Agent: Workspace-Organizer/' . Version::VERSION . "\r\n";

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $body,
                'timeout' => 45,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);

        $response = @file_get_contents($endpoint, false, $context);
        $status = $this->responseStatus($http_response_header ?? []);
        if (!is_string($response) || $status !== 202) {
            throw new RuntimeException(
                $status > 0
                    ? 'Сервер диагностики отклонил запрос (HTTP ' . $status . ')'
                    : 'Сервер диагностики недоступен'
            );
        }

        $decoded = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        $diagnosticId = is_array($decoded)
            ? trim((string) ($decoded['diagnostic_id'] ?? ''))
            : '';
        if (preg_match('/^[0-9a-f-]{36}$/D', $diagnosticId) !== 1) {
            throw new RuntimeException('Сервер диагностики вернул некорректный ответ');
        }

        ServiceLog::emit(
            'support.diagnostics_sent',
            'info',
            'support',
            [
                'diagnostic_id' => $diagnosticId,
                'bundle_id' => $bundle['bundle_id'],
                'bundle_sha256' => $bundle['sha256'],
                'size' => $bundle['size'],
            ]
        );

        return [
            'diagnostic_id' => $diagnosticId,
            'status' => 'accepted',
            'bundle_id' => (string) $bundle['bundle_id'],
            'sha256' => (string) $bundle['sha256'],
            'size' => (int) $bundle['size'],
        ];
    }

    private function field(string $boundary, string $name, string $value): string
    {
        return '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n"
            . $value . "\r\n";
    }

    /** @param list<string> $headers */
    private function responseStatus(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+([0-9]{3})\b~i', (string) $header, $match) === 1) {
                return (int) $match[1];
            }
        }
        return 0;
    }
}
