<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
final class AuthService
{
    public function __construct(private Database $db) {}
    public function current(): ?array
    {
        if (empty($_SESSION['uid'])) return null;
        $user=$this->db->query('SELECT * FROM users WHERE id=?',[$_SESSION['uid']])->fetch();
        if (!$user || !hash_equals(hash('sha256',$user['password_hash']),(string)($_SESSION['auth_version'] ?? ''))) {
            unset($_SESSION['uid'],$_SESSION['auth_version']);
            return null;
        }
        unset($user['password_hash']);
        return $user;
    }
    public function csrf(): void
    {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) throw new RuntimeException('Lejárt vagy érvénytelen űrlap. Frissítsd az oldalt.');
    }
    public function login(array $data): void
    {
        $identity=trim(is_string($data['email'] ?? null) ? $data['email'] : '');
        $email=strtolower($identity);
        (new LoginRateLimiter())->consume(substr($email,0,190),(string)($_SERVER['REMOTE_ADDR'] ?? 'local'));
        $hasUsername=(bool)$this->db->query("SHOW COLUMNS FROM users LIKE 'username'")->fetch();
        $user=$hasUsername ? $this->db->query('SELECT * FROM users WHERE email=? OR username=? LIMIT 1',[$email,$identity])->fetch() : $this->db->query('SELECT * FROM users WHERE email=?',[$email])->fetch();
        $password=is_string($data['password'] ?? null) ? $data['password'] : '';
        $valid=password_verify($password,$user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        if (!$user || !$valid) throw new RuntimeException('Hibás bejelentkezési adatok.');
        (new LoginRateLimiter())->clearAccount($email);
        session_regenerate_id(true);
        $_SESSION['uid']=$user['id']; $_SESSION['auth_version']=hash('sha256',$user['password_hash']);
        $_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public function updateAccount(array $user, array $data): void
    {
        $email=Validation::email($data);
        $account=$this->db->query('SELECT password_hash FROM users WHERE id=?',[$user['id']])->fetch();
        if (!$account || !password_verify((string)($data['current_password'] ?? ''),$account['password_hash'])) throw new RuntimeException('A jelenlegi jelszó hibás.');
        $hash=$account['password_hash'];
        if (($data['new_password'] ?? '') !== '') {
            if ($data['new_password'] !== ($data['password_confirm'] ?? '')) throw new RuntimeException('A két új jelszó nem egyezik.');
            $hash=Validation::password((string)$data['new_password']);
        }
        $this->db->query('UPDATE users SET email=?,password_hash=? WHERE id=? AND password_hash=?',[$email,$hash,$user['id'],$account['password_hash']]);
        // MySQL reports zero changed rows if unchanged; verify instead of rejecting that case.
        $current=$this->db->query('SELECT password_hash FROM users WHERE id=?',[$user['id']])->fetchColumn();
        if ($current !== $hash) throw new RuntimeException('A fiók közben megváltozott. Jelentkezz be újra.');
        session_regenerate_id(true); $_SESSION['auth_version']=hash('sha256',$hash); $_SESSION['csrf']=bin2hex(random_bytes(32));
        $this->db->query('INSERT INTO audit_log(user_id,server_id,action,outcome) VALUES(?,0,?,?)',[$user['id'],'account_update','accepted']);
    }
}
