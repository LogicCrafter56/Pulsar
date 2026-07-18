<?php
declare(strict_types=1);

function page_header(string $title, string $active = '')
{
    $flashes = get_flashes();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= APP_VER ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><circle cx='16' cy='16' r='10' fill='%232FD98A'/></svg>">
</head>
<body data-page="<?= $active === 'dash' ? 'dashboard' : e($active) ?>">
<header class="topbar">
  <a class="brand" href="index.php">
    <span class="brand-dot"></span>
    <span class="brand-name">Pulsar</span>
    <span class="brand-sub">uptime</span>
  </a>
  <nav class="nav">
    <a href="index.php" class="<?= $active === 'dash' ? 'active' : '' ?>">Monitors</a>
    <a href="settings.php" class="<?= $active === 'settings' ? 'active' : '' ?>">Settings</a>
  </nav>
  <div class="topbar-actions">
    <a class="btn btn-primary" href="edit.php">+ New monitor</a>
    <a class="btn btn-ghost" href="logout.php" title="Sign out">Sign out</a>
  </div>
</header>
<main class="wrap">
<?php foreach ($flashes as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
<?php
}

function page_footer(string $extraJs = '')
{
    $lastCron = (int)setting('last_cron', '0');
    $stale = $lastCron > 0 && (time() - $lastCron) > 180;
    ?>
</main>
<footer class="foot">
  <span>Checker last ran <b><?= e(fmt_ago($lastCron ?: null)) ?></b><?= $stale ? ' — set up the cron task in Settings, or keep this tab open' : '' ?></span>
  <span class="foot-right"><?= APP_NAME ?> <?= APP_VER ?> · self-hosted uptime monitor</span>
</footer>
<script>window.CRON_KEY = <?= json_encode(setting('cron_key')) ?>;</script>
<script src="assets/app.js?v=<?= APP_VER ?>"></script>
<?= $extraJs ?>
</body>
</html>
<?php
}
