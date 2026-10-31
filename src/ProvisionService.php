<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class ProvisionService
{
    public function __construct(private Database $db,private SshClient $ssh) {}
    private function server(array $actor,int $id): array
    {
        if (!$this->db->query('SELECT is_admin FROM users WHERE id=?',[$actor['id']])->fetchColumn()) throw new RuntimeException('Adminisztrátori jogosultság szükséges.');
        $server=$this->db->query('SELECT s.*,n.ssh_host,n.ssh_port,n.ssh_user,n.ssh_fingerprint FROM servers s JOIN nodes n ON n.id=s.node_id WHERE s.id=?',[$id])->fetch();
        if (!$server || empty($server['provision_job'])) throw new RuntimeException('Nincs másolási feladat ehhez a szerverhez.');
        return $server;
    }
    public function enqueue(array $actor,int $id): array
    {
        $s=$this->server($actor,$id);
        try { $result=$this->ssh->provision($s,'create',['job'=>$s['provision_job'],'unit'=>$s['unit_name'],'game'=>$s['game'], 'source'=>$s['install_source'],'folder'=>$s['install_folder'],'disk_limit_mb'=>(int)($s['disk_limit_mb'] ?? 0),'port'=>(int)$s['port'],'template_key'=>$s['template_key'] ?? null,'template_version'=>$s['template_version'] ?? null,'minecraft_type'=>$s['minecraft_type'] ?? null,'eula_accepted'=>!empty($s['minecraft_type'])]); }
        catch (RuntimeException $e) {
            if ($e->getCode()!==422) throw $e;
            $result=['state'=>'failed','message'=>$e->getMessage()];
        }
        return $this->save($s,$result);
    }
    public function poll(array $actor,int $id): array
    {
        $s=$this->server($actor,$id);
        if (in_array($s['provision_state'],['ready','failed'],true)) return ['state'=>$s['provision_state'],'message'=>$s['provision_message'] ?? ''];
        return $this->save($s,$this->ssh->provision($s,'status',['job'=>$s['provision_job']]));
    }
    public function pollForUser(array $actor,int $id): array
    {
        $s=(new ServerService($this->db,$this->ssh))->find($actor,$id);
        if (empty($s['provision_job'])) return ['state'=>'ready','message'=>'A szerver beállítva.'];
        if (in_array($s['provision_state'],['ready','failed'],true)) return ['state'=>$s['provision_state'],'message'=>$s['provision_state']==='ready' ? 'A szerver indítható.' : 'A fájlmásolás nem sikerült. Keresd az adminisztrátort.'];
        $result=$this->save($s,$this->ssh->provision($s,'status',['job'=>$s['provision_job']]));
        return ['state'=>$result['state'],'message'=>$result['state']==='ready' ? 'A szerver indítható.' : 'A fájlmásolás állapota: '.$result['state']];
    }
    private function save(array $server,array $result): array
    {
        $state=$result['state'] ?? '';
        if (!in_array($state,['queued','running','ready','failed'],true)) throw new RuntimeException('A Linux-gép ismeretlen másolási állapotot adott.');
        $message=mb_substr((string)($result['message'] ?? ''),0,500);
        $this->db->query('UPDATE servers SET provision_state=?,provision_message=? WHERE id=? AND provision_job=?',[$state,$message,$server['id'],$server['provision_job']]);
        return ['state'=>$state,'message'=>$message];
    }
}
