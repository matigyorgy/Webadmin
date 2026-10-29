<?php
declare(strict_types=1);
namespace GameDock;
use PDO;
use RuntimeException;
use Throwable;
use phpseclib3\Crypt\RSA;
final class Installer
{
    public function __construct(private string $root) {}

    public function requirements(): array
    {
        return [
            ['label'=>'PHP 8.2 vagy újabb', 'ok'=>PHP_VERSION_ID >= 80200],
            ['label'=>'PDO MySQL bővítmény', 'ok'=>extension_loaded('pdo_mysql')],
            ['label'=>'Smarty és SSH2 könyvtárak', 'ok'=>class_exists(\Smarty\Smarty::class) && class_exists(\phpseclib3\Net\SSH2::class)],
            ['label'=>'Írható config könyvtár', 'ok'=>is_dir($this->root.'/config') && is_writable($this->root.'/config')],
            ['label'=>'Írható Smarty fordítási könyvtár', 'ok'=>is_dir($this->root.'/var/smarty/compile') && is_writable($this->root.'/var/smarty/compile')],
        ];
    }

    public function validate(array $input): array
    {
        $get = static fn(string $key): string => trim((string)($input[$key] ?? ''));
        $host = $get('db_host'); $port = $get('db_port'); $name = $get('db_name'); $user = $get('db_user');
        if (!preg_match('/\A(?:[a-zA-Z0-9_.-]+|[a-fA-F0-9:]+)\z/', $host)
            || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
            || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $name) || $user === '' || strlen($user) > 128) {
            throw new RuntimeException('Ellenőrizd az adatbázis címét, portját, nevét és felhasználóját.');
        }
        $email = $get('admin_email'); $password = (string)($input['admin_password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new RuntimeException('Érvényes admin e-mail-cím szükséges.');
        if (strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) throw new RuntimeException('Az admin jelszava 12–72 bájt hosszú legyen.');
        if ($password !== (string)($input['admin_password_confirm'] ?? '')) throw new RuntimeException('A két adminjelszó nem egyezik.');
        $node = null;
        if ($get('ssh_host') !== '') {
            if (!preg_match('/\A(?:[a-zA-Z0-9_.-]+|[a-fA-F0-9:]+)\z/', $get('ssh_host'))
                || !ctype_digit($get('ssh_port')) || (int)$get('ssh_port') < 1 || (int)$get('ssh_port') > 65535
                || !preg_match('/\A[a-z_][a-z0-9_-]{0,63}\z/', $get('ssh_user')) || $get('ssh_user') === 'root'
                || !preg_match('/\ASHA256:[A-Za-z0-9+\/]{43}\z/', $get('ssh_fingerprint'))
                || $get('node_name') === '' || strlen($get('node_name')) > 120) {
                throw new RuntimeException('A Linux-géphez érvényes cím, port, nem root felhasználó, név és ellenőrzött SHA256 hostkulcs-ujjlenyomat kell.');
            }
            $node = ['name'=>$get('node_name'),'host'=>$get('ssh_host'),'port'=>(int)$get('ssh_port'),'user'=>$get('ssh_user'),'fingerprint'=>$get('ssh_fingerprint')];
        }
        $server = null;
        if ($get('server_name') !== '') throw new RuntimeException('A szervereket telepítés után az adminban hozd létre.');
        return ['dsn'=>"mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",'db_user'=>$user,'db_password'=>(string)($input['db_password'] ?? ''),
            'email'=>$email,'password_hash'=>password_hash($password, PASSWORD_DEFAULT),'node'=>$node,'server'=>$server];
    }

    public function install(array $input): array
    {
        $data = $this->validate($input);
        foreach ($this->requirements() as $requirement) {
            if (!$requirement['ok']) throw new RuntimeException('Hiányzó feltétel: '.$requirement['label']);
        }
        $directory = $this->root.'/config';
        $lock = fopen($directory.'/setup.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('A telepítő nem zárolható.');
        $created = []; $pdo = null; $temporary = null; $keyCreated = false; $published = false;
        try {
            if (Config::installed() || is_file($directory.'/local.json')) throw new RuntimeException('A telepítés már megtörtént.');
            try {
                $pdo = new PDO($data['dsn'], $data['db_user'], $data['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
            } catch (Throwable $e) { throw new RuntimeException('Nem sikerült kapcsolódni a MySQL-adatbázishoz. Ellenőrizd az adatokat és a hozzáférést.'); }
            if ($pdo->query('SHOW FULL TABLES')->fetch() !== false) throw new RuntimeException('A telepítéshez üres, külön adatbázis szükséges. Meglévő táblákat nem módosítunk.');
            $privateKeyPath = $directory.'/ssh-private.pem';
            if (file_exists($privateKeyPath) || file_exists($directory.'/ssh-public.pub')) throw new RuntimeException('A config könyvtárban már van SSH-kulcs; a telepítő nem írja felül.');
            $key = RSA::createKey(3072);
            $publicKey = $key->getPublicKey()->toString('OpenSSH');
            $oldMask = umask(0077);
            try {
                $handle = fopen($privateKeyPath, 'x');
                if ($handle === false) throw new RuntimeException('Az SSH-kulcs nem menthető.');
                $keyCreated = true;
                try {
                    $contents = $key->toString('PKCS8');
                    if (fwrite($handle, $contents) !== strlen($contents)) throw new RuntimeException('Az SSH-kulcs mentése nem teljes.');
                } finally { fclose($handle); }
                if (file_put_contents($directory.'/ssh-public.pub', $publicKey."\n") === false) throw new RuntimeException('A nyilvános SSH-kulcs nem menthető.');
            } finally { umask($oldMask); }
            $schema = file_get_contents($this->root.'/database/schema.sql');
            foreach (explode(';', $schema) as $statement) {
                if (trim($statement) === '') continue;
                if (!preg_match('/CREATE TABLE ([a-z_]+)/', $statement, $match)) throw new RuntimeException('Ismeretlen telepítési séma.');
                $pdo->exec($statement);
                $created[] = $match[1];
            }
            $pdo->beginTransaction();
            foreach(GameRegistry::defaults() as $profile)$pdo->prepare('INSERT INTO game_types(slug,name,template_path,startup_command,provider) VALUES(?,?,?,?,?)')->execute(array_values($profile));
            $pdo->prepare('INSERT INTO users(email,password_hash,is_admin) VALUES(?,?,1)')->execute([$data['email'],$data['password_hash']]);
            $userId = $pdo->lastInsertId();
            if ($data['node']) {
                $n=$data['node'];
                $pdo->prepare('INSERT INTO nodes(name,ssh_host,ssh_port,ssh_user,ssh_fingerprint) VALUES(?,?,?,?,?)')->execute([$n['name'],$n['host'],$n['port'],$n['user'],$n['fingerprint']]);
                $nodeId=$pdo->lastInsertId();
            }
            $settings=['DB_DSN'=>$data['dsn'],'DB_USER'=>$data['db_user'],'DB_PASSWORD'=>$data['db_password'],
                'SSH_PRIVATE_KEY_PATH'=>$privateKeyPath];
            $temporary=tempnam($directory, '.install-');
            if ($temporary === false || !chmod($temporary,0600)) throw new RuntimeException('A konfiguráció nem menthető.');
            $json=json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary,$json) !== strlen($json)) throw new RuntimeException('A konfiguráció mentése nem teljes.');
            $pdo->commit();
            if (!rename($temporary,$directory.'/local.json')) throw new RuntimeException('A konfiguráció nem aktiválható.');
            $published=true;
            return ['public_key'=>$publicKey,'has_node'=>$data['node'] !== null];
        } catch (Throwable $e) {
            if (!$published) {
                if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
                // Only remove tables created by this attempt, never pre-existing data.
                foreach (array_reverse($created) as $table) {
                    try { $pdo->exec('DROP TABLE `'.$table.'`'); } catch (Throwable $ignored) {}
                }
                if ($keyCreated) { @unlink($directory.'/ssh-private.pem'); @unlink($directory.'/ssh-public.pub'); }
                if (is_string($temporary)) @unlink($temporary);
            }
            throw $e instanceof RuntimeException && !$e instanceof \PDOException ? $e : new RuntimeException('A telepítés nem sikerült. Ellenőrizd az adatbázis CREATE/INSERT jogosultságát és a config könyvtár írhatóságát.');
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
