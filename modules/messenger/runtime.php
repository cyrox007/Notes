<?php

declare(strict_types=1);

$moduleRoot = __DIR__;

$runtimeFiles = [
    '/MessengerCapability.php',
    '/models/DialogModel.php',
    '/models/MessageModel.php',
    '/models/UserToDialogsModel.php',
    '/handlers/MessengerCrypto.php',
    '/handlers/SocketTicket.php',
    '/services/MessengerDialogStateService.php',
    '/services/MessengerForwardService.php',
    '/services/MessengerGroupAvatarService.php',
    '/services/MessengerGroupService.php',
    '/services/MessengerMediaCleanupService.php',
    '/services/MessengerMediaService.php',
    '/services/MessengerReactionService.php',
    '/services/MessengerReceiptService.php',
    '/services/MessengerSavedService.php',
    '/services/MessengerSearchService.php',
    '/services/MessengerService.php',
    '/services/MessengerVoiceService.php',
    '/middlewares/RequireMessengerUse.php',
    '/middlewares/EnforceMessengerUploadPolicy.php',
    '/controllers/MessagerController.php',
    '/controllers/MessengerGroupController.php',
    '/controllers/MessengerVoiceController.php',
    '/socket/SocketConnection.php',
    '/socket/SocketFrameCodec.php',
    '/socket/SocketHandshake.php',
    '/socket/NativeSocketConnection.php',
    '/socket/PingSocket.php',
    '/socket/MessangerSocket.php',
    '/socket/DialogStateSocket.php',
    '/socket/ReceiptSocket.php',
    '/socket/MediaSocket.php',
    '/socket/GroupSocket.php',
    '/socket/SearchSocket.php',
    '/socket/ForwardSocket.php',
    '/socket/ReactionSocket.php',
    '/socket/NativeMessengerServer.php',
    '/MessengerRuntimeProvider.php',
];

foreach ($runtimeFiles as $relativePath) {
    $path = $moduleRoot . $relativePath;
    if (!is_file($path)) {
        throw new RuntimeException('Messenger module runtime file is missing: ' . $relativePath);
    }
    require_once $path;
}

return new \Modules\Messenger\MessengerRuntimeProvider();
