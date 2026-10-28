<!doctype html>
<html lang="hu" data-bs-theme="dark">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{block name=title}GameDock · Szerverkezelő{/block}</title><link rel="stylesheet" href="/vendor/bootstrap/bootstrap.min.css"><link rel="stylesheet" href="/style.css"><script src="/panel.js" defer></script></head>
<body><main class="container py-4 py-md-5">
<header class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-4 border-bottom pb-4 mb-4">
 <a href="/" class="text-decoration-none"><span class="brand-label text-success fw-semibold">GAMEDOCK / CONTROL PANEL</span><h1 class="h2 text-body fw-bold mt-2 mb-0">Játékszervereid egy helyen.</h1></a>
 {if $user}<form method="post" action="/" class="d-flex flex-wrap align-items-center gap-3">{include file='partials/csrf.tpl'}<span class="small text-body-secondary text-break">{$user.email}</span><span class="badge text-bg-secondary">{if $user.is_admin}Admin{else}Ügyfél{/if}</span><button class="btn btn-outline-secondary" name="action" value="logout">Kilépés</button></form>{/if}
</header>
{if $user}<nav class="nav nav-pills gap-2 mb-4" aria-label="Főmenü"><a class="nav-link" href="/">Szervereim</a><a class="nav-link" href="/?page=history">Műveleti előzmények</a><a class="nav-link" href="/?page=account">Saját fiók</a>{if $user.is_admin}<a class="nav-link bg-success text-white" href="/admin.php">Adminisztráció</a>{/if}</nav>{/if}
{block name=content}{/block}
<footer class="border-top mt-5 pt-4 small text-body-secondary">GameDock · Minecraft · Counter-Strike 2 · FiveM · Counter-Strike: Source</footer>
</main></body></html>
