<?php
declare(strict_types=1);
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly'=>true,'secure'=>getenv('COOKIE_SECURE') !== '0','samesite'=>'Strict']);
session_start();
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');
function esc(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function db(): PDO {
 static $db;
 return $db ??= new PDO(getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=webadmin;charset=utf8mb4', getenv('DB_USER') ?: 'webadmin', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function query(string $sql, array $params=[]): PDOStatement { $q=db()->prepare($sql); $q->execute($params); return $q; }
function agent(array $server, string $action): array {
 if (!preg_match('~^https://~', $server['url'])) throw new RuntimeException('HTTPS szükséges');
 $ch=curl_init(rtrim($server['url'],'/').'/v1/action');
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['unit'=>$server['unit_name'],'action'=>$action],JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$server['token']],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
 $result=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
 if ($result===false || $code!==200) throw new RuntimeException('Az ügynök nem elérhető vagy elutasította a kérést.');
 return json_decode($result,true,512,JSON_THROW_ON_ERROR);
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error=''; $notice='';
try {
 $user=isset($_SESSION['uid']) ? query('SELECT id,email,is_admin FROM users WHERE id=?',[$_SESSION['uid']])->fetch() : false;
 if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf']??''))) throw new RuntimeException('Érvénytelen űrlap.');
  $action=(string)($_POST['action']??'');
  if ($action==='login') {
   $account=query('SELECT * FROM users WHERE email=?',[trim((string)($_POST['email']??''))])->fetch();
   if (!$account || !password_verify((string)($_POST['password']??''),$account['password_hash'])) { usleep(500000); throw new RuntimeException('Hibás bejelentkezési adatok.'); }
   session_regenerate_id(true); $_SESSION['uid']=$account['id']; $_SESSION['csrf']=bin2hex(random_bytes(32)); header('Location: /'); exit;
  }
  if (!$user) throw new RuntimeException('Jelentkezz be.');
  if ($action==='logout') { $_SESSION=[]; session_destroy(); header('Location: /'); exit; }
  if (!in_array($action,['start','stop','restart'],true)) throw new RuntimeException('Ismeretlen művelet.');
  $server=query('SELECT s.*,n.url,n.token FROM servers s JOIN nodes n ON n.id=s.node_id WHERE s.id=? AND (s.user_id=? OR ?=1)',[(int)($_POST['server_id']??0),$user['id'],(int)$user['is_admin']])->fetch();
  if (!$server) throw new RuntimeException('A szerver nem érhető el.');
  $audit=query('INSERT INTO audit_log(user_id,server_id,action,outcome) VALUES(?,?,?,?)',[$user['id'],$server['id'],$action,'requested']);
  $auditId=db()->lastInsertId();
  try { agent($server,$action); query('UPDATE audit_log SET outcome=? WHERE id=?',['accepted',$auditId]); $notice='A műveletet a Linux-gép elfogadta. Az állapotot a frissítés után ellenőrizd.'; }
  catch (Throwable $e) { query('UPDATE audit_log SET outcome=? WHERE id=?',['failed',$auditId]); throw $e; }
 }
 $servers=$user ? query('SELECT s.*,n.name AS node_name,n.url,n.token FROM servers s JOIN nodes n ON n.id=s.node_id WHERE s.user_id=? OR ?=1 ORDER BY s.name',[$user['id'],(int)$user['is_admin']])->fetchAll() : [];
} catch (Throwable $e) { $error=$e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Adatbázis- vagy szolgáltatáshiba. Ellenőrizd a konfigurációt.'; }
function csrf(): void { echo '<input type="hidden" name="csrf" value="'.esc($_SESSION['csrf']).'">'; }
?>
<!doctype html>
<html lang="hu" data-bs-theme="dark">
<head>
 <meta charset="utf-8">
 <meta name="viewport" content="width=device-width, initial-scale=1">
 <title>GameDock • Szerverkezelő</title>
 <link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css">
 <link rel="stylesheet" href="/style.css">
</head>
<body>
<main class="container py-4 py-md-5">
 <header class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-4 border-bottom pb-4 mb-4">
  <div>
   <span class="brand-label text-success fw-semibold">GAMEDOCK / CONTROL PANEL</span>
   <h1 class="display-6 fw-bold mt-3">Játékszervereid egy helyen.</h1>
   <p class="text-body-secondary mb-0">Minecraft · Counter-Strike 2 · FiveM · Counter-Strike: Source</p>
  </div>
  <?php if (!empty($user)): ?>
  <form method="post" class="d-flex flex-wrap align-items-center gap-3">
   <?php csrf(); ?>
   <span class="small text-body-secondary text-break"><?=esc($user['email'])?></span>
   <button class="btn btn-outline-secondary" name="action" value="logout">Kilépés</button>
  </form>
  <?php endif; ?>
 </header>
 <?php if ($error): ?><div class="alert alert-danger" role="alert"><?=esc($error)?></div><?php endif; ?>
 <?php if ($notice): ?><div class="alert alert-success" role="status"><?=esc($notice)?></div><?php endif; ?>
 <?php if (empty($user)): ?>
 <div class="row justify-content-center py-4 py-md-5">
  <div class="col-12 col-md-8 col-lg-5">
   <section class="card shadow-sm rounded-4">
    <div class="card-body p-4 p-md-5">
     <h2 class="h3 fw-bold">Üdv újra!</h2>
     <p class="text-body-secondary mb-4">Jelentkezz be a szervereid kezeléséhez.</p>
     <form method="post">
      <?php csrf(); ?>
      <div class="mb-3"><label class="form-label" for="email">E-mail</label><input class="form-control form-control-lg" id="email" type="email" name="email" autocomplete="username" required maxlength="190"></div>
      <div class="mb-4"><label class="form-label" for="password">Jelszó</label><input class="form-control form-control-lg" id="password" type="password" name="password" autocomplete="current-password" required></div>
      <button name="action" value="login" class="btn btn-success btn-lg w-100">Bejelentkezés →</button>
     </form>
    </div>
   </section>
  </div>
 </div>
 <?php else: ?>
 <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 my-4">
  <h2 class="h4 mb-0"><?=!empty($user['is_admin'])?'Összes szerver':'Saját szervereim'?> <span class="badge text-bg-secondary rounded-pill ms-2"><?=count($servers??[])?></span></h2>
  <a class="btn btn-outline-success" href="/">Állapot frissítése ↻</a>
 </div>
 <div class="row g-4">
 <?php foreach ($servers??[] as $s):
  $state='ismeretlen';
  try { $status=agent($s,'status'); $state=$status['state']??'ismeretlen'; } catch (Throwable $e) {}
  $gameNames=['minecraft'=>'Minecraft','cs2'=>'Counter-Strike 2','fivem'=>'FiveM','css'=>'Counter-Strike: Source'];
 ?>
  <div class="col-12 col-md-6 col-xl-4">
   <article class="card h-100 shadow-sm rounded-4">
    <div class="card-body p-4 d-flex flex-column">
     <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
      <span class="small text-success fw-semibold"><?=esc($gameNames[$s['game']]??$s['game'])?></span>
      <span class="badge rounded-pill <?= $state==='active'?'text-bg-success':'text-bg-secondary' ?>"><?=esc($state)?></span>
     </div>
     <h3 class="h4 text-break"><?=esc($s['name'])?></h3>
     <p class="text-body-secondary text-break mb-4"><?=esc($s['node_name'])?></p>
     <form method="post" class="d-flex flex-wrap gap-2 mt-auto">
      <?php csrf(); ?>
      <input type="hidden" name="server_id" value="<?=esc($s['id'])?>">
      <button name="action" value="start" class="btn btn-success">Indítás</button>
      <button name="action" value="restart" class="btn btn-outline-secondary">Újraindítás</button>
      <button name="action" value="stop" class="btn btn-outline-danger">Leállítás</button>
     </form>
    </div>
   </article>
  </div>
 <?php endforeach; ?>
 </div>
 <?php if (empty($servers)): ?>
 <section class="card rounded-4 text-center"><div class="card-body py-5 px-4"><h2 class="h4">Még nincs hozzárendelt szerver.</h2><p class="text-body-secondary mb-0">Az adminisztrátor rendelhet játékszervereket a fiókodhoz.</p></div></section>
 <?php endif; endif; ?>
 <footer class="border-top mt-5 pt-4 small text-body-secondary">GameDock · Linux szerverkezelés</footer>
</main>
</body>
</html>
