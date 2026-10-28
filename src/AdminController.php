<?php
declare(strict_types=1);
namespace GameDock;
use RuntimeException;
use Throwable;
use PDOException;
final class AdminController
{
    public function __construct(private Database $db, private SshClient $ssh, private SmartyView $view) {}
    public function handle(): void
    {
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $auth=new AuthService($this->db); $user=$auth->current();
        if (!$user) { header('Location: /',true,303); return; }
        if (!(int)$user['is_admin']) { http_response_code(403); $this->view->render('forbidden.tpl',['user'=>$user,'csrf'=>$_SESSION['csrf']]); return; }
        $service=new AdminService($this->db);
        $section=(string)($_GET['section'] ?? 'servers');
        if (!in_array($section,['servers','nodes','users','audit','setup'],true)) $section='servers';
        $error=''; $notice=$_SESSION['admin_notice'] ?? ''; unset($_SESSION['admin_notice']);
        $record=null; $rows=[]; $users=[]; $nodes=[]; $key='';
        try {
            if ($_SERVER['REQUEST_METHOD']==='POST') {
                $auth->csrf();
                if (!in_array($section,['servers','nodes','users'],true)) throw new RuntimeException('Ezen az oldalon nincs módosítás.');
                $action=(string)($_POST['action'] ?? '');
                if ($action==='upgrade_startup' && $section==='servers') {
                    (new SchemaUpdater($this->db))->enableStartup($user);
                    $_SESSION['admin_notice']='Az indítóparancsokat és szerverfájl-másolást engedélyeztük. A meglévő szerverek és adatok megmaradtak.';
                } elseif ($action==='save') {
                    $created=$section==='servers' && empty($_POST['id']);
                    $savedId=$service->save($user,$section,$_POST); $_SESSION['admin_notice']='A bejegyzést elmentettük.';
                    $savedServer=$section==='servers' ? $service->find($user,'servers',$savedId) : null;
                    if ($created || ($savedServer && ($savedServer['provision_state'] ?? '')==='queued' && !empty($savedServer['provision_job']))) {
                        try { $queued=(new ProvisionService($this->db,$this->ssh))->enqueue($user,$savedId); $_SESSION['admin_notice']=$queued['state']==='failed' ? 'A szerver rögzítve, de a másolás nem indult: '.$queued['message'] : 'A szervert létrehoztuk, a fájlok másolását elindítottuk. Az állapot a szerver szerkesztésénél követhető.'; }
                        catch (Throwable $e) { $_SESSION['admin_notice']='A szervert rögzítettük, a másolás indítása még nem igazolt. Nyisd meg a szerkesztést, ellenőrizd a Linux-beállításokat, majd indítsd újra ugyanazt a másolási kérést.'; }
                        header('Location: /admin.php?section=servers&edit='.$savedId,true,303); return;
                    }
                } elseif (in_array($action,['provision_status','provision_retry'],true) && $section==='servers') {
                    $id=Validation::id($_POST['id'] ?? 0);$provision=new ProvisionService($this->db,$this->ssh);
                    if ($action==='provision_retry') {
                        $s=$service->find($user,'servers',$id);
                        if (($s['provision_state'] ?? 'ready')==='failed') $this->db->query('UPDATE servers SET provision_job=?,provision_state=? WHERE id=?',[bin2hex(random_bytes(16)),'queued',$id]);
                        $result=$provision->enqueue($user,$id);
                    } else $result=$provision->poll($user,$id);
                    if ($action==='provision_retry') $service->audit($user,'provision_retry',$id);
                    $_SESSION['admin_notice']='Másolás: '.$result['state'].'. '.$result['message'];
                    header('Location: /admin.php?section=servers&edit='.$id,true,303); return;
                } elseif ($action==='delete') {
                    $service->delete($user,$section,Validation::id($_POST['id'] ?? 0),($_POST['confirm'] ?? '')==='1');
                    $_SESSION['admin_notice']=$section==='servers' ? 'A szervert kivettük a panelből. A Linuxon futó játékot és fájljait ez nem módosította.' : 'A bejegyzést töröltük.';
                } elseif ($action==='test' && $section==='nodes') {
                    $node=$service->find($user,'nodes',Validation::id($_POST['id'] ?? 0));
                    try { $this->ssh->test($node); } catch (Throwable $e) { throw new RuntimeException('Az SSH-teszt sikertelen. Ellenőrizd a címet, portot, hostkulcsot és az authorized_keys beállítást.'); }
                    $service->audit($user,'node_test:'.$node['id']); $_SESSION['admin_notice']='Az SSH-kapcsolat, a hostkulcs és a kulcsos hitelesítés sikeres. A játékszolgáltatás sudoers-jogát ez nem ellenőrzi.';
                } else throw new RuntimeException('Ismeretlen adminművelet.');
                header('Location: /admin.php?section='.$section,true,303); return;
            }
        } catch (Throwable $e) {
            $error=$e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'A mentés nem sikerült. Az e-mail már foglalt lehet, vagy a bejegyzésre más adat hivatkozik.';
            if (($_POST['action'] ?? '')==='save') {
                $record=[];
                foreach (['id','email','is_admin','name','ssh_host','ssh_port','ssh_user','ssh_fingerprint','user_id','node_id','game','unit_name','startup_command','install_source','install_folder'] as $field) $record[$field]=is_scalar($_POST[$field] ?? null) ? (string)$_POST[$field] : '';
            }
        }
        try {
            $rows=$service->listing($user,$section);
            if ($section==='servers') { $users=$service->listing($user,'users'); $nodes=$service->listing($user,'nodes'); }
            if ($section==='setup') {
                $key=$service->publicKey($user); $nodes=$service->listing($user,'nodes'); $rows=$service->listing($user,'servers');
            }
            if ($record===null && isset($_GET['edit'])) $record=$service->find($user,$section,Validation::id($_GET['edit']));
            if ($record===null && isset($_GET['new'])) $record=[];
        } catch (Throwable $e) { $error=$e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Az adminlista nem tölthető be.'; }
        $defaults=['id'=>'','email'=>'','is_admin'=>'0','name'=>'','ssh_host'=>'','ssh_port'=>'22','ssh_user'=>'gamedock','ssh_fingerprint'=>'','user_id'=>'','node_id'=>'','game'=>'minecraft','unit_name'=>'','startup_command'=>'','install_source'=>'','install_folder'=>'','provision_job'=>'','provision_state'=>'ready','provision_message'=>''];
        $this->view->render('admin.tpl',['user'=>$user,'csrf'=>$_SESSION['csrf'],'section'=>$section,'error'=>$error,'notice'=>$notice,'rows'=>$rows,
            'record'=>$record===null ? null : array_replace($defaults,$record),'users'=>$users,'nodes'=>$nodes,'public_key'=>$key,'startup_supported'=>(new SchemaUpdater($this->db))->startupEnabled(),'provision_supported'=>(new SchemaUpdater($this->db))->provisionEnabled(),'games'=>Validation::GAMES]);
    }
}
