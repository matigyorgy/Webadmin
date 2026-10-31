<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
(new GameDock\SmartyView(dirname(__DIR__,2)))->render('partials/automation.tpl',['selected'=>['id'=>42],'csrf'=>'automation-test']);
