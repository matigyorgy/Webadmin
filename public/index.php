<?php
declare(strict_types=1);
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly'=>true,'secure'=>getenv('COOKIE_SECURE') !== '0','samesite'=>'Strict']);
session_start();
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');
require dirname(__DIR__) . '/autoload.php';
$controller = new GameDock\PanelController(
    new GameDock\Database(),
    new GameDock\SshClient(),
    new GameDock\SmartyView(dirname(__DIR__))
);
$controller->handle();
