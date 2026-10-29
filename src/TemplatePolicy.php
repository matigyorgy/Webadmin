<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class TemplatePolicy
{
    public const TYPES=['vanilla'=>'Vanilla','bukkit'=>'Bukkit','spigot'=>'Spigot','paper'=>'Paper','forge'=>'Forge','purpur'=>'Purpur','fabric'=>'Fabric'];
    public function __construct(private Database $db) {}
    public static function install(Database $db): void
    {
        $db->query('CREATE TABLE IF NOT EXISTS template_policies (node_id BIGINT UNSIGNED NOT NULL,template_key VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,minimum_memory_mb INT UNSIGNED NOT NULL DEFAULT 0,requires_reinstall TINYINT(1) NOT NULL DEFAULT 0,PRIMARY KEY(node_id,template_key),FOREIGN KEY(node_id) REFERENCES nodes(id) ON DELETE CASCADE) ENGINE=InnoDB');
    }
    public function available(): bool {return (bool)$this->db->query("SHOW TABLES LIKE 'template_policies'")->fetch();}
    public function get(int $node,array $template): array
    {
        $policy=$this->available() ? $this->db->query('SELECT minimum_memory_mb,requires_reinstall FROM template_policies WHERE node_id=? AND template_key=?',[$node,$template['key']])->fetch() : false;
        return ['minimum_memory_mb'=>(int)($policy['minimum_memory_mb'] ?? $template['minimum_memory_mb'] ?? 0),'requires_reinstall'=>(bool)($policy['requires_reinstall'] ?? false)];
    }
    public function catalog(int $node,array $catalog): array
    {
        $policies=[];
        if($this->available())foreach($this->db->query('SELECT * FROM template_policies WHERE node_id=?',[$node])->fetchAll() as $row)$policies[$row['template_key']]=$row;
        foreach($catalog['templates'] as &$row){$policy=$policies[$row['key']] ?? [];$row['minimum_memory_mb']=(int)($policy['minimum_memory_mb'] ?? $row['minimum_memory_mb'] ?? 0);$row['requires_reinstall']=(bool)($policy['requires_reinstall'] ?? false);}
        unset($row);return $catalog;
    }
    public function save(array $actor,int $node,string $key,array $data): void
    {
        if(!$this->db->query('SELECT is_admin FROM users WHERE id=?',[$actor['id']])->fetchColumn())throw new RuntimeException('Adminisztrátori jogosultság szükséges.');
        if(!$this->available())throw new RuntimeException('Először futtasd az admin adatbázisfrissítését.');
        if(!preg_match('/\A[A-Za-z0-9:._-]{1,200}\z/',$key))throw new RuntimeException('Hibás template-azonosító.');
        $value=(string)($data['minimum_memory_mb'] ?? '');
        if(!ctype_digit($value) || (float)$value>1048576)throw new RuntimeException('A minimum memória 0–1048576 MiB közötti egész szám legyen.');
        $reinstall=($data['requires_reinstall'] ?? '')==='1';
        $this->db->query('INSERT INTO template_policies(node_id,template_key,minimum_memory_mb,requires_reinstall) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE minimum_memory_mb=VALUES(minimum_memory_mb),requires_reinstall=VALUES(requires_reinstall)',[$node,$key,(int)$value,(int)$reinstall]);
    }
    public static function assertFits(array $policy,array $server): void
    {
        $limit=(int)($server['memory_limit_mb'] ?? 0);$minimum=(int)$policy['minimum_memory_mb'];
        if($limit>0 && $limit<$minimum)throw new RuntimeException('Ehhez a template-hez legalább '.$minimum.' MiB memória szükséges. A szerver memóriakeretét adminisztrátornak kell növelnie.');
    }
}
