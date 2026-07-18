<?php
declare(strict_types=1);

require_once APP_ROOT . '/inc/notify.php';

/** Does an HTTP status code match a spec like "200-299,301,404"? */
function code_matches(int $code, string $spec): bool
{
    foreach (array_map('trim', explode(',', $spec)) as $part) {
        if ($part === '') continue;
        if (strpos($part, '-') !== false) {
            list($lo, $hi) = array_map('intval', explode('-', $part, 2));
            if ($code >= $lo && $code <= $hi) return true;
        } elseif ((int)$part === $code) {
            return true;
        }
    }
    return false;
}

function monitor_host(array $m): string
{
    $u = $m['url'];
    if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $u)) {
        return parse_url($u, PHP_URL_HOST) ?: $u;
    }
    // bare host, maybe with port or path
    return preg_replace('~[:/].*$~', '', $u);
}

/**
 * Perform a single check. Returns:
 * ['ok' => bool, 'rt' => int ms, 'code' => ?int, 'message' => string]
 */
function perform_check(array $m): array
{
    switch ($m['type']) {
        case 'http':
        case 'keyword':
            return check_http($m);
        case 'ping':
            return check_ping($m);
        case 'port':
            return check_port($m);
        default:
            return ['ok' => false, 'rt' => 0, 'code' => null, 'message' => 'Unknown check type'];
    }
}

function check_http(array $m): array
{
    $url = $m['url'];
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'http://' . $url;
    }
    $needBody = $m['type'] === 'keyword' || $m['method'] !== 'HEAD';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY         => !$needBody,
        CURLOPT_CUSTOMREQUEST  => $m['type'] === 'keyword' ? 'GET' : $m['method'],
        CURLOPT_TIMEOUT        => (int)$m['timeout'],
        CURLOPT_CONNECTTIMEOUT => (int)$m['timeout'],
        CURLOPT_FOLLOWLOCATION => (bool)$m['follow_redirects'],
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_SSL_VERIFYPEER => (bool)$m['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => $m['verify_ssl'] ? 2 : 0,
        CURLOPT_USERAGENT      => 'Pulsar/' . APP_VER . ' (uptime monitor)',
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    $rt   = (int)round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'rt' => $rt, 'code' => null, 'message' => $err ?: 'Connection failed'];
    }
    if (!code_matches($code, $m['expected_codes'] ?: '200-399')) {
        return ['ok' => false, 'rt' => $rt, 'code' => $code, 'message' => "HTTP $code (expected {$m['expected_codes']})"];
    }
    if ($m['type'] === 'keyword' && $m['keyword'] !== '') {
        $found = stripos((string)$body, $m['keyword']) !== false;
        if ($m['keyword_alert'] === 'missing' && !$found) {
            return ['ok' => false, 'rt' => $rt, 'code' => $code, 'message' => 'Keyword "' . $m['keyword'] . '" not found'];
        }
        if ($m['keyword_alert'] === 'present' && $found) {
            return ['ok' => false, 'rt' => $rt, 'code' => $code, 'message' => 'Keyword "' . $m['keyword'] . '" found on page'];
        }
    }
    return ['ok' => true, 'rt' => $rt, 'code' => $code, 'message' => "HTTP $code"];
}

function check_ping(array $m): array
{
    $host = monitor_host($m);
    if ($host === '') {
        return ['ok' => false, 'rt' => 0, 'code' => null, 'message' => 'Invalid host'];
    }
    $timeoutMs = max(1000, (int)$m['timeout'] * 1000);
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $cmdline = $isWin
        ? 'ping -n 1 -w ' . $timeoutMs . ' ' . escapeshellarg($host)
        : 'ping -c 1 -W ' . max(1, (int)$m['timeout']) . ' ' . escapeshellarg($host);
    $start = microtime(true);
    exec($cmdline, $out, $exit);
    $elapsed = (int)round((microtime(true) - $start) * 1000);
    $text = implode("\n", $out);

    // On Windows, exit code 0 can still mean "Destination host unreachable" — require TTL in the reply.
    $alive = $exit === 0 && ($isWin ? stripos($text, 'TTL=') !== false : true);
    if (!$alive) {
        return ['ok' => false, 'rt' => $elapsed, 'code' => null, 'message' => 'No ping reply from ' . $host];
    }
    if (preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $text, $mt)) {
        $elapsed = max(1, (int)round((float)$mt[1]));
    }
    return ['ok' => true, 'rt' => $elapsed, 'code' => null, 'message' => 'Ping reply'];
}

