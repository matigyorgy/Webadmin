<?php
declare(strict_types=1);
namespace Webadmin;
use RuntimeException;
final class StorageService
{
    public function __construct(private Database $db,private SshClient $ssh) {}
    public static function limit(mixed $value): int
    {
        if($value==='')$value=0;
        if((!is_int($value) && !is_string($value)) || !preg_match('/\A[0-9]{1,10}\z/',(string)$value) || (int)$value>1073741824)throw new RuntimeException('Érvénytelen tárhelylimit. 0–1073741824 MiB közötti egész szám szükséges.');
        return (int)$value;
    }
    public function apply(array $actor,int $id,int $limit): void
    {
        if(!$this->db->query('SELECT is_admin FROM users WHERE id=?',[$actor['id']])->fetchColumn())throw new RuntimeException('Tárhelylimitet csak adminisztrátor állíthat be.');
        (new ServerService($this->db,$this->ssh))->find($actor,$id);
        self::limit($limit);
    }
}
