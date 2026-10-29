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
        if (($s['provision_state'] ?? 'ready')!=='ready') throw new RuntimeException('A szerver fájljainak elkészülte után váltható verzió.');
        if (!(new SchemaUpdater($this->db))->provisionEnabled()) throw new RuntimeException('A verzióváltáshoz először futtasd az admin adatbázisfrissítését.');
        return $s;
    }
    public function choices(array $user,int $id): array
    {
        $job=$this->poll($user,$id);$s=$this->server($user,$id);$catalog=$this->ssh->templates($s,'catalog');$choices=[];
        $catalog=(new TemplatePolicy($this->db))->catalog((int)$s['node_id'],$catalog);
        foreach($catalog['templates'] ?? [] as $row)if(($row['state'] ?? '')==='ready' && self::matches($s,$row))$choices[]=[
            'key'=>$row['key'],'type'=>$row['type'],'version'=>$row['version'],'build'=>$row['build'],'name'=>$row['name'] ?? ucfirst($row['type']),
            'minimum_memory_mb'=>$row['minimum_memory_mb'],'requires_reinstall'=>$row['requires_reinstall'],
            'current'=>($row['path'] ?? '')===$s['install_source'],
            'allowed'=>(int)($s['memory_limit_mb'] ?? 0)===0 || (int)$s['memory_limit_mb']>=$row['minimum_memory_mb']];
        return ['versions'=>$choices,'current_type'=>$s['minecraft_type'] ?: $s['game'],'current_version'=>$s['minecraft_version'] ?: ($s['template_version'] ?? 'egyedi'),'job'=>$job,'server_state'=>$this->ssh->execute($s,'status')['state'] ?? 'unknown'];
    }
    public function request(array $user,int $id,string $key,bool $confirmed=false): array
    {
        $pending=$this->poll($user,$id);
        if(in_array($pending['state'],['queued','running'],true)) throw new RuntimeException('Már folyamatban van egy verzióváltás.');
        $request=$this->db->transaction(function()use($user,$id,$key,$confirmed){
            $this->db->query('SELECT id FROM servers WHERE id=? FOR UPDATE',[$id]);
            $s=$this->server($user,$id);
            $old=$this->db->query('SELECT state FROM server_version_jobs WHERE server_id=?',[$id])->fetchColumn();
            if(in_array($old,['queued','running'],true)) throw new RuntimeException('Már folyamatban van egy verzióváltás.');
            if(($this->ssh->execute($s,'status')['state'] ?? '')!=='inactive') throw new RuntimeException('Verzióváltás előtt állítsd le a szervert.');
            $catalog=$this->ssh->templates($s,'catalog');$selected=null;
            foreach($catalog['templates'] ?? [] as $row) if(($row['state'] ?? '')==='ready' && self::matches($s,$row) && $row['key']===$key)$selected=$row;
            if(!$selected) throw new RuntimeException('Válassz kész template-et ehhez a játékhoz.');
            $policy=(new TemplatePolicy($this->db))->get((int)$s['node_id'],$selected);
            TemplatePolicy::assertFits($policy,$s);
            if($policy['requires_reinstall'] && !$confirmed)throw new RuntimeException('Ehhez a template-hez újratelepítés szükséges. Erősítsd meg az adatok cseréjét.');
            if($selected['path']===$s['install_source'])throw new RuntimeException('Ez a template már a szerver jelenlegi verziója.');
            $job=bin2hex(random_bytes(16));$startup=StartupCommand::prepare($selected['startup'],$s);
            $this->db->query('INSERT INTO server_version_jobs(server_id,job,state,startup_command,install_source,minecraft_type,minecraft_version,template_key,template_version,message) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE job=VALUES(job),state=VALUES(state),startup_command=VALUES(startup_command),install_source=VALUES(install_source),minecraft_type=VALUES(minecraft_type),minecraft_version=VALUES(minecraft_version),message=VALUES(message),template_key=VALUES(template_key),template_version=VALUES(template_version)',[$id,$job,'queued',$startup,$selected['path'],($selected['provider'] ?? '')==='folder' ? '' : $selected['type'],($selected['provider'] ?? '')==='folder' ? '' : $selected['version'],$selected['key'],$selected['version'],'A verzióváltás indítása folyamatban.']);
            $this->db->query('INSERT INTO audit_log(user_id,server_id,action,outcome) VALUES(?,?,?,?)',[$user['id'],$id,'version_change','requested']);
            return [$s,$job,$policy];
        });
        [$s,$job,$policy]=$request;
        try {$result=$this->ssh->versions($s,'create',['job'=>$job,'unit'=>$s['unit_name'],'template_key'=>$key,'reinstall'=>$policy['requires_reinstall'],'confirmed'=>$confirmed]);}
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
    public static function matches(array $server,array $row): bool
    {
        return !empty($server['minecraft_type']) ? ($row['provider'] ?? '')!=='folder' && array_key_exists($row['type'] ?? '',TemplatePolicy::TYPES) : ($row['provider'] ?? '')==='folder' && ($row['game'] ?? '')===$server['game'];
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
            if($state==='ready') $this->db->query('UPDATE servers SET startup_command=?,install_source=?,minecraft_type=?,minecraft_version=?,template_key=?,template_version=? WHERE id=?',[$job['startup_command'],$job['install_source'],$job['minecraft_type'],$job['minecraft_version'],$job['template_key'],$job['template_version'],$id]);
            $this->db->query('UPDATE server_version_jobs SET state=?,message=? WHERE server_id=? AND job=?',[$state,$message,$id,$token]);
            if(in_array($state,['ready','failed'],true))$this->db->query('UPDATE audit_log SET outcome=? WHERE server_id=? AND action=? AND outcome=?',[$state==='ready'?'accepted':'failed',$id,'version_change','requested']);
        });
        return ['state'=>$state,'message'=>$message];
    }
}
