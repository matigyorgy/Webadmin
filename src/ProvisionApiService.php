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
        $allowed=['action','user_id','node_id','game','minecraft_template','install_source','startup_command','port','memory_limit_mb','cpu_limit_percent','disk_limit_mb','slot_limit','expires_at'];
        foreach($data as $key=>$value)if(!in_array($key,$allowed,true) || !is_scalar($value))throw new RuntimeException('Ismeretlen vagy hibás API-mező: '.$key,400);
        $result=[];foreach($data as $key=>$value)if($key!=='action')$result[$key]=(string)$value;
        try{
            $result['user_id']=(string)Validation::id($result['user_id'] ?? 0);
            $result['node_id']=(string)Validation::id($result['node_id'] ?? 0);
            $result['game']=Validation::text($result,'game',32);
        }catch(RuntimeException $e){throw new RuntimeException($e->getMessage(),400,$e);}
        ksort($result);return $result;
    }
    public static function order(array $query): array
    {
        $pick=static function(string $key,string $fallback='')use($query):string{$value=$query[$key]??$fallback;if(!is_scalar($value))throw new RuntimeException('Hibás rendelési mező: '.$key,400);return trim((string)$value);};
        if($pick('feladat')!=='szolg_letrehozas')throw new RuntimeException('Ismeretlen feladat. A feladat=szolg_letrehozas érték szükséges.',400);
        $type=$pick('type');if($type!==''&&!in_array($type,['basic','premium'],true))throw new RuntimeException('A type mező basic vagy premium legyen.',400);
        $premium=filter_var($pick('premium',$type==='premium'?'true':'false'),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
        if($premium===null)throw new RuntimeException('A premium mező true vagy false legyen.',400);
        if($type!==''&&(($type==='premium')!==$premium))throw new RuntimeException('A type és premium mezők értéke nem egyezik.',400);
        $service=$pick('szolg_type');
        $service=preg_replace('/\A(?:premium|basic)_/','',$service);
        $aliases=['fivm'=>'fivem'];$game=$aliases[$service]??$service;
        if(!in_array($game,['minecraft','cs16','cs2','css','fivem'],true))throw new RuntimeException('Nem támogatott szolgáltatástípus. Használható: minecraft, cs16, cs2, css, fivem.',400);
        $version=$pick('version');$template='';
        if($game==='minecraft'){
            if(!preg_match('/\A(vanilla|paper|forge|bukkit|spigot|purpur|fabric)_([A-Za-z0-9.-]{1,32})\z/',$version,$match))throw new RuntimeException('A Minecraft verzió például vanilla_26.1.2 legyen.',400);
            $template=$match[1].':'.$match[2];
        }
        foreach(['slot','memory','hdd','lejar'] as $number)if(!preg_match('/\A[0-9]{1,12}\z/',$pick($number)))throw new RuntimeException('Érvénytelen számmező: '.$number,400);
        $slot=(int)$pick('slot');$memory=(int)$pick('memory');$gb=(int)$pick('hdd');$expiry=(int)$pick('lejar');
        if($slot<1 || $slot>1000 || $memory<0 || $memory>1048576 || $gb<0 || $gb>1048576 || $expiry<=time())throw new RuntimeException('A férőhely, memória, HDD vagy lejárat kívül esik az engedélyezett tartományon.',400);
        $email=strtolower($pick('email_cim'));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)throw new RuntimeException('Érvényes email_cim szükséges.',400);
        $username=$pick('felhasznalonev');if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{2,63}\z/',$username))throw new RuntimeException('Érvényes, legalább 3 karakteres felhasznalonev szükséges.',400);
        $password=$pick('jelszo');if(strlen($password)<12||strlen($password)>72||str_contains($password,"\0"))throw new RuntimeException('A jelszo 12–72 bájt hosszú legyen.',400);
        $orderId=$pick('rendeles_id');if($orderId!==''&&!preg_match('/\A[A-Za-z0-9_.:-]{8,120}\z/',$orderId))throw new RuntimeException('A rendeles_id 8–120 biztonságos karakter legyen.',400);
        return ['premium'=>$premium,'game'=>$game,'template'=>$template,'slot_limit'=>$slot,'memory_limit_mb'=>$memory,'disk_limit_mb'=>$gb*1024,'expires_at'=>$expiry,'email'=>$email,'username'=>$username,'password'=>$password,'order_id'=>$orderId];
    }
    public function runOrder(array $actor,array $order): array
    {
        if(!$this->db->query('SELECT is_admin FROM users WHERE id=?',[$actor['id']])->fetchColumn())throw new RuntimeException('Adminisztrátori jogosultság szükséges.',403);
        $schema=new SchemaUpdater($this->db);
        if(!$schema->orderFieldsEnabled() || !$schema->provisionEnabled())$schema->enableStartup($actor);
        if(!$schema->orderFieldsEnabled())throw new RuntimeException('Az adatbázis rendelési mezői nem frissültek. Futtasd az adminfelületen az Új szerverfunkciók engedélyezése műveletet, és ellenőrizd, hogy a panel adatbázis-felhasználójának van ALTER TABLE jogosultsága.',409);
        if(!$schema->provisionEnabled())throw new RuntimeException('Az adatbázis szerverlétrehozási sémája nem frissült. Futtasd az adminfelületen az Új szerverfunkciók engedélyezése műveletet, majd próbáld újra.',409);
            $key=$order['order_id']!=='' ? 'order:'.$order['order_id'] : 'order:'.hash('sha256',json_encode([$order['email'],$order['username'],$order['game'],$order['template'],$order['premium'],$order['slot_limit'],$order['memory_limit_mb'],$order['disk_limit_mb'],$order['expires_at']],JSON_THROW_ON_ERROR));
            $prior=$this->db->query('SELECT r.server_id,s.user_id,s.node_id,s.game,s.template_key,s.template_version,s.minecraft_type,s.memory_limit_mb,s.disk_limit_mb,s.slot_limit,s.expires_at,u.email,u.username,n.premium FROM provision_api_requests r JOIN servers s ON s.id=r.server_id JOIN users u ON u.id=s.user_id JOIN nodes n ON n.id=s.node_id WHERE r.request_hash=?',[hash('sha256',$key)])->fetch();
            if($prior){if(strtolower($prior['email'])!==$order['email']||strcasecmp((string)$prior['username'],$order['username'])!==0||$prior['game']!==$order['game']||(int)$prior['premium']!==(int)$order['premium']||(int)$prior['memory_limit_mb']!==$order['memory_limit_mb']||(int)$prior['disk_limit_mb']!==$order['disk_limit_mb']||(int)$prior['slot_limit']!==$order['slot_limit']||(int)$prior['expires_at']!==$order['expires_at']||($order['game']==='minecraft'&&($prior['minecraft_type'].':'.$prior['template_version']!==$order['template'])))throw new RuntimeException('Ehhez a rendelésazonosítóhoz már más adatok tartoznak.',409);$input=['action'=>'create','user_id'=>(int)$prior['user_id'],'node_id'=>(int)$prior['node_id'],'game'=>$prior['game'],'memory_limit_mb'=>(int)$prior['memory_limit_mb'],'disk_limit_mb'=>(int)$prior['disk_limit_mb'],'slot_limit'=>(int)$prior['slot_limit'],'expires_at'=>(int)$prior['expires_at']];if($prior['template_key'])$input['minecraft_template']=$prior['template_key'];return $this->orderResult($actor,$key,$input);}
            $nodes=$this->db->query('SELECT n.id,n.premium,(SELECT COUNT(*) FROM servers s WHERE s.node_id=n.id) AS used FROM nodes n WHERE n.premium=? ORDER BY used,n.id',[$order['premium']?1:0])->fetchAll();
            if(!$nodes)throw new RuntimeException($order['premium']?'Nincs prémiumként megjelölt Linux-gép.':'Nincs normál Linux-gép.',409);
            $node=$nodes[0];$templateKey=null;
            if($order['game']==='minecraft'){
                $node=null;
                foreach($nodes as $candidateNode){
                    try{$catalog=(new TemplateService($this->db,$this->ssh))->catalog($actor,(int)$candidateNode['id']);}catch(\Throwable){continue;}
                    foreach($catalog['templates']??[] as $candidate)if(($candidate['type']??'').':'.($candidate['version']??'')===$order['template']&&($candidate['state']??'')==='ready'&&($candidate['provider']??'')!=='folder'){$node=$candidateNode;$templateKey=$candidate['key'];break 2;}
                }
                if(!$node)throw new RuntimeException('A kért Minecraft template egyik megfelelő Linux-gépen sem érhető el.',409);
            }
        $ownerId=$this->db->transaction(function()use($order){
            $owner=$this->db->query('SELECT * FROM users WHERE email=? FOR UPDATE',[$order['email']])->fetch();
            if(!$owner){$claimed=$this->db->query('SELECT id FROM users WHERE username=?',[$order['username']])->fetch();if($claimed)throw new RuntimeException('A rendelés felhasználóneve már foglalt.',409);$this->db->query('INSERT INTO users(email,username,password_hash,is_admin) VALUES(?,?,?,0)',[$order['email'],$order['username'],Validation::password($order['password'])]);return (int)$this->db->lastInsertId();}
            $ownerId=(int)$owner['id'];if($owner['username']!==null&&strcasecmp((string)$owner['username'],$order['username'])!==0)throw new RuntimeException('Ehhez az e-mail-címhez már más felhasználónév tartozik.',409);
            if($owner['username']===null){$claimed=$this->db->query('SELECT id FROM users WHERE username=? AND id<>?',[$order['username'],$ownerId])->fetch();if($claimed)throw new RuntimeException('A rendelés felhasználóneve már foglalt.',409);$this->db->query('UPDATE users SET username=? WHERE id=?',[$order['username'],$ownerId]);}
            return $ownerId;
        });
        $input=['action'=>'create','user_id'=>$ownerId,'node_id'=>(int)$node['id'],'game'=>$order['game'],'memory_limit_mb'=>$order['memory_limit_mb'],'disk_limit_mb'=>$order['disk_limit_mb'],'slot_limit'=>$order['slot_limit'],'expires_at'=>$order['expires_at']];if($templateKey!==null)$input['minecraft_template']=$templateKey;
        return $this->orderResult($actor,$key,$input);
    }
    private function orderResult(array $actor,string $key,array $input): array
    {
        $result=$this->run($actor,$key,$input);
        if(($result['status']??0)===202)$result['json']['message']='A szerver létrehozása folyamatban van. Ismételd meg ugyanazt a rendelési URL-t vagy POST-kérést az állapot lekéréséhez.';
        return $result;
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
