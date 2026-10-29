<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class ServerCommand
{
    public function build(string $unitName, string $action, string $input = '', string $startup = ''): string
    {
        if (!in_array($action, ['status','start','stop','restart','logs','command'], true)
            || !preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_-]*(?:\.service)?\z/', $unitName)) {
            throw new RuntimeException('Nem engedélyezett SSH-művelet.');
        }
        if ($action === 'command' && (trim($input) === '' || strlen($input) > 500 || preg_match('/[\x00-\x1f\x7f]/', $input))) {
            throw new RuntimeException('A konzolparancs egy sorból állhat, legfeljebb 500 bájttal.');
        }
        $startup=Validation::startup($startup);
        if (in_array($action,['start','restart'],true) && $startup==='') throw new RuntimeException('Az adminban add meg a szerver indítóparancsát.');
        $command='/usr/bin/sudo -n /usr/local/sbin/gamedock-launch '.escapeshellarg($unitName).' '.$action;
        if (in_array($action,['start','restart'],true)) $command.=' '.escapeshellarg(base64_encode($startup));
        if ($action==='command') $command.=' '.escapeshellarg(base64_encode($input));
        return $command;
    }
}
