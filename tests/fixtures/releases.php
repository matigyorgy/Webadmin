<?php
// Render the real Smarty page for browser_release_picker.cjs, without SSH or MySQL.
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
(new GameDock\SmartyView(dirname(__DIR__, 2)))->render('admin/templates.tpl', [
    'nodes'=>[['id'=>1,'name'=>'Test node']], 'selected_node'=>1, 'csrf'=>'release-picker-test',
    'catalog'=>['state'=>'idle','templates'=>[['key'=>'paper:1.2:1','type'=>'paper','version'=>'1.2','build'=>'1','state'=>'ready','minimum_memory_mb'=>2048,'requires_reinstall'=>true]]], 'profiles'=>['minecraft'=>['name'=>'Minecraft','provider'=>'minecraft'],'cs16'=>['name'=>'Counter-Strike 1.6','provider'=>'folder'],'cs2'=>['name'=>'Counter-Strike 2','provider'=>'folder'],'css'=>['name'=>'Counter-Strike: Source','provider'=>'folder'],'custom'=>['name'=>'Custom game','provider'=>'folder']],
]);
