<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
GameDock\WebSession::start();
$root=dirname(__DIR__);
$installed=GameDock\Config::installed();
$error=''; $success=null;
$defaults=['db_host'=>'localhost','db_port'=>'3306','db_name'=>'webadmin','db_user'=>'webadmin','admin_email'=>'',
    'node_name'=>'Linux gép 1','ssh_host'=>'','ssh_port'=>'22','ssh_user'=>'gamedock','ssh_fingerprint'=>'',
    'server_name'=>'','game'=>'minecraft','unit_name'=>'minecraft-survival.service'];
$values=$defaults;
$_SESSION['install_csrf'] ??= bin2hex(random_bytes(32));
// Create only runtime directories, never overwrite settings or existing files.
if (!$installed) {
    foreach ([$root.'/config',$root.'/var/smarty/compile'] as $directory) {
        if (!is_dir($directory)) @mkdir($directory,0770,true);
    }
}
$installer=new GameDock\Installer($root);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    foreach ($defaults as $name=>$default) $values[$name]=is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : $default;
    try {
        if (!hash_equals($_SESSION['install_csrf'],(string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Érvénytelen űrlap. Frissítsd az oldalt és próbáld újra.');
        $success=$installer->install($_POST);
        $installed=true;
        unset($_SESSION['install_csrf']);
        session_regenerate_id(true);
    } catch (Throwable $e) {
        $error=$e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'A telepítés nem sikerült. Ellenőrizd a szerver beállításait.';
    }
}
if ($installed && !$success) http_response_code(403);
try {
    (new GameDock\SmartyView($root))->render('install.tpl', [
        'installed'=>$installed,'success'=>$success,'error'=>$error,'values'=>$values,
        'requirements'=>$installer->requirements(),'csrf'=>$_SESSION['install_csrf'] ?? '',
        'https'=>isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
} catch (Throwable $e) {
    GameDock\BootstrapErrorHandler::exception($e);
}
