<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
GameDock\WebSession::start();header('Content-Type: application/json; charset=UTF-8');
try {
    $db=new GameDock\Database();$user=(new GameDock\AuthService($db))->current();
    if (!$user || !$user['is_admin']) { http_response_code(403); echo json_encode(['error'=>'Adminisztrátori jogosultság szükséges.']); exit; }
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405);header('Allow: GET');exit; }
    $id=GameDock\Validation::id($_GET['server_id'] ?? 0);session_write_close();
    echo json_encode((new GameDock\ProvisionService($db,new GameDock\SshClient()))->poll($user,$id),JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
} catch (Throwable $e) { http_response_code(400);echo json_encode(['error'=>'A másolás állapota nem kérdezhető le. Ellenőrizd a Linux másolási segédprogramját.']); }
