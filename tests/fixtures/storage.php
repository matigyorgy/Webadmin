<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
(new GameDock\SmartyView(dirname(__DIR__,2)))->render('partials/server-overview.tpl',['selected'=>['id'=>42,'name'=>'Fixture','game_name'=>'Counter-Strike 1.6','node_name'=>'Fixture node','provision_state'=>'ready','memory_limit_mb'=>1024,'disk_limit_mb'=>10240],'user'=>['is_admin'=>0],'csrf'=>'storage-test']);
