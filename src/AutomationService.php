<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class AutomationService
{
    public function __construct(private Database $db,private SshClient $ssh) {}
    private function server(array $user,int $id): array
    {
        $s=(new ServerService($this->db,$this->ssh))->find($user,$id);
        if(($s['provision_state'] ?? 'ready')!=='ready')throw new RuntimeException('A szerverfájlok elkészülte után használható az időzítő és a mentés.');
        if(!array_key_exists('last_restore_job',$s))throw new RuntimeException('Az adminban először engedélyezd az új szerverfunkciókat, majd frissítsd a Linux-segédprogramokat.');
        return $s;
    }
    private function panel(array $s): array
    {
        return ['startup'=>(string)$s['startup_command'],'limits'=>array_merge(ResourceLimits::validate($s),['port'=>(int)$s['port']]),'template_key'=>$s['template_key'],'template_version'=>$s['template_version'],'minecraft_type'=>$s['minecraft_type'],'minecraft_version'=>$s['minecraft_version'],'install_source'=>$s['install_source']];
    }
    public function listing(array $user,int $id): array
    {
        $s=$this->server($user,$id);$result=$this->ssh->automation($s,'list',['panel'=>$this->panel($s)]);$job=$result['job'] ?? [];
        if(($job['operation'] ?? '')==='restore' && ($job['state'] ?? '')==='ready' && isset($job['restored_panel'],$job['id'])) {
            $this->reconcile($user,$id,$job);
            $this->ssh->automation($this->server($user,$id),'ack_restore',['data'=>['id'=>$job['id']],'panel'=>$this->panel($this->server($user,$id))]);
        }
        unset($result['job']['restored_panel']);return $result;
    }
    public function updatePolicy(array $user,int $id,bool $clear=false): void
    {
        $s=$this->server($user,$id);
        $result=$this->ssh->automation($s,$clear ? 'clear_schedules' : 'policy',['panel'=>$this->panel($s)]);
        if(($result['accepted'] ?? false)!==true)throw new RuntimeException('A folyamatban lévő visszaállítás befejezése után mentsd újra a szervert.');
    }
    public function reconcile(array $user,int $id,array $job): void
    {
        $this->db->transaction(function()use($user,$id,$job){
            $this->db->query('SELECT id FROM servers WHERE id=? FOR UPDATE',[$id]);$s=$this->server($user,$id);
            if($s['last_restore_job']===$job['id'])return;
            if(!preg_match('/\A[a-f0-9]{32}\z/',$job['id']))throw new RuntimeException('Érvénytelen visszaállítási azonosító.');
            $panel=$job['restored_panel'];$startup=StartupCommand::prepare(Validation::startup((string)$panel['startup']),$s);
            $this->db->query('UPDATE servers SET startup_command=?,template_key=?,template_version=?,minecraft_type=?,minecraft_version=?,install_source=?,last_restore_job=? WHERE id=?',[$startup,$panel['template_key'],$panel['template_version'],$panel['minecraft_type'],$panel['minecraft_version'],$panel['install_source'],$job['id'],$id]);
            // A completed old version job must not reapply the pre-restore version metadata.
            $this->db->query('DELETE FROM server_version_jobs WHERE server_id=?',[$id]);
        });
    }
    public function perform(array $user,int $id,string $action,array $input): array
    {
        $this->listing($user,$id);$s=$this->server($user,$id);$data=[];
        if($action==='schedule_add') {
            $operation=(string)($input['operation'] ?? '');$repeat=(string)($input['repeat'] ?? 'once');
            if(!in_array($operation,['start','stop','restart','backup'],true) || !in_array($repeat,['once','daily','weekly'],true))throw new RuntimeException('Válassz érvényes műveletet és ismétlést.');
            $zone=Validation::text($input,'timezone',100);try{$timezone=new \DateTimeZone($zone);}catch(\Exception){throw new RuntimeException('Érvénytelen időzóna.');}
            $local=Validation::text($input,'local_time',16);$date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$local,$timezone);
            if(!$date || $date->format('Y-m-d\TH:i')!==$local || $date->getTimestamp()<=time())throw new RuntimeException('Válassz jövőbeli, érvényes dátumot és időt.');
            $keep=filter_var($input['keep'] ?? 7,FILTER_VALIDATE_INT);if($keep===false || $keep<1 || $keep>100)throw new RuntimeException('A megőrzött mentések száma 1–100 lehet.');
            $data=['operation'=>$operation,'repeat'=>$repeat,'timezone'=>$zone,'local_time'=>$local,'keep'=>$keep];
        } elseif(in_array($action,['schedule_delete','backup_delete'],true))$data=['id'=>Validation::text($input,'id',32)];
        elseif($action==='restore') {
            if(($input['confirmed'] ?? '')!=='1')throw new RuntimeException('Erősítsd meg a mentés visszaállítását és a fájlok cseréjét.');
            if(($this->ssh->execute($s,'status')['state'] ?? '')!=='inactive')throw new RuntimeException('Visszaállítás előtt állítsd le a szervert.');
            $data=['backup_id'=>Validation::text($input,'backup_id',32),'confirmed'=>true];
        } elseif($action!=='backup')throw new RuntimeException('Ismeretlen időzítési vagy mentési művelet.');
        $result=$this->ssh->automation($s,$action,['data'=>$data,'panel'=>$this->panel($s)]);
        $this->db->query('INSERT INTO audit_log(user_id,server_id,action,outcome) VALUES(?,?,?,?)',[$user['id'],$id,'automation_'.$action,'accepted']);
        return $result;
    }
}
