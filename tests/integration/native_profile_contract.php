<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function nativeProfileAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$views = [
    'modules/profile/views/index.php',
    'modules/profile/views/publication.php',
    'modules/profile/views/public.php',
];
foreach ($views as $relative) {
    $source = file_get_contents($root . '/' . $relative);
    nativeProfileAssert(is_string($source), "missing native profile view: {$relative}");
    nativeProfileAssert(!str_contains($source, '{extends'), "{$relative} still contains Smarty extends syntax");
    nativeProfileAssert(!str_contains($source, '{include'), "{$relative} still contains Smarty include syntax");
    nativeProfileAssert(!str_contains($source, '{foreach'), "{$relative} still contains Smarty foreach syntax");
    nativeProfileAssert(!str_contains($source, '$smarty'), "{$relative} still depends on Smarty runtime state");
}

$index = (string) file_get_contents($root . '/modules/profile/views/index.php');
nativeProfileAssert(str_contains($index, '$view->layout(\'core/base\''), 'profile hub does not use native application shell');
nativeProfileAssert(str_contains($index, "partial('profile_page/publication'"), 'profile publication compatibility partial is missing');
nativeProfileAssert(str_contains($index, '$view->csrfInput()'), 'profile forms lost CSRF input');
nativeProfileAssert(str_contains($index, "route('profile-set')"), 'profile update route is missing');
nativeProfileAssert(str_contains($index, "route('profile-password-set')"), 'password update route is missing');
nativeProfileAssert(str_contains($index, "route('profile-delete')"), 'account deactivation route is missing');
nativeProfileAssert(str_contains($index, '$access[\'notes\']'), 'profile workspace links are not RBAC-gated');

$publication = (string) file_get_contents($root . '/modules/profile/views/publication.php');
nativeProfileAssert(str_contains($publication, "route('profile-publication')"), 'publication action route is missing');
nativeProfileAssert(str_contains($publication, '$view->csrfInput()'), 'publication forms lost CSRF input');
nativeProfileAssert(str_contains($publication, 'is_profile_public'), 'publication visibility state is missing');
nativeProfileAssert(str_contains($publication, '$view->e($label)'), 'publication item labels are not escaped');

$public = (string) file_get_contents($root . '/modules/profile/views/public.php');
nativeProfileAssert(str_contains($public, '$view->layout(\'core/base\''), 'public profile does not use native application shell');
nativeProfileAssert(str_contains($public, '$publicContent'), 'public profile no longer renders publication service output');
nativeProfileAssert(!str_contains($public, "route('files_get'"), 'public profile unexpectedly exposes protected file download links');

$script = (string) file_get_contents($root . '/modules/profile/assets/profile.js');
nativeProfileAssert($script !== '', 'isolated profile JS bundle is missing');
nativeProfileAssert(!str_contains($script, '{literal}'), 'profile JS still contains Smarty literal syntax');
nativeProfileAssert(str_contains($script, 'profile-account-settings'), 'profile edit panel behavior was dropped');
nativeProfileAssert(str_contains($script, 'Пароли не совпадают'), 'password confirmation behavior was dropped');

$indexBridge = (string) file_get_contents($root . '/app/views/profile_page/index.php');
$publicBridge = (string) file_get_contents($root . '/app/views/profile_page/public.php');
$publicationBridge = (string) file_get_contents($root . '/app/views/profile_page/publication.php');
nativeProfileAssert(str_contains($indexBridge, "partial('@profile/index'"), 'profile hub compatibility bridge does not target isolated view');
nativeProfileAssert(str_contains($indexBridge, "moduleAsset('profile', 'profile.js')"), 'profile hub bridge does not load isolated JS');
nativeProfileAssert(str_contains($publicBridge, "partial('@profile/public'"), 'public profile bridge does not target isolated view');
nativeProfileAssert(str_contains($publicationBridge, "partial('@profile/publication'"), 'publication bridge does not target isolated view');

foreach ([
    'app/views/profile_page/index.tpl',
    'app/views/profile_page/public.tpl',
    'app/views/profile_page/publication.tpl',
    'app/views/profile_page/style.css',
    'app/views/profile_page/hub.css',
    'app/views/profile_page/metrics.css',
    'app/views/profile_page/script.js',
    'assets/js/profile.js',
] as $legacyProductFile) {
    nativeProfileAssert(!file_exists($root . '/' . $legacyProductFile), "legacy Profile product file remains: {$legacyProductFile}");
}

echo "[OK] native isolated profile views contract\n";
