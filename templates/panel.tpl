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
  {if $user}
  <form method="post" class="d-flex flex-wrap align-items-center gap-3">
   {include file='partials/csrf.tpl'}
   <span class="small text-body-secondary text-break">{$user.email}</span>
   <button class="btn btn-outline-secondary" name="action" value="logout">Kilépés</button>
  </form>
  {/if}
 </header>
 {if $error}<div class="alert alert-danger" role="alert">{$error}</div>{/if}
 {if $notice}<div class="alert alert-success" role="status">{$notice}</div>{/if}
 {if !$user}
 <div class="row justify-content-center py-4 py-md-5">
  <div class="col-12 col-md-8 col-lg-5">
   <section class="card shadow-sm rounded-4">
    <div class="card-body p-4 p-md-5">
     <h2 class="h3 fw-bold">Üdv újra!</h2>
     <p class="text-body-secondary mb-4">Jelentkezz be a szervereid kezeléséhez.</p>
     <form method="post">
      {include file='partials/csrf.tpl'}
      <div class="mb-3"><label class="form-label" for="email">E-mail</label><input class="form-control form-control-lg" id="email" type="email" name="email" autocomplete="username" required maxlength="190"></div>
      <div class="mb-4"><label class="form-label" for="password">Jelszó</label><input class="form-control form-control-lg" id="password" type="password" name="password" autocomplete="current-password" required></div>
      <button name="action" value="login" class="btn btn-success btn-lg w-100">Bejelentkezés →</button>
     </form>
    </div>
   </section>
  </div>
 </div>
 {else}
 <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 my-4">
  <h2 class="h4 mb-0">{if $user.is_admin}Összes szerver{else}Saját szervereim{/if} <span class="badge text-bg-secondary rounded-pill ms-2">{$server_count}</span></h2>
  <a class="btn btn-outline-success" href="/">Állapot frissítése ↻</a>
 </div>
 <div class="row g-4">
 {foreach $servers as $server}
  <div class="col-12 col-md-6 col-xl-4">
   <article class="card h-100 shadow-sm rounded-4">
    <div class="card-body p-4 d-flex flex-column">
     <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
      <span class="small text-success fw-semibold">{$server.game_name}</span>
      <span class="badge rounded-pill {if $server.state == 'active'}text-bg-success{else}text-bg-secondary{/if}">{$server.state}</span>
     </div>
     <h3 class="h4 text-break">{$server.name}</h3>
     <p class="text-body-secondary text-break mb-4">{$server.node_name}</p>
     <form method="post" class="d-flex flex-wrap gap-2 mt-auto">
      {include file='partials/csrf.tpl'}
      <input type="hidden" name="server_id" value="{$server.id}">
      <button name="action" value="start" class="btn btn-success">Indítás</button>
      <button name="action" value="restart" class="btn btn-outline-secondary">Újraindítás</button>
      <button name="action" value="stop" class="btn btn-outline-danger">Leállítás</button>
      <button name="action" value="logs" class="btn btn-outline-info">Konzol</button>
     </form>
    </div>
   </article>
  </div>
 {/foreach}
 </div>
 {if !$servers}
 <section class="card rounded-4 text-center"><div class="card-body py-5 px-4"><h2 class="h4">Még nincs hozzárendelt szerver.</h2><p class="text-body-secondary mb-0">Az adminisztrátor rendelhet játékszervereket a fiókodhoz.</p></div></section>
 {/if}{/if}
 {if $console}
 <section class="card rounded-4 mt-4" aria-labelledby="console-title">
  <div class="card-body p-4">
   <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <h2 id="console-title" class="h4 mb-0">Konzol · {$console.name}</h2>
    <form method="post">{include file='partials/csrf.tpl'}<input type="hidden" name="server_id" value="{$console.id}"><button class="btn btn-outline-info" name="action" value="logs">Napló frissítése</button></form>
   </div>
   <pre class="console-output bg-black text-light p-3 rounded" tabindex="0" aria-label="Szervernapló">{$console.logs}</pre>
   <form method="post" class="row g-2 align-items-end">
    {include file='partials/csrf.tpl'}<input type="hidden" name="server_id" value="{$console.id}">
    <div class="col-12 col-md"><label class="form-label" for="console-command">Játékkonzol-parancs</label><input id="console-command" class="form-control font-monospace" name="command" required maxlength="500" autocomplete="off" aria-describedby="console-help"></div>
    <div class="col-12 col-md-auto"><button class="btn btn-success" name="action" value="command">Küldés</button></div>
    <p id="console-help" class="small text-body-secondary mb-0">Játékparancsot adj meg, például: status vagy list. A konzolhoz a Linux-gépen tmux-beállítás szükséges.</p>
   </form>
  </div>
 </section>
 {/if}
 <footer class="border-top mt-5 pt-4 small text-body-secondary">GameDock · Linux szerverkezelés</footer>
</main>
</body>
</html>
