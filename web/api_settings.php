<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
GameDock\WebSession::start();
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
$db=new GameDock\Database();$auth=new GameDock\AuthService($db);$user=$auth->current();
if(!$user){header('Location: /',true,303);exit;}
$view=new GameDock\SmartyView(dirname(__DIR__));
if(!(int)$user['is_admin']){http_response_code(403);$view->render('forbidden.tpl',['user'=>$user,'csrf'=>$_SESSION['csrf'] ?? '']);exit;}
$_SESSION['csrf'] ??=bin2hex(random_bytes(32));
$token='';$error='';$notice='';
try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $auth->csrf();
        if(($_POST['action'] ?? '')==='rotate'){
            GameDock\ProvisionApiService::install($db);
            $token=GameDock\ProvisionApiCredentials::rotate((int)$user['id']);
            $notice='Az új API-kulcs elkészült. Most másold ki, később nem jelenítjük meg. A korábbi kulcs érvénytelen.';
        }elseif(($_POST['action'] ?? '')==='revoke'){
            GameDock\ProvisionApiCredentials::revoke();$notice='Az API-kulcsot visszavontuk.';
        }else throw new RuntimeException('Ismeretlen művelet.');
    }
}catch(Throwable $e){$error=$e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Az API-beállítás nem sikerült. A config mappa írásához és a provision_api_requests tábla létrehozásához jogosultság szükséges.';}
$view->render('api-settings.tpl',['user'=>$user,'csrf'=>$_SESSION['csrf'],'page'=>'api','error'=>$error,'notice'=>$notice,'token'=>$token,'configured'=>GameDock\ProvisionApiCredentials::configured(),'servers'=>[],'server_count'=>0]);
