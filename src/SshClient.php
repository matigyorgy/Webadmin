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
        $command = (new ServerCommand())->build($server['unit_name'], $action, $input);
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
        $keyPath = getenv('SSH_PRIVATE_KEY_PATH') ?: '';
        if ($keyPath === '' || !is_readable($keyPath)) {
            throw new RuntimeException('Az SSH privát kulcs nincs beállítva vagy nem olvasható.');
        }
        $key = PublicKeyLoader::loadPrivateKey(file_get_contents($keyPath), getenv('SSH_KEY_PASSPHRASE') ?: false);
        if (!$ssh->login($server['ssh_user'], $key)) {
            throw new RuntimeException('Sikertelen SSH-hitelesítés.');
        }
        $output = $ssh->exec($command);
        $exitCode = $ssh->getExitStatus();
        if ($output === false || $ssh->isTimeout() || $exitCode !== 0) {
            throw new RuntimeException('Az SSH-művelet nem sikerült. Ellenőrizd a szerver állapotát és a sudoers beállítást.');
        }
        if ($action === 'logs') return ['logs'=>$output];
        return $action === 'status' ? ['state'=>trim($output)] : ['accepted'=>true];
    }
}
