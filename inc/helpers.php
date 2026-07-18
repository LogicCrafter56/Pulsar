<?php
declare(strict_types=1);

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to)
{
    header('Location: ' . $to);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check()
{
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid session token. Go back and try again.');
    }
}

function flash(string $msg, string $type = 'ok')
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function get_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** "2 h 14 min", "45 s", "3 d 4 h" */
function fmt_duration(int $sec): string
{
    if ($sec < 60) return $sec . ' s';
    if ($sec < 3600) return floor($sec / 60) . ' min ' . ($sec % 60 ? ($sec % 60) . ' s' : '');
    if ($sec < 86400) {
        $h = floor($sec / 3600);
        $m = floor(($sec % 3600) / 60);
        return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
    }
    $d = floor($sec / 86400);
    $h = floor(($sec % 86400) / 3600);
    return $d . ' d' . ($h ? ' ' . $h . ' h' : '');
}

function fmt_ago($ts): string
{
    if (!$ts) return 'never';
    $diff = time() - $ts;
    if ($diff < 5)  return 'just now';
    if ($diff < 60) return $diff . ' s ago';
    return fmt_duration($diff) . ' ago';
}

function fmt_dt($ts): string
{
    return $ts ? date('M j, Y H:i', $ts) : '—';
}

/** Uptime ratio over a window, from recorded checks. Returns float|null (null = no data). */
function uptime_pct(int $monitorId, int $sinceSec)
{
    $row = db()->prepare('SELECT COUNT(*) AS n, SUM(ok) AS up FROM checks WHERE monitor_id = ? AND checked_at >= ?');
    $row->execute([$monitorId, time() - $sinceSec]);
    $r = $row->fetch();
    if (!$r || (int)$r['n'] === 0) return null;
    return 100.0 * (int)$r['up'] / (int)$r['n'];
}

function avg_response(int $monitorId, int $sinceSec)
{
    $st = db()->prepare('SELECT AVG(rt) AS a FROM checks WHERE monitor_id = ? AND ok = 1 AND checked_at >= ?');
    $st->execute([$monitorId, time() - $sinceSec]);
    $a = $st->fetch()['a'];
    return $a === null ? null : (int)round((float)$a);
}

function fmt_pct($p): string
{
    if ($p === null) return '—';
    if ($p >= 99.995) return '100%';
    return rtrim(rtrim(number_format($p, 2), '0'), '.') . '%';
}

/** Recent checks for the signal strip, oldest first. */
function recent_checks(int $monitorId, int $limit = 30): array
{
    $st = db()->prepare('SELECT checked_at, ok, rt, code, message FROM checks WHERE monitor_id = ? ORDER BY checked_at DESC LIMIT ?');
    $st->execute([$monitorId, $limit]);
    return array_reverse($st->fetchAll());
}

/** Render the signature signal strip. */
function signal_strip(array $checks, int $slots = 30, string $size = ''): string
{
    $max = 1;
    foreach ($checks as $c) {
        if ($c['ok'] && $c['rt'] > $max) $max = (int)$c['rt'];
    }
    $html = '<div class="signal-strip ' . $size . '" aria-hidden="true">';
    $pad = $slots - count($checks);
    for ($i = 0; $i < $pad; $i++) {
        $html .= '<i class="pb pb-empty"></i>';
    }
    foreach ($checks as $c) {
        if ($c['ok']) {
            $h = 25 + (int)round(70 * min(1, (int)$c['rt'] / $max));
            $tip = date('H:i', (int)$c['checked_at']) . ' · ' . (int)$c['rt'] . ' ms';
            $html .= '<i class="pb pb-up" style="height:' . $h . '%" title="' . e($tip) . '"></i>';
        } else {
            $tip = date('H:i', (int)$c['checked_at']) . ' · ' . ($c['message'] ?: 'down');
            $html .= '<i class="pb pb-down" title="' . e($tip) . '"></i>';
        }
    }
    $html .= '</div>';
    return $html;
}

function status_label(string $s): string
{
    return ['up' => 'Up', 'down' => 'Down', 'paused' => 'Paused', 'pending' => 'Waiting'][$s] ?? $s;
}

function type_label(string $t): string
{
    return ['http' => 'HTTP(s)', 'keyword' => 'Keyword', 'ping' => 'Ping', 'port' => 'Port'][$t] ?? $t;
}

function interval_options(): array
{
    return [1 => 'Every minute', 2 => 'Every 2 min', 5 => 'Every 5 min', 10 => 'Every 10 min',
            15 => 'Every 15 min', 30 => 'Every 30 min', 60 => 'Every hour'];
}

/** @return array|null */
function get_monitor(int $id)
{
    $st = db()->prepare('SELECT * FROM monitors WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
