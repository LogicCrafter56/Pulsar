<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/inc/auth.php';
require APP_ROOT . '/inc/layout.php';
require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$m = $id ? get_monitor($id) : null;
if ($id && !$m) {
    flash('That monitor no longer exists.', 'warn');
    redirect('index.php');
}

$defaults = [
    'name' => '', 'type' => 'http', 'url' => '', 'port' => '', 'keyword' => '',
    'keyword_alert' => 'missing', 'method' => 'GET', 'interval_min' => 5, 'timeout' => 10,
    'expected_codes' => '200-399', 'follow_redirects' => 1, 'verify_ssl' => 1,
    'fail_threshold' => 1, 'notify_telegram' => 0, 'notify_email' => 0, 'notify_pushover' => 0,
    'email_to' => '', 'notify_recovery' => 1, 'resend_every' => 0,
];
$v = $m ? array_merge($defaults, $m) : $defaults;
$errors = [];

if (is_post()) {
    csrf_check();
    foreach ($defaults as $k => $_) {
        if (isset($_POST[$k])) $v[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k];
    }
    // checkboxes: absent when unchecked
    foreach (['follow_redirects', 'verify_ssl', 'notify_telegram', 'notify_email', 'notify_pushover', 'notify_recovery'] as $cb) {
        $v[$cb] = isset($_POST[$cb]) ? 1 : 0;
    }

    if (!in_array($v['type'], ['http', 'keyword', 'ping', 'port'], true)) $v['type'] = 'http';
    if ($v['name'] === '') $errors[] = 'Give the monitor a name.';
    if ($v['url'] === '')  $errors[] = ($v['type'] === 'ping' || $v['type'] === 'port') ? 'Enter a host or IP to check.' : 'Enter a URL to check.';
    if (in_array($v['type'], ['http', 'keyword'], true) && $v['url'] !== '' && !preg_match('~^https?://~i', $v['url'])) {
        $v['url'] = 'https://' . $v['url'];
    }
    if ($v['type'] === 'port' && ((int)$v['port'] < 1 || (int)$v['port'] > 65535)) $errors[] = 'Enter a port between 1 and 65535.';
    if ($v['type'] === 'keyword' && $v['keyword'] === '') $errors[] = 'Enter the keyword to look for.';
    if ($v['notify_email'] && $v['email_to'] === '' && setting('mail_to_default') === '') {
        $errors[] = 'Email alerts are on but no recipient is set — add one here or set a default in Settings.';
    }

    $v['interval_min']   = max(1, min(1440, (int)$v['interval_min']));
    $v['timeout']        = max(1, min(120, (int)$v['timeout']));
    $v['fail_threshold'] = max(1, min(10, (int)$v['fail_threshold']));
    $v['resend_every']   = max(0, min(1000, (int)$v['resend_every']));
    if ($v['expected_codes'] === '' || !preg_match('/^[\d\s,\-]+$/', $v['expected_codes'])) $v['expected_codes'] = '200-399';
    if (!in_array($v['method'], ['GET', 'HEAD', 'POST'], true)) $v['method'] = 'GET';
    if (!in_array($v['keyword_alert'], ['missing', 'present'], true)) $v['keyword_alert'] = 'missing';

    if (!$errors) {
        $cols = ['name', 'type', 'url', 'port', 'keyword', 'keyword_alert', 'method', 'interval_min',
                 'timeout', 'expected_codes', 'follow_redirects', 'verify_ssl', 'fail_threshold',
                 'notify_telegram', 'notify_email', 'notify_pushover', 'email_to', 'notify_recovery', 'resend_every'];
        $data = [];
        foreach ($cols as $c) $data[$c] = $v[$c] === '' && in_array($c, ['port', 'keyword', 'email_to'], true) ? null : $v[$c];

        if ($m) {
            $set = implode(', ', array_map(function ($c) { return "$c = :$c"; }, $cols));
            $data['id'] = $id;
            db()->prepare("UPDATE monitors SET $set, next_check = 0 WHERE id = :id")->execute($data);
            flash('Monitor updated. It will be re-checked within a minute.');
        } else {
            $data['created_at'] = time();
            $fields = implode(', ', array_keys($data));
            $ph = ':' . implode(', :', array_keys($data));
            db()->prepare("INSERT INTO monitors ($fields, status, next_check) VALUES ($ph, 'pending', 0)")->execute($data);
            $newId = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO events (monitor_id, type, message, created_at) VALUES (?, ?, ?, ?)')
               ->execute([$newId, 'created', 'Monitor created', time()]);
            flash('Monitor added — first check runs within a minute.');
        }
        redirect('index.php');
    }
}

