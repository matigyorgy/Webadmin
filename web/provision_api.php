<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Type: application/json; charset=UTF-8');
try{
    $method=$_SERVER['REQUEST_METHOD'] ?? '';
    if(!in_array($method,['POST','GET'],true)){header('Allow: GET, POST');throw new RuntimeException('GET és POST kérés támogatott.',405);}
    $db=new GameDock\Database();
    $authorization=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if($method==='GET'){
        if(strlen($_SERVER['QUERY_STRING'] ?? '')>16384)throw new RuntimeException('Túl nagy API-kérés.',413);
        if(!isset($_GET['key'])||!is_string($_GET['key']))throw new RuntimeException('A GET-rendeléshez key paraméter szükséges.',401);
        $authorization='Bearer '.$_GET['key'];
    }
    $actor=GameDock\ProvisionApiCredentials::authenticate($db,$authorization);
    $service=new GameDock\ProvisionApiService($db,new GameDock\SshClient());
    if($method==='GET'){$result=$service->runOrder($actor,GameDock\ProvisionApiService::order($_GET));}
    else{
        $contentType=strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if(str_starts_with($contentType,'application/json')){
            $stream=fopen('php://input','rb');$raw=stream_get_contents($stream,16385);fclose($stream);
            if(strlen($raw)>16384)throw new RuntimeException('Túl nagy API-kérés.',413);
            try{$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException $e){throw new RuntimeException('Hibás JSON-kérés.',400);}
            if(!is_array($data) || !str_starts_with(ltrim($raw),'{'))throw new RuntimeException('JSON-objektum szükséges.',400);
            $result=$service->run($actor,(string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''),$data);
        }elseif(str_starts_with($contentType,'application/x-www-form-urlencoded')){
            if(($_POST['feladat'] ?? '')!=='szolg_letrehozas')throw new RuntimeException('Hibás rendelési feladat.',400);
            $result=$service->runOrder($actor,GameDock\ProvisionApiService::order($_POST));
        }else throw new RuntimeException('application/json vagy application/x-www-form-urlencoded tartalomtípus szükséges.',415);
    }
    http_response_code($result['status']);
    if(isset($result['text'])){header('Content-Type: text/plain; charset=UTF-8');echo $result['text'];}
    else{if($result['status']===202)header('Retry-After: 3');echo json_encode($result['json'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
}catch(Throwable $e){
    $status=$e instanceof RuntimeException && !$e instanceof PDOException && in_array($e->getCode(),[400,401,403,404,405,409,410,413,415],true) ? $e->getCode() : 500;
    http_response_code($status);
    if($status===401)header('WWW-Authenticate: Bearer');
    if($status===500)error_log('GameDock provisioning API: '.get_class($e));
    echo json_encode(['error'=>$status===500 ? 'Az API-művelet nem sikerült. Ellenőrizd a panel beállításait, a Linux-kapcsolatot és az adminfelületen a szerver állapotát. Azonos Idempotency-Key értékkel folytasd a kérést.' : $e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}
