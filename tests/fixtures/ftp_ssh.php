<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/autoload.php';
for($i=0;$i<100 && !is_file('/fixture/ready');$i++)usleep(100000);
if(!is_file('/fixture/ready'))throw new RuntimeException('Disposable SSH fixture did not start');
putenv('SSH_PRIVATE_KEY_PATH=/fixture/panel-key.pem');
$server=['ssh_host'=>'127.0.0.1','ssh_port'=>22,'ssh_user'=>'gamedock','ssh_fingerprint'=>trim(file_get_contents('/fixture/fingerprint')),'unit_name'=>'990003'];
$ssh=new GameDock\SshClient();$status=$ssh->ftp($server,'list');if(!$status['ready'])throw new RuntimeException('Fixture FTP service not ready');
$password='Fixture-SSH-stdin-password-123';$created=$ssh->ftp($server,'create',['username'=>'stdin_user','password'=>$password]);
if($created['account']['username']!=='stdin_user' || str_contains(json_encode($created),$password) || isset($created['account']['hash']))throw new RuntimeException('SSH credential transport or response filtering failed');
$removed=$ssh->ftp($server,'delete');if($removed['account']!==null)throw new RuntimeException('SSH FTP deletion failed');
echo "PHP/SSH FTP integration passed: pinned host key, public-key authentication, no-PTY stdin password transport, filtered response and deletion.\n";