function check_port(array $m): array
{
    $host = monitor_host($m);
    $port = (int)$m['port'];
    if ($host === '' || $port < 1) {
        return ['ok' => false, 'rt' => 0, 'code' => null, 'message' => 'Invalid host or port'];
    }
    $start = microtime(true);
    $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, (int)$m['timeout']);
    $rt = (int)round((microtime(true) - $start) * 1000);
    if (!$fp) {
        return ['ok' => false, 'rt' => $rt, 'code' => null, 'message' => "Port $port closed or unreachable ($errstr)"];
    }
    fclose($fp);
    return ['ok' => true, 'rt' => $rt, 'code' => null, 'message' => "Port $port open"];
}

/**
 * Run a check for one monitor: record it, walk the up/down state machine,
 * fire notifications, schedule the next run. Returns the check result.
 */
function run_monitor_check(array $m): array
{
    $db = db();
    $res = perform_check($m);
    $now = time();

    $db->prepare('INSERT INTO checks (monitor_id, checked_at, ok, rt, code, message) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$m['id'], $now, $res['ok'] ? 1 : 0, $res['rt'], $res['code'], $res['message']]);

    $status = $m['status'];
    $failCount = (int)$m['fail_count'];
    $resendCounter = (int)$m['resend_counter'];

    if ($res['ok']) {
        if ($status === 'down') {
            // recovered
            $downSince = $db->prepare("SELECT created_at FROM events WHERE monitor_id = ? AND type = 'down' ORDER BY id DESC LIMIT 1");
            $downSince->execute([$m['id']]);
            $since = (int)($downSince->fetchColumn() ?: 0);
            $duration = $since ? $now - $since : null;
            $db->prepare('INSERT INTO events (monitor_id, type, message, duration, created_at) VALUES (?, ?, ?, ?, ?)')
               ->execute([$m['id'], 'up', 'Back online' . ($duration !== null ? ' after ' . fmt_duration($duration) : ''), $duration, $now]);
            if ((int)$m['notify_recovery'] === 1) {
                notify_monitor($m, 'up', $res['message'], $since);
            }
        }
        $status = 'up';
        $failCount = 0;
        $resendCounter = 0;
    } else {
        $failCount++;
        if ($status !== 'down' && $failCount >= (int)$m['fail_threshold']) {
            $status = 'down';
            $db->prepare('INSERT INTO events (monitor_id, type, message, created_at) VALUES (?, ?, ?, ?)')
               ->execute([$m['id'], 'down', $res['message'], $now]);
            notify_monitor($m, 'down', $res['message']);
            $resendCounter = 0;
        } elseif ($status === 'down' && (int)$m['resend_every'] > 0) {
            $resendCounter++;
            if ($resendCounter >= (int)$m['resend_every']) {
                notify_monitor($m, 'still-down', $res['message']);
                $resendCounter = 0;
            }
        }
    }

    $db->prepare('UPDATE monitors SET status = ?, fail_count = ?, resend_counter = ?, last_check = ?,
                  next_check = ?, last_rt = ?, last_code = ?, last_error = ? WHERE id = ?')
       ->execute([
           $status, $failCount, $resendCounter, $now,
           $now + (int)$m['interval_min'] * 60,
           $res['rt'], $res['code'],
           $res['ok'] ? null : $res['message'],
           $m['id'],
       ]);

    return $res;
}

/** Run every monitor that is due. Returns number of checks performed. */
function run_due_checks(): int
{
    $db = db();
    $due = $db->prepare("SELECT * FROM monitors WHERE status != 'paused' AND next_check <= ? ORDER BY next_check ASC");
    $due->execute([time()]);
    $count = 0;
    foreach ($due->fetchAll() as $m) {
        run_monitor_check($m);
        $count++;
    }
    set_setting('last_cron', (string)time());
    prune_old_checks();
    return $count;
}

function prune_old_checks()
{
    $days = max(7, (int)setting('retention_days', '30'));
    $cutoff = time() - $days * 86400;
    // cheap probabilistic prune, no need to do this every run
    if (random_int(1, 20) === 1) {
        db()->prepare('DELETE FROM checks WHERE checked_at < ?')->execute([$cutoff]);
        db()->prepare('DELETE FROM events WHERE created_at < ?')->execute([$cutoff]);
    }
}
