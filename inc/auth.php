<?php
declare(strict_types=1);

function is_logged_in(): bool
{
    return !empty($_SESSION['auth']);
}

function require_login()
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

function attempt_login(string $user, string $pass): bool
{
    if ($user === setting('admin_user') && password_verify($pass, setting('admin_pass'))) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        return true;
    }
    return false;
}

function logout()
{
    $_SESSION = [];
    session_destroy();
}
