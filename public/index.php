<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
GameDock\WebSession::start();
if (!GameDock\Config::installed()) {
    header('Location: /install.php');
    exit;
}
$controller = new GameDock\PanelController(
    new GameDock\Database(),
    new GameDock\SshClient(),
    new GameDock\SmartyView(dirname(__DIR__))
);
$controller->handle();
