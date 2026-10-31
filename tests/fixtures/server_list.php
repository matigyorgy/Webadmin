<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
$view=new GameDock\SmartyView(dirname(__DIR__,2));
$servers=[
 ['id'=>11,'name'=>'Survival','node_name'=>'node_a','game_name'=>'Minecraft','address'=>'games.example.test','port'=>25555,'state'=>'ellenőrzés…','provision_state'=>'ready'],
 ['id'=>12,'name'=>'New server','node_name'=>'node_b','game_name'=>'CS2','address'=>'[2001:db8::2]','port'=>25556,'state'=>'telepítés','provision_state'=>'running'],
];
$view->render('panel.tpl',['user'=>['id'=>1,'email'=>'admin@example.test','is_admin'=>1],'servers'=>$servers,'server_count'=>count($servers),'csrf'=>'server-list-test','error'=>'','notice'=>'','console'=>null,'selected'=>null,'history'=>[],'page'=>'servers','search'=>'','navigation_groups'=>[]]);
