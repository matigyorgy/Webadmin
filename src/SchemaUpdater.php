<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
use Throwable;
final class SchemaUpdater
{
    public function __construct(private Database $db) {}
    public function startupEnabled(): bool
    {
        return (bool)$this->db->query("SHOW COLUMNS FROM servers LIKE 'startup_command'")->fetch();
    }
    public function provisionEnabled(): bool
    {
        $available=array_column($this->db->query('SHOW COLUMNS FROM servers')->fetchAll(),'Field');
        foreach (['startup_command','install_source','install_folder','provision_job','provision_state','provision_message'] as $name) {
            if (!in_array($name,$available,true)) return false;
        }
        return true;
    }
    public function enableStartup(array $actor): void
    {
        if (!$this->db->query('SELECT is_admin FROM users WHERE id=?',[$actor['id']])->fetchColumn()) throw new RuntimeException('Adminisztrátori jogosultság szükséges.');
        $columns=['startup_command'=>'TEXT NULL','install_source'=>'VARCHAR(255) NULL','install_folder'=>'VARCHAR(120) NULL',
            'provision_job'=>'VARCHAR(32) NULL','provision_state'=>"VARCHAR(16) NOT NULL DEFAULT 'ready'",'provision_message'=>'VARCHAR(500) NULL'];
        $missing=[];
        $available=array_column($this->db->query('SHOW COLUMNS FROM servers')->fetchAll(),'Field');
        foreach($columns as $name=>$type) if (!in_array($name,$available,true)) $missing[]='ADD COLUMN '.$name.' '.$type;
        if (!$missing) return;
        try { $this->db->query('ALTER TABLE servers '.implode(',',$missing)); }
        catch (Throwable $e) {
            if (!$this->provisionEnabled()) throw new RuntimeException('Az új szerverfunkciókhoz ALTER TABLE jogosultság szükséges. Engedélyezd a panel adatbázis-felhasználójának, majd próbáld újra.');
        }
    }
}
