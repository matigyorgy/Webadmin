<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
use Throwable;
final class ServerService
{
    public function __construct(private Database $db, private SshClient $ssh) {}
    public function listing(array $user, string $search=''): array
    {
        $current=(new SchemaUpdater($this->db))->provisionEnabled();$provision=$current ? 's.provision_state' : "'ready'";$port=$current ? 's.port' : 'NULL';
        $public=(new SchemaUpdater($this->db))->publicHostEnabled() ? 'n.public_host' : 'NULL';
        $minecraft=$current ? 's.minecraft_type,s.minecraft_version' : "NULL AS minecraft_type,NULL AS minecraft_version";
        $rows=$this->db->query('SELECT '.$public.' AS public_host,n.ssh_host,'.$minecraft.','.$provision.' AS provision_state,'.$port.' AS port,s.id,s.name,s.game,s.unit_name,n.name AS node_name,u.email AS owner_email FROM servers s JOIN nodes n ON n.id=s.node_id JOIN users u ON u.id=s.user_id WHERE (s.user_id=? OR ?=1) ORDER BY s.name',[$user['id'],(int)$user['is_admin']])->fetchAll();
        $names=(new GameRegistry($this->db))->names();
        return array_values(array_filter($rows,static fn($s)=>$search==='' || mb_stripos($s['name'].' '.$s['node_name'].' '.($names[$s['game']] ?? $s['game']),$search)!==false));
    }
    public function navigation(array $user): array
    {
        $groups=[];$names=(new GameRegistry($this->db))->names();
        foreach($this->listing($user) as $s) {
            $game=$s['game'];$host=$s['public_host'] ?: $s['ssh_host'];
            $address=(str_contains($host,':') ? '['.$host.']' : $host).(!empty($s['port']) ? ':'.$s['port'] : '');
            $groups[$game] ??= ['name'=>$names[$game] ?? $game,'servers'=>[]];
            $groups[$game]['servers'][]=['id'=>$s['id'],'name'=>!empty($s['minecraft_version']) ? ucfirst($s['minecraft_type']).' '.$s['minecraft_version'] : $s['name'],'address'=>$address];
        }
        return $groups;
    }
    public function find(array $user, int $id): array
    {
        $public=(new SchemaUpdater($this->db))->publicHostEnabled() ? 'n.public_host' : 'NULL';
        $server=$this->db->query('SELECT '.$public.' AS public_host,s.*,n.name AS node_name,n.ssh_host,n.ssh_port,n.ssh_user,n.ssh_fingerprint FROM servers s JOIN nodes n ON n.id=s.node_id WHERE s.id=? AND (s.user_id=? OR ?=1)',[$id,$user['id'],(int)$user['is_admin']])->fetch();
        if (!$server) throw new RuntimeException('A szerver nem található vagy nincs hozzá jogosultságod.');
        return $server;
    }
    public function read(array $user, int $id, string $action): array
    {
        if ($action==='versions') return (new VersionService($this->db,$this->ssh))->choices($user,$id);
        if ($action==='version_status') return (new VersionService($this->db,$this->ssh))->poll($user,$id);
        if ($action==='provision') return (new ProvisionService($this->db,$this->ssh))->pollForUser($user,$id);
        if (!in_array($action,['status','logs','metrics'],true)) throw new RuntimeException('Nem engedélyezett lekérdezés.');
        $server=$this->find($user,$id);
        if (($server['provision_state'] ?? 'ready')!=='ready') throw new RuntimeException('A szerver fájljainak másolása még nem fejeződött be.');
        return $this->ssh->execute($server,$action);
    }
    public function perform(array $user, int $id, string $action, string $command=''): array
    {
        if (in_array($action,['start','restart'],true)) {
            $version=(new VersionService($this->db,$this->ssh))->poll($user,$id);
            if(in_array($version['state'],['queued','running'],true)) throw new RuntimeException('A verzióváltás még folyamatban van.');
        }
        $server=$this->find($user,$id);
        if (($server['provision_state'] ?? 'ready')!=='ready') throw new RuntimeException('A szerver csak sikeres fájlmásolás után vezérelhető.');
        (new ServerCommand())->build($server['unit_name'],$action,$command,(string)($server['startup_command'] ?? ''));
        $this->db->query('INSERT INTO audit_log(user_id,server_id,action,outcome) VALUES(?,?,?,?)',[$user['id'],$id,$action,'requested']);
        $audit=$this->db->lastInsertId();
        try {
            $result=$this->ssh->execute($server,$action,$command);
            $this->db->query('UPDATE audit_log SET outcome=? WHERE id=?',['accepted',$audit]);
            return $result;
        } catch (Throwable $e) {
            $this->db->query('UPDATE audit_log SET outcome=? WHERE id=?',['failed',$audit]);
            error_log('GameDock SSH action failed: '.get_class($e));
            if (str_contains($e->getMessage(),'cgroup v2')) throw $e;
            throw new RuntimeException('Az SSH-művelet nem sikerült. Ellenőrizd a Linux-gép elérhetőségét, a hostkulcsot, az SSH-kulcsot és a szolgáltatás jogosultságait.'.(!empty($server['startup_command']) ? ' Saját indítóparancsnál a gamedock-launch telepítése, a Linux-oldali engedélylista és egy nem ütköző unitnév is szükséges.' : ''));
        }
    }
    public function audit(array $user, ?int $id=null): array
    {
        if ($id!==null) $this->find($user,$id);
        $sql='SELECT a.*,s.name AS server_name FROM audit_log a LEFT JOIN servers s ON s.id=a.server_id WHERE (s.user_id=? OR (a.server_id=0 AND a.user_id=?) OR ?=1)';
        $params=[$user['id'],$user['id'],(int)$user['is_admin']];
        if ($id!==null) { $sql.=' AND a.server_id=?'; $params[]=$id; }
        return AuditText::rows($this->db->query($sql.' ORDER BY a.id DESC LIMIT 100',$params)->fetchAll());
    }
}
