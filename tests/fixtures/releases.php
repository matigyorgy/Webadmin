<?php
// Render the real Smarty page for browser_release_picker.cjs, without SSH or MySQL.
declare(strict_types=1);
require dirname(__DIR__, 2).'/autoload.php';
(new GameDock\SmartyView(dirname(__DIR__, 2)))->render('admin/templates.tpl', [
    'nodes'=>[['id'=>1,'name'=>'Test node']], 'selected_node'=>1, 'csrf'=>'release-picker-test',
    'catalog'=>['state'=>'idle','templates'=>[]], 'profiles'=>[],
]);
