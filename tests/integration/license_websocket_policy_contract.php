<?php

declare(strict_types=1);

use App\Sockets\NativeMessengerServer;

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/LicenseRuntimePolicy.php';
require_once $root . '/modules/messenger/socket/NativeMessengerServer.php';

function wsLicenseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$reflection = new ReflectionClass(NativeMessengerServer::class);
$allowedConstant = $reflection->getReflectionConstant('ALLOWED_ROUTES');
$readOnlyConstant = $reflection->getReflectionConstant('READ_ONLY_ROUTES');
wsLicenseAssert($allowedConstant !== false, 'ALLOWED_ROUTES constant must exist');
wsLicenseAssert($readOnlyConstant !== false, 'READ_ONLY_ROUTES constant must exist');

$allowed = $allowedConstant->getValue();
$readOnly = $readOnlyConstant->getValue();
wsLicenseAssert(is_array($allowed) && is_array($readOnly), 'WebSocket route maps must be arrays');

foreach ($readOnly as $className => $methods) {
    wsLicenseAssert(isset($allowed[$className]), "read-only class {$className} must be an allowed route");
    foreach ($methods as $methodName) {
        wsLicenseAssert(
            in_array($methodName, $allowed[$className], true),
            "read-only action {$className}:{$methodName} must also be allowed"
        );
    }
}

$expectedReadOnly = [
    'PingSocket' => ['index'],
    'MessangerSocket' => ['get_dialogs', 'load', 'user_typing', 'stop_typing', 'activity'],
    'DialogStateSocket' => ['list'],
    'ReceiptSocket' => ['list'],
    'GroupSocket' => ['info', 'refresh'],
    'SearchSocket' => ['all', 'messages', 'dialogs'],
    'ReactionSocket' => ['list'],
];
wsLicenseAssert($readOnly === $expectedReadOnly, 'read-only WebSocket allowlist changed; review persistence semantics explicitly');

$ephemeral = [
    'MessangerSocket' => ['user_typing', 'stop_typing', 'activity'],
];

foreach ($ephemeral as $className => $methods) {
    foreach ($methods as $methodName) {
        wsLicenseAssert(
            isset($allowed[$className]) && in_array($methodName, $allowed[$className], true),
            "ephemeral action {$className}:{$methodName} must remain an allowed Messenger action"
        );
        wsLicenseAssert(
            isset($readOnly[$className]) && in_array($methodName, $readOnly[$className], true),
            "ephemeral action {$className}:{$methodName} must remain available in license read-only mode"
        );
    }
}

$mutating = [
    'MessangerSocket' => ['create_dialog', 'message_send', 'edit_message', 'delete_message', 'mark_read'],
    'DialogStateSocket' => ['pin', 'archive', 'mute'],
    'ReceiptSocket' => ['delivered'],
    'MediaSocket' => ['send'],
    'GroupSocket' => ['rename', 'add_members', 'remove_member', 'set_role', 'transfer_owner', 'leave'],
    // saved can create the Saved dialog, so it is deliberately mutating.
    'ForwardSocket' => ['saved', 'save_message', 'forward'],
    'ReactionSocket' => ['toggle'],
];

foreach ($mutating as $className => $methods) {
    foreach ($methods as $methodName) {
        wsLicenseAssert(
            isset($allowed[$className]) && in_array($methodName, $allowed[$className], true),
            "expected mutation {$className}:{$methodName} must remain an allowed Messenger action"
        );
        wsLicenseAssert(
            !isset($readOnly[$className]) || !in_array($methodName, $readOnly[$className], true),
            "mutation {$className}:{$methodName} must never be classified read-only"
        );
    }
}

$source = file_get_contents($root . '/modules/messenger/socket/NativeMessengerServer.php');
wsLicenseAssert(is_string($source), 'NativeMessengerServer source must be readable');
wsLicenseAssert(str_contains($source, '$this->licensePolicy->state()'), 'WebSocket dispatch must evaluate runtime license state');
wsLicenseAssert(str_contains($source, '$this->maintenanceStateResolver'), 'WebSocket mutations must evaluate maintenance state');
wsLicenseAssert(str_contains($source, "'action' => 'MaintenanceMode'"), 'WebSocket must reject mutations while maintenance is active');
wsLicenseAssert(str_contains($source, "'action' => 'LicenseReadOnly'"), 'WebSocket must report read-only state to clients');
wsLicenseAssert(str_contains($source, '$this->sendReadOnlyLicenseState($client);'), 'WebSocket handshake must surface existing read-only state');

echo "[OK] WebSocket license read-only classification contract\n";
