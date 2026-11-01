<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
function apiCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function apiReject(callable $call,int $code): void {try{$call();}catch(RuntimeException $e){apiCheck($e->getCode()===$code,'Hibás HTTP-hibakód: '.$e->getMessage());return;}throw new RuntimeException('A hibás kérés elfogadva.');}
apiCheck(Webadmin\ProvisionApiService::success(['user_id'=>1731,'unit_name'=>'15366','node_name'=>'node_s1'])==='FELH_ID:1731|SZOLG_ID:15366|NODE:node_s1|','Eltérő válaszformátum');
apiReject(fn()=>Webadmin\ProvisionApiService::success(['user_id'=>1,'unit_name'=>'12345678','node_name'=>'node|FELH_ID:2']),409);
apiReject(fn()=>Webadmin\ProvisionApiService::input(['user_id'=>1,'node_id'=>1,'game'=>'cs2','id'=>12]),400);
apiReject(fn()=>Webadmin\ProvisionApiService::input(['user_id'=>1,'node_id'=>1,'game'=>'cs2','startup_command'=>[]]),400);
apiReject(fn()=>Webadmin\ProvisionApiService::input(['user_id'=>0,'node_id'=>1,'game'=>'cs2']),400);
$a=Webadmin\ProvisionApiService::input(['game'=>'cs2','user_id'=>1,'node_id'=>2]);
$b=Webadmin\ProvisionApiService::input(['node_id'=>'2','user_id'=>'1','game'=>'cs2']);
apiCheck($a===$b,'A paraméterek normalizálása nem stabil.');
$template=Webadmin\ProvisionApiService::input(['game'=>'cs2','user_id'=>1,'node_id'=>2,'custom_template'=>'custom:0123456789abcdef01234567']);
apiCheck($template['custom_template']==='custom:0123456789abcdef01234567','Az API nem fogadja el a kész játéktípus-template kulcsát.');
$order=Webadmin\ProvisionApiService::order(['feladat'=>'szolg_letrehozas','premium'=>'true','type'=>'premium','slot'=>'10','hdd'=>'100','memory'=>'4096','version'=>'vanilla_26.1.2','szolg_type'=>'premium_minecraft','lejar'=>(string)(time()+3600),'email_cim'=>'laszlo@example.com','felhasznalonev'=>'SS9-360985','jelszo'=>'test-password-123','rendeles_id'=>'order-test-1']);
apiCheck($order['premium']&&$order['game']==='minecraft'&&$order['template']==='vanilla:26.1.2'&&$order['slot_limit']===10&&$order['disk_limit_mb']===102400&&$order['memory_limit_mb']===4096,'A rendelési mezők normalizálása hibás.');
apiCheck(Webadmin\ProvisionApiService::order(['feladat'=>'szolg_letrehozas','premium'=>'false','slot'=>'8','hdd'=>'50','memory'=>'2048','version'=>'latest','szolg_type'=>'fivm','lejar'=>(string)(time()+3600),'email_cim'=>'user@example.com','felhasznalonev'=>'user-123','jelszo'=>'test-password-123'])['game']==='fivem','A FiveM alias nem működik.');
apiReject(fn()=>Webadmin\ProvisionApiService::order(['feladat'=>'wrong']),400);
$view=new Webadmin\SmartyView(dirname(__DIR__));ob_start();
try{$view->render('api-settings.tpl',['user'=>['id'=>1,'email'=>'admin@example.com','is_admin'=>1],'page'=>'api','csrf'=>'test','error'=>'','notice'=>'','token'=>'','configured'=>false]);$html=ob_get_contents();}finally{ob_end_clean();}
apiCheck(str_contains($html,'provision_api.php') && str_contains($html,'name="csrf"') && str_contains($html,'API-kulcs létrehozása'),'Hiányos API-beállító felület.');
echo "API válaszformátum, mezőellenőrzések és Smarty-beállító felület rendben.\n";
if(!getenv('TEST_MYSQL_HOST')){echo "MySQL-integrációhoz TEST_MYSQL_HOST szükséges.\n";exit;}
$root=new PDO('mysql:host='.getenv('TEST_MYSQL_HOST').';charset=utf8mb4','root',getenv('TEST_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='webadmin_api_'.bin2hex(random_bytes(5));$root->exec('CREATE DATABASE `'.$name.'`');
putenv('DB_DSN=mysql:host='.getenv('TEST_MYSQL_HOST').';dbname='.$name.';charset=utf8mb4');putenv('DB_USER=root');putenv('DB_PASSWORD='.getenv('TEST_MYSQL_PASSWORD'));
$db=new Webadmin\Database();
try{
    foreach(explode(';',file_get_contents(dirname(__DIR__).'/database/schema.sql')) as $sql)if(trim($sql)!=='')$db->query($sql);
    Webadmin\ProvisionApiService::install($db);Webadmin\ProvisionApiService::install($db);
    $admin=new Webadmin\AdminService($db);$actor=['id'=>1,'is_admin'=>1];
    $db->query('INSERT INTO users(id,email,password_hash,is_admin) VALUES(1,?,?,1)',['api-admin@example.com',password_hash('Test-password-123',PASSWORD_DEFAULT)]);
    $db->query('INSERT INTO users(id,email,password_hash,is_admin) VALUES(2,?,?,0)',['api-owner@example.com',password_hash('Test-password-123',PASSWORD_DEFAULT)]);
    apiReject(fn()=>(new Webadmin\ProvisionApiService($db,new Webadmin\SshClient()))->run(['id'=>2,'is_admin'=>1],'test-key-123',['action'=>'status']),403);
    apiReject(fn()=>Webadmin\ProvisionApiCredentials::authenticate($db,'Bearer wrong'),401);
    $before=(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    try{$admin->save($actor,'users',['email'=>'rollback@example.com','password'=>'Test-password-123'],static function(int $id){throw new RuntimeException('rollback');});}catch(RuntimeException $e){apiCheck($e->getMessage()==='rollback','A mentési callback nem futott le.');}
    apiCheck((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()===$before,'A mentés nem gördült vissza.');
    $db->query('INSERT INTO nodes(id,name,ssh_host,ssh_port,ssh_user,ssh_fingerprint) VALUES(1,?,?,?,?,?)',['node_s1','127.0.0.1',22,'webadmin','SHA256:'.str_repeat('A',43)]);
    $db->query('ALTER TABLE servers DROP COLUMN slot_limit,DROP COLUMN expires_at');$db->query('ALTER TABLE nodes DROP COLUMN premium');$db->query('ALTER TABLE users DROP COLUMN username');
    try{(new Webadmin\ProvisionApiService($db,new Webadmin\SshClient()))->runOrder($actor,Webadmin\ProvisionApiService::order(['feladat'=>'szolg_letrehozas','premium'=>'true','type'=>'premium','slot'=>'10','hdd'=>'100','memory'=>'4096','version'=>'latest','szolg_type'=>'premium_cs2','lejar'=>(string)(time()+3600),'email_cim'=>'migration@example.com','felhasznalonev'=>'migration-user','jelszo'=>'test-password-123']));throw new RuntimeException('A hiányzó rendelési adatbázismezők után API-ellenőrzés nem futott tovább.');}
    catch(RuntimeException $e){apiCheck($e->getCode()===409 && str_contains($e->getMessage(),'Nincs prémiumként megjelölt Linux-gép'),'Az API nem frissítette automatikusan a rendelési adatbázismezőket: '.$e->getMessage());}
    apiCheck((new Webadmin\SchemaUpdater($db))->orderFieldsEnabled(),'Az API-kérés nem végezte el a hiányzó rendelési mezők adatbázisfrissítését.');
    $db->query('INSERT INTO servers(id,name,game,unit_name,user_id,node_id,provision_job,provision_state) VALUES(1,?,?,?,?,?,?,?)',['CS2','cs2','15366',2,1,str_repeat('a',32),'ready']);
    $key='integration-order-123';$input=['action'=>'create','user_id'=>2,'node_id'=>1,'game'=>'cs2'];
    $hash=hash('sha256',json_encode(Webadmin\ProvisionApiService::input($input),JSON_THROW_ON_ERROR));
    $db->query('INSERT INTO provision_api_requests(request_hash,payload_hash,server_id) VALUES(?,?,1)',[hash('sha256',$key),$hash]);
    $service=new Webadmin\ProvisionApiService($db,new Webadmin\SshClient());
    $lock='gd_api_'.substr(hash('sha256',$key),0,56);
    $statement=$root->prepare('SELECT GET_LOCK(?,0)');$statement->execute([$lock]);apiCheck((int)$statement->fetchColumn()===1,'A tesztzár nem jött létre.');
    try{apiReject(fn()=>$service->run($actor,$key,$input),409);}finally{$statement=$root->prepare('SELECT RELEASE_LOCK(?)');$statement->execute([$lock]);}
    foreach([$input,['action'=>'status']] as $request){$result=$service->run($actor,$key,$request);apiCheck($result===['status'=>201,'text'=>'FELH_ID:2|SZOLG_ID:15366|NODE:node_s1|'],'Hibás idempotens sikeres válasz.');}
    apiCheck((int)$db->query('SELECT COUNT(*) FROM servers')->fetchColumn()===1,'Ismételt kérés duplikált szervert készített.');
    $sameKey=$service->run($actor,$key,array_replace($input,['user_id'=>1]));apiCheck($sameKey===['status'=>201,'text'=>'FELH_ID:2|SZOLG_ID:15366|NODE:node_s1|'],'Eltérő paraméterű ismétlés nem a meglévő rendelést adta vissza.');
    apiReject(fn()=>$service->run($actor,'unknown-order-123',['action'=>'status']),404);
    $db->query("UPDATE servers SET provision_state='failed' WHERE id=1");
    $failed=$service->run($actor,$key,['action'=>'status']);apiCheck($failed['status']===422 && $failed['json']['server_id']===1 && $failed['json']['job_id']===str_repeat('a',32) && str_contains($failed['json']['log'],str_repeat('a',32).'.log') && str_contains($failed['json']['message'],'Másolási kérés újrapróbálása'),'Hibás másolás sikert jelez vagy hiányzik a retry/log útmutató.');
    try{$service->run($actor,$key,array_replace($input,['memory_limit_mb'=>2048]));}catch(Throwable){}
    $retried=$db->query('SELECT provision_job,provision_state,memory_limit_mb FROM servers WHERE id=1')->fetch();apiCheck($retried['provision_state']==='queued' && $retried['provision_job']!==str_repeat('a',32) && (int)$retried['memory_limit_mb']===0 && (int)$db->query('SELECT COUNT(*) FROM servers')->fetchColumn()===1,'A failed rendelés eltérő retry-payloadja módosította az eredeti szervert vagy duplikátumot hozott létre.');
    $db->query('DELETE FROM servers WHERE id=1');apiReject(fn()=>$service->run($actor,$key,$input),410);
    apiCheck((int)$db->query('SELECT COUNT(*) FROM servers')->fetchColumn()===0,'A törölt példány újra létrejött.');
    (new Webadmin\GameRegistry($db))->seed();
    $limited=(new Webadmin\AdminService($db,static fn(array $node):array=>[]))->save($actor,'servers',['user_id'=>'2','node_id'=>'1','game'=>'cs16','memory_limit_mb'=>'512','cpu_limit_percent'=>'0','disk_limit_mb'=>'0','slot_limit'=>'10','expires_at'=>(string)(time()+3600)]);
    $limitedRow=$db->query('SELECT startup_command,slot_limit,expires_at FROM servers WHERE id=?',[$limited])->fetch();
    apiCheck(str_contains($limitedRow['startup_command'],'+maxplayers 10')&&(int)$limitedRow['slot_limit']===10&&(int)$limitedRow['expires_at']>time(),'A férőhely/lejárat limit nem került a szerverre.');$db->query('DELETE FROM servers WHERE id=?',[$limited]);
    echo "MySQL: tranzakciós visszagörgetés, adminjog, API-kérés állapotkövetés és hibás/törölt szerver ellenőrizve.\n";
}finally{$root->exec('DROP DATABASE `'.$name.'`');}
