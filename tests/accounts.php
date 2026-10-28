<?php
declare(strict_types=1);
require dirname(__DIR__).'/autoload.php';
function verify(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
function denies(callable $operation,string $message): void { $denied=false; try { $operation(); } catch (RuntimeException $e) { $denied=true; } verify($denied,$message); }
if (!getenv('TEST_MYSQL_HOST')) { echo "Account integration not run: TEST_MYSQL_HOST required.\n"; exit; }
session_start(); $_SESSION=[];
$host=getenv('TEST_MYSQL_HOST'); $dbPassword=getenv('TEST_MYSQL_PASSWORD');
$root=new PDO('mysql:host='.$host.';charset=utf8mb4','root',$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$name='gamedock_accounts_'.bin2hex(random_bytes(5)); $root->exec('CREATE DATABASE `'.$name.'`');
putenv('DB_DSN=mysql:host='.$host.';dbname='.$name.';charset=utf8mb4');putenv('DB_USER=root');putenv('DB_PASSWORD='.$dbPassword);
$db=new GameDock\Database();$auth=new GameDock\AuthService($db);$admin=new GameDock\AdminService($db);$servers=new GameDock\ServerService($db,new GameDock\SshClient());
$prefix=bin2hex(random_bytes(5));$password='A-strong-test-password-123';
try {
    foreach (explode(';',file_get_contents(dirname(__DIR__).'/database/schema.sql')) as $sql) if (trim($sql)!=='') $db->query($sql);
    $email='owner-'.$prefix.'@example.com';
    $db->query('INSERT INTO users(email,password_hash,is_admin) VALUES(?,?,1)',[$email,password_hash($password,PASSWORD_DEFAULT)]);
    $auth->login(['email'=>$email,'password'=>$password]); $owner=$auth->current(); verify((int)$owner['is_admin']===1,'Admin login failed');
    $clientId=$admin->save($owner,'users',['email'=>'alice-'.$prefix.'@example.com','password'=>$password]);
    $otherId=$admin->save($owner,'users',['email'=>'bob-'.$prefix.'@example.com','password'=>$password]);
    $client=['id'=>$clientId,'is_admin'=>0];$other=['id'=>$otherId,'is_admin'=>0];
    $nodeData=['name'=>'Linux <test>','ssh_host'=>'127.0.0.1','ssh_port'=>'22','ssh_user'=>'gamedock','ssh_fingerprint'=>'SHA256:'.str_repeat('A',43)];
    $nodeId=$admin->save($owner,'nodes',$nodeData);
    $serverData=['name'=>'Survival <test>','game'=>'minecraft','unit_name'=>'minecraft.service','user_id'=>(string)$clientId,'node_id'=>(string)$nodeId];
    $serverId=$admin->save($owner,'servers',$serverData);
    verify(count($servers->listing($client))===1 && count($servers->listing($other))===0,'Owner list isolation failed');
    denies(fn()=>$servers->find($other,$serverId),'Other owner read allowed');
    $before=$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
    denies(fn()=>$servers->perform($other,$serverId,'start'),'Other owner power allowed');
    verify($before===$db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(),'Unauthorized action reached audit/SSH');
    denies(fn()=>$admin->save($client,'nodes',$nodeData),'Client admin write allowed');
    denies(fn()=>$admin->listing($client,'users'),'Client user list allowed');
    denies(fn()=>$admin->save($owner,'servers',$serverData),'Duplicate unit allowed');
    denies(fn()=>$admin->delete($owner,'nodes',$nodeId,true),'Node with server deleted');
    denies(fn()=>$admin->delete($owner,'users',$clientId,true),'Owner with server deleted');
    denies(fn()=>$admin->delete($owner,'servers',$serverId,false),'Unconfirmed delete allowed');
    denies(fn()=>$admin->save($owner,'users',['id'=>$owner['id'],'email'=>$email,'is_admin'=>'0']),'Last/self admin demoted');
    denies(fn()=>$admin->delete($owner,'users',(int)$owner['id'],true),'Self admin deleted');
    $serverData['id']=$serverId;$serverData['user_id']=$otherId;$admin->save($owner,'servers',$serverData);
    verify(count($servers->listing($client))===0 && count($servers->listing($other))===1,'Ownership reassignment failed');
    $auth->login(['email'=>'bob-'.$prefix.'@example.com','password'=>$password]);$stale=$_SESSION;
    $admin->save($owner,'users',['id'=>$otherId,'email'=>'bob-'.$prefix.'@example.com','password'=>'Changed-admin-password-123']);
    verify($auth->current()===null,'Password reset did not invalidate session');
    $auth->login(['email'=>'bob-'.$prefix.'@example.com','password'=>'Changed-admin-password-123']);$otherUser=$auth->current();
    denies(fn()=>$auth->updateAccount($otherUser,['email'=>'new-'.$prefix.'@example.com','current_password'=>'wrong']),'Wrong current password allowed');
    $oldSession=$_SESSION;
    $auth->updateAccount($otherUser,['email'=>'new-'.$prefix.'@example.com','current_password'=>'Changed-admin-password-123','new_password'=>'Changed-client-password-123','password_confirm'=>'Changed-client-password-123']);
    verify($auth->current()['email']==='new-'.$prefix.'@example.com','Account update failed');
    $newSession=$_SESSION;$_SESSION=$oldSession;verify($auth->current()===null,'Other session remained valid');$_SESSION=$newSession;
    verify(!array_key_exists('password_hash',$admin->listing($owner,'users')[0]),'Password hash in admin listing');
    $admin->delete($owner,'servers',$serverId,true);$admin->delete($owner,'users',$clientId,true);$admin->delete($owner,'nodes',$nodeId,true);
    verify(count($servers->listing($other))===0,'Server unregister failed');
    $limiter=new GameDock\LoginRateLimiter();$rateEmail='rate-'.$prefix.'@example.com';
    for($i=0;$i<10;$i++) $limiter->consume($rateEmail,'rate-test-'.$prefix);
    denies(fn()=>$limiter->consume($rateEmail,'rate-test-'.$prefix),'Account rate limit missing');
    $limiter->clearAccount($rateEmail);$limiter->consume($rateEmail,'rate-test-'.$prefix);
    echo "Account integration passed: CRUD, ownership, admin authorization, duplicate units, deletion guards, last admin, password/session invalidation, profile and rate limit.\n";
} finally { $root->exec('DROP DATABASE `'.$name.'`'); session_destroy(); }