$tgReady = setting('tg_token') !== '' && setting('tg_chat') !== '';
$mailReady = setting('mail_mode') === 'mail' || setting('smtp_host') !== '';
$poReady = setting('po_token') !== '' && setting('po_user') !== '';

page_header($m ? 'Edit monitor' : 'New monitor', 'dash');
?>

<div class="page-head">
  <div>
    <h1><?= $m ? 'Edit “' . e($m['name']) . '”' : 'New monitor' ?></h1>
    <div class="sub">Choose what to watch, how often, and who gets told when it breaks.</div>
  </div>
</div>

<?php foreach ($errors as $err): ?><div class="flash flash-err"><?= e($err) ?></div><?php endforeach; ?>

<form method="post">
  <?= csrf_field() ?>
  <?php if ($m): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

  <div class="panel">
    <h2>What to check</h2>
    <p class="panel-sub">The target and the kind of check Pulsar runs against it.</p>
    <div class="form-grid">
      <label class="f"><span class="lab">Check type</span>
        <select name="type" id="mon-type">
          <option value="http"    <?= $v['type'] === 'http' ? 'selected' : '' ?>>HTTP(s) — is the site responding?</option>
          <option value="keyword" <?= $v['type'] === 'keyword' ? 'selected' : '' ?>>Keyword — does the page contain a word?</option>
          <option value="ping"    <?= $v['type'] === 'ping' ? 'selected' : '' ?>>Ping — is the host reachable?</option>
          <option value="port"    <?= $v['type'] === 'port' ? 'selected' : '' ?>>Port — is a TCP port open?</option>
        </select>
      </label>
      <label class="f"><span class="lab">Friendly name</span>
        <input type="text" name="name" value="<?= e((string)$v['name']) ?>" placeholder="My website" required>
      </label>
      <label class="f full"><span class="lab" id="url-label">URL</span>
        <input type="text" name="url" id="mon-url" value="<?= e((string)$v['url']) ?>" placeholder="https://example.com" required>
      </label>

      <label class="f" data-show-for="port"><span class="lab">Port</span>
        <input type="number" name="port" value="<?= e((string)$v['port']) ?>" min="1" max="65535" placeholder="443">
      </label>

      <label class="f" data-show-for="keyword"><span class="lab">Keyword</span>
        <input type="text" name="keyword" value="<?= e((string)$v['keyword']) ?>" placeholder="Welcome">
        <span class="hint">Case-insensitive match against the page HTML.</span>
      </label>
      <label class="f" data-show-for="keyword"><span class="lab">Alert when the keyword is…</span>
        <select name="keyword_alert">
          <option value="missing" <?= $v['keyword_alert'] === 'missing' ? 'selected' : '' ?>>Missing from the page (it should be there)</option>
          <option value="present" <?= $v['keyword_alert'] === 'present' ? 'selected' : '' ?>>Found on the page (it shouldn't be there)</option>
        </select>
      </label>

      <label class="f"><span class="lab">Check every</span>
        <select name="interval_min">
          <?php foreach (interval_options() as $min => $lab): ?>
          <option value="<?= $min ?>" <?= (int)$v['interval_min'] === $min ? 'selected' : '' ?>><?= e($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="f"><span class="lab">Timeout (seconds)</span>
        <input type="number" name="timeout" value="<?= (int)$v['timeout'] ?>" min="1" max="120">
      </label>
    </div>
  </div>

  <div class="panel" data-show-for="http keyword">
    <h2>HTTP options</h2>
    <p class="panel-sub">Fine-tune how the request is made and what counts as "up".</p>
    <div class="form-grid">
      <label class="f" data-show-for="http"><span class="lab">Method</span>
        <select name="method">
          <?php foreach (['GET', 'HEAD', 'POST'] as $meth): ?>
          <option <?= $v['method'] === $meth ? 'selected' : '' ?>><?= $meth ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="f"><span class="lab">Accepted status codes</span>
        <input type="text" name="expected_codes" value="<?= e((string)$v['expected_codes']) ?>" placeholder="200-399">
        <span class="hint">Ranges and single codes, e.g. <b>200-299, 301, 401</b>. Anything else counts as down.</span>
      </label>
      <div class="full">
        <label class="check"><input type="checkbox" name="follow_redirects" <?= $v['follow_redirects'] ? 'checked' : '' ?>>
          <span>Follow redirects<span class="desc">Judge the final page after up to 10 redirects.</span></span>
        </label>
        <label class="check"><input type="checkbox" name="verify_ssl" <?= $v['verify_ssl'] ? 'checked' : '' ?>>
          <span>Verify SSL certificate<span class="desc">An invalid or expired certificate counts as down.</span></span>
        </label>
      </div>
    </div>
  </div>

  <div class="panel">
    <h2>When to alert</h2>
    <p class="panel-sub">Avoid false alarms and control repeat reminders.</p>
    <div class="form-grid">
      <label class="f"><span class="lab">Confirm down after</span>
        <select name="fail_threshold">
          <?php foreach ([1 => '1 failed check (alert immediately)', 2 => '2 failed checks in a row', 3 => '3 failed checks in a row', 5 => '5 failed checks in a row'] as $ft => $lab): ?>
          <option value="<?= $ft ?>" <?= (int)$v['fail_threshold'] === $ft ? 'selected' : '' ?>><?= e($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="f"><span class="lab">While it stays down, remind me</span>
        <select name="resend_every">
          <?php foreach ([0 => 'Never — one alert is enough', 3 => 'Every 3 checks', 5 => 'Every 5 checks', 10 => 'Every 10 checks', 20 => 'Every 20 checks'] as $re => $lab): ?>
          <option value="<?= $re ?>" <?= (int)$v['resend_every'] === $re ? 'selected' : '' ?>><?= e($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="full">
        <label class="check"><input type="checkbox" name="notify_recovery" <?= $v['notify_recovery'] ? 'checked' : '' ?>>
          <span>Notify on recovery<span class="desc">Send a follow-up when the monitor comes back up, including how long it was down.</span></span>
        </label>
      </div>
    </div>
  </div>

  <div class="panel">
    <h2>Where to alert</h2>
    <p class="panel-sub">Channels are configured once in <a href="settings.php">Settings</a>; turn them on per monitor here.</p>
    <label class="check">
      <input type="checkbox" name="notify_telegram" <?= $v['notify_telegram'] ? 'checked' : '' ?>>
      <span>Telegram
        <span class="desc"><?= $tgReady ? 'Bot connected — messages go to your configured chat.' : 'Not configured yet — set the bot token and chat ID in Settings first.' ?></span>
      </span>
    </label>
    <label class="check">
      <input type="checkbox" name="notify_pushover" <?= $v['notify_pushover'] ? 'checked' : '' ?>>
      <span>Pushover
        <span class="desc"><?= $poReady ? 'App connected — push notifications go to your devices.' : 'Not configured yet — set the API token and user key in Settings first.' ?></span>
      </span>
    </label>
    <label class="check">
      <input type="checkbox" name="notify_email" <?= $v['notify_email'] ? 'checked' : '' ?>>
      <span>Email
        <span class="desc"><?= $mailReady ? 'Mail transport is configured.' : 'Not configured yet — set up SMTP in Settings first.' ?></span>
      </span>
    </label>
    <div class="form-grid" style="margin-top:8px">
      <label class="f full"><span class="lab">Send email alerts to</span>
        <input type="text" name="email_to" value="<?= e((string)$v['email_to']) ?>" placeholder="<?= e(setting('mail_to_default') ?: 'you@example.com') ?>">
        <span class="hint">Comma-separate multiple addresses. Leave empty to use the default recipient from Settings.</span>
      </label>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= $m ? 'Save changes' : 'Start monitoring' ?></button>
    <a class="btn btn-ghost" href="<?= $m ? 'monitor.php?id=' . $id : 'index.php' ?>">Cancel</a>
  </div>
</form>

<?php page_footer(); ?>
