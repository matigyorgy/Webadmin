<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
Webadmin\WebSession::start();
if (!Webadmin\Config::installed()) { header('Location: /install.php'); exit; }
(new Webadmin\AdminController(new Webadmin\Database(),new Webadmin\SshClient(),new Webadmin\SmartyView(dirname(__DIR__))))->handle();
