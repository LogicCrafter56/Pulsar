<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/inc/auth.php';
require APP_ROOT . '/inc/layout.php';
require_login();

$m = get_monitor((int)($_GET['id'] ?? 0));
if (!$m) {
    flash('That monitor no longer exists.', 'warn');
    redirect('index.php');
}
$id = (int)$m['id'];

$checks = recent_checks($id, 60);
$pct24 = uptime_pct($id, 86400);
$pct7  = uptime_pct($id, 7 * 86400);
$pct30 = uptime_pct($id, 30 * 86400);
$avg24 = avg_response($id, 86400);

// events (incidents)
$ev = db()->prepare('SELECT * FROM events WHERE monitor_id = ? ORDER BY created_at DESC LIMIT 50');
$ev->execute([$id]);
$events = $ev->fetchAll();

// chart data: checks over the last 24 h
$cd = db()->prepare('SELECT checked_at, ok, rt FROM checks WHERE monitor_id = ? AND checked_at >= ? ORDER BY checked_at ASC');
$cd->execute([$id, time() - 86400]);
$chartRows = $cd->fetchAll();
$chartData = ['labels' => [], 'rt' => [], 'down' => []];
foreach ($chartRows as $r) {
    $chartData['labels'][] = date('H:i', (int)$r['checked_at']);
    $chartData['rt'][]     = $r['ok'] ? (int)$r['rt'] : null;
    $chartData['down'][]   = $r['ok'] ? null : 0;
}

$target = $m['type'] === 'port' ? $m['url'] . ':' . $m['port'] : $m['url'];
$isLink = in_array($m['type'], ['http', 'keyword'], true);

page_header($m['name'], 'dash');
?>

<div class="detail-head">
  <span class="pill pill-<?= e($m['status']) ?>"><span class="dot dot-<?= e($m['status']) ?>"></span><?= e(status_label($m['status'])) ?></span>
  <h1><?= e($m['name']) ?></h1>
  <div class="spacer"></div>
  <form method="post" action="action.php" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="do" value="run_one">
    <button class="btn btn-sm" type="submit">Check now</button>
  </form>
  <form method="post" action="action.php" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="do" value="<?= $m['status'] === 'paused' ? 'resume' : 'pause' ?>">
    <button class="btn btn-sm" type="submit"><?= $m['status'] === 'paused' ? 'Resume' : 'Pause' ?></button>
  </form>
  <a class="btn btn-sm" href="edit.php?id=<?= $id ?>">Edit</a>
  <form method="post" action="action.php" style="display:inline" data-confirm="Delete this monitor and all of its history?">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="do" value="delete">
    <button class="btn btn-danger btn-sm" type="submit">Delete</button>
  </form>
  <div class="url">
    <?= e(type_label($m['type'])) ?> ·
    <?php if ($isLink): ?><a href="<?= e($m['url']) ?>" target="_blank" rel="noopener"><?= e($target) ?></a>
    <?php else: ?><?= e($target) ?><?php endif; ?>
    · every <?= (int)$m['interval_min'] ?> min
    <?php if ($m['status'] === 'down' && $m['last_error']): ?> · <span style="color:var(--down)"><?= e($m['last_error']) ?></span><?php endif; ?>
  </div>
</div>

<div class="stats">
  <div class="stat"><div class="k">Uptime · 24 h</div><div class="v"><?= e(fmt_pct($pct24)) ?></div></div>
  <div class="stat"><div class="k">Uptime · 7 d</div><div class="v"><?= e(fmt_pct($pct7)) ?></div></div>
  <div class="stat"><div class="k">Uptime · 30 d</div><div class="v"><?= e(fmt_pct($pct30)) ?></div></div>
  <div class="stat"><div class="k">Avg response · 24 h</div><div class="v"><?= $avg24 !== null ? $avg24 . ' <small>ms</small>' : '—' ?></div></div>
  <div class="stat"><div class="k">Last check</div><div class="v" style="font-size:14px;padding-top:4px"><?= e(fmt_ago($m['last_check'] ? (int)$m['last_check'] : null)) ?></div></div>
</div>

<div class="panel">
  <h2>Recent checks</h2>
  <p class="panel-sub">Last <?= count($checks) ?> checks — bar height is response time, red bars are failures. Hover for details.</p>
  <?= signal_strip($checks, 60, 'lg') ?>
</div>

<div class="panel">
  <h2>Response time · last 24 h</h2>
  <p class="panel-sub">Successful checks in milliseconds; red points mark failed checks.</p>
  <div class="chart-box"><canvas id="rt-chart"></canvas></div>
</div>

<div class="panel">
  <h2>Incidents</h2>
  <p class="panel-sub">Down and recovery events for this monitor.</p>
  <?php if ($events): ?>
  <table class="tbl">
    <tr><th>Event</th><th>Details</th><th class="mono">Downtime</th><th class="mono">When</th></tr>
    <?php foreach ($events as $evt): ?>
    <tr>
      <td class="<?= $evt['type'] === 'down' ? 't-down' : ($evt['type'] === 'up' ? 't-up' : '') ?>">
        <?= e(['down' => '● Down', 'up' => '● Up', 'created' => '○ Created', 'paused' => '○ Paused', 'resumed' => '○ Resumed'][$evt['type']] ?? $evt['type']) ?>
      </td>
      <td><?= e((string)$evt['message']) ?></td>
      <td class="mono"><?= $evt['duration'] !== null ? e(fmt_duration((int)$evt['duration'])) : '—' ?></td>
      <td class="mono"><?= e(fmt_dt((int)$evt['created_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p style="color:var(--dim);margin:0">No incidents recorded — quiet so far.</p>
  <?php endif; ?>
</div>

<?php
$chartJson = json_encode($chartData);
$extra = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js"></script>
<script>
(function () {
  var d = $chartJson;
  var ctx = document.getElementById('rt-chart');
  if (!ctx || !window.Chart) return;
  var g2d = ctx.getContext('2d');
  var fillGrad = g2d.createLinearGradient(0, 0, 0, 260);
  fillGrad.addColorStop(0, 'rgba(47,217,138,.32)');
  fillGrad.addColorStop(.6, 'rgba(53,200,232,.10)');
  fillGrad.addColorStop(1, 'rgba(110,168,255,0)');
  var lineGrad = g2d.createLinearGradient(0, 0, 900, 0);
  lineGrad.addColorStop(0, '#2FD98A');
  lineGrad.addColorStop(.55, '#35C8E8');
  lineGrad.addColorStop(1, '#6EA8FF');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: d.labels,
      datasets: [{
        label: 'Response (ms)',
        data: d.rt,
        borderColor: lineGrad,
        backgroundColor: fillGrad,
        fill: true,
        tension: .35,
        spanGaps: false,
        pointRadius: 0,
        pointHitRadius: 8,
        borderWidth: 1.5
      }, {
        label: 'Failed check',
        data: d.down,
        borderColor: 'transparent',
        backgroundColor: '#FF5C69',
        pointRadius: 4,
        pointStyle: 'rectRot',
        showLine: false
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { color: '#5C6E8C', maxTicksLimit: 12, font: { family: 'IBM Plex Mono', size: 10 } }, grid: { color: 'rgba(34,48,74,.5)' } },
        y: { beginAtZero: true, ticks: { color: '#5C6E8C', font: { family: 'IBM Plex Mono', size: 10 } }, grid: { color: 'rgba(34,48,74,.5)' } }
      }
    }
  });
})();
</script>
HTML;
page_footer($extra);
