<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/inc/auth.php';
require APP_ROOT . '/inc/layout.php';
require APP_ROOT . '/inc/notify.php';
require_login();

if (is_post()) {
    csrf_check();
    $section = (string)($_POST['section'] ?? '');

    if ($section === 'telegram') {
        set_setting('tg_token', trim((string)$_POST['tg_token']));
        set_setting('tg_chat', trim((string)$_POST['tg_chat']));
        if (isset($_POST['test'])) {
            $r = telegram_send("\u{2705} <b>Pulsar test</b> — Telegram alerts are working.");
            $r === true ? flash('Test message sent — check your Telegram.') : flash($r, 'err');
        } else {
            flash('Telegram settings saved.');
        }
    }

    if ($section === 'pushover') {
        set_setting('po_token', trim((string)$_POST['po_token']));
        set_setting('po_user', trim((string)$_POST['po_user']));
        set_setting('po_priority_down', isset($_POST['po_priority_down']) ? '1' : '0');
        if (isset($_POST['test'])) {
            $r = pushover_send('✅ Pulsar test', 'Pushover alerts are working. Down/up notifications will reach this device.');
            $r === true ? flash('Test notification sent — check your devices.') : flash($r, 'err');
        } else {
            flash('Pushover settings saved.');
        }
    }

    if ($section === 'email') {
        set_setting('mail_mode', in_array($_POST['mail_mode'] ?? '', ['smtp', 'mail'], true) ? $_POST['mail_mode'] : 'smtp');
        set_setting('smtp_host', trim((string)$_POST['smtp_host']));
        set_setting('smtp_port', (string)max(1, min(65535, (int)$_POST['smtp_port'])));
        set_setting('smtp_secure', in_array($_POST['smtp_secure'] ?? '', ['none', 'ssl', 'tls'], true) ? $_POST['smtp_secure'] : 'tls');
        set_setting('smtp_user', trim((string)$_POST['smtp_user']));
        if (trim((string)$_POST['smtp_pass']) !== '') {
            set_setting('smtp_pass', trim((string)$_POST['smtp_pass']));
        }
        set_setting('mail_from', trim((string)$_POST['mail_from']));
        set_setting('mail_from_name', trim((string)$_POST['mail_from_name']));
        set_setting('mail_to_default', trim((string)$_POST['mail_to_default']));
        if (isset($_POST['test'])) {
            $to = setting('mail_to_default');
            if ($to === '') {
                flash('Set a default recipient first, then send the test.', 'warn');
            } else {
                $r = email_send($to, '✅ Pulsar test — email alerts are working',
                    email_template('Email alerts are working', '<p>This is a test from your Pulsar uptime monitor. If you can read this, down/up alerts will reach you.</p>'));
                $r === true ? flash("Test email sent to $to.") : flash($r, 'err');
            }
        } else {
            flash('Email settings saved.');
        }
    }

    if ($section === 'security') {
        $newUser = trim((string)$_POST['admin_user']);
        $newPass = (string)$_POST['new_pass'];
        $confirm = (string)$_POST['new_pass2'];
        if ($newUser !== '') {
            set_setting('admin_user', $newUser);
        }
        if ($newPass !== '') {
            if (strlen($newPass) < 6) {
                flash('Password needs at least 6 characters.', 'err');
            } elseif ($newPass !== $confirm) {
                flash('Passwords do not match.', 'err');
            } else {
                set_setting('admin_pass', password_hash($newPass, PASSWORD_DEFAULT));
                set_setting('default_creds', '0');
                flash('Password changed.');
            }
        } else {
            flash('Account settings saved.');
        }
    }

    if ($section === 'system') {
        $tz = (string)$_POST['timezone'];
        if (in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            set_setting('timezone', $tz);
        }
        set_setting('retention_days', (string)max(7, min(365, (int)$_POST['retention_days'])));
        flash('System settings saved.');
    }

    redirect('settings.php');
}

$cronUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/cron.php?key=' . setting('cron_key');
$cronCli = 'php ' . APP_ROOT . DIRECTORY_SEPARATOR . 'cron.php';

page_header('Settings', 'settings');
?>

<div class="page-head">
  <div>
    <h1>Settings</h1>
    <div class="sub">Alert channels, account, and how the checker runs.</div>
  </div>
</div>

<?php if (setting('default_creds') === '1'): ?>
<div class="flash flash-warn">You are still using the default password — change it below.</div>
<?php endif; ?>

<form method="post" class="panel">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="telegram">
  <h2>Telegram alerts</h2>
  <p class="panel-sub">Create a bot with <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>, copy its token, then message the bot once and get your chat ID from <span style="font-family:var(--font-mono)">api.telegram.org/bot&lt;token&gt;/getUpdates</span> (or ask <a href="https://t.me/userinfobot" target="_blank" rel="noopener">@userinfobot</a>).</p>
  <div class="form-grid">
    <label class="f"><span class="lab">Bot token</span>
      <input type="text" name="tg_token" value="<?= e(setting('tg_token')) ?>" placeholder="123456789:AAF...">
    </label>
    <label class="f"><span class="lab">Chat ID</span>
      <input type="text" name="tg_chat" value="<?= e(setting('tg_chat')) ?>" placeholder="-1001234567890 or 987654321">
      <span class="hint">A user, group, or channel ID. The bot must be a member of groups/channels.</span>
    </label>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Save Telegram settings</button>
    <button class="btn" type="submit" name="test" value="1">Save &amp; send test message</button>
  </div>
</form>

<form method="post" class="panel">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="pushover">
  <h2>Pushover alerts</h2>
  <p class="panel-sub">Create an application at <a href="https://pushover.net/apps/build" target="_blank" rel="noopener">pushover.net/apps/build</a> and copy its API token; your user key is on the <a href="https://pushover.net" target="_blank" rel="noopener">Pushover dashboard</a>.</p>
  <div class="form-grid">
    <label class="f"><span class="lab">API token</span>
      <input type="text" name="po_token" value="<?= e(setting('po_token')) ?>" placeholder="azGDORePK8gMaC0QOYAMyEEuzJnyUi">
    </label>
    <label class="f"><span class="lab">User key</span>
      <input type="text" name="po_user" value="<?= e(setting('po_user')) ?>" placeholder="uQiRzpo4DXghDmr9QzzfQu27cmVRsG">
      <span class="hint">A user or group key — group keys deliver to every member.</span>
    </label>
    <div class="full">
      <label class="check"><input type="checkbox" name="po_priority_down" <?= setting('po_priority_down', '1') === '1' ? 'checked' : '' ?>>
        <span>High priority for down alerts<span class="desc">Down notifications bypass your Pushover quiet hours; recovery notifications stay normal priority.</span></span>
      </label>
    </div>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Save Pushover settings</button>
    <button class="btn" type="submit" name="test" value="1">Save &amp; send test notification</button>
  </div>
</form>

