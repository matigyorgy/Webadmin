<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
Webadmin\WebSession::start();header('Content-Type: application/json; charset=UTF-8');
try {
    $db=new Webadmin\Database();$user=(new Webadmin\AuthService($db))->current();
    if (!$user || !$user['is_admin']) { http_response_code(403); echo json_encode(['error'=>'Adminisztrátori jogosultság szükséges.']); exit; }
    $action=(string)($_REQUEST['action'] ?? 'provision');
    if($_SERVER['REQUEST_METHOD']==='POST' && $action==='probe') {(new Webadmin\AuthService($db))->csrf();echo json_encode(['fingerprint'=>Webadmin\SshClient::probe((string)($_POST['host'] ?? ''),(int)($_POST['port'] ?? 22))]);exit;}
    if($_SERVER['REQUEST_METHOD']==='POST' && in_array($action,['setup_start','setup_status'],true)) {
        (new Webadmin\AuthService($db))->csrf();
        echo json_encode((new Webadmin\NodeSetupService($db))->run($user,Webadmin\Validation::id($_POST['node_id'] ?? 0),$_POST,$action==='setup_status'),JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);exit;
    }
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405);header('Allow: GET, POST');exit; }
    if($action==='releases'){set_time_limit(90);$node=Webadmin\Validation::id($_GET['node_id'] ?? 0);$type=(string)($_GET['type'] ?? 'vanilla');session_write_close();echo json_encode((new Webadmin\TemplateService($db,new Webadmin\SshClient()))->releases($user,$node,$type),JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);exit;}
    if($action==='templates') {
        $node=Webadmin\Validation::id($_GET['node_id'] ?? 0);session_write_close();
        echo json_encode((new Webadmin\TemplateService($db,new Webadmin\SshClient()))->catalog($user,$node),JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);exit;
    }
    $id=Webadmin\Validation::id($_GET['server_id'] ?? 0);session_write_close();
    echo json_encode((new Webadmin\ProvisionService($db,new Webadmin\SshClient()))->poll($user,$id),JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
} catch (Throwable $e) { http_response_code(400);echo json_encode(['error'=>$e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'A művelet nem sikerült. Ellenőrizd a Linux-kapcsolatot.']); }
