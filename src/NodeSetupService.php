<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;
final class NodeSetupService
{
    public function __construct(private Database $db) {}
    public function run(array $actor,int $id,array $data,bool $status=false): array
    {
        $node=(new TemplateService($this->db,new SshClient()))->node($actor,$id);
        $user=Validation::text($data,'setup_user',64);
        if(!preg_match('/\A[a-z_][a-z0-9_-]*\z/',$user))throw new RuntimeException('Érvénytelen telepítési SSH-felhasználó.');
        $ssh=new SFTP($node['ssh_host'],(int)$node['ssh_port'],10);$ssh->setTimeout(20);$ssh->enableQuietMode();
        $parts=explode(' ',trim((string)$ssh->getServerPublicHostKey()));$raw=base64_decode($parts[1] ?? '',true);
        $fingerprint='SHA256:'.rtrim(base64_encode(hash('sha256',$raw ?: '',true)),'=');
        if(!hash_equals($node['ssh_fingerprint'],$fingerprint))throw new RuntimeException('A Linux-gép hostkulcsa nem egyezik a rögzített értékkel.');
        $key=trim((string)($data['setup_key'] ?? ''));
        $credential=$key!=='' ? PublicKeyLoader::loadPrivateKey($key,(string)($data['setup_passphrase'] ?? '') ?: false) : (string)($data['setup_password'] ?? '');
        if(!$ssh->login($user,$credential))throw new RuntimeException('A telepítési SSH-hitelesítés sikertelen.');
        $prefix=trim((string)$ssh->exec('/usr/bin/id -u'))==='0' ? '' : '/usr/bin/sudo -n ';
        if(trim((string)$ssh->exec($prefix.'/usr/bin/id -u'))!=='0')throw new RuntimeException('Root vagy jelszó nélküli sudo-jogú telepítési SSH-fiók szükséges.');
        if(!$status && isset($_SESSION['node_setup'][$id])) {
            $old=$ssh->exec($prefix.'/bin/cat '.escapeshellarg($_SESSION['node_setup'][$id].'/setup-state.json'));
            $previous=json_decode((string)$old,true);
            if(is_array($previous) && ($previous['state'] ?? '')==='running')return $previous;
        }
        if($status) {
            $folder=$_SESSION['node_setup'][$id] ?? null;
            if(!$folder)throw new RuntimeException('Nincs telepítési feladat ebben a munkamenetben.');
            $output=$ssh->exec($prefix.'/bin/cat '.escapeshellarg($folder.'/setup-state.json'));
            $result=json_decode((string)$output,true);
            if(!is_array($result))throw new RuntimeException('A telepítési állapot még nem érhető el.');
            if($result['state']==='failed') {
                $log=$ssh->exec($prefix.'/usr/bin/tail -n 60 '.escapeshellarg($folder.'/setup.log'));
                $result['log']=mb_substr((string)$log,-12000);
            }
            return $result;
        }
        $folder=rtrim((string)$ssh->pwd(),'/').'/.gamedock-install-'.bin2hex(random_bytes(12));
        if(!$ssh->mkdir($folder,0700))throw new RuntimeException('A telepítési munkakönyvtár nem hozható létre.');
        $files=['install.py','bootstrap.sh','gamedock_runtime.py','gamedock-launch','gamedock-runner','gamedock-provision','gamedock-versions','gamedock-templates'];
        foreach($files as $file) {
            $contents=file_get_contents(Config::root().'/linux/'.$file);
            if(!$ssh->put($folder.'/'.$file,$contents) || !hash_equals(hash('sha256',$contents),hash('sha256',(string)$ssh->get($folder.'/'.$file))))throw new RuntimeException('A telepítőfájl feltöltésének ellenőrzése sikertelen.');
            $ssh->chmod(0600,$folder.'/'.$file);
        }
        $public=(new AdminService($this->db))->publicKey($actor);
        if(!$ssh->put($folder.'/panel.pub',$public))throw new RuntimeException('A nyilvános kulcs nem tölthető fel.');
        $source='/home/gamedock/templates';
        if(!str_starts_with($source,'/') || strlen($source)>255 || preg_match('/[\x00-\x1f\x7f]/',$source))throw new RuntimeException('Abszolút template gyökérmappa szükséges.');
        $_SESSION['node_setup'][$id]=$folder;
        $command=$prefix.'/usr/bin/nohup /bin/sh '.escapeshellarg($folder.'/bootstrap.sh').' '.escapeshellarg($source).' '.escapeshellarg($node['ssh_user']).' '.(($data['setup_packages'] ?? '')==='1' ? '1':'0').' '.(($data['setup_firewall'] ?? '')==='1' ? '1':'0').' > '.escapeshellarg($folder.'/setup.log').' 2>&1 < /dev/null &';
        $ssh->exec($command);
        return ['state'=>'running','message'=>'A Linux-telepítés elindult. A telepítési jelszót/privát kulcsot nem mentjük; az állapotkéréshez a böngésző memóriájában marad a folyamat végéig.'];
    }
}
