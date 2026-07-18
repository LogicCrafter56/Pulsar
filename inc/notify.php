<?php
declare(strict_types=1);

/**
 * Send a Telegram message via the Bot API.
 * Returns true on success, or an error string.
 */
function telegram_send(string $text)
{
    $token = setting('tg_token');
    $chat  = setting('tg_chat');
    if ($token === '' || $chat === '') {
        return 'Telegram is not configured (bot token or chat ID missing).';
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chat,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_invoke($ch);
    if ($body === false) {
        return 'Telegram request failed: connection error.';
    }
    $json = json_decode($body, true);
    if (!($json['ok'] ?? false)) {
        return 'Telegram error: ' . ($json['description'] ?? 'unknown response');
    }
    return true;
}

/**
 * Send a Pushover notification via the Message API.
 * Returns true on success, or an error string.
 */
function pushover_send(string $title, string $message, int $priority = 0, string $url = '')
{
    $token = setting('po_token');
    $user  = setting('po_user');
    if ($token === '' || $user === '') {
        return 'Pushover is not configured (API token or user key missing).';
    }
    $post = [
        'token'    => $token,
        'user'     => $user,
        'title'    => $title,
        'message'  => $message,
        'priority' => $priority,
    ];
    if ($url !== '') {
        $post['url'] = $url;
    }
    $ch = curl_init('https://api.pushover.net/1/messages.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_invoke($ch);
    if ($body === false) {
        return 'Pushover request failed: connection error.';
    }
    $json = json_decode($body, true);
    if ((int)($json['status'] ?? 0) !== 1) {
        $errs = isset($json['errors']) && is_array($json['errors']) ? implode(', ', $json['errors']) : 'unknown response';
        return 'Pushover error: ' . $errs;
    }
    return true;
}

/** @return string|false */
function curl_invoke($ch)
{
    $body = curl_exec($ch);
    curl_close($ch);
    return $body;
}

/**
 * Send an email using configured transport (SMTP or PHP mail()).
 * Returns true on success, or an error string.
 */
function email_send(string $to, string $subject, string $html)
{
    if ($to === '') {
        return 'No recipient address set.';
    }
    if (setting('mail_mode') === 'mail') {
        $headers = "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . 'From: ' . mail_from_header() . "\r\n";
        return mail($to, $subject, $html, $headers)
            ? true
            : 'PHP mail() returned false — check your sendmail/SMTP setup in php.ini.';
    }
    return smtp_send($to, $subject, $html);
}

function mail_from_header(): string
{
    $name = setting('mail_from_name', 'Pulsar');
    $addr = setting('mail_from', 'uptime@localhost');
    return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $addr . '>';
}

/**
 * Minimal SMTP client with SSL / STARTTLS and AUTH LOGIN. No dependencies.
 */
function smtp_send(string $to, string $subject, string $html)
{
    $host   = setting('smtp_host');
    $port   = (int)setting('smtp_port', '587');
    $secure = setting('smtp_secure', 'tls');   // none | ssl | tls (STARTTLS)
    $user   = setting('smtp_user');
    $pass   = setting('smtp_pass');
    $from   = setting('mail_from', 'uptime@localhost');

    if ($host === '') {
        return 'SMTP is not configured (host missing). Set it up in Settings → Email.';
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return "SMTP connection to $host:$port failed: $errstr";
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $data = '';
        while ($line = fgets($fp, 1024)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($c, array $expect) use ($fp, $read) {
        fwrite($fp, $c . "\r\n");
        $resp = $read();
        $code = (int)substr($resp, 0, 3);
        return in_array($code, $expect, true) ? true : trim($resp);
    };
    $fail = function (string $step, string $resp) use ($fp): string {
        fclose($fp);
        return "SMTP $step failed: $resp";
    };

    $greeting = $read();
    if ((int)substr($greeting, 0, 3) !== 220) return $fail('greeting', trim($greeting));

    $hostname = gethostname() ?: 'localhost';
    if (($r = $cmd('EHLO ' . $hostname, [250])) !== true) return $fail('EHLO', $r);

    if ($secure === 'tls') {
        if (($r = $cmd('STARTTLS', [220])) !== true) return $fail('STARTTLS', $r);
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return 'SMTP TLS negotiation failed.';
        }
        if (($r = $cmd('EHLO ' . $hostname, [250])) !== true) return $fail('EHLO (TLS)', $r);
    }

    if ($user !== '') {
        if (($r = $cmd('AUTH LOGIN', [334])) !== true) return $fail('AUTH', $r);
        if (($r = $cmd(base64_encode($user), [334])) !== true) return $fail('AUTH user', $r);
        if (($r = $cmd(base64_encode($pass), [235])) !== true) return $fail('AUTH password', $r);
    }

    if (($r = $cmd('MAIL FROM:<' . $from . '>', [250])) !== true) return $fail('MAIL FROM', $r);
    foreach (array_map('trim', explode(',', $to)) as $rcpt) {
        if ($rcpt === '') continue;
        if (($r = $cmd('RCPT TO:<' . $rcpt . '>', [250, 251])) !== true) return $fail('RCPT TO', $r);
    }
    if (($r = $cmd('DATA', [354])) !== true) return $fail('DATA', $r);

    $headers = 'From: ' . mail_from_header() . "\r\n"
        . 'To: ' . $to . "\r\n"
        . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
        . 'Date: ' . date('r') . "\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $hostname . ">\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n";
    $body = chunk_split(base64_encode($html));
    if (($r = $cmd($headers . "\r\n" . $body . "\r\n.", [250])) !== true) return $fail('send', $r);

    $cmd('QUIT', [221]);
    fclose($fp);
    return true;
}

/** Build a simple branded HTML email around a message block. */
function email_template(string $title, string $lines): string
{
    return '<!doctype html><html><body style="margin:0;padding:24px;background:#0B1220;font-family:Segoe UI,Arial,sans-serif">'
        . '<div style="max-width:520px;margin:0 auto;background:#131C2E;border:1px solid #22304A;border-radius:12px;padding:28px">'
        . '<div style="color:#6EA8FF;font-size:12px;letter-spacing:2px;text-transform:uppercase;margin-bottom:12px">Pulsar · Uptime monitor</div>'
        . '<h2 style="color:#E7EDF7;margin:0 0 16px;font-size:20px">' . $title . '</h2>'
        . '<div style="color:#8CA0BE;font-size:14px;line-height:1.7">' . $lines . '</div>'
        . '</div></body></html>';
}

/**
 * Dispatch a down/up notification for a monitor on its enabled channels.
 * $kind is 'down', 'up' or 'still-down'. Returns list of error strings (empty = all good).
 */
function notify_monitor(array $m, string $kind, string $detail, int $downSince = 0): array
{
    $errors = [];
    $target = $m['type'] === 'port' ? $m['url'] . ':' . $m['port'] : $m['url'];

    if ($kind === 'up') {
        $dur = $downSince > 0 ? fmt_duration(time() - $downSince) : '';
        $emoji = "\u{1F7E2}";
        $tgText = "$emoji <b>" . htmlspecialchars($m['name']) . '</b> is UP again'
            . ($dur ? " after <b>$dur</b> of downtime" : '') . ".\n"
            . htmlspecialchars($target);
        $poTitle = '🟢 ' . $m['name'] . ' is up again';
        $poMsg = $m['name'] . ' is back online' . ($dur ? " after $dur of downtime" : '') . '.' . "\n" . $target;
        $poPriority = 0;
        $subject = '🟢 UP: ' . $m['name'] . ' is back online';
        $htmlBody = '<p><b style="color:#2FD98A">' . e($m['name']) . ' is up again</b>'
            . ($dur ? ' after <b>' . e($dur) . '</b> of downtime' : '') . '.</p>'
            . '<p>Target: <a style="color:#6EA8FF" href="' . e($target) . '">' . e($target) . '</a></p>'
            . '<p>Checked at ' . e(date('M j, Y H:i:s')) . '</p>';
    } else {
        $still = $kind === 'still-down' ? ' still' : '';
        $emoji = "\u{1F534}";
        $tgText = "$emoji <b>" . htmlspecialchars($m['name']) . "</b> is$still DOWN.\n"
            . 'Reason: ' . htmlspecialchars($detail) . "\n"
            . htmlspecialchars($target);
        $poTitle = '🔴 ' . $m['name'] . ' is' . $still . ' down';
        $poMsg = 'Reason: ' . $detail . "\n" . $target;
        $poPriority = setting('po_priority_down', '1') === '1' ? 1 : 0;
        $subject = '🔴 DOWN: ' . $m['name'] . ($still ? ' is still down' : ' is down');
        $htmlBody = '<p><b style="color:#FF5C69">' . e($m['name']) . ' is' . $still . ' down.</b></p>'
            . '<p>Reason: ' . e($detail) . '</p>'
            . '<p>Target: <a style="color:#6EA8FF" href="' . e($target) . '">' . e($target) . '</a></p>'
            . '<p>Detected at ' . e(date('M j, Y H:i:s')) . '</p>';
    }

    if ((int)$m['notify_telegram'] === 1) {
        $r = telegram_send($tgText);
        if ($r !== true) $errors[] = $r;
    }
    if ((int)$m['notify_email'] === 1) {
        $to = trim((string)$m['email_to']) !== '' ? $m['email_to'] : setting('mail_to_default');
        $r = email_send($to, $subject, email_template($subject, $htmlBody));
        if ($r !== true) $errors[] = $r;
    }
    if ((int)($m['notify_pushover'] ?? 0) === 1) {
        $link = preg_match('~^https?://~i', $target) ? $target : '';
        $r = pushover_send($poTitle, $poMsg, $poPriority, $link);
        if ($r !== true) $errors[] = $r;
    }
    return $errors;
}
