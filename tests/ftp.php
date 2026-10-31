<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
function ftpCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$valid=['ftp_username'=>'my_server','ftp_password'=>'Fixture-FTP-password-123','ftp_password_confirm'=>'Fixture-FTP-password-123'];
ftpCheck(GameDock\FtpService::credentials($valid)['username']==='my_server','Chosen username changed');
foreach([['ftp_username'=>'root'],['ftp_username'=>'bad;id'],['ftp_username'=>'xy'],['ftp_password'=>'short'],['ftp_password_confirm'=>'different']] as $bad){$denied=false;try{GameDock\FtpService::credentials(array_replace($valid,$bad));}catch(RuntimeException){$denied=true;}ftpCheck($denied,'Invalid FTP credentials accepted');}
echo "FTP credential validation passed.\n";
if(!getenv('TEST_MYSQL_HOST')){echo "FTP MySQL not run: TEST_MYSQL_HOST required.\n";exit;}
$root=new PDO('mysql:host='.getenv('TEST_MYSQL_HOST').';charset=utf8mb4','root',getenv('TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$name='gamedock_ftp_'.bin2hex(random_bytes(5));$root->exec('CREATE DATABASE `'.$name.'`');
putenv('DB_DSN=mysql:host='.getenv('TEST_MYSQL_HOST').';dbname='.$name.';charset=utf8mb4');putenv('DB_USER=root');putenv('DB_PASSWORD='.getenv('TEST_MYSQL_PASSWORD'));
try{
 $db=new GameDock\Database();foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/schema.sql')) as $sql)if(trim($sql)!=='')$db->query($sql);
 (new GameDock\GameRegistry($db))->seed();$db->query("INSERT INTO users(id,email,password_hash,is_admin) VALUES(1,'admin@example.test','fixture',1),(2,'owner@example.test','fixture',0),(3,'other@example.test','fixture',0)");
 $db->query("INSERT INTO nodes(id,name,ssh_host,ssh_port,ssh_user,ssh_fingerprint) VALUES(1,'fixture','127.0.0.1',9,'fixture','fixture')");$db->query("INSERT INTO servers(id,user_id,node_id,name,game,unit_name,port,startup_command) VALUES(42,2,1,'Fixture','cs16','880001',25555,'./hlds_run -game cstrike -port {port}')");
 $service=new GameDock\FtpService($db,new GameDock\SshClient());$other=['id'=>3,'is_admin'=>0];
 foreach([fn()=>$service->listing($other,42),fn()=>$service->perform($other,42,'ftp_create',$valid),fn()=>$service->perform($other,42,'ftp_delete')] as $request){$denied=false;try{$request();}catch(RuntimeException $e){$denied=str_contains($e->getMessage(),'nincs hozzá jogosultságod');}ftpCheck($denied,'Other owner reached FTP SSH');}
 $admin=new GameDock\AdminService($db,static fn(array $node):array=>[]);$ownerChange=['id'=>'42','name'=>'Fixture','game'=>'cs16','unit_name'=>'880001','port'=>'25555','startup_command'=>'./hlds_run -game cstrike -port {port}','user_id'=>'3','node_id'=>'1'];$called=false;
 try{$admin->save(['id'=>1,'is_admin'=>1],'servers',$ownerChange,function(int $id)use(&$called){$called=true;throw new RuntimeException('Simulated FTP revocation failure');});}catch(RuntimeException $e){ftpCheck($e->getMessage()==='Simulated FTP revocation failure','Unexpected ownership callback failure');}
 ftpCheck($called && (int)$db->query('SELECT user_id FROM servers WHERE id=42')->fetchColumn()===2,'Ownership transfer committed without FTP revocation');
 echo "FTP MySQL passed: ownership before SSH and ownership-transfer rollback when access revocation fails.\n";
}finally{$root->exec('DROP DATABASE `'.$name.'`');}
