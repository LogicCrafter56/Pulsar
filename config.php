<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);
define('DATA_DIR', APP_ROOT . '/data');
define('DB_FILE', DATA_DIR . '/uptime.db');
define('APP_NAME', 'Pulsar');
define('APP_VER', '1.1');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

require APP_ROOT . '/inc/db.php';
require APP_ROOT . '/inc/helpers.php';

$tz = setting('timezone', '');
if ($tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true)) {
    date_default_timezone_set($tz);
}
