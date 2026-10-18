<?php

declare(strict_types=1);

namespace App\Services\Files;

/** Разрешённые форматы загрузки для личного и общего хранилища. */
final class FileUploadTypes
{
    /** @var array<string, array<int, string>> */
    public const array ALLOWED_UPLOADS = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'djvu' => ['image/vnd.djvu', 'image/x-djvu', 'application/x-djvu', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'vsd' => ['application/vnd.ms-office', 'application/vnd.visio', 'application/octet-stream'],
        'vsdx' => ['application/vnd.ms-visio.drawing.main+xml', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'mp3' => ['audio/mpeg', 'audio/mp3'],
        'wav' => ['audio/wav', 'audio/x-wav'],
        'ogg' => ['audio/ogg', 'application/ogg'],
        'flac' => ['audio/flac', 'audio/x-flac'],
        'm4a' => ['audio/mp4', 'audio/x-m4a'],
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
        'avi' => ['video/x-msvideo', 'application/octet-stream'],
        'mov' => ['video/quicktime'],
        'mkv' => ['video/x-matroska', 'application/octet-stream'],
    ];

}
