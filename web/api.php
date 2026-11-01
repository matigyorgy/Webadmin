<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
Webadmin\WebSession::start();
header('Content-Type: application/json; charset=UTF-8');
try {
    $db=new Webadmin\Database(); $auth=new Webadmin\AuthService($db);$user=$auth->current();
    if (!$user) { http_response_code(401); echo json_encode(['error'=>'Jelentkezz be újra.']); exit; }
    $write=$_SERVER['REQUEST_METHOD']==='POST';
    if (!$write && $_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); header('Allow: GET, POST'); echo json_encode(['error'=>'Nem támogatott kérés.']); exit; }
    if ($write) $auth->csrf();
    $input=$write ? $_POST : $_GET;
    $action=(string)($input['action'] ?? ''); $id=Webadmin\Validation::id($input['server_id'] ?? 0);
    if ($write && !in_array($action,['start','stop','restart','change_version','schedule_add','schedule_delete','backup','restore','backup_delete','ftp_create','ftp_delete'],true)) throw new RuntimeException('Nem engedélyezett vezérlési művelet.');
    $service=new Webadmin\ServerService($db,new Webadmin\SshClient());
    $service->find($user,$id); // Reject other owners before any network operation.
    session_write_close();
    if($action==='ftp' && !$write)$result=(new Webadmin\FtpService($db,new Webadmin\SshClient()))->listing($user,$id);
    elseif($write && in_array($action,['ftp_create','ftp_delete'],true))$result=(new Webadmin\FtpService($db,new Webadmin\SshClient()))->perform($user,$id,$action,$input);
    elseif($action==='automation' && !$write)$result=(new Webadmin\AutomationService($db,new Webadmin\SshClient()))->listing($user,$id);
    elseif($write && in_array($action,['schedule_add','schedule_delete','backup','restore','backup_delete'],true))$result=(new Webadmin\AutomationService($db,new Webadmin\SshClient()))->perform($user,$id,$action,$input);
    else $result=$write ? ($action==='change_version' ? (new Webadmin\VersionService($db,new Webadmin\SshClient()))->request($user,$id,(string)($_POST['template_key'] ?? ''),($_POST['confirm_reinstall'] ?? '')==='1') : $service->perform($user,$id,$action)) : $service->read($user,$id,$action);
    echo json_encode($result,JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error'=>$e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'A művelet nem sikerült. Ellenőrizd a jogosultságot és az SSH-beállításokat.'],JSON_INVALID_UTF8_SUBSTITUTE);
}
