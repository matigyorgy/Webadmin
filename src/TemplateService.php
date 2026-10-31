<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class TemplateService
{
    public function __construct(private Database $db,private SshClient $ssh) {}
    public function node(array $actor,int $id): array
    {
        if(!$this->db->query('SELECT is_admin FROM users WHERE id=?',[$actor['id']])->fetchColumn())throw new RuntimeException('Adminisztrátori jogosultság szükséges.');
        return $this->db->query('SELECT * FROM nodes WHERE id=?',[$id])->fetch() ?: throw new RuntimeException('A Linux-gép nem található.');
    }
    public function catalog(array $actor,int $id): array { return (new TemplatePolicy($this->db))->catalog($id,$this->ssh->templates($this->node($actor,$id),'catalog')); }
    public function delete(array $actor,int $id,string $key): array {
        if ($key==='' || strlen($key)>200) throw new RuntimeException('Érvénytelen template-azonosító.');
        return $this->ssh->templates($this->node($actor,$id),'delete',$key);
    }
    public function saveCustom(array $actor,int $id,array $data): array
    {
        $node=$this->node($actor,$id);$profile=(new GameRegistry($this->db))->get(Validation::text($data,'template_game',32));
        if($profile['provider']==='minecraft')return $this->ssh->templates($node,'install',Validation::text($data,'minecraft_release',200));
        $path=Validation::text($data,'template_path',255);
        if(!str_starts_with($path,'/home/gamedock/templates/'))throw new RuntimeException('A forrásmappa a /home/gamedock/templates/ alatt legyen.');
        $startup=Validation::startup((string)($data['template_startup'] ?? '')) ?: $profile['startup_command'];
        $minimum=ResourceLimits::validate(['memory_limit_mb'=>$data['template_minimum_memory'] ?? 0]);
        $payload=['key'=>'custom:'.bin2hex(random_bytes(12)),'game'=>$profile['slug'],'name'=>Validation::text($data,'template_name'),'version'=>Validation::text($data,'template_version',64),'path'=>$path,'startup'=>$startup,'minimum_memory_mb'=>$minimum['memory_limit_mb']];
        return $this->ssh->templates($node,'custom-save',base64_encode(json_encode($payload,JSON_THROW_ON_ERROR)));
    }
    public function installSteam(array $actor,int $id,array $data): array
    {
        $game=Validation::text($data,'steam_game',32);
        if(!in_array($game,['cs16','cs2','css'],true))throw new RuntimeException('Ehhez a játékhoz nincs automatikus SteamCMD-letöltés.');
        $profile=(new GameRegistry($this->db))->get($game);
        if($profile['provider']!=='folder')throw new RuntimeException('Ehhez a játéktípushoz nem használható a SteamCMD-letöltés.');
        return $this->ssh->templates($this->node($actor,$id),'steam-install',$game);
    }
    public function releases(array $actor,int $id,string $type): array {if(!array_key_exists($type,TemplatePolicy::TYPES))throw new RuntimeException('Ismeretlen Minecraft-típus.');return $this->ssh->templates($this->node($actor,$id),'releases',$type);}
    public function sync(array $actor,int $id): array { return $this->ssh->templates($this->node($actor,$id),'sync'); }
}
