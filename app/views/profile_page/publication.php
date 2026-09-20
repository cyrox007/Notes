<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$data = get_defined_vars();
unset($data['view']);

echo $view->partial('@profile/publication', $data);
