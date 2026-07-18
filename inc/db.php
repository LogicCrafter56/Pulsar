<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $fresh = !file_exists(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ($fresh) {
        db_install($pdo);
    } else {
        db_migrate($pdo);
    }
    return $pdo;
}

/** Bring older databases up to the current schema. */
function db_migrate(PDO $pdo)
{
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(monitors)') as $c) {
        $cols[] = $c['name'];
    }
    if (!in_array('notify_pushover', $cols, true)) {
        $pdo->exec("ALTER TABLE monitors ADD COLUMN notify_pushover INTEGER NOT NULL DEFAULT 0");
    }
}

function db_install(PDO $pdo)
{
    $pdo->exec("
        CREATE TABLE monitors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'http',
            url TEXT NOT NULL,
            port INTEGER,
            keyword TEXT,
            keyword_alert TEXT NOT NULL DEFAULT 'missing',
            method TEXT NOT NULL DEFAULT 'GET',
            interval_min INTEGER NOT NULL DEFAULT 5,
            timeout INTEGER NOT NULL DEFAULT 10,
            expected_codes TEXT NOT NULL DEFAULT '200-399',
            follow_redirects INTEGER NOT NULL DEFAULT 1,
            verify_ssl INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'pending',
            fail_count INTEGER NOT NULL DEFAULT 0,
            fail_threshold INTEGER NOT NULL DEFAULT 1,
            notify_telegram INTEGER NOT NULL DEFAULT 0,
            notify_email INTEGER NOT NULL DEFAULT 0,
            notify_pushover INTEGER NOT NULL DEFAULT 0,
            email_to TEXT,
            notify_recovery INTEGER NOT NULL DEFAULT 1,
            resend_every INTEGER NOT NULL DEFAULT 0,
            resend_counter INTEGER NOT NULL DEFAULT 0,
            last_check INTEGER,
            next_check INTEGER NOT NULL DEFAULT 0,
            last_rt INTEGER,
            last_code INTEGER,
            last_error TEXT,
            created_at INTEGER NOT NULL
        );

        CREATE TABLE checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            monitor_id INTEGER NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
            checked_at INTEGER NOT NULL,
            ok INTEGER NOT NULL,
            rt INTEGER,
            code INTEGER,
            message TEXT
        );
        CREATE INDEX idx_checks_monitor ON checks(monitor_id, checked_at);

        CREATE TABLE events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            monitor_id INTEGER NOT NULL REFERENCES monitors(id) ON DELETE CASCADE,
            type TEXT NOT NULL,
            message TEXT,
            duration INTEGER,
            created_at INTEGER NOT NULL
        );
        CREATE INDEX idx_events_monitor ON events(monitor_id, created_at);

        CREATE TABLE settings (
            k TEXT PRIMARY KEY,
            v TEXT
        );
    ");

    $defaults = [
        'admin_user'      => 'admin',
        'admin_pass'      => password_hash('admin', PASSWORD_DEFAULT),
        'default_creds'   => '1',
        'cron_key'        => bin2hex(random_bytes(16)),
        'tg_token'        => '',
        'tg_chat'         => '',
        'po_token'        => '',
        'po_user'         => '',
        'po_priority_down' => '1',
        'mail_mode'       => 'smtp',
        'smtp_host'       => '',
        'smtp_port'       => '587',
        'smtp_secure'     => 'tls',
        'smtp_user'       => '',
        'smtp_pass'       => '',
        'mail_from'       => 'uptime@localhost',
        'mail_from_name'  => 'Pulsar',
        'mail_to_default' => '',
        'timezone'        => date_default_timezone_get(),
        'last_cron'       => '0',
        'retention_days'  => '30',
    ];
    $stmt = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }
}

function setting(string $key, string $default = ''): string
{
    if (!isset($GLOBALS['__settings'])) {
        $GLOBALS['__settings'] = db()->query('SELECT k, v FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $GLOBALS['__settings'][$key] ?? $default;
}

function set_setting(string $key, string $value)
{
    // INSERT OR REPLACE instead of ON CONFLICT: works on the old SQLite bundled with PHP 7.x
    db()->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')
        ->execute([$key, $value]);
    if (isset($GLOBALS['__settings'])) {
        $GLOBALS['__settings'][$key] = $value;
    }
}
