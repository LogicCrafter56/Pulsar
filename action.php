<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/inc/auth.php';
require APP_ROOT . '/inc/checker.php';
require_login();

if (!is_post()) {
    redirect('index.php');
}
csrf_check();

$do = (string)($_POST['do'] ?? '');
$id = (int)($_POST['id'] ?? 0);
$back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
// only bounce back to our own pages
$back = preg_match('~/(index|monitor)\.php~', $back) ? $back : 'index.php';

switch ($do) {
    case 'pause':
        $m = get_monitor($id);
        if ($m) {
            db()->prepare("UPDATE monitors SET status = 'paused', fail_count = 0 WHERE id = ?")->execute([$id]);
            db()->prepare('INSERT INTO events (monitor_id, type, message, created_at) VALUES (?, ?, ?, ?)')
               ->execute([$id, 'paused', 'Checks paused', time()]);
            flash('Checks paused for "' . $m['name'] . '".');
        }
        break;

    case 'resume':
        $m = get_monitor($id);
        if ($m) {
            db()->prepare("UPDATE monitors SET status = 'pending', next_check = 0 WHERE id = ?")->execute([$id]);
            db()->prepare('INSERT INTO events (monitor_id, type, message, created_at) VALUES (?, ?, ?, ?)')
               ->execute([$id, 'resumed', 'Checks resumed', time()]);
            flash('Checks resumed for "' . $m['name'] . '" — running within a minute.');
        }
        break;

    case 'delete':
        $m = get_monitor($id);
        if ($m) {
            db()->prepare('DELETE FROM monitors WHERE id = ?')->execute([$id]);
            flash('Deleted "' . $m['name'] . '" and its history.');
        }
        redirect('index.php');

    case 'run_one':
        $m = get_monitor($id);
        if ($m && $m['status'] !== 'paused') {
            $res = run_monitor_check($m);
            flash(
                $res['ok']
                    ? '"' . $m['name'] . '" is up — ' . $res['message'] . ' in ' . $res['rt'] . ' ms.'
                    : '"' . $m['name'] . '" failed: ' . $res['message'],
                $res['ok'] ? 'ok' : 'err'
            );
        } elseif ($m) {
            flash('This monitor is paused — resume it first.', 'warn');
        }
        break;

    case 'run_all':
        db()->exec("UPDATE monitors SET next_check = 0 WHERE status != 'paused'");
        $n = run_due_checks();
        flash($n > 0 ? "Checked $n monitor" . ($n === 1 ? '' : 's') . '.' : 'No active monitors to check.');
        break;

    default:
        flash('Unknown action.', 'warn');
}

redirect($back);
