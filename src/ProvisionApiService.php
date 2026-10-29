<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class ProvisionApiService
{
    public function __construct(private Database $db,private SshClient $ssh) {}
    public static function install(Database $db): void
    {
        $db->query('CREATE TABLE IF NOT EXISTS provision_api_requests (request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,payload_hash CHAR(64) NOT NULL,server_id BIGINT UNSIGNED NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    }
    public static function input(array $data): array
    {
        $allowed=['action','user_id','node_id','game','minecraft_template','install_source','startup_command','port','memory_limit_mb','cpu_limit_percent'];
        foreach($data as $key=>$value)if(!in_array($key,$allowed,true) || !is_scalar($value))throw new RuntimeException('Ismeretlen vagy hibás API-mező: '.$key,400);
        $result=[];foreach($data as $key=>$value)if($key!=='action')$result[$key]=(string)$value;
        try{
            $result['user_id']=(string)Validation::id($result['user_id'] ?? 0);
            $result['node_id']=(string)Validation::id($result['node_id'] ?? 0);
            $result['game']=Validation::text($result,'game',32);
        }catch(RuntimeException $e){throw new RuntimeException($e->getMessage(),400,$e);}
        ksort($result);return $result;
    }
    public static function success(array $server): string
    {
        $user=Validation::id($server['user_id']);
        // The public server identifier is the number also displayed as Szerverazonosító.
        $service=Validation::id($server['unit_name']);
        $node=(string)$server['node_name'];
        if(!preg_match('/\A[A-Za-z0-9_.-]{1,120}\z/',$node))throw new RuntimeException('Az API-hoz a Linux-gép neve csak betűt, számot, pontot, aláhúzást és kötőjelet tartalmazhat.',409);
        return 'FELH_ID:'.$user.'|SZOLG_ID:'.$service.'|NODE:'.$node.'|';
    }
    public function run(array $actor,string $key,array $data): array
    {
        if(!preg_match('/\A[A-Za-z0-9_.:-]{8,128}\z/',$key))throw new RuntimeException('Adj meg 8–128 karakteres egyedi Idempotency-Key fejlécet.',400);
        if(!$this->db->query('SELECT is_admin FROM users WHERE id=?',[$actor['id']])->fetchColumn())throw new RuntimeException('Adminisztrátori jogosultság szükséges.',403);
        $action=$data['action'] ?? 'create';
        if(!in_array($action,['create','status'],true))throw new RuntimeException('Ismeretlen API-művelet.',400);
        $hash=hash('sha256',$key);$lock='gd_api_'.substr($hash,0,56);
        if(!(int)$this->db->query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn())throw new RuntimeException('Ez a kérés már feldolgozás alatt áll. Próbáld újra.',409);
        try{
            $request=$this->db->query('SELECT * FROM provision_api_requests WHERE request_hash=?',[$hash])->fetch();
            if($action==='create'){
                $input=self::input($data);$payload=hash('sha256',json_encode($input,JSON_THROW_ON_ERROR));
                if($request && !hash_equals($request['payload_hash'],$payload))throw new RuntimeException('Ehhez az Idempotency-Key értékhez már más paraméterek tartoznak.',409);
                if(!$request){
                    $node=$this->db->query('SELECT name FROM nodes WHERE id=?',[$input['node_id']])->fetchColumn();
                    if(!$node || !preg_match('/\A[A-Za-z0-9_.-]{1,120}\z/',$node))throw new RuntimeException('Válassz létező Linux-gépet, amelynek neve például node_s1.',400);
                    if(!(new SchemaUpdater($this->db))->provisionEnabled())throw new RuntimeException('Először futtasd az admin adatbázisfrissítést.',409);
                    $id=(new AdminService($this->db))->save($actor,'servers',$input,function(int $id)use($hash,$payload){
                        $this->db->query('INSERT INTO provision_api_requests(request_hash,payload_hash,server_id) VALUES(?,?,?)',[$hash,$payload,$id]);
                    });
                    $request=['server_id'=>$id];
                }
            }
            if(!$request)throw new RuntimeException('Nincs ilyen API-kérés.',404);
            $id=(int)$request['server_id'];
            $server=$this->db->query('SELECT s.*,n.name AS node_name FROM servers s JOIN nodes n ON n.id=s.node_id WHERE s.id=?',[$id])->fetch();
            if(!$server)throw new RuntimeException('Az ehhez a kéréshez tartozó szervert már törölték. Új szerverhez új Idempotency-Key szükséges.',410);
            if(empty($server['provision_job']))throw new RuntimeException('Ehhez a szerverhez nem készült Linux-másolási feladat.',409);
            $provision=new ProvisionService($this->db,$this->ssh);
            if($server['provision_state']==='queued' && $action==='create')$result=$provision->enqueue($actor,$id);
            else $result=$provision->poll($actor,$id);
            if($result['state']==='ready')return ['status'=>201,'text'=>self::success($server)];
            if($result['state']==='failed')return ['status'=>422,'json'=>['state'=>'failed','message'=>'A Linux-oldali szerverlétrehozás nem sikerült. Ellenőrizd az adminfelületen; azonos kulccsal nem hozunk létre új szervert.']];
            return ['status'=>202,'json'=>['state'=>$result['state'],'message'=>'A szerver létrehozása folyamatban. Azonos Idempotency-Key fejléccel kérdezd le az állapotot.']];
        }finally{$this->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
