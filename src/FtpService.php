<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class FtpService
{
    public function __construct(private Database $db,private SshClient $ssh) {}
    private function server(array $user,int $id): array
    {
        $server=(new ServerService($this->db,$this->ssh))->find($user,$id);
        if(($server['provision_state'] ?? 'ready')!=='ready')throw new RuntimeException('Az FTP-hozzáférés a szerverfájlok elkészülte után hozható létre.');
        return $server;
    }
    public static function credentials(array $input): array
    {
        $name=Validation::text($input,'ftp_username',32);$password=$input['ftp_password'] ?? null;
        if(!preg_match('/\A[a-z][a-z0-9_.-]{2,31}\z/',$name) || in_array($name,['anonymous','ftp','root'],true))throw new RuntimeException('Az FTP-felhasználónév 3–32 karakter: kisbetűvel kezdődjön, és kisbetűt, számot, pontot, kötőjelet vagy aláhúzást tartalmazhat.');
        if(!is_string($password) || strlen($password)<12 || strlen($password)>128 || preg_match('/[\x00-\x1f\x7f]/',$password))throw new RuntimeException('Az FTP-jelszó 12–128 bájt hosszú legyen, vezérlőkarakter nélkül.');
        if(!is_string($input['ftp_password_confirm'] ?? null) || !hash_equals($password,$input['ftp_password_confirm']))throw new RuntimeException('A két FTP-jelszó nem egyezik.');
        return ['username'=>$name,'password'=>$password];
    }
    public function listing(array $user,int $id): array
    {
        $server=$this->server($user,$id);$result=$this->ssh->ftp($server,'list');
        $result['host']=$server['public_host'] ?: $server['ssh_host'];return $result;
    }
    public function perform(array $user,int $id,string $action,array $input=[]): array
    {
        $server=$this->server($user,$id);
        if(!in_array($action,['ftp_create','ftp_delete'],true))throw new RuntimeException('Ismeretlen FTP-művelet.');
        $payload=$action==='ftp_create' ? self::credentials($input) : ['username'=>isset($input['ftp_username']) ? Validation::text($input,'ftp_username',32) : ''];
        $result=$this->ssh->ftp($server,$action==='ftp_create' ? 'create' : 'delete',$payload);
        $this->db->query('INSERT INTO audit_log(user_id,server_id,action,outcome) VALUES(?,?,?,?)',[$user['id'],$id,$action,'accepted']);
        $result['host']=$server['public_host'] ?: $server['ssh_host'];return $result;
    }
}
