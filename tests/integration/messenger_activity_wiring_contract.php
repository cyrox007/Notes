<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function activityContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

/** @return string */
function activityContractSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        fwrite(STDERR, "[FAIL] unable to read {$path}\n");
        exit(1);
    }
    return $source;
}

$index = activityContractSource($root . '/app/views/messager_page/index.php');
$activity = activityContractSource($root . '/app/views/messager_page/activity.js');
$voice = activityContractSource($root . '/app/views/messager_page/voice.js');
$media = activityContractSource($root . '/app/views/messager_page/media.js');
$socket = activityContractSource($root . '/app/socket/MessangerSocket.php');
$server = activityContractSource($root . '/app/socket/NativeMessengerServer.php');

activityContractAssert(
    strpos($index, "['script.js', 'activity.js'") !== false,
    'activity.js must load immediately after the canonical Messenger client'
);
activityContractAssert(str_contains($activity, "action: 'MessangerSocket:activity'"), 'activity client does not emit the unified WS action');
activityContractAssert(str_contains($activity, 'ACTIVITY_TTL_MS = 5000'), 'remote activity TTL is missing');
activityContractAssert(str_contains($activity, "app.notifyTyping = () =>"), 'typing input path was not migrated to unified activity');
activityContractAssert(str_contains($activity, "recording_voice: 'записывает голосовое…'"), 'voice recording label is missing');
activityContractAssert(str_contains($activity, "recording_video: 'записывает видеосообщение…'"), 'video recording protocol label is missing');
activityContractAssert(str_contains($activity, "uploading_image: 'отправляет изображение…'"), 'image upload label is missing');
activityContractAssert(str_contains($activity, "uploading_audio: 'отправляет музыку/аудио…'"), 'audio upload label is missing');
activityContractAssert(str_contains($activity, "uploading_document: 'отправляет документ…'"), 'document upload label is missing');
activityContractAssert(str_contains($activity, "uploading_file: 'отправляет файл…'"), 'generic file upload label is missing');

activityContractAssert(str_contains($voice, "setActivity('recording_voice', true, initialDialogUid)"), 'voice recording start does not publish activity');
activityContractAssert(str_contains($voice, "setActivity('recording_voice', false, dialogUid)"), 'voice recording stop does not clear activity');
activityContractAssert(str_contains($voice, "setActivity('uploading_voice', true, dialogUid)"), 'voice upload start does not publish activity');
activityContractAssert(str_contains($voice, "setActivity('uploading_voice', false, dialogUid)"), 'voice upload finish does not clear activity');

foreach ([
    "mime.startsWith('image/') => 'uploading_image'" => ["mime.startsWith('image/')", "return 'uploading_image'"],
    "mime.startsWith('video/') => 'uploading_video'" => ["mime.startsWith('video/')", "return 'uploading_video'"],
    "mime.startsWith('audio/') => 'uploading_audio'" => ["mime.startsWith('audio/')", "return 'uploading_audio'"],
    "document extension classifier" => ["'pdf', 'txt', 'md', 'doc', 'docx'", "return 'uploading_document'"],
    "generic file fallback" => ["return 'uploading_file'"],
] as $label => $markers) {
    foreach ($markers as $marker) {
        activityContractAssert(str_contains($media, $marker), "media classifier missing {$label}: {$marker}");
    }
}
activityContractAssert(str_contains($media, 'setActivity(activity, true, initialDialogUid)'), 'attachment upload start does not publish activity');
activityContractAssert(str_contains($media, 'setActivity(activity, false, initialDialogUid)'), 'attachment upload finish does not clear activity');

activityContractAssert(str_contains($socket, "'recording_voice'"), 'server activity allowlist misses recording_voice');
activityContractAssert(str_contains($socket, "'recording_video'"), 'server activity allowlist misses recording_video');
activityContractAssert(str_contains($socket, "'uploading_document'"), 'server activity allowlist misses uploading_document');
activityContractAssert(str_contains($socket, "'action' => 'activity'"), 'server does not broadcast activity frames');
activityContractAssert(str_contains($server, "'activity'"), 'native server route allowlist misses activity');
activityContractAssert(
    preg_match("/'MessangerSocket'\\s*=>\\s*\\[[^\\]]*'activity'/s", $server) === 1,
    'activity must remain available through the Messenger read-only route set'
);

echo "[OK] Messenger realtime activity producer wiring contract\n";
