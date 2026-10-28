<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
GameDock\WebSession::start();
if (!GameDock\Config::installed()) { header('Location: /install.php'); exit; }
(new GameDock\AdminController(new GameDock\Database(),new GameDock\SshClient(),new GameDock\SmartyView(dirname(__DIR__))))->handle();
