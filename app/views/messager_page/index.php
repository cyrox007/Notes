<?php

declare(strict_types=1);

// Transitional compatibility shim for the legacy Controller::render_template()
// call. Messenger view ownership lives in modules/messenger; remove this shim
// when the final shared app/* loader is retired.
$moduleView = SITEPATH . '/modules/messenger/views/index.php';
if (!is_file($moduleView)) {
    http_response_code(404);
    return;
}
require $moduleView;
