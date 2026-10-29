<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class ProvisionApiCredentials
{
    private static function path(): string {return Config::root().'/config/provision-api.json';}
    public static function configured(): bool {return is_file(self::path());}
    public static function rotate(int $adminId): string
    {
        $token=bin2hex(random_bytes(32));
        $temp=tempnam(dirname(self::path()),'.api-');
        if($temp===false)throw new RuntimeException('Az API-kulcs nem menthető a config könyvtárba.');
        try{
            if(!chmod($temp,0600) || file_put_contents($temp,json_encode(['admin_id'=>$adminId,'hash'=>hash('sha256',$token)],JSON_THROW_ON_ERROR))===false || !rename($temp,self::path()))throw new RuntimeException('Az API-kulcs mentése nem sikerült.');
        }finally{if(is_file($temp))unlink($temp);}
        return $token;
    }
    public static function revoke(): void
    {
        if(self::configured() && !unlink(self::path()))throw new RuntimeException('Az API-kulcs visszavonása nem sikerült.');
    }
    public static function authenticate(Database $db,string $authorization): array
    {
        if(!preg_match('/\ABearer ([a-f0-9]{64})\z/',$authorization,$match) || !self::configured())throw new RuntimeException('Érvénytelen API-kulcs.',401);
        $settings=json_decode((string)file_get_contents(self::path()),true,8,JSON_THROW_ON_ERROR);
        if(!hash_equals((string)($settings['hash'] ?? ''),hash('sha256',$match[1])))throw new RuntimeException('Érvénytelen API-kulcs.',401);
        $actor=$db->query('SELECT id,email,is_admin FROM users WHERE id=?',[$settings['admin_id'] ?? 0])->fetch();
        if(!$actor || !(int)$actor['is_admin'])throw new RuntimeException('Az API-kulcs adminisztrátori jogosultsága megszűnt.',403);
        return $actor;
    }
}
