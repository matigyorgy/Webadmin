<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class VersionService
{
    public function __construct(private Database $db,private SshClient $ssh) {}
    private function server(array $user,int $id): array
    {
        $s=(new ServerService($this->db,$this->ssh))->find($user,$id);
        if (empty($s['minecraft_type']) || ($s['provision_state'] ?? 'ready')!=='ready') throw new RuntimeException('Ehhez a szerverhez nincs választható Minecraft-verzió.');
        if (!(new SchemaUpdater($this->db))->versionsEnabled()) throw new RuntimeException('A verzióváltáshoz először futtasd az admin adatbázisfrissítését.');
        return $s;
    }
    public function choices(array $user,int $id): array
    {
        $job=$this->poll($user,$id);$s=$this->server($user,$id);$catalog=$this->ssh->templates($s,'catalog');$choices=[];
        foreach($catalog['templates'] ?? [] as $row) if(($row['state'] ?? '')==='ready' && $row['type']===$s['minecraft_type'] && ($row['path'] ?? '')!==$s['install_source']) $choices[]=['key'=>$row['key'],'type'=>$row['type'],'version'=>$row['version'],'build'=>$row['build']];
        return ['versions'=>$choices,'current_type'=>$s['minecraft_type'],'current_version'=>$s['minecraft_version'],'job'=>$job];
    }
    public function request(array $user,int $id,string $key): array
    {
        $pending=$this->poll($user,$id);
        if(in_array($pending['state'],['queued','running'],true)) throw new RuntimeException('Már folyamatban van egy verzióváltás.');
        $request=$this->db->transaction(function()use($user,$id,$key){
            $this->db->query('SELECT id FROM servers WHERE id=? FOR UPDATE',[$id]);
            $s=$this->server($user,$id);
            $old=$this->db->query('SELECT state FROM server_version_jobs WHERE server_id=?',[$id])->fetchColumn();
            if(in_array($old,['queued','running'],true)) throw new RuntimeException('Már folyamatban van egy verzióváltás.');
            if(($this->ssh->execute($s,'status')['state'] ?? '')!=='inactive') throw new RuntimeException('Verzióváltás előtt állítsd le a szervert.');
            $catalog=$this->ssh->templates($s,'catalog');$selected=null;
            foreach($catalog['templates'] ?? [] as $row) if(($row['state'] ?? '')==='ready' && $row['type']===$s['minecraft_type'] && $row['key']===$key)$selected=$row;
            if(!$selected) throw new RuntimeException('Válassz letöltött verziót a szerver jelenlegi Minecraft-típusából.');
            $job=bin2hex(random_bytes(16));$startup=StartupCommand::prepare($selected['startup'],$s);
            $this->db->query('INSERT INTO server_version_jobs(server_id,job,state,startup_command,install_source,minecraft_type,minecraft_version,message) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE job=VALUES(job),state=VALUES(state),startup_command=VALUES(startup_command),install_source=VALUES(install_source),minecraft_type=VALUES(minecraft_type),minecraft_version=VALUES(minecraft_version),message=VALUES(message)',[$id,$job,'queued',$startup,$selected['path'],$selected['type'],$selected['version'],'A verzióváltás indítása folyamatban.']);
            $this->db->query('INSERT INTO audit_log(user_id,server_id,action,outcome) VALUES(?,?,?,?)',[$user['id'],$id,'version_change','requested']);
            return [$s,$job];
        });
        [$s,$job]=$request;
        try {$result=$this->ssh->versions($s,'create',['job'=>$job,'unit'=>$s['unit_name'],'template_key'=>$key]);}
        catch(RuntimeException $error) {
            if ($error->getCode()===422) return $this->record($id,$job,['state'=>'failed','message'=>$error->getMessage()]);
            // Poll the same token after a lost response; never enqueue a second replacement.
            return ['state'=>'queued','message'=>'A kérés elküldése még nem igazolt. Az állapotot automatikusan újra ellenőrizzük.'];
        }
        return $this->record($id,$job,$result);
    }
    public function poll(array $user,int $id): array
    {
        (new ServerService($this->db,$this->ssh))->find($user,$id);
        if (!(new SchemaUpdater($this->db))->versionsEnabled()) return ['state'=>'idle','message'=>''];
        $job=$this->db->query('SELECT * FROM server_version_jobs WHERE server_id=?',[$id])->fetch();
        if(!$job) return ['state'=>'idle','message'=>''];
        if(in_array($job['state'],['ready','failed'],true)) return ['state'=>$job['state'],'message'=>$job['message']];
        $s=(new ServerService($this->db,$this->ssh))->find($user,$id);
        return $this->record($id,$job['job'],$this->ssh->versions($s,'status',['job'=>$job['job']]));
    }
    private function record(int $id,string $token,array $result): array
    {
        $state=$result['state'] ?? '';
        if($state==='missing'){$state='failed';$result['message']='A verzióváltás nem indult el. Ellenőrizd a Linux-segédprogramok frissítését, majd próbáld újra.';}
        if(!in_array($state,['queued','running','ready','failed'],true)) throw new RuntimeException('Ismeretlen verzióváltási állapot.');
        $message=mb_substr((string)($result['message'] ?? ''),0,500);
        $this->db->transaction(function()use($id,$token,$state,$message){
            $this->db->query('SELECT id FROM servers WHERE id=? FOR UPDATE',[$id]);
            $job=$this->db->query('SELECT * FROM server_version_jobs WHERE server_id=? AND job=? FOR UPDATE',[$id,$token])->fetch();
            if(!$job || in_array($job['state'],['ready','failed'],true))return;
            if($state==='ready') $this->db->query('UPDATE servers SET startup_command=?,install_source=?,minecraft_type=?,minecraft_version=? WHERE id=?',[$job['startup_command'],$job['install_source'],$job['minecraft_type'],$job['minecraft_version'],$id]);
            $this->db->query('UPDATE server_version_jobs SET state=?,message=? WHERE server_id=? AND job=?',[$state,$message,$id,$token]);
            if(in_array($state,['ready','failed'],true))$this->db->query('UPDATE audit_log SET outcome=? WHERE server_id=? AND action=? AND outcome=?',[$state==='ready'?'accepted':'failed',$id,'version_change','requested']);
        });
        return ['state'=>$state,'message'=>$message];
    }
}
