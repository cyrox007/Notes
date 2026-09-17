<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function nativeControlsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function nativeControlsRead(string $path): string
{
    $source = file_get_contents($path);
    if (!is_string($source)) {
        fwrite(STDERR, "[FAIL] unable to read {$path}\n");
        exit(1);
    }
    return $source;
}

$base = nativeControlsRead($root . '/app/views/core/base.php');
$controls = nativeControlsRead($root . '/app/views/core/controls.css');
$legacy = nativeControlsRead($root . '/app/views/^elements/UI/FormInput/style.css');

$profileIndexPath = is_file($root . '/modules/profile/views/index.php')
    ? $root . '/modules/profile/views/index.php'
    : $root . '/app/views/profile_page/index.php';
$profileStylePath = is_file($root . '/modules/profile/assets/style.css')
    ? $root . '/modules/profile/assets/style.css'
    : $root . '/app/views/profile_page/style.css';
$profile = nativeControlsRead($profileIndexPath);
$profileStyle = nativeControlsRead($profileStylePath);

nativeControlsAssert(
    !str_contains($base, "'^elements/UI/FormInput/style.css'"),
    'legacy FormInput stylesheet is still loaded globally'
);
nativeControlsAssert(
    str_contains($base, "'/core/controls.css'"),
    '1.0 shared control layer is not loaded by the native shell'
);
$moduleStylesPosition = strpos($base, 'foreach ($moduleStyles as $moduleStyle)');
$controlsPosition = strpos($base, '$controlsPath =');
nativeControlsAssert(
    $moduleStylesPosition !== false && $controlsPosition !== false && $controlsPosition > $moduleStylesPosition,
    'shared controls must load after module style links'
);

nativeControlsAssert(str_contains($legacy, '#0069d9') || str_contains($legacy, '#F5F8FA'), 'legacy fixture unexpectedly changed; contract no longer proves migration boundary');
nativeControlsAssert(str_contains($controls, '--control-height: 44px'), 'shared control height token missing');
nativeControlsAssert(str_contains($controls, '--control-radius: 10px'), 'shared control radius token missing');
nativeControlsAssert(str_contains($controls, 'var(--border-strong'), 'controls are not using current border tokens');
nativeControlsAssert(str_contains($controls, 'var(--primary'), 'controls are not using current primary token');
nativeControlsAssert(str_contains($controls, '.form-input_input'), 'legacy FormInput markup bridge missing');

foreach (['.profile__card-info--edit--set-input', '.profile__card-info--edit--set-save', '.profile__edit_user-info'] as $profileSelector) {
    nativeControlsAssert(
        !str_contains($controls, $profileSelector),
        "Profile selector {$profileSelector} leaked into the shared control layer"
    );
    nativeControlsAssert(
        str_contains($profileStyle, $profileSelector),
        "Profile-owned control bridge missing {$profileSelector}"
    );
}
nativeControlsAssert(str_contains($profileStyle, 'textarea.profile__card-info--edit--set-input'), 'Profile textarea sizing bridge missing');
nativeControlsAssert(str_contains($profileStyle, '.profile__card-info--edit .set-files'), 'Profile file input bridge missing');
nativeControlsAssert(str_contains($profileStyle, '.profile__card-info--edit--delete'), 'Profile danger button bridge missing');

foreach (['#0069d9', '#0056b3', '#007bff', '#0062cc', '#ddd'] as $alphaColor) {
    nativeControlsAssert(
        !str_contains(strtolower($controls), strtolower($alphaColor)),
        "alpha-era color {$alphaColor} leaked into the shared 1.0 control layer"
    );
}

nativeControlsAssert(str_contains($profile, 'class="profile__card-info--edit--set-input"'), 'Profile form no longer exercises the owned field class');
nativeControlsAssert(str_contains($profile, 'class="profile__card-info--edit--set-save"'), 'Profile form no longer exercises the owned save class');
nativeControlsAssert(str_contains($profile, 'class="profile__card-info--edit--delete"'), 'Profile form no longer exercises the owned danger class');

// Generic control rules must not target Messenger/icon buttons by class. Module
// controls keep ownership of their compact/icon geometry.
nativeControlsAssert(!str_contains($controls, '.messenger-icon-button'), 'shared controls unexpectedly override Messenger icon buttons');
nativeControlsAssert(!preg_match('/(^|\n)\s*button\s*\{/m', $controls), 'shared controls contain an unrestricted bare button rule');

echo "[OK] native 1.0 shared controls contract\n";
