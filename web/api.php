<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
GameDock\WebSession::start();
header('Content-Type: application/json; charset=UTF-8');
try {
    $db=new GameDock\Database(); $auth=new GameDock\AuthService($db);$user=$auth->current();
    if (!$user) { http_response_code(401); echo json_encode(['error'=>'Jelentkezz be újra.']); exit; }
    $write=$_SERVER['REQUEST_METHOD']==='POST';
    if (!$write && $_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); header('Allow: GET, POST'); echo json_encode(['error'=>'Nem támogatott kérés.']); exit; }
    if ($write) $auth->csrf();
    $input=$write ? $_POST : $_GET;
    $action=(string)($input['action'] ?? ''); $id=GameDock\Validation::id($input['server_id'] ?? 0);
    if ($write && !in_array($action,['start','stop','restart','change_version'],true)) throw new RuntimeException('Nem engedélyezett vezérlési művelet.');
    $service=new GameDock\ServerService($db,new GameDock\SshClient());
    $service->find($user,$id); // Reject other owners before any network operation.
    session_write_close();
    $result=$write ? ($action==='change_version' ? (new GameDock\VersionService($db,new GameDock\SshClient()))->request($user,$id,(string)($_POST['template_key'] ?? '')) : $service->perform($user,$id,$action)) : $service->read($user,$id,$action);
    echo json_encode($result,JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error'=>$e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'A művelet nem sikerült. Ellenőrizd a jogosultságot és az SSH-beállításokat.'],JSON_INVALID_UTF8_SUBSTITUTE);
}
