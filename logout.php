<?php
require __DIR__ . '/config.php';
require APP_ROOT . '/inc/auth.php';
logout();
header('Location: login.php');
exit;
