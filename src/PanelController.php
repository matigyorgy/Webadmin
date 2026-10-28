<?php
declare(strict_types=1);
namespace GameDock;
use Throwable;
use RuntimeException;
use PDOException;
final class PanelController
{
    public function __construct(private Database $database, private SshClient $ssh, private SmartyView $view) {}
    public function handle(): void
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $error=''; $notice=''; $user=false; $servers=[]; $console=null;
        try {
         $user=isset($_SESSION['uid']) ? $this->database->query('SELECT id,email,is_admin FROM users WHERE id=?',[$_SESSION['uid']])->fetch() : false;
         if ($_SERVER['REQUEST_METHOD']==='POST') {
          if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf']??''))) throw new RuntimeException('Érvénytelen űrlap.');
          $action=(string)($_POST['action']??'');
          if ($action==='login') {
           $account=$this->database->query('SELECT * FROM users WHERE email=?',[trim((string)($_POST['email']??''))])->fetch();
           if (!$account || !password_verify((string)($_POST['password']??''),$account['password_hash'])) { usleep(500000); throw new RuntimeException('Hibás bejelentkezési adatok.'); }
           session_regenerate_id(true); $_SESSION['uid']=$account['id']; $_SESSION['csrf']=bin2hex(random_bytes(32)); header('Location: /'); exit;
          }
          if (!$user) throw new RuntimeException('Jelentkezz be.');
          if ($action==='logout') { $_SESSION=[]; session_destroy(); header('Location: /'); exit; }
          if (!in_array($action,['start','stop','restart','logs','command'],true)) throw new RuntimeException('Ismeretlen művelet.');
          $server=$this->database->query('SELECT s.*,n.ssh_host,n.ssh_port,n.ssh_user,n.ssh_fingerprint FROM servers s JOIN nodes n ON n.id=s.node_id WHERE s.id=? AND (s.user_id=? OR ?=1)',[(int)($_POST['server_id']??0),$user['id'],(int)$user['is_admin']])->fetch();
          if (!$server) throw new RuntimeException('A szerver nem érhető el.');
          $command = trim((string)($_POST['command'] ?? ''));
          if ($action === 'command' && ($command === '' || strlen($command) > 500 || preg_match('/[\\x00-\\x1f\\x7f]/', $command))) {
              throw new RuntimeException('Érvénytelen konzolparancs. Egy sor, legfeljebb 500 bájt engedélyezett.');
          }
          $this->database->query('INSERT INTO audit_log(user_id,server_id,action,outcome) VALUES(?,?,?,?)',[$user['id'],$server['id'],$action,'requested']);
          $auditId=$this->database->lastInsertId();
          try {
              $result=$this->ssh->execute($server,$action,$command);
              $this->database->query('UPDATE audit_log SET outcome=? WHERE id=?',['accepted',$auditId]);
              if ($action === 'logs') {
                  $console=['id'=>$server['id'],'name'=>$server['name'],'logs'=>$result['logs']];
              } elseif ($action === 'command') {
                  $notice='A parancsot továbbítottuk a játék konzoljának. A végrehajtás eredményét a naplóban ellenőrizd.';
                  $console=['id'=>$server['id'],'name'=>$server['name'],'logs'=>'Frissítsd a konzolnaplót az eredmény megtekintéséhez.'];
              } else {
                  $notice='Az SSH-parancs sikeresen lefutott. Frissítés után ellenőrizd az állapotot.';
              }
          } catch (Throwable $e) {
              $this->database->query('UPDATE audit_log SET outcome=? WHERE id=?',['failed',$auditId]);
              throw $e;
          }
         }
         $servers=$user ? $this->database->query('SELECT s.*,n.name AS node_name,n.ssh_host,n.ssh_port,n.ssh_user,n.ssh_fingerprint FROM servers s JOIN nodes n ON n.id=s.node_id WHERE s.user_id=? OR ?=1 ORDER BY s.name',[$user['id'],(int)$user['is_admin']])->fetchAll() : [];
        } catch (Throwable $e) { $error=$e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Adatbázis- vagy szolgáltatáshiba. Ellenőrizd a konfigurációt.'; }

        $cards = [];
        $gameNames = ['minecraft'=>'Minecraft','cs2'=>'Counter-Strike 2','fivem'=>'FiveM','css'=>'Counter-Strike: Source'];
        foreach ($servers as $server) {
            $state = 'ismeretlen';
            try { $state = $this->ssh->execute($server, 'status')['state'] ?? 'ismeretlen'; } catch (Throwable $e) {}
            // Only presentation data crosses the template boundary.
            $cards[] = ['id'=>$server['id'], 'name'=>$server['name'], 'node_name'=>$server['node_name'],
                'game_name'=>$gameNames[$server['game']] ?? $server['game'], 'state'=>$state];
        }
        $this->view->render('panel.tpl', ['user'=>$user, 'servers'=>$cards, 'server_count'=>count($cards),
            'console'=>$console, 'csrf'=>$_SESSION['csrf'], 'error'=>$error, 'notice'=>$notice]);
    }
}
