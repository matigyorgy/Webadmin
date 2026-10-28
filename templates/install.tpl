<!doctype html>
<html lang="hu" data-bs-theme="dark">
<head>
 <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
 <title>GameDock · Telepítés</title>
 <link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css"><link rel="stylesheet" href="/style.css">
</head>
<body><main class="container py-4 py-md-5 installer-container">
 <header class="border-bottom pb-4 mb-4"><span class="brand-label text-success fw-semibold">GAMEDOCK / TELEPÍTŐ</span><h1 class="display-6 fw-bold mt-3">Néhány adat, és indulhat a panel.</h1><p class="text-body-secondary mb-0">Adatbázis, adminfiók és opcionálisan az első Linux-gép. Composer és kézi SQL-import nélkül.</p></header>
 {if $error}<div class="alert alert-danger" role="alert">{$error}</div>{/if}
 {if $success}
 <section class="card rounded-4"><div class="card-body p-4">
  <h2 class="h3 text-success">Sikeres telepítés!</h2><p>A táblák és az adminfiók létrejöttek, a konfigurációt elmentettük. A telepítő automatikusan lezárult.</p>
  <h3 class="h5 mt-4">A panel nyilvános SSH-kulcsa</h3><p class="text-body-secondary">Ezt a sort add a Linux-gépen a kiválasztott SSH-felhasználó <code>~/.ssh/authorized_keys</code> fájljához. A privát kulcs a szerveren marad.</p>
  <textarea class="form-control font-monospace mb-3" rows="5" readonly aria-label="Nyilvános SSH-kulcs">{$success.public_key}</textarea>
  <div class="alert alert-info">A panel telepítése kész. A Linux-gépen a kulcs engedélyezése, a systemd/sudoers és konzolhoz a tmux-segédprogram beállítása még szükséges. A telepítő nem telepíti magukat a játékokat.</div>
  <a class="btn btn-success" href="/">Tovább a bejelentkezéshez →</a>
 </div></section>
 {elseif $installed}
 <div class="alert alert-info">A panel már telepítve van. A telepítő le van zárva, a meglévő adatokat nem módosítja.</div><a class="btn btn-success" href="/">Bejelentkezés</a>
 {else}
 <section class="card rounded-4 mb-4"><div class="card-body p-4"><h2 class="h5 mb-3">Környezet ellenőrzése</h2><ul class="list-group list-group-flush">{foreach $requirements as $requirement}<li class="list-group-item bg-transparent d-flex justify-content-between gap-3"><span>{$requirement.label}</span><span class="badge {if $requirement.ok}text-bg-success{else}text-bg-danger{/if}">{if $requirement.ok}Rendben{else}Hiányzik{/if}</span></li>{/foreach}</ul></div></section>
 {if !$https}<div class="alert alert-warning">A jelszavak védelméhez nyilvános szerveren HTTPS-kapcsolaton nyisd meg a telepítőt.</div>{/if}
 <form method="post" action="/install.php">
  <input type="hidden" name="csrf" value="{$csrf}">
  <section class="card rounded-4 mb-4"><div class="card-body p-4"><h2 class="h4 mb-3">1. MySQL-adatbázis</h2><p class="text-body-secondary">A tárhelyen már létrehozott, üres adatbázis adatait add meg. A táblákat automatikusan létrehozzuk; más adatokat nem írunk felül.</p>
   <div class="row g-3">
    <div class="col-md-8"><label class="form-label" for="db_host">Adatbázis címe</label><input id="db_host" class="form-control" name="db_host" value="{$values.db_host}" required autocomplete="off"></div>
    <div class="col-md-4"><label class="form-label" for="db_port">Port</label><input id="db_port" class="form-control" name="db_port" type="number" min="1" max="65535" value="{$values.db_port}" required></div>
    <div class="col-md-6"><label class="form-label" for="db_name">Adatbázis neve</label><input id="db_name" class="form-control" name="db_name" value="{$values.db_name}" required maxlength="64"></div>
    <div class="col-md-6"><label class="form-label" for="db_user">Adatbázis-felhasználó</label><input id="db_user" class="form-control" name="db_user" value="{$values.db_user}" required autocomplete="off"></div>
    <div class="col-12"><label class="form-label" for="db_password">Adatbázis jelszava</label><input id="db_password" class="form-control" name="db_password" type="password" autocomplete="new-password"></div>
   </div>
  </div></section>
  <section class="card rounded-4 mb-4"><div class="card-body p-4"><h2 class="h4 mb-3">2. Első adminisztrátor</h2><div class="row g-3">
   <div class="col-12"><label class="form-label" for="admin_email">E-mail-cím</label><input id="admin_email" class="form-control" name="admin_email" type="email" maxlength="190" value="{$values.admin_email}" required autocomplete="username"></div>
   <div class="col-md-6"><label class="form-label" for="admin_password">Jelszó</label><input id="admin_password" class="form-control" name="admin_password" type="password" minlength="12" maxlength="72" required autocomplete="new-password"><div class="form-text">12–72 bájt, ékezetes karakter több bájt lehet.</div></div>
   <div class="col-md-6"><label class="form-label" for="admin_password_confirm">Jelszó megerősítése</label><input id="admin_password_confirm" class="form-control" name="admin_password_confirm" type="password" minlength="12" maxlength="72" required autocomplete="new-password"></div>
  </div></div></section>
  <section class="card rounded-4 mb-4"><div class="card-body p-4"><h2 class="h4 mb-3">3. Első Linux-gép <span class="badge text-bg-secondary fs-6">Opcionális</span></h2><p class="text-body-secondary">Ha később állítod be, a gép címét hagyd üresen. A panel automatikusan készít saját SSH-kulcspárt.</p><div class="row g-3">
   <div class="col-md-6"><label class="form-label" for="node_name">Gép neve</label><input id="node_name" class="form-control" name="node_name" maxlength="120" value="{$values.node_name}"></div>
   <div class="col-md-6"><label class="form-label" for="ssh_host">Gép IP-címe vagy domainneve</label><input id="ssh_host" class="form-control" name="ssh_host" value="{$values.ssh_host}" placeholder="games.example.com"></div>
   <div class="col-md-4"><label class="form-label" for="ssh_port">SSH-port</label><input id="ssh_port" class="form-control" name="ssh_port" type="number" min="1" max="65535" value="{$values.ssh_port}"></div>
   <div class="col-md-8"><label class="form-label" for="ssh_user">SSH-felhasználó</label><input id="ssh_user" class="form-control" name="ssh_user" value="{$values.ssh_user}"><div class="form-text">Külön, korlátozott felhasználó, például gamedock; ne root.</div></div>
   <div class="col-12"><label class="form-label" for="ssh_fingerprint">Ellenőrzött SSH hostkulcs-ujjlenyomat</label><input id="ssh_fingerprint" class="form-control font-monospace" name="ssh_fingerprint" value="{$values.ssh_fingerprint}" placeholder="SHA256:..."><div class="form-text">A Linux-gép megbízható konzolján: <code>ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256</code>. Az SSH által használt hostkulcs SHA256: kezdetű értékét másold ide.</div></div>
  </div></div></section>
  <section class="card rounded-4 mb-4"><div class="card-body p-4"><h2 class="h4 mb-3">4. Első játékszerver <span class="badge text-bg-secondary fs-6">Opcionális</span></h2><p class="text-body-secondary">Első szerverpéldányt rögzíthetsz az adminfiókhoz. A fájlok másolását az SSH-kulcs és a Linux másolási segédprogram beállítása után, az adminoldalról indíthatod. Az alapértelmezett forrás: /srv/gamedock/templates/játéktípus.</p><div class="row g-3">
   <div class="col-md-6"><label class="form-label" for="server_name">Szerver neve</label><input id="server_name" class="form-control" name="server_name" maxlength="120" value="{$values.server_name}" placeholder="Survival"></div>
   <div class="col-md-6"><label class="form-label" for="game">Játék</label><select id="game" class="form-select" name="game">{foreach ['minecraft'=>'Minecraft','cs2'=>'Counter-Strike 2','fivem'=>'FiveM','css'=>'Counter-Strike: Source'] as $value=>$label}<option value="{$value}"{if $values.game == $value} selected{/if}>{$label}</option>{/foreach}</select></div>
   <div class="col-12"><label class="form-label" for="unit_name">Systemd-szolgáltatás neve</label><input id="unit_name" class="form-control" name="unit_name" maxlength="120" value="{$values.unit_name}"></div>
  </div></div></section>
  <p class="small text-body-secondary">A telepítő az adatbázisban táblákat hoz létre, adminfiókot készít, és elmenti a konfigurációt. Siker után automatikusan lezárul.</p>
  <button class="btn btn-success btn-lg w-100" type="submit">Telepítés indítása →</button>
 </form>
 {/if}
 <footer class="border-top mt-5 pt-4 small text-body-secondary">GameDock · Webes telepítő</footer>
</main></body></html>
