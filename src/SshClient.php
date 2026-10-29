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
        $command = (new ServerCommand())->build($server['unit_name'], $action, $input, str_replace('{port}',(string)($server['port'] ?? ''),(string)($server['startup_command'] ?? '')));
        $ssh = $this->connect($server);
        if (!empty($server['startup_command']) && in_array($action,['stop','restart'],true)) $ssh->setTimeout(45);
        $output = $ssh->exec($command);
        $exitCode = $ssh->getExitStatus();
        if ($output === false || $ssh->isTimeout() || $exitCode !== 0) {
            throw new RuntimeException('Az SSH-művelet nem sikerült. Ellenőrizd a szerver állapotát és a sudoers beállítást.');
        }
        if ($action === 'logs') return ['logs'=>$output];
        return $action === 'status' ? ['state'=>trim($output)] : ['accepted'=>true];
    }
    public static function probe(string $host,int $port): string
    {
        if(!preg_match('/\A(?:[a-zA-Z0-9_.-]+|[a-fA-F0-9:]+)\z/',$host) || $port<1 || $port>65535)throw new RuntimeException('Érvénytelen SSH-cím/port.');
        $ssh=new SSH2($host,$port,8);$parts=explode(' ',trim((string)$ssh->getServerPublicHostKey()));$raw=base64_decode($parts[1] ?? '',true);
        if(!$raw)throw new RuntimeException('A hostkulcs nem kérdezhető le.');
        return 'SHA256:'.rtrim(base64_encode(hash('sha256',$raw,true)),'=');
    }
    public function templates(array $node,string $action): array
    {
        if(!in_array($action,['catalog','sync'],true))throw new RuntimeException('Ismeretlen katalógusművelet.');
        $ssh=$this->connect($node);$output=$ssh->exec('/usr/bin/sudo -n /usr/local/sbin/gamedock-templates '.$action);
        if($output===false || $ssh->getExitStatus()!==0)throw new RuntimeException('A Minecraft-katalógus nem érhető el. Futtasd a webes Linux-telepítést.');
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
