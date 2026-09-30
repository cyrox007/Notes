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
echo $view->partial('@profile/index', $data);
