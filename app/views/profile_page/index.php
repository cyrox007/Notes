<?php

declare(strict_types=1);

/**
 * Compatibility bridge for ProfileController while Profile is isolated.
 * Product view code and assets are owned by modules/profile.
 *
 * @var \Core\NativeViewRenderer $view
 */
$data = get_defined_vars();
unset($data['view']);
$data['module_styles'] = [
    $view->moduleAsset('profile', 'style.css'),
    $view->moduleAsset('profile', 'hub.css'),
    $view->moduleAsset('profile', 'metrics.css'),
];
$data['module_scripts'] = [
    $view->moduleAsset('profile', 'profile.js'),
];

echo $view->partial('@profile/index', $data);
