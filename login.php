<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/inc/auth.php';

if (is_logged_in()) {
    redirect('index.php');
}

$error = '';
if (is_post()) {
    csrf_check();
    if (attempt_login(trim((string)($_POST['user'] ?? '')), (string)($_POST['pass'] ?? ''))) {
        redirect('index.php');
    }
    $error = 'Wrong username or password.';
}
$showDefault = setting('default_creds') === '1';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= APP_VER ?>">
</head>
<body class="login-body">
<div class="login-card">
  <img class="login-orb" src="assets/img/orb.png" alt="">
  <div class="brand">
    <span class="brand-dot"></span>
    <span class="brand-name">Pulsar</span>
    <span class="brand-sub">uptime</span>
  </div>
  <p class="login-tag">status · latency · alerts</p>

  <?php if ($error): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <?php if ($showDefault): ?><div class="flash flash-warn">First run — sign in with <b>admin / admin</b>, then change the password in Settings.</div><?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>
    <label class="f"><span class="lab">Username</span>
      <input type="text" name="user" required autofocus autocomplete="username">
    </label>
    <label class="f"><span class="lab">Password</span>
      <input type="password" name="pass" required autocomplete="current-password">
    </label>
    <button class="btn btn-primary" type="submit">Sign in</button>
  </form>
</div>
</body>
</html>
