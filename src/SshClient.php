<?php
declare(strict_types=1);
namespace GameDock;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;
final class SshClient
{
    public function execute(array $server, string $action, string $input = ''): array
    {
        $command = (new ServerCommand())->build($server['unit_name'], $action, $input, str_replace('{port}',(string)($server['port'] ?? ''),(string)($server['startup_command'] ?? '')),$server);
        $ssh = $this->connect($server);
        $ssh->enableQuietMode();
        if (!empty($server['startup_command']) && in_array($action,['stop','restart'],true)) $ssh->setTimeout(45);
        $output = $ssh->exec($command);
        $exitCode = $ssh->getExitStatus();
        if ($output === false || $ssh->isTimeout() || $exitCode !== 0) {
            if (str_contains((string)$ssh->getStdError(),'Resource limits require')) throw new RuntimeException('A CPU- és memórialimithez írható Linux cgroup v2 CPU/memory vezérlő szükséges. Futtasd újra a webes Linux-telepítést; konténer/VPS esetén a szolgáltatói beállítást is ellenőrizni kell.');
            throw new RuntimeException('Az SSH-művelet nem sikerült. Ellenőrizd a szerver állapotát és a sudoers beállítást.');
        }
        if ($action === 'metrics') return json_decode($output,true,512,JSON_THROW_ON_ERROR);
        if ($action === 'logs') return ['logs'=>$output];
        return $action === 'status' ? ['state'=>trim($output)] : ['accepted'=>true];
    }
    public function occupiedPorts(array $node): array
    {
        // Read both protocols and address families in the game's network namespace.
        $script=<<<'PYTHON'
import json
from pathlib import Path
ports=set()
for protocol in ('tcp','tcp6','udp','udp6'):
    path=Path('/proc/net')/protocol
    if protocol.endswith('6') and not path.exists():continue
    for row in path.read_text().splitlines()[1:]:
        port=int(row.split()[1].rsplit(':',1)[1],16)
        if 25000<=port<=25999:ports.add(port)
print(json.dumps(sorted(ports)))
PYTHON;
        $ssh=$this->connect($node);$ssh->enableQuietMode();
        $output=$ssh->exec('/usr/bin/python3 -c '.escapeshellarg($script));
        if ($output===false || $ssh->isTimeout() || $ssh->getExitStatus()!==0) throw new RuntimeException('A foglalt Linux-portok nem ellenőrizhetők. Ellenőrizd az SSH-kapcsolatot és a Python-telepítést.');
        $ports=json_decode($output,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($ports)) throw new RuntimeException('Érvénytelen portellenőrzési válasz.');
        foreach ($ports as $port) if (!is_int($port) || $port<25000 || $port>25999) throw new RuntimeException('Érvénytelen portellenőrzési válasz.');
        return $ports;
    }
    public static function probe(string $host,int $port): string
    {
        if(!preg_match('/\A(?:[a-zA-Z0-9_.-]+|[a-fA-F0-9:]+)\z/',$host) || $port<1 || $port>65535)throw new RuntimeException('Érvénytelen SSH-cím/port.');
        $ssh=new SSH2($host,$port,8);$parts=explode(' ',trim((string)$ssh->getServerPublicHostKey()));$raw=base64_decode($parts[1] ?? '',true);
        if(!$raw)throw new RuntimeException('A hostkulcs nem kérdezhető le.');
        return 'SHA256:'.rtrim(base64_encode(hash('sha256',$raw,true)),'=');
    }
    public function templates(array $node,string $action,string $key=''): array
    {
        if(!in_array($action,['catalog','sync','delete','custom-save','releases','install','steam-install'],true))throw new RuntimeException('Ismeretlen katalógusművelet.');
        $ssh=$this->connect($node);$ssh->enableQuietMode();if($action==='releases')$ssh->setTimeout(60);$output=$ssh->exec('/usr/bin/sudo -n /usr/local/sbin/gamedock-templates '.$action.(in_array($action,['delete','custom-save','releases','install','steam-install'],true) ? ' '.escapeshellarg($key) : ''));
        return TemplateCommandResult::parse($output,$ssh->getExitStatus(),(string)$ssh->getStdError(),$ssh->isTimeout());
    }
    public function storage(array $server,int $limit): array
    {
        StorageService::limit($limit);
        if(!preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_-]*(?:\.service)?\z/',$server['unit_name']))throw new RuntimeException('Érvénytelen szerverazonosító.');
        $ssh=$this->connect($server);$ssh->enableQuietMode();$ssh->setTimeout(90);
        $output=$ssh->exec('/usr/bin/sudo -n /usr/local/sbin/gamedock-storage set '.escapeshellarg($server['unit_name']).' '.(string)$limit);
        if($output===false || $ssh->isTimeout() || $ssh->getExitStatus()!==0){
            $error=(string)$ssh->getStdError();
            foreach(['Project quota support required'=>'A tárhelylimithez ext4/XFS fájlrendszer és bekapcsolt projektkvóta szükséges a Linux-gépen (prjquota). A korlát nem lett beállítva.','Stop the server'=>'A tárhelylimit módosítása előtt állítsd le a szervert.','smaller than occupied storage'=>'A megadott maximum kisebb a jelenlegi foglalt tárhelynél. Előbb szabadíts fel helyet.','Foreign storage project'=>'A szervermappa másik projektkvótához tartozik. A meglévő kvótát nem írjuk felül.','backup or restore is running'=>'Mentés vagy visszaállítás közben a tárhelylimit nem módosítható.'] as $needle=>$message)if(str_contains($error,$needle))throw new RuntimeException($message);
            throw new RuntimeException('A tárhelylimit Linux-oldali beállítása nem igazolt. Ellenőrizd a segédprogram frissítését és a projektkvóta támogatását.');
        }
        return json_decode($output,true,512,JSON_THROW_ON_ERROR);
    }
    public function ftp(array $server,string $action,array $payload=[]): array
    {
        if(!in_array($action,['list','create','delete'],true) || !preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_-]*(?:\.service)?\z/',$server['unit_name']))throw new RuntimeException('Érvénytelen FTP-művelet.');
        $ssh=$this->connect($server);$ssh->enableQuietMode();$ssh->setTimeout(20);
        // No PTY and no password in process arguments, shell history or sudo command logs.
        if(!$ssh->exec('/usr/bin/sudo -n /usr/local/sbin/gamedock-ftp '.$action.' '.escapeshellarg($server['unit_name']),false))throw new RuntimeException('Az FTP-segédprogram nem indult el.');
        if($action==='create')$ssh->write(json_encode($payload,JSON_THROW_ON_ERROR),SSH2::CHANNEL_EXEC);
        $ssh->sendEOF(SSH2::CHANNEL_EXEC);$output='';
        while(true){
            $chunk=$ssh->read('',SSH2::READ_NEXT,SSH2::CHANNEL_EXEC);
            if($chunk===true)break;
            if($chunk===false || $ssh->isTimeout())throw new RuntimeException('Az FTP-művelet válasza nem igazolt. Ellenőrizd a hozzáférés állapotát, mielőtt újra próbálod.');
            $output.=$chunk;if(strlen($output)>65536)throw new RuntimeException('Érvénytelen FTP-válasz.');
        }
        if($ssh->getExitStatus()!==0){
            $error=(string)$ssh->getStdError();
            foreach(['FTP username already in use'=>'Ez az FTP-felhasználónév ezen a Linux-gépen már foglalt.','FTP setup required'=>'A Linux-gépen még nincs beállítva a Pure-FTPd. Futtasd az Automatikus Linux-telepítést bekapcsolt csomagtelepítéssel.','Invalid FTP username'=>'Érvénytelen FTP-felhasználónév.','Invalid FTP password'=>'Érvénytelen FTP-jelszó.'] as $needle=>$message)if(str_contains($error,$needle))throw new RuntimeException($message);
            throw new RuntimeException('Az FTP-szolgáltatás nem érhető el. Ellenőrizd a Pure-FTPd telepítését, a Linux-segédprogramot és a gép gamedock-ftp.log naplóját.');
        }
        return json_decode($output,true,512,JSON_THROW_ON_ERROR);
    }
    public function automation(array $server,string $action,array $payload): array
    {
        if(!in_array($action,['list','schedule_add','schedule_delete','backup','restore','backup_delete','ack_restore','policy','clear_schedules'],true) || !preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_-]*(?:\.service)?\z/',$server['unit_name']))throw new RuntimeException('Érvénytelen időzítési művelet.');
        $ssh=$this->connect($server);$ssh->enableQuietMode();$ssh->setTimeout(20);
        $output=$ssh->exec('/usr/bin/sudo -n /usr/local/sbin/gamedock-automation '.$action.' '.escapeshellarg($server['unit_name']).' '.escapeshellarg(base64_encode(json_encode($payload,JSON_THROW_ON_ERROR))));
        if($output===false || $ssh->isTimeout() || $ssh->getExitStatus()!==0){
            $error=(string)$ssh->getStdError();
            foreach(['Cron service is not running'=>'A Linux-gépen nem fut az időzítő szolgáltatás. Az admin indítsa el a cron szolgáltatást vagy futtassa újra a Linux-telepítést.','Restore confirmation required'=>'A visszaállításhoz megerősítés szükséges.','Stop the server before restoring'=>'Visszaállítás előtt állítsd le a szervert.','Another automation job'=>'Ezen a szerveren már fut egy mentési vagy vezérlési feladat.','Version change is running'=>'A verzióváltás befejezése után próbáld újra.','Choose a future time'=>'Válassz jövőbeli időpontot.','daylight saving time'=>'Ez a helyi időpont az óraátállítás miatt nem létezik.','Maximum 100 backups'=>'Elérted a 100 mentéses korlátot. Törölj régi mentéseket.'] as $needle=>$message)if(str_contains($error,$needle))throw new RuntimeException($message);
            throw new RuntimeException('Az időzítő/mentés segédprogram nem érhető el vagy elutasította a kérést. Ellenőrizd a Linux-telepítő frissítését és a gép automation/units naplóját.');
        }
        return json_decode($output,true,512,JSON_THROW_ON_ERROR);
    }
    public function versions(array $server,string $action,array $payload): array
    {
        if(!in_array($action,['create','status'],true) || !preg_match('/\A[a-f0-9]{32}\z/',(string)($payload['job'] ?? ''))) throw new RuntimeException('Érvénytelen verzióváltási művelet.');
        $argument=$action==='status' ? $payload['job'] : base64_encode(json_encode($payload,JSON_THROW_ON_ERROR));
        $ssh=$this->connect($server);$ssh->enableQuietMode();
        $output=$ssh->exec('/usr/bin/sudo -n /usr/local/sbin/gamedock-versions '.$action.' '.escapeshellarg($argument));
        if($output===false || $ssh->isTimeout() || $ssh->getExitStatus()===null) throw new RuntimeException('A verzióváltás Linux-válasza nem igazolt. Ellenőrizd az SSH-kapcsolatot.');
        if($ssh->getExitStatus()!==0) throw new RuntimeException('A Linux-gép elutasította a verzióváltási műveletet. A szerver legyen leállítva, a sablon kész állapotú, és a friss Linux-segédprogramok legyenek telepítve.',422);
        return json_decode($output,true,512,JSON_THROW_ON_ERROR);
    }
    public function provision(array $node,string $action,array $payload): array
    {
        if (!in_array($action,['create','status'],true)) throw new RuntimeException('Ismeretlen másolási művelet.');
        $job=(string)($payload['job'] ?? '');
        if (!preg_match('/\A[a-f0-9]{32}\z/',$job)) throw new RuntimeException('Érvénytelen másolási azonosító.');
        $argument=$action==='status' ? $job : base64_encode(json_encode($payload,JSON_THROW_ON_ERROR));
        $ssh=$this->connect($node);
        $output=$ssh->exec('/usr/bin/sudo -n /usr/local/sbin/gamedock-provision '.$action.' '.escapeshellarg($argument));
        $exit=$ssh->getExitStatus();
        if ($output!==false && is_int($exit) && $exit!==0) throw new RuntimeException('A Linux-gép elutasította a másolást. Ellenőrizd a forrásmappát, a célmappát, az engedélylistát és a segédprogram telepítését.',422);
        if ($output===false || $exit!==0) throw new RuntimeException('A másolási segédprogram válasza nem igazolt. Ellenőrizd az SSH-kapcsolatot.');
        return json_decode($output,true,512,JSON_THROW_ON_ERROR);
    }
    public function test(array $node): void
    {
        $ssh=$this->connect($node);
        $output=$ssh->exec('/usr/bin/true');
        if ($output===false || $ssh->isTimeout() || $ssh->getExitStatus()!==0) throw new RuntimeException('Az SSH-tesztparancs nem futott le.');
    }
    private function connect(array $server): SSH2
    {
        $ssh = new SSH2($server['ssh_host'], (int)$server['ssh_port'], 10);
        $ssh->setTimeout(12);
        // Verify host identity before sending authentication credentials.
        $hostKey = $ssh->getServerPublicHostKey();
        $parts = is_string($hostKey) ? explode(' ', trim($hostKey)) : [];
        $rawKey = isset($parts[1]) ? base64_decode($parts[1], true) : false;
        $fingerprint = $rawKey !== false ? 'SHA256:' . rtrim(base64_encode(hash('sha256', $rawKey, true)), '=') : '';
        if ($fingerprint === '' || !hash_equals($server['ssh_fingerprint'], $fingerprint)) {
            throw new RuntimeException('Az SSH hostkulcs nem egyezik a rögzített kulccsal.');
        }
        $keyPath = Config::get('SSH_PRIVATE_KEY_PATH');
        if ($keyPath === '' || !is_readable($keyPath)) {
            throw new RuntimeException('Az SSH privát kulcs nincs beállítva vagy nem olvasható.');
        }
        $key = PublicKeyLoader::loadPrivateKey(file_get_contents($keyPath), Config::get('SSH_KEY_PASSPHRASE') ?: false);
        if (!$ssh->login($server['ssh_user'], $key)) {
            throw new RuntimeException('Sikertelen SSH-hitelesítés.');
        }
        return $ssh;
    }

}