<form method="post" class="panel">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="email">
  <h2>Email alerts</h2>
  <p class="panel-sub">SMTP is recommended. For Gmail use an app password with smtp.gmail.com, port 587, STARTTLS.</p>
  <div class="form-grid">
    <label class="f"><span class="lab">Send using</span>
      <select name="mail_mode">
        <option value="smtp" <?= setting('mail_mode') === 'smtp' ? 'selected' : '' ?>>SMTP server (recommended)</option>
        <option value="mail" <?= setting('mail_mode') === 'mail' ? 'selected' : '' ?>>PHP mail() — needs a configured local mailer</option>
      </select>
    </label>
    <label class="f"><span class="lab">Default recipient</span>
      <input type="email" name="mail_to_default" value="<?= e(setting('mail_to_default')) ?>" placeholder="you@example.com">
      <span class="hint">Used when a monitor has no address of its own.</span>
    </label>
    <label class="f"><span class="lab">SMTP host</span>
      <input type="text" name="smtp_host" value="<?= e(setting('smtp_host')) ?>" placeholder="smtp.gmail.com">
    </label>
    <label class="f"><span class="lab">Port &amp; encryption</span>
      <div style="display:flex;gap:10px">
        <input type="number" name="smtp_port" value="<?= e(setting('smtp_port')) ?>" min="1" max="65535" style="width:110px">
        <select name="smtp_secure">
          <option value="tls"  <?= setting('smtp_secure') === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
          <option value="ssl"  <?= setting('smtp_secure') === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
          <option value="none" <?= setting('smtp_secure') === 'none' ? 'selected' : '' ?>>None (25)</option>
        </select>
      </div>
    </label>
    <label class="f"><span class="lab">SMTP username</span>
      <input type="text" name="smtp_user" value="<?= e(setting('smtp_user')) ?>" placeholder="you@example.com" autocomplete="off">
    </label>
    <label class="f"><span class="lab">SMTP password</span>
      <input type="password" name="smtp_pass" value="" placeholder="<?= setting('smtp_pass') !== '' ? '•••••••• (saved — leave blank to keep)' : '' ?>" autocomplete="new-password">
    </label>
    <label class="f"><span class="lab">From address</span>
      <input type="email" name="mail_from" value="<?= e(setting('mail_from')) ?>">
    </label>
    <label class="f"><span class="lab">From name</span>
      <input type="text" name="mail_from_name" value="<?= e(setting('mail_from_name')) ?>">
    </label>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Save email settings</button>
    <button class="btn" type="submit" name="test" value="1">Save &amp; send test email</button>
  </div>
</form>

<form method="post" class="panel">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="security">
  <h2>Account</h2>
  <p class="panel-sub">One admin account guards this dashboard.</p>
  <div class="form-grid">
    <label class="f"><span class="lab">Username</span>
      <input type="text" name="admin_user" value="<?= e(setting('admin_user')) ?>">
    </label>
    <div></div>
    <label class="f"><span class="lab">New password</span>
      <input type="password" name="new_pass" placeholder="Leave blank to keep current" autocomplete="new-password">
    </label>
    <label class="f"><span class="lab">Repeat new password</span>
      <input type="password" name="new_pass2" autocomplete="new-password">
    </label>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Save account</button>
  </div>
</form>

<form method="post" class="panel">
  <?= csrf_field() ?>
  <input type="hidden" name="section" value="system">
  <h2>System</h2>
  <div class="form-grid">
    <label class="f"><span class="lab">Timezone</span>
      <select name="timezone">
        <?php foreach (DateTimeZone::listIdentifiers() as $tz): ?>
        <option <?= setting('timezone') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="f"><span class="lab">Keep history for</span>
      <input type="number" name="retention_days" value="<?= e(setting('retention_days')) ?>" min="7" max="365">
      <span class="hint">Days of check history and incidents to keep in the database.</span>
    </label>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit">Save system settings</button>
  </div>
</form>

<div class="panel">
  <h2>Background checker</h2>
  <p class="panel-sub">While an Pulsar tab is open, checks run automatically every minute. For 24/7 monitoring with the browser closed, schedule one of these to run every minute:</p>
  <table class="tbl">
    <tr>
      <th style="width:170px">Windows Task Scheduler</th>
      <td class="mono"><?= e($cronCli) ?></td>
    </tr>
    <tr>
      <th>Web cron (cron-job.org etc.)</th>
      <td class="mono" style="word-break:break-all"><?= e($cronUrl) ?></td>
    </tr>
  </table>
  <p class="panel-sub" style="margin:14px 0 0">Task Scheduler: create a task, trigger “Daily”, repeat every 1 minute for a duration of 1 day, action “Start a program” with the command above (program: <span style="font-family:var(--font-mono)">php</span>, arguments: <span style="font-family:var(--font-mono)">"<?= e(APP_ROOT . DIRECTORY_SEPARATOR . 'cron.php') ?>"</span>).</p>
</div>

<?php page_footer(); ?>
