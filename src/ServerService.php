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
        $rows=$this->db->query('SELECT s.id,s.name,s.game,s.unit_name,n.name AS node_name,u.email AS owner_email FROM servers s JOIN nodes n ON n.id=s.node_id JOIN users u ON u.id=s.user_id WHERE (s.user_id=? OR ?=1) ORDER BY s.name',[$user['id'],(int)$user['is_admin']])->fetchAll();
        return array_values(array_filter($rows,static fn($s)=>$search==='' || mb_stripos($s['name'].' '.$s['node_name'].' '.(Validation::GAMES[$s['game']] ?? ''),$search)!==false));
    }
    public function find(array $user, int $id): array
    {
        $server=$this->db->query('SELECT s.*,n.name AS node_name,n.ssh_host,n.ssh_port,n.ssh_user,n.ssh_fingerprint FROM servers s JOIN nodes n ON n.id=s.node_id WHERE s.id=? AND (s.user_id=? OR ?=1)',[$id,$user['id'],(int)$user['is_admin']])->fetch();
        if (!$server) throw new RuntimeException('A szerver nem található vagy nincs hozzá jogosultságod.');
        return $server;
    }
    public function read(array $user, int $id, string $action): array
    {
        if (!in_array($action,['status','logs'],true)) throw new RuntimeException('Nem engedélyezett lekérdezés.');
        return $this->ssh->execute($this->find($user,$id),$action);
    }
    public function perform(array $user, int $id, string $action, string $command=''): array
    {
        $server=$this->find($user,$id);
        (new ServerCommand())->build($server['unit_name'],$action,$command);
        $this->db->query('INSERT INTO audit_log(user_id,server_id,action,outcome) VALUES(?,?,?,?)',[$user['id'],$id,$action,'requested']);
        $audit=$this->db->lastInsertId();
        try {
            $result=$this->ssh->execute($server,$action,$command);
            $this->db->query('UPDATE audit_log SET outcome=? WHERE id=?',['accepted',$audit]);
            return $result;
        } catch (Throwable $e) {
            $this->db->query('UPDATE audit_log SET outcome=? WHERE id=?',['failed',$audit]);
            error_log('GameDock SSH action failed: '.get_class($e));
            throw new RuntimeException('Az SSH-művelet nem sikerült. Ellenőrizd a Linux-gép elérhetőségét, a hostkulcsot, az SSH-kulcsot és a szolgáltatás jogosultságait.');
        }
    }
    public function audit(array $user, ?int $id=null): array
    {
        if ($id!==null) $this->find($user,$id);
        $sql='SELECT a.*,s.name AS server_name FROM audit_log a LEFT JOIN servers s ON s.id=a.server_id WHERE (s.user_id=? OR (a.server_id=0 AND a.user_id=?) OR ?=1)';
        $params=[$user['id'],$user['id'],(int)$user['is_admin']];
        if ($id!==null) { $sql.=' AND a.server_id=?'; $params[]=$id; }
        return $this->db->query($sql.' ORDER BY a.id DESC LIMIT 100',$params)->fetchAll();
    }
}
