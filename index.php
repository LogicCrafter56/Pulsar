<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/inc/auth.php';
require APP_ROOT . '/inc/layout.php';
require_login();

$monitors = db()->query("SELECT * FROM monitors ORDER BY CASE status WHEN 'down' THEN 0 WHEN 'pending' THEN 1 WHEN 'up' THEN 2 ELSE 3 END, name COLLATE NOCASE")->fetchAll();

$counts = ['up' => 0, 'down' => 0, 'paused' => 0, 'pending' => 0];
foreach ($monitors as $m) {
    $counts[$m['status']] = ($counts[$m['status']] ?? 0) + 1;
}

// overall 24h uptime across all checks
$overall = db()->prepare('SELECT COUNT(*) AS n, SUM(ok) AS up FROM checks WHERE checked_at >= ?');
$overall->execute([time() - 86400]);
$o = $overall->fetch();
$overallPct = ((int)$o['n'] > 0) ? 100.0 * (int)$o['up'] / (int)$o['n'] : null;

page_header('Monitors', 'dash');
?>

<div class="page-head">
  <div>
    <h1><?php
      if (!$monitors)              echo 'Monitors';
      elseif ($counts['down'] > 0) echo $counts['down'] . ' monitor' . ($counts['down'] > 1 ? 's' : '') . ' down';
      else                         echo 'All systems up';
    ?></h1>
    <div class="sub"><?= count($monitors) ?> monitor<?= count($monitors) === 1 ? '' : 's' ?> · refreshes every minute while this tab is open</div>
  </div>
  <form method="post" action="action.php">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="run_all">
    <button class="btn" type="submit">Check all now</button>
  </form>
</div>

<?php if ($monitors): ?>
<div class="summary">
  <div class="sum-card"><div class="k">Up</div><div class="v v-up"><?= $counts['up'] ?></div></div>
  <div class="sum-card"><div class="k">Down</div><div class="v <?= $counts['down'] ? 'v-down' : 'v-muted' ?>"><?= $counts['down'] ?></div></div>
  <div class="sum-card"><div class="k">Paused / waiting</div><div class="v v-muted"><?= $counts['paused'] + $counts['pending'] ?></div></div>
  <div class="sum-card"><div class="k">Uptime · 24 h</div><div class="v"><?= e(fmt_pct($overallPct)) ?></div></div>
</div>

<div class="monitors">
<?php foreach ($monitors as $m):
    $checks = recent_checks((int)$m['id'], 30);
    $pct = uptime_pct((int)$m['id'], 86400);
?>
  <div class="mon <?= $m['status'] === 'down' ? 'is-down' : '' ?>">
    <span class="dot dot-<?= e($m['status']) ?>" title="<?= e(status_label($m['status'])) ?>"></span>
    <div class="mon-name">
      <a href="monitor.php?id=<?= (int)$m['id'] ?>"><?= e($m['name']) ?></a>
      <span class="url"><?= e(type_label($m['type'])) ?> · <?= e($m['type'] === 'port' ? $m['url'] . ':' . $m['port'] : $m['url']) ?></span>
    </div>
    <div class="mon-strip"><?= signal_strip($checks, 30) ?></div>
    <div class="mon-meta mon-meta-1">
      <b><?= e(fmt_pct($pct)) ?></b>
      <span class="lbl">24 h uptime</span>
    </div>
    <div class="mon-meta mon-meta-2">
      <b><?= $m['status'] === 'up' && $m['last_rt'] !== null ? (int)$m['last_rt'] . ' ms' : '—' ?></b>
      <span class="lbl"><?= e(fmt_ago($m['last_check'] ? (int)$m['last_check'] : null)) ?></span>
    </div>
    <div class="mon-actions">
      <a class="btn btn-ghost btn-sm" href="edit.php?id=<?= (int)$m['id'] ?>" title="Edit">Edit</a>
      <form method="post" action="action.php">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
        <?php if ($m['status'] === 'paused'): ?>
          <input type="hidden" name="do" value="resume">
          <button class="btn btn-ghost btn-sm" type="submit" title="Resume checks">Resume</button>
        <?php else: ?>
          <input type="hidden" name="do" value="pause">
          <button class="btn btn-ghost btn-sm" type="submit" title="Pause checks">Pause</button>
        <?php endif; ?>
      </form>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php else: ?>
<div class="empty">
  <img class="empty-orb" src="assets/img/orb.png" alt="">
  <h2>Nothing on the radar yet</h2>
  <p>Add your first website or server and Pulsar will start watching it within a minute.</p>
  <a class="btn btn-primary" href="edit.php">+ Add your first monitor</a>
</div>
<?php endif; ?>

<?php page_footer(); ?>
