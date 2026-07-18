<?php
/**
 * Pulsar checker entry point. Run every minute.
 *   CLI:  php cron.php
 *   Web:  cron.php?key=<cron_key from settings>
 */
require __DIR__ . '/config.php';
require APP_ROOT . '/inc/checker.php';

if (PHP_SAPI !== 'cli') {
    $key = (string)($_GET['key'] ?? '');
    if (!hash_equals(setting('cron_key'), $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
    ignore_user_abort(true);
}

set_time_limit(280);
$start = microtime(true);
$n = run_due_checks();
$elapsed = round(microtime(true) - $start, 2);

if (PHP_SAPI === 'cli' || !isset($_GET['quiet'])) {
    echo "OK — ran $n check(s) in {$elapsed}s\n";
} else {
    echo 'OK';
}
