<?php
declare(strict_types=1);
require dirname(__DIR__) . '/autoload.php';
function ensure(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $action, string $message): void {
    $caught=false;
    try { $action(); } catch (RuntimeException $e) { $caught=true; }
    ensure($caught,$message);
}
$input=['db_host'=>'localhost','db_port'=>'3306','db_name'=>'test','db_user'=>'test', 'db_password'=>'',
    'admin_email'=>'owner@example.com','admin_password'=>'A-strong-password-123','admin_password_confirm'=>'A-strong-password-123'];
$installer=new GameDock\Installer(dirname(__DIR__));
ensure($installer->validate($input)['node']===null,'Optional node should not be required');
foreach ([['db_host'=>'x;dbname=other'], ['db_port'=>'65536'], ['db_name'=>'db;host=x'],
    ['admin_email'=>'bad'], ['admin_password_confirm'=>'different'], ['admin_password'=>'short'],
    ['server_name'=>'Survival','unit_name'=>'game.service','game'=>'minecraft']] as $invalid) {
    rejected(fn()=>$installer->validate(array_replace($input,$invalid)), 'Invalid installation input accepted');
}
$node=['node_name'=>'Linux','ssh_host'=>'games.example.com','ssh_port'=>'22','ssh_user'=>'gamedock',
    'ssh_fingerprint'=>'SHA256:'.str_repeat('A',43),'server_name'=>'Survival','game'=>'minecraft','unit_name'=>'minecraft.service'];
ensure($installer->validate(array_replace($input,$node))['server']['game']==='minecraft','First server validation failed');
rejected(fn()=>$installer->validate(array_replace($input,$node,['ssh_user'=>'root'])), 'Root SSH user allowed');
echo "10 installer validation checks passed.\n";

if (!getenv('TEST_MYSQL_HOST')) { echo "MySQL integration checks not run (TEST_MYSQL_HOST not set).\n"; exit; }
$host=getenv('TEST_MYSQL_HOST'); $password=getenv('TEST_MYSQL_PASSWORD');
$admin=new PDO('mysql:host='.$host.';charset=utf8mb4','root',$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database='gamedock_test_'.bin2hex(random_bytes(5));
$directory=sys_get_temp_dir().'/'.$database;
mkdir($directory.'/config',0700,true);mkdir($directory.'/var/smarty/compile',0700,true);mkdir($directory.'/database',0700,true);
copy(dirname(__DIR__).'/database/schema.sql',$directory.'/database/schema.sql');
$admin->exec('CREATE DATABASE `'.$database.'`');
$test=new GameDock\Installer($directory);
$valid=array_replace($input,$node,['db_host'=>$host,'db_name'=>$database,'db_user'=>'root','db_password'=>$password]);
try {
    rejected(fn()=>$test->install(array_replace($valid,['db_password'=>'invalid-test-password'])), 'Bad credentials accepted');
    ensure(!is_file($directory.'/config/local.json'), 'Failed install published config');
    // Exercise rollback after an actual DDL error; retry must be possible.
    file_put_contents($directory.'/database/schema.sql', 'CREATE TABLE users (id INT); CREATE TABLE bad (INVALID SQL);');
    rejected(fn()=>$test->install($valid), 'Invalid schema accepted');
    $admin->exec('USE `'.$database.'`');
    ensure($admin->query('SHOW TABLES')->fetch()===false, 'Created tables left behind after error');
    ensure(!is_file($directory.'/config/ssh-private.pem'), 'Failed install left private key');
    copy(dirname(__DIR__).'/database/schema.sql',$directory.'/database/schema.sql');
    $result=$test->install($valid);
    ensure(str_starts_with($result['public_key'],'ssh-rsa '),'Public key missing');
    $settings=json_decode(file_get_contents($directory.'/config/local.json'),true);
    ensure($settings['DB_PASSWORD']===$password,'Database settings not saved');
    ensure((fileperms($directory.'/config/local.json') & 0777)===0600,'Config permissions unsafe');
    ensure((fileperms($directory.'/config/ssh-private.pem') & 0777)===0600,'Private key permissions unsafe');
    $user=$admin->query('SELECT * FROM users')->fetch(PDO::FETCH_ASSOC);
    ensure((int)$user['is_admin']===1 && password_verify($valid['admin_password'],$user['password_hash']),'Admin creation failed');
    ensure((int)$admin->query('SELECT COUNT(*) FROM nodes')->fetchColumn()===1,'First node missing');
    ensure((int)$admin->query('SELECT COUNT(*) FROM servers')->fetchColumn()===1,'First server missing');
    rejected(fn()=>$test->install($valid),'Reinstallation allowed');
    ensure((int)$admin->query('SELECT COUNT(*) FROM users')->fetchColumn()===1,'Reinstallation changed users');
    // Remove only fixture config to test nonempty database guard.
    unlink($directory.'/config/local.json');
    rejected(fn()=>$test->install($valid),'Existing tables accepted');
    ensure((int)$admin->query('SELECT COUNT(*) FROM users')->fetchColumn()===1,'Existing user lost');
    echo "MySQL integration passed: bad credentials, DDL rollback/retry, admin/node/server, config/key permissions, reinstallation and existing-data protection.\n";
} finally {
    $admin->exec('DROP DATABASE `'.$database.'`');
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($directory);
}
