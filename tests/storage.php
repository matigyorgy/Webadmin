<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
function storageCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
storageCheck(GameDock\StorageService::limit('10240')===10240,'Valid disk cap rejected');storageCheck(GameDock\StorageService::limit('')===0,'Unlimited disk cap rejected');
foreach([-1,'-1',1.5,'1e3',true,'1073741825'] as $bad){$rejected=false;try{GameDock\StorageService::limit($bad);}catch(RuntimeException){$rejected=true;}storageCheck($rejected,'Invalid disk cap accepted');}
if(!getenv('TEST_MYSQL_HOST')){echo "Storage MySQL not run: TEST_MYSQL_HOST required.\n";exit;}
$root=new PDO('mysql:host='.getenv('TEST_MYSQL_HOST').';charset=utf8mb4','root',getenv('TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$name='gamedock_storage_'.bin2hex(random_bytes(5));$root->exec('CREATE DATABASE `'.$name.'`');
putenv('DB_DSN=mysql:host='.getenv('TEST_MYSQL_HOST').';dbname='.$name.';charset=utf8mb4');putenv('DB_USER=root');putenv('DB_PASSWORD='.getenv('TEST_MYSQL_PASSWORD'));
try{
 $db=new GameDock\Database();foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/schema.sql')) as $sql)if(trim($sql)!=='')$db->query($sql);(new GameDock\GameRegistry($db))->seed();
 $db->query("INSERT INTO users(id,email,password_hash,is_admin) VALUES(1,'admin@example.test','fixture',1),(2,'owner@example.test','fixture',0)");$db->query("INSERT INTO nodes(id,name,ssh_host,ssh_port,ssh_user,ssh_fingerprint) VALUES(1,'fixture','127.0.0.1',9,'fixture','fixture')");$db->query("INSERT INTO servers(id,user_id,node_id,name,game,unit_name,port,startup_command) VALUES(42,2,1,'Fixture','cs16','880001',25555,'./hlds_run -game cstrike -port {port}')");
 $admin=new GameDock\AdminService($db,static fn(array $node):array=>[]);$actor=['id'=>1,'is_admin'=>1];$edit=['id'=>'42','name'=>'Fixture','game'=>'cs16','unit_name'=>'880001','port'=>'25555','startup_command'=>'./hlds_run -game cstrike -port {port}','user_id'=>'2','node_id'=>'1','disk_limit_mb'=>'10240'];
 $called=false;try{$admin->save($actor,'servers',$edit,function(int $id)use(&$called){$called=true;throw new RuntimeException('Project quota unavailable');});}catch(RuntimeException $e){storageCheck($e->getMessage()==='Project quota unavailable','Unexpected quota callback failure');}
 storageCheck($called && (int)$db->query('SELECT disk_limit_mb FROM servers WHERE id=42')->fetchColumn()===0,'Unenforced quota persisted in DB');
 $admin->save($actor,'servers',$edit);storageCheck((int)$db->query('SELECT disk_limit_mb FROM servers WHERE id=42')->fetchColumn()===10240,'Disk cap not stored');
 unset($edit['disk_limit_mb']);$admin->save($actor,'servers',$edit);storageCheck((int)$db->query('SELECT disk_limit_mb FROM servers WHERE id=42')->fetchColumn()===10240,'Older form silently cleared disk cap');
 $client=['id'=>2,'is_admin'=>0];$denied=false;try{(new GameDock\StorageService($db,new GameDock\SshClient()))->apply($client,42,20);}catch(RuntimeException $e){$denied=str_contains($e->getMessage(),'csak adminisztrátor');}storageCheck($denied,'Client reached quota SSH');
 $db->query('ALTER TABLE servers DROP COLUMN disk_limit_mb');$updater=new GameDock\SchemaUpdater($db);storageCheck($updater->provisionEnabled() && !$updater->storageEnabled(),'Old schema advertised storage support');$updater->enableStartup($actor);$updater->enableStartup($actor);storageCheck($updater->storageEnabled(),'Storage migration not applied');
 storageCheck((int)$db->query('SELECT disk_limit_mb FROM servers WHERE id=42')->fetchColumn()===0,'Migrated default not unlimited');
 echo "Storage MySQL passed: validation, cap persistence, unsupported-kernel rollback, old-form preservation, admin-only access and repeatable migration.\n";
}finally{$root->exec('DROP DATABASE `'.$name.'`');}
