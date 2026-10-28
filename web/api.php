<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
GameDock\WebSession::start();
header('Content-Type: application/json; charset=UTF-8');
try {
    $db=new GameDock\Database(); $user=(new GameDock\AuthService($db))->current();
    if (!$user) { http_response_code(401); echo json_encode(['error'=>'Jelentkezz be újra.']); exit; }
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); header('Allow: GET'); echo json_encode(['error'=>'Csak lekérdezés engedélyezett.']); exit; }
    $action=(string)($_GET['action'] ?? ''); $id=GameDock\Validation::id($_GET['server_id'] ?? 0);
    $service=new GameDock\ServerService($db,new GameDock\SshClient());
    $service->find($user,$id); // Reject other owners before any network operation.
    session_write_close();
    $result=$service->read($user,$id,$action);
    echo json_encode($result,JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error'=>'A lekérdezés nem sikerült. Ellenőrizd a szerver jogosultságát és az SSH-beállításokat.']);
}
