<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
Webadmin\WebSession::start();
if (!Webadmin\Config::installed()) {
    header('Location: /install.php');
    exit;
}
$controller = new Webadmin\PanelController(
    new Webadmin\Database(),
    new Webadmin\SshClient(),
    new Webadmin\SmartyView(dirname(__DIR__))
);
$controller->handle();
