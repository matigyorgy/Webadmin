<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class GameRegistry
{
    public function __construct(private Database $db) {}
    public static function defaults(): array
    {
        $rows=[];
        foreach(Validation::GAMES as $slug=>$name) $rows[$slug]=['slug'=>$slug,'name'=>$name,'template_path'=>'/home/gamedock/templates/'.$slug,'startup_command'=>Validation::STARTUP[$slug],'provider'=>$slug==='minecraft' ? 'minecraft' : 'folder'];
        return $rows;
    }
    public function available(): bool { return (bool)$this->db->query("SHOW TABLES LIKE 'game_types'")->fetch(); }
    public function all(): array
    {
        if (!$this->available()) return self::defaults();
        $rows=$this->db->query('SELECT * FROM game_types ORDER BY name')->fetchAll();$result=[];
        foreach($rows as $row)$result[$row['slug']]=$row;
        return $result;
    }
    public function names(): array { return array_column($this->all(),'name','slug'); }
    public function get(string $slug): array { return $this->all()[$slug] ?? throw new RuntimeException('Ismeretlen játéktípus.'); }
    public function seed(): void
    {
        foreach(self::defaults() as $row)$this->db->query('INSERT IGNORE INTO game_types(slug,name,template_path,startup_command,provider) VALUES(?,?,?,?,?)',array_values($row));
    }
}
