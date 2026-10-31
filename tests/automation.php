<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
function checkAutomation(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
if(!getenv('TEST_MYSQL_HOST')){echo "Automation MySQL checks not run: TEST_MYSQL_HOST required.\n";exit;}
$root=new PDO('mysql:host='.getenv('TEST_MYSQL_HOST').';charset=utf8mb4','root',getenv('TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='gamedock_automation_'.bin2hex(random_bytes(5));$root->exec('CREATE DATABASE `'.$name.'`');
putenv('DB_DSN=mysql:host='.getenv('TEST_MYSQL_HOST').';dbname='.$name.';charset=utf8mb4');putenv('DB_USER=root');putenv('DB_PASSWORD='.getenv('TEST_MYSQL_PASSWORD'));
try{
 $db=new GameDock\Database();foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/schema.sql')) as $sql)if(trim($sql)!=='')$db->query($sql);
 $db->query("INSERT INTO users(id,email,password_hash,is_admin) VALUES(1,'admin@example.test','fixture',1),(2,'owner@example.test','fixture',0),(3,'other@example.test','fixture',0)");
 $db->query("INSERT INTO nodes(id,name,ssh_host,ssh_port,ssh_user,ssh_fingerprint) VALUES(1,'fixture','127.0.0.1',9,'fixture','fixture')");
 $db->query("INSERT INTO servers(id,user_id,node_id,name,game,unit_name,port,startup_command,memory_limit_mb,cpu_limit_percent) VALUES(42,2,1,'Fixture','minecraft','880001',25555,'original',512,100)");
 $service=new GameDock\AutomationService($db,new GameDock\SshClient());$owner=['id'=>2,'is_admin'=>0];$other=['id'=>3,'is_admin'=>0];
 foreach([fn()=>$service->listing($other,42),fn()=>$service->perform($other,42,'backup',[])] as $request){
  $denied=false;try{$request();}catch(RuntimeException $e){$denied=str_contains($e->getMessage(),'nincs hozzá jogosultságod');}checkAutomation($denied,'Other owner reached automation SSH');
 }
 $panel=['startup'=>'java -Xmx8G -jar server.jar --port {port}','template_key'=>'paper:1.21.1:1','template_version'=>'1.21.1','minecraft_type'=>'paper','minecraft_version'=>'1.21.1','install_source'=>'/home/gamedock/templates/minecraft/paper/1.21.1/1'];
 $job=['id'=>str_repeat('a',32),'restored_panel'=>$panel];$service->reconcile($owner,42,$job);
 $server=(new GameDock\ServerService($db,new GameDock\SshClient()))->find($owner,42);checkAutomation($server['last_restore_job']===$job['id'] && $server['minecraft_version']==='1.21.1','Restore metadata not applied');
 checkAutomation(str_contains($server['startup_command'],'-Xmx409M'),'Restore ignored current memory limit');
 $db->query('UPDATE servers SET startup_command=? WHERE id=42',['admin-changed']);$service->reconcile($owner,42,$job);checkAutomation($db->query('SELECT startup_command FROM servers WHERE id=42')->fetchColumn()==='admin-changed','Old restore reapplied on subsequent poll');
 $denied=false;try{$service->reconcile($other,42,['id'=>str_repeat('b',32),'restored_panel'=>$panel]);}catch(RuntimeException $e){$denied=true;}checkAutomation($denied,'Other owner could reconcile restore');
 $db->query('ALTER TABLE servers DROP COLUMN last_restore_job');$updater=new GameDock\SchemaUpdater($db);$updater->enableStartup(['id'=>1,'is_admin'=>1]);$updater->enableStartup(['id'=>1,'is_admin'=>1]);checkAutomation((bool)$db->query("SHOW COLUMNS FROM servers LIKE 'last_restore_job'")->fetch(),'Automation migration missing');
 echo "Automation MySQL passed: ownership before SSH, restore metadata and current limits, idempotent reconciliation, migration repeatability.\n";
}finally{$root->exec('DROP DATABASE `'.$name.'`');}
