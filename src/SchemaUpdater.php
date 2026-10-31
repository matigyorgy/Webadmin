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
        foreach (['startup_command','install_source','install_folder','provision_job','provision_state','provision_message','port','minecraft_type','minecraft_version','template_key','template_version','memory_limit_mb','cpu_limit_percent'] as $name) {
            if (!in_array($name,$available,true)) return false;
        }
        if(!$this->versionsEnabled())return false;
        $versionColumns=array_column($this->db->query('SHOW COLUMNS FROM server_version_jobs')->fetchAll(),'Field');
        foreach(['template_key','template_version'] as $name)if(!in_array($name,$versionColumns,true))return false;
        return (new TemplatePolicy($this->db))->available() && $this->publicHostEnabled() && (new GameRegistry($this->db))->available();
    }
    public function versionsEnabled(): bool
    {
        return (bool)$this->db->query("SHOW TABLES LIKE 'server_version_jobs'")->fetch();
    }
    public function publicHostEnabled(): bool
    {
        return (bool)$this->db->query("SHOW COLUMNS FROM nodes LIKE 'public_host'")->fetch();
    }
    public function enableStartup(array $actor): void
    {
        if (!$this->db->query('SELECT is_admin FROM users WHERE id=?',[$actor['id']])->fetchColumn()) throw new RuntimeException('Adminisztrátori jogosultság szükséges.');
        $columns=['last_restore_job'=>'VARCHAR(32) NULL','template_key'=>'VARCHAR(200) NULL','template_version'=>'VARCHAR(64) NULL','memory_limit_mb'=>'INT UNSIGNED NOT NULL DEFAULT 0','cpu_limit_percent'=>'INT UNSIGNED NOT NULL DEFAULT 0','startup_command'=>'TEXT NULL','install_source'=>'VARCHAR(255) NULL','install_folder'=>'VARCHAR(120) NULL',
            'provision_job'=>'VARCHAR(32) NULL','provision_state'=>"VARCHAR(16) NOT NULL DEFAULT 'ready'",'provision_message'=>'VARCHAR(500) NULL','port'=>'SMALLINT UNSIGNED NULL','minecraft_type'=>'VARCHAR(24) NULL','minecraft_version'=>'VARCHAR(32) NULL'];
        $missing=[];
        $available=array_column($this->db->query('SHOW COLUMNS FROM servers')->fetchAll(),'Field');
        foreach($columns as $name=>$type) if (!in_array($name,$available,true)) $missing[]='ADD COLUMN '.$name.' '.$type;
        $type=$this->db->query("SHOW COLUMNS FROM servers LIKE 'game'")->fetch();
        if(str_starts_with($type['Type'],'enum('))$missing[]='MODIFY COLUMN game VARCHAR(32) NOT NULL';
        try {
            TemplatePolicy::install($this->db);
            if($this->versionsEnabled()){
                $versionColumns=array_column($this->db->query('SHOW COLUMNS FROM server_version_jobs')->fetchAll(),'Field');
                foreach(['template_key'=>'VARCHAR(200) NULL','template_version'=>'VARCHAR(64) NULL'] as $name=>$type)if(!in_array($name,$versionColumns,true))$this->db->query('ALTER TABLE server_version_jobs ADD COLUMN '.$name.' '.$type);
            }
            if(!$this->versionsEnabled()) $this->db->query('CREATE TABLE server_version_jobs (server_id BIGINT UNSIGNED PRIMARY KEY,job VARCHAR(32) NOT NULL,state VARCHAR(16) NOT NULL,startup_command TEXT NOT NULL,install_source VARCHAR(255) NOT NULL,minecraft_type VARCHAR(24) NOT NULL,minecraft_version VARCHAR(32) NOT NULL,template_key VARCHAR(200) NULL,template_version VARCHAR(64) NULL,message VARCHAR(500) NULL,FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE)');
            if(!$this->publicHostEnabled()) $this->db->query('ALTER TABLE nodes ADD COLUMN public_host VARCHAR(255) NULL');
            if($missing)$this->db->query('ALTER TABLE servers '.implode(',',$missing));
            if(!(new GameRegistry($this->db))->available()) {
                $this->db->query("CREATE TABLE game_types (slug VARCHAR(32) PRIMARY KEY,name VARCHAR(120) NOT NULL,template_path VARCHAR(255) NOT NULL,startup_command TEXT NOT NULL,provider VARCHAR(16) NOT NULL DEFAULT 'folder')");
                (new GameRegistry($this->db))->seed();
            }
        }
        catch (Throwable $e) {
            if (!$this->provisionEnabled() || !$this->db->query("SHOW COLUMNS FROM servers LIKE 'last_restore_job'")->fetch()) throw new RuntimeException('Az új szerverfunkciókhoz ALTER TABLE jogosultság szükséges. Engedélyezd a panel adatbázis-felhasználójának, majd próbáld újra.');
        }
        foreach (['game_types'=>['template_path','startup_command'],'servers'=>['install_source','startup_command']] as $table=>$fields) foreach($fields as $field) $this->db->query("UPDATE $table SET $field=REPLACE($field,?,?) WHERE $field LIKE ?",['/srv/gamedock/templates','/home/gamedock/templates','%/srv/gamedock/templates%']);
    }
}
