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
        $auth=new AuthService($this->database); $service=new ServerService($this->database,$this->ssh);
        $user=$auth->current(); $error=''; $flash=$_SESSION['flash'] ?? []; unset($_SESSION['flash']);
        $notice=$flash['notice'] ?? ''; $page=(string)($_GET['page'] ?? 'servers');
        if (!in_array($page,['servers','account','history'],true)) $page='servers';
        $selected=null; $console=null;
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            try {
                $auth->csrf(); $action=(string)($_POST['action'] ?? '');
                if ($action==='login') { $auth->login($_POST); $this->redirect('/'); }
                if (!$user) throw new RuntimeException('Jelentkezz be a folytatáshoz.');
                if ($action==='logout') { $_SESSION=[]; session_destroy(); $this->redirect('/'); }
                if ($action==='account') {
                    $auth->updateAccount($user,$_POST); $_SESSION['flash']=['notice'=>'A fiókadatokat elmentettük. Jelszóváltoztatás után a többi munkamenet kijelentkezik.'];
                    $this->redirect('/?page=account');
                }
                if (!in_array($action,['start','stop','restart','logs','command','status'],true)) throw new RuntimeException('Ismeretlen művelet.');
                $id=Validation::id($_POST['server_id'] ?? 0);
                $result=$service->perform($user,$id,$action,(string)($_POST['command'] ?? ''));
                $_SESSION['flash']=['notice'=>match($action){'command'=>'A parancsot továbbítottuk. Az eredményt a konzolnaplóban ellenőrizd.','logs'=>'A konzolnapló frissült.','status'=>'Állapot: '.match($result['state'] ?? ''){'active'=>'online','inactive'=>'offline',default=>'ismeretlen'},default=>'A vezérlési parancs lefutott. Ellenőrizd a szerver állapotát.'}];
                if ($action==='logs') $_SESSION['flash']['logs']=$result['logs'];
                $this->redirect('/?server='.$id);
            } catch (Throwable $e) {
                $error=$e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'A mentés nem sikerült. Az e-mail-cím már használatban lehet, vagy adatbázishiba történt.';
            }
        }
        $servers=[]; $history=[];
        if ($user) {
            $user=$auth->current();
            if ($user) {
                try {
                    $servers=$service->listing($user,mb_substr(trim((string)($_GET['q'] ?? '')),0,100));
                    $gameNames=(new GameRegistry($this->database))->names();
                    foreach ($servers as &$server) { $server['game_name']=$gameNames[$server['game']] ?? $server['game']; $server['state']='nincs lekérdezve'; } unset($server);
                    if (isset($_GET['server'])) {
                        $s=$service->find($user,Validation::id($_GET['server']));
                        $host=$s['public_host'] ?: $s['ssh_host'];
                        $selected=['address'=>str_contains($host,':') ? '['.$host.']' : $host,'memory_limit_mb'=>$s['memory_limit_mb'] ?? 0,'cpu_limit_percent'=>$s['cpu_limit_percent'] ?? 0,'id'=>$s['id'],'name'=>$s['name'],'game_name'=>$gameNames[$s['game']] ?? $s['game'],'node_name'=>$s['node_name'],'port'=>$s['port'] ?? null,'provision_state'=>$s['provision_state'] ?? 'ready'];
                        if (($s['provision_state'] ?? 'ready')==='ready') $console=['id'=>$s['id'],'name'=>$s['name'],'logs'=>$flash['logs'] ?? 'A napló a Frissítés gombbal kérdezhető le.'];
                        $history=$service->audit($user,(int)$s['id']);
                    } elseif ($page==='history') $history=$service->audit($user);
                } catch (Throwable $e) { $error=$e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'A lista nem tölthető be.'; }
            }
        }
        $this->view->render('panel.tpl',['user'=>$user,'servers'=>$servers,'server_count'=>count($servers),'selected'=>$selected,
            'console'=>$console,'history'=>$history,'page'=>$page,'search'=>(string)($_GET['q'] ?? ''),'csrf'=>$_SESSION['csrf'],'error'=>$error,'notice'=>$notice]);
    }
    private function redirect(string $url): never { header('Location: '.$url,true,303); exit; }
}
