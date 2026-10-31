<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class Validation
{
    public const GAMES = ['minecraft'=>'Minecraft','cs16'=>'Counter-Strike 1.6','cs2'=>'Counter-Strike 2','fivem'=>'FiveM','css'=>'Counter-Strike: Source'];
    public const STARTUP = ['minecraft'=>'java -Xms1G -Xmx4G -jar server.jar nogui --port {port}','cs16'=>'./hlds_run -game cstrike -console -port {port} +map de_dust2 +maxplayers 16','cs2'=>'./game/bin/linuxsteamrt64/cs2 -dedicated -console -usercon -port {port} +map de_dust2','fivem'=>'./run.sh +exec server.cfg','css'=>'./srcds_run -game cstrike -console +map de_dust2 -port {port}'];
    public static function text(array $data, string $name, int $max=120): string
    {
        $value=trim(is_string($data[$name] ?? null) ? $data[$name] : '');
        if ($value === '' || mb_strlen($value)>$max || preg_match('/[\x00-\x1f\x7f]/',$value)) throw new RuntimeException('Hiányzó vagy túl hosszú mező: '.$name);
        return $value;
    }
    public static function id(mixed $value): int
    {
        if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value<1 || (float)$value>PHP_INT_MAX) throw new RuntimeException('Érvénytelen azonosító.');
        return (int)$value;
    }
    public static function email(array $data): string
    {
        $email=strtolower(self::text($data,'email',190));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Érvényes e-mail-cím szükséges.');
        return $email;
    }
    public static function password(string $password): string
    {
        if (strlen($password)<12 || strlen($password)>72 || str_contains($password,"\0")) throw new RuntimeException('A jelszó 12–72 bájt hosszú legyen.');
        return password_hash($password,PASSWORD_DEFAULT);
    }
    public static function startup(string $command): string
    {
        $command=trim($command);
        if (strlen($command)>4096 || preg_match('/[\x00-\x1f\x7f]/',$command)) throw new RuntimeException('Az indítóparancs egy sorból állhat, legfeljebb 4096 bájttal.');
        return $command;
    }
    public static function node(array $data): array
    {
        $node=['name'=>self::text($data,'name'),'ssh_host'=>self::text($data,'ssh_host',255),
            'ssh_user'=>self::text($data,'ssh_user',64),'ssh_fingerprint'=>self::text($data,'ssh_fingerprint',100)];
        if (!preg_match('/\A(?:[a-zA-Z0-9_.-]+|[a-fA-F0-9:]+)\z/',$node['ssh_host'])) throw new RuntimeException('Érvénytelen SSH-cím.');
        $node['ssh_port']=self::id($data['ssh_port'] ?? 0);
        if ($node['ssh_port']>65535) throw new RuntimeException('Érvénytelen SSH-port.');
        if (!preg_match('/\A[a-z_][a-z0-9_-]{0,63}\z/',$node['ssh_user']) || $node['ssh_user']==='root') throw new RuntimeException('Korlátozott SSH-felhasználót adj meg, ne rootot.');
        if (!preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/',$node['ssh_fingerprint'])) throw new RuntimeException('Érvényes SHA256 hostkulcs-ujjlenyomat szükséges.');
        return $node;
    }
}
