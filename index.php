<?php
ini_set('display_errors', 1);
if (!defined('SITEPATH')) {
    define('SITEPATH', dirname(__FILE__));
}
error_reporting(E_ALL);
ini_set('error_log', SITEPATH . '/.logs/php-errors.log');

require_once SITEPATH . '/core.php';

// Маршрутизатор
require_once SITEPATH . '/core/Router.php';
require_once SITEPATH . '/core/RouterConfig.php';
