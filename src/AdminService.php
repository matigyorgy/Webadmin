<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
use phpseclib3\Crypt\PublicKeyLoader;
final class AdminService
{
    public function __construct(private Database $db) {}
    private function admin(array $actor): void
    {
        if (!$this->db->query('SELECT is_admin FROM users WHERE id=?',[$actor['id']])->fetchColumn()) throw new RuntimeException('Adminisztrátori jogosultság szükséges.');
    }
    public function listing(array $actor, string $section): array
    {
        $this->admin($actor);
        return match($section) {
            'games'=>array_values((new GameRegistry($this->db))->all()),
            'users'=>$this->db->query('SELECT u.id,u.email,u.is_admin,COUNT(s.id) AS server_count FROM users u LEFT JOIN servers s ON s.user_id=u.id GROUP BY u.id ORDER BY u.email')->fetchAll(),
            'nodes'=>$this->db->query('SELECT n.*,COUNT(s.id) AS server_count FROM nodes n LEFT JOIN servers s ON s.node_id=n.id GROUP BY n.id ORDER BY n.name')->fetchAll(),
            'servers'=>$this->db->query('SELECT s.*,u.email AS owner_email,n.name AS node_name FROM servers s JOIN users u ON u.id=s.user_id JOIN nodes n ON n.id=s.node_id ORDER BY s.name')->fetchAll(),
            'audit'=>$this->db->query('SELECT a.*,u.email,s.name AS server_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id LEFT JOIN servers s ON s.id=a.server_id ORDER BY a.id DESC LIMIT 200')->fetchAll(),
            default=>[],
        };
    }
    public function find(array $actor, string $section, int $id): array
    {
        $this->admin($actor);
        $table=match($section){'users'=>'users','nodes'=>'nodes','servers'=>'servers',default=>throw new RuntimeException('Ismeretlen lista.')};
        $fields=$table==='users' ? 'id,email,is_admin' : '*';
        $record=$this->db->query("SELECT $fields FROM $table WHERE id=?",[$id])->fetch();
        if (!$record) throw new RuntimeException('A bejegyzés nem található.');
        return $record;
    }
    public function save(array $actor, string $section, array $data): int
    {
        return $this->db->transaction(function () use($actor,$section,$data) {
            $this->admin($actor);
            $id=empty($data['id']) ? null : Validation::id($data['id']);
            if ($id!==null) $this->find($actor,$section,$id);
            if ($section==='games') {
                if(!(new GameRegistry($this->db))->available())throw new RuntimeException('Először futtasd az admin adatbázisfrissítést.');
                $slug=trim((string)($data['slug'] ?? ''));
                if(!preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/',$slug))throw new RuntimeException('A típusazonosító kisbetűből, számból, kötőjelből és aláhúzásból állhat.');
                $name=Validation::text($data,'name');$source=Validation::text($data,'template_path',255);
                if(!str_starts_with($source,'/'))throw new RuntimeException('Abszolút template mappa szükséges.');
                $startup=Validation::startup((string)($data['startup_command'] ?? ''));
                if($startup==='')throw new RuntimeException('Indítóparancs szükséges.');
                $provider=($data['provider'] ?? '')==='minecraft' ? 'minecraft' : 'folder';
                if($provider==='folder' && $slug!=='fivem' && !str_contains($startup,'{port}'))throw new RuntimeException('Az indítóparancs tartalmazza a {port} helyőrzőt. Konfigurációs fájlnál is használható: PORT={port} ./start.sh.');
                if(!empty($data['existing_slug'])) {
                    if($slug!==$data['existing_slug'])throw new RuntimeException('A típusazonosító nem módosítható.');
                    $this->db->query('UPDATE game_types SET name=?,template_path=?,startup_command=?,provider=? WHERE slug=?',[$name,$source,$startup,$provider,$slug]);
                }else $this->db->query('INSERT INTO game_types(slug,name,template_path,startup_command,provider) VALUES(?,?,?,?,?)',[$slug,$name,$source,$startup,$provider]);
                $this->audit($actor,'game_save');return 0;
            } elseif ($section==='users') {
                $admins=$this->db->query('SELECT id FROM users WHERE is_admin=1 ORDER BY id FOR UPDATE')->fetchAll();
                $email=Validation::email($data); $isAdmin=(string)($data['is_admin'] ?? '')==='1' ? 1 : 0;
                $existing=$id ? $this->find($actor,'users',$id) : null;
                if ($existing && $existing['is_admin'] && !$isAdmin && count($admins)<2) throw new RuntimeException('Az utolsó admin jogosultsága nem vonható vissza.');
                if ($id===(int)$actor['id'] && !$isAdmin) throw new RuntimeException('A saját adminjogodat itt nem vonhatod vissza.');
                $password=(string)($data['password'] ?? '');
                if (!$id) {
                    $this->db->query('INSERT INTO users(email,password_hash,is_admin) VALUES(?,?,?)',[$email,Validation::password($password),$isAdmin]);
                } else {
                    if ($id===(int)$actor['id'] && $password!=='') throw new RuntimeException('A saját jelszavadat a Saját fiók oldalon módosítsd.');
                    $this->db->query('UPDATE users SET email=?,is_admin=? WHERE id=?',[$email,$isAdmin,$id]);
                    if ($password!=='') $this->db->query('UPDATE users SET password_hash=? WHERE id=?',[Validation::password($password),$id]);
                }
            } elseif ($section==='nodes') {
                $node=Validation::node($data);
                $values=[$node['name'],$node['ssh_host'],$node['ssh_port'],$node['ssh_user'],$node['ssh_fingerprint']];
                if ($id) $this->db->query('UPDATE nodes SET name=?,ssh_host=?,ssh_port=?,ssh_user=?,ssh_fingerprint=? WHERE id=?',[...$values,$id]);
                else $this->db->query('INSERT INTO nodes(name,ssh_host,ssh_port,ssh_user,ssh_fingerprint) VALUES(?,?,?,?,?)',$values);
            } elseif ($section==='servers') {
                $startup=Validation::startup((string)($data['startup_command'] ?? ''));$explicitStartup=$startup!=='';
                $startupEnabled=(new SchemaUpdater($this->db))->startupEnabled();
                if ($startup!=='' && !$startupEnabled) throw new RuntimeException('Először kattints az Indítóparancsok engedélyezése adatbázisfrissítő gombra.');
                $name=Validation::text($data,'name'); $game=Validation::text($data,'game'); $unit=Validation::text($data,'unit_name');
                $profile=(new GameRegistry($this->db))->get($game);
                if($explicitStartup && $profile['provider']==='folder' && $game!=='fivem' && !str_contains($startup,'{port}'))throw new RuntimeException('Az indítóparancsban használd a {port} helyőrzőt az automatikusan kiosztott porthoz.');
                if((new GameRegistry($this->db))->available())$this->db->query('SELECT slug FROM game_types WHERE slug=? FOR UPDATE',[$game]);
                if (!preg_match('/\A[a-zA-Z0-9_][a-zA-Z0-9_-]*(?:\.service)?\z/',$unit)) throw new RuntimeException('Érvénytelen játéktípus vagy szerverazonosító.');
                if (!$id && !(new SchemaUpdater($this->db))->provisionEnabled()) throw new RuntimeException('Először engedélyezd az új szerverfunkciókat az adatbázisfrissítő gombbal.');
                $existing=$id ? $this->find($actor,'servers',$id) : null;
                if ((!$id || !empty($existing['provision_job'])) && $startup==='') $startup=$profile['startup_command'];
                if ($existing && !empty($existing['install_folder']) && ($existing['unit_name']!==$unit || (int)$existing['node_id']!==(int)($data['node_id'] ?? 0))) throw new RuntimeException('Másolt szervernél a Linux-gép és az unitnév nem módosítható. Költöztetéshez hozz létre új példányt.');
                $user=Validation::id($data['user_id'] ?? 0); $node=Validation::id($data['node_id'] ?? 0);
                $this->find($actor,'users',$user);
                if (!$this->db->query('SELECT id FROM nodes WHERE id=? FOR UPDATE',[$node])->fetch()) throw new RuntimeException('A Linux-gép nem található.');
                $duplicate=$this->db->query('SELECT id FROM servers WHERE node_id=? AND unit_name=? AND id<>?',[$node,$unit,$id ?? 0])->fetch();
                if ($duplicate) throw new RuntimeException('Ez a szolgáltatás ezen a gépen már hozzá van adva.');
                if ($id) $this->db->query('UPDATE servers SET name=?,game=?,unit_name=?,user_id=?,node_id=? WHERE id=?',[$name,$game,$unit,$user,$node,$id]);
                else $this->db->query('INSERT INTO servers(name,game,unit_name,user_id,node_id) VALUES(?,?,?,?,?)',[$name,$game,$unit,$user,$node]);
                $serverId=$id ?? (int)$this->db->lastInsertId();
                if ($startupEnabled) $this->db->query('UPDATE servers SET startup_command=? WHERE id=?',[$startup==='' ? null : $startup,$serverId]);
                if (!$id || (($existing['provision_state'] ?? '')==='failed' && !empty($existing['provision_job']))) {
                    $source=trim((string)($data['install_source'] ?? '')) ?: $profile['template_path'];
                    $mcType=null;$mcVersion=null;
                    if($profile['provider']==='minecraft') {
                        $key=(string)($data['minecraft_template'] ?? '');
                        $catalog=(new TemplateService($this->db,new SshClient()))->catalog($actor,$node);
                        $entry=null;foreach($catalog['templates'] ?? [] as $candidate)if(($candidate['key'] ?? '')===$key && ($candidate['state'] ?? '')==='ready')$entry=$candidate;
                        if(($data['eula_accepted'] ?? '')!=='1')throw new RuntimeException('A Minecraft EULA elfogadása szükséges.');
                        if(!$entry)throw new RuntimeException('Válassz letöltött Minecraft típust és verziót. Először szinkronizáld a node Minecraft-template katalógusát.');
                        $source=$entry['path'];$startup=$explicitStartup ? $startup : $entry['startup'];$mcType=$entry['type'];$mcVersion=$entry['version'];
                    }
                    $folder=(string)$serverId;
                    if (!str_starts_with($source,'/') || strlen($source)>255 || preg_match('/[\x00-\x1f\x7f]/',$source) || !preg_match('/\A[a-zA-Z0-9_-]{1,120}\z/',$folder)) throw new RuntimeException('Abszolút forrásmappa és egyszerű célmappanév szükséges.');
                    if ($this->db->query('SELECT id FROM servers WHERE node_id=? AND install_folder=? AND id<>?',[$node,$folder,$id ?? 0])->fetch()) throw new RuntimeException('Ez a célmappanév ezen a gépen már foglalt.');
                    $this->db->query('UPDATE servers SET startup_command=?,install_source=?,install_folder=?,provision_job=?,provision_state=? WHERE id=?',[$startup!=='' ? $startup : $profile['startup_command'],$source,$folder,bin2hex(random_bytes(16)),'queued',$serverId]);
                }
                if(!$id || empty($existing['port'])) {
                    $used=$this->db->query('SELECT port FROM servers WHERE node_id=? AND id<>? AND port IS NOT NULL',[$node,$serverId])->fetchAll();
                    $ports=array_column($used,'port');$port=25000;while(in_array($port,$ports)&&$port<=25999)$port++;
                    if($port>25999)throw new RuntimeException('A 25000–25999 porttartomány ezen a gépen megtelt.');
                    $this->db->query('UPDATE servers SET port=?,minecraft_type=?,minecraft_version=? WHERE id=?',[$port,$mcType ?? $existing['minecraft_type'] ?? null,$mcVersion ?? $existing['minecraft_version'] ?? null,$serverId]);
                }
                $id=$serverId;
            } else throw new RuntimeException('Ismeretlen bejegyzéstípus.');
            $id ??= (int)$this->db->lastInsertId();
            $this->audit($actor,$section.'_save:'.$id,$section==='servers' ? $id : 0);
            return $id;
        });
    }
    public function deleteGame(array $actor,string $slug,bool $confirmed): void
    {
        if(!$confirmed)throw new RuntimeException('Erősítsd meg a törlést.');
        $this->db->transaction(function()use($actor,$slug){
            $this->admin($actor);$this->db->query('SELECT slug FROM game_types WHERE slug=? FOR UPDATE',[$slug]);
            if($this->db->query('SELECT id FROM servers WHERE game=? LIMIT 1',[$slug])->fetch())throw new RuntimeException('Használatban lévő játéktípus nem törölhető.');
            $this->db->query('DELETE FROM game_types WHERE slug=?',[$slug]);$this->audit($actor,'game_delete');
        });
    }
    public function delete(array $actor, string $section, int $id, bool $confirmed): void
    {
        if (!$confirmed) throw new RuntimeException('A törlést a jelölőnégyzettel erősítsd meg.');
        $this->db->transaction(function () use($actor,$section,$id) {
            $this->admin($actor); $record=$this->find($actor,$section,$id);
            if ($section==='users') {
                $admins=$this->db->query('SELECT id FROM users WHERE is_admin=1 ORDER BY id FOR UPDATE')->fetchAll();
                if ($id===(int)$actor['id']) throw new RuntimeException('A saját fiókodat itt nem törölheted.');
                if ($record['is_admin'] && count($admins)<2) throw new RuntimeException('Az utolsó admin nem törölhető.');
                if ($this->db->query('SELECT id FROM servers WHERE user_id=? LIMIT 1',[$id])->fetch()) throw new RuntimeException('Előbb rendeld másik felhasználóhoz a fiók szervereit.');
            } elseif ($section==='nodes') {
                $this->db->query('SELECT id FROM nodes WHERE id=? FOR UPDATE',[$id]);
                if ($this->db->query('SELECT id FROM servers WHERE node_id=? LIMIT 1',[$id])->fetch()) throw new RuntimeException('Előbb rendeld másik géphez vagy vedd ki a panelből a gép szervereit.');
            }
            if ($section==='servers' && in_array($record['provision_state'] ?? 'ready',['queued','running'],true)) throw new RuntimeException('Folyamatban lévő másolásnál a bejegyzés nem törölhető. Előbb ellenőrizd a másolás állapotát.');
            $table=match($section){'users'=>'users','nodes'=>'nodes','servers'=>'servers',default=>throw new RuntimeException('Ismeretlen bejegyzéstípus.')};
            $this->db->query("DELETE FROM $table WHERE id=?",[$id]);
            $this->audit($actor,$section.'_delete:'.$id,$section==='servers' ? $id : 0);
        });
    }
    public function audit(array $actor, string $action, int $server=0): void
    {
        $this->db->query('INSERT INTO audit_log(user_id,server_id,action,outcome) VALUES(?,?,?,?)',[$actor['id'],$server,substr($action,0,32),'accepted']);
    }
    public function publicKey(array $actor): string
    {
        $this->admin($actor); $path=Config::get('SSH_PRIVATE_KEY_PATH');
        if ($path==='' || !is_readable($path)) throw new RuntimeException('Nincs olvasható panel SSH-kulcs beállítva.');
        return PublicKeyLoader::loadPrivateKey(file_get_contents($path),Config::get('SSH_KEY_PASSPHRASE') ?: false)->getPublicKey()->toString('OpenSSH');
    }
}
