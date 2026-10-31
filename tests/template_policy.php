<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
function policyCheck(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function policyDenied(callable $call): void {try{$call();}catch(RuntimeException $e){return;}throw new RuntimeException('A tiltott művelet sikerült.');}
GameDock\TemplatePolicy::assertFits(['minimum_memory_mb'=>2048],['memory_limit_mb'=>2048]);
GameDock\TemplatePolicy::assertFits(['minimum_memory_mb'=>2048],['memory_limit_mb'=>0]);
policyDenied(fn()=>GameDock\TemplatePolicy::assertFits(['minimum_memory_mb'=>2048],['memory_limit_mb'=>1024]));
policyCheck(GameDock\VersionService::matches(['minecraft_type'=>'vanilla'],['type'=>'paper']),'Minecraft-típusváltás nem engedélyezett.');
policyCheck(!GameDock\VersionService::matches(['minecraft_type'=>'vanilla'],['type'=>'cs2','provider'=>'folder','game'=>'cs2']),'Másik játék választható Minecraftnak.');
$view=new GameDock\SmartyView(dirname(__DIR__));ob_start();
try{$view->render('admin/templates.tpl',['nodes'=>[['id'=>1,'name'=>'node_s1']],'selected_node'=>1,'csrf'=>'test','catalog'=>['state'=>'idle','templates'=>[['key'=>'paper:1.2:1','type'=>'paper','version'=>'1.2','build'=>'1','state'=>'ready','minimum_memory_mb'=>2048,'requires_reinstall'=>true]]],'profiles'=>[]]);$html=ob_get_contents();}finally{ob_end_clean();}
foreach(GameDock\TemplatePolicy::TYPES as $type=>$name)policyCheck(str_contains($html,'value="'.$type.'"'),'Hiányzó kiadási kategória.');
policyCheck(str_contains($html,'name="minimum_memory_mb"') && str_contains($html,'name="requires_reinstall"') && str_contains($html,'version=paper_1.2') && !str_contains($html,'Minecraft-templatek'),'Hiányos adminfelület vagy hiányzó API-verzióérték.');
echo "Template minimum memória, játéktípus-korlát és kategorizált Smarty-felület rendben.\n";
if(!getenv('TEST_MYSQL_HOST'))exit;
$root=new PDO('mysql:host='.getenv('TEST_MYSQL_HOST').';charset=utf8mb4','root',getenv('TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='gamedock_policy_'.bin2hex(random_bytes(5));$root->exec('CREATE DATABASE `'.$name.'`');
putenv('DB_DSN=mysql:host='.getenv('TEST_MYSQL_HOST').';dbname='.$name.';charset=utf8mb4');putenv('DB_USER=root');putenv('DB_PASSWORD='.getenv('TEST_MYSQL_PASSWORD'));$db=new GameDock\Database();
try{
    foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/schema.sql')) as $sql)if(trim($sql)!=='')$db->query($sql);
    $db->query("INSERT INTO users(id,email,password_hash,is_admin) VALUES(1,'admin@example.com','fixture',1),(2,'client@example.com','fixture',0)");
    $db->query("INSERT INTO nodes(id,name,ssh_host,ssh_port,ssh_user,ssh_fingerprint) VALUES(1,'node_s1','localhost',22,'gamedock','fixture'),(2,'node_s2','localhost',22,'gamedock','fixture')");
    $policy=new GameDock\TemplatePolicy($db);$admin=['id'=>1];$row=['key'=>'paper:1.2:1'];
    $policy->save($admin,1,$row['key'],['minimum_memory_mb'=>'2048','requires_reinstall'=>'1']);
    policyCheck($policy->get(1,$row)===['minimum_memory_mb'=>2048,'requires_reinstall'=>true],'A szabály nem mentődött.');
    policyCheck($policy->get(2,$row)['minimum_memory_mb']===0,'Más node szabálya kiszivárgott.');
    policyDenied(fn()=>$policy->save(['id'=>2],1,$row['key'],['minimum_memory_mb'=>'0']));
    policyDenied(fn()=>$policy->save($admin,1,$row['key'],['minimum_memory_mb'=>'-1']));
    // Upgrade an existing installation, then repeat the web migration safely.
    $db->query('DROP TABLE template_policies');$db->query('ALTER TABLE servers DROP COLUMN template_key,DROP COLUMN template_version');$db->query('ALTER TABLE server_version_jobs DROP COLUMN template_key,DROP COLUMN template_version');
    $updater=new GameDock\SchemaUpdater($db);policyCheck(!$updater->provisionEnabled(),'A régi séma frissítés nélkül elfogadva.');
    $updater->enableStartup($admin);$updater->enableStartup($admin);policyCheck($updater->provisionEnabled(),'A frissített séma hiányos.');
    echo "MySQL: verziónkénti szabályok, node-elkülönítés, adminvédelem és ismételhető sémamigráció rendben.\n";
}finally{$root->exec('DROP DATABASE `'.$name.'`');}
