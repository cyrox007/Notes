<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$module = $root . '/modules/messenger';

function activityContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function activityContractSource(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        fwrite(STDERR, "[FAIL] unable to read {$path}\n");
        exit(1);
    }
    return $source;
}

$index = activityContractSource($module . '/views/index.php');
$activity = activityContractSource($module . '/views/activity.js');
$voice = activityContractSource($module . '/views/voice.js');
$media = activityContractSource($module . '/views/media.js');
$socket = activityContractSource($module . '/socket/MessangerSocket.php');
$server = activityContractSource($module . '/socket/NativeMessengerServer.php');
$activityService = activityContractSource($module . '/services/MessengerActivityService.php');
$longPoll = activityContractSource($module . '/services/MessengerLongPollService.php');
$realtimeController = activityContractSource($module . '/controllers/MessengerRealtimeController.php');
$moduleManifest = activityContractSource($module . '/module.json');
$moduleSchema = activityContractSource($root . '/database/messenger_module_schema.sql');
$migration = activityContractSource($root . '/database/migrations/20260928_messenger_activity.sql');

activityContractAssert(
    strpos($index, "'protocol-origin.js', 'script.js', 'activity.js'") !== false,
    'activity.js must load immediately after the canonical Messenger client'
);
activityContractAssert(
    str_contains($activity, "app.sendEvent('MessangerSocket:activity'")
    && !str_contains($activity, 'app.socket.send(JSON.stringify({'),
    'activity client must use the transport-neutral sendEvent path'
);
activityContractAssert(
    str_contains($activity, "data?.action === 'activity_snapshot'")
    && str_contains($activity, 'clearDialogActivities(dialogUid)'),
    'activity client does not apply Long Poll snapshots'
);
activityContractAssert(str_contains($activity, 'ACTIVITY_TTL_MS = 5000'), 'remote activity TTL is missing');
activityContractAssert(str_contains($activity, 'app.notifyTyping = () =>'), 'typing input path was not migrated to unified activity');
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
    'document extension classifier' => ["'pdf', 'txt', 'md', 'doc', 'docx'", "return 'uploading_document'"],
    'generic file fallback' => ["return 'uploading_file'"],
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
activityContractAssert(
    str_contains($socket, 'MessengerActivityService')
    && str_contains($socket, '->publish($userUid, $dialogUid, $activity, $active)')
    && str_contains($socket, '->publish($userUid, $dialogUid, \'typing\', $typing)'),
    'WebSocket/HTTP dispatcher does not persist short-lived activity state'
);
activityContractAssert(
    str_contains($activityService, 'TTL_SECONDS = 6')
    && str_contains($activityService, 'messenger_activity')
    && str_contains($activityService, 'expires_at <= CURRENT_TIMESTAMP(3)')
    && str_contains($activityService, 'expires_at > CURRENT_TIMESTAMP(3)'),
    'activity service does not enforce short-lived TTL state'
);
activityContractAssert(
    str_contains($longPoll, 'activity_state')
    && str_contains($longPoll, 'messenger_activity')
    && str_contains($realtimeController, "'action' => 'activity_snapshot'"),
    'Long Poll does not observe and return activity state'
);
activityContractAssert(
    str_contains($moduleManifest, 'messenger_activity')
    && str_contains($moduleManifest, '20260928_messenger_activity.sql')
    && str_contains($moduleSchema, 'CREATE TABLE IF NOT EXISTS `messenger_activity`')
    && str_contains($migration, 'CREATE TABLE IF NOT EXISTS `messenger_activity`'),
    'Messenger activity table is missing from fresh-install or upgrade ownership'
);

echo "[OK] Messenger activity works across WebSocket and Long Poll transports\n";