{extends file='layout.tpl'}
{block name='content'}
<div class="page-heading"><h1>Szerverlétrehozási API</h1></div>
{if $error}<div class="alert alert-danger">{$error}</div>{/if}
{if $notice}<div class="alert alert-success">{$notice}</div>{/if}
<section class="dashboard-panel p-3">
<p>Végpont: <code>/provision_api.php</code> · Rendelés: <strong>GET query mezőkkel vagy POST</strong>.</p>
<p>Az API-kulcs jogosultsága szerver létrehozására és az API-val indított létrehozás állapotának lekérdezésére terjed ki. Csak megbízható kiszolgálóoldali integrációban használd HTTPS-en, böngészőbe ne építsd be.</p>
<p>Hitelesítés: <code>Authorization: Bearer API_KULCS</code><br>Ismétlésvédelem: <code>Idempotency-Key: EGYEDI_RENDELES_AZONOSITO</code></p>
<p>Állapot: {if $configured}<strong>Engedélyezve</strong>{else}<strong>Kikapcsolva</strong>{/if}</p>
{if $token}<label class="form-label" for="api-token">Új API-kulcs (egyszer látható)</label><input id="api-token" class="form-control mb-3" readonly value="{$token}" autocomplete="off">{/if}
<form method="post"><input type="hidden" name="csrf" value="{$csrf}"><button class="btn btn-primary" name="action" value="rotate">{if $configured}Új kulcs létrehozása, régi visszavonása{else}API-kulcs létrehozása{/if}</button> {if $configured}<button class="btn btn-outline-danger" name="action" value="revoke">API kikapcsolása</button>{/if}</form>
</section>
<section class="dashboard-panel p-3 mt-3"><h2>Példa kérés</h2>
<p>A régi rendelési rendszer GET URL-paramétereket küldhet az alábbi mezőkkel. A <code>key</code> az API-kulcs; <code>premium=true</code> kizárólag a Prémiumként megjelölt Linux-gépekre irányít. A GET címsor tartalma bekerülhet proxy-, webszerver- és böngészőnaplókba; ha a rendelési rendszer támogatja, a POST törzset és Authorization fejlécet használd.</p>
<pre>GET /provision_api.php?key=API-KULCS&amp;premium=true&amp;slot=10&amp;hdd=100&amp;type=premium&amp;memory=4096&amp;version=vanilla_26.1.2&amp;szolg_type=premium_minecraft&amp;feladat=szolg_letrehozas&amp;lejar=1893456000&amp;email_cim=ugyfel%40pelda.hu&amp;felhasznalonev=ugyfel123&amp;jelszo=JELSZO</pre>
<p>A <code>hdd</code> GB-ban, a memória MiB-ban értendő. A lejárat Unix időbélyeg; a gép percenkénti ellenőrzése leállítja a lejárt szervert, és többé nem engedi indítani. A <code>rendeles_id</code> opcionális; nélküle azonos rendelési adatokból készül ismétlésvédelmi kulcs. A <code>version</code> Minecraftnál szolgáltató_verzió formátumú, és a megfelelő már telepített template szükséges. Más játékhoz a <code>szolg_type</code> legyen a játéktípus azonosítója, például <code>cs2</code>, <code>css</code> vagy <code>fivem</code>.</p>
<pre>{literal}{"action":"create","user_id":1731,"node_id":1,"game":"cs2","memory_limit_mb":4096,"cpu_limit_percent":200}{/literal}</pre>
<p>A <code>user_id</code> a meglévő ügyfél számos adatbázis-azonosítója, a <code>node_id</code> a kiválasztott Linux-gép adatbázis-azonosítója, a <code>game</code> az adminban megadott játéktípus azonosítója. A template mappát és az indítóparancsot alapból a játéktípusból veszi át. Minecraftnál a letöltött <code>minecraft_template</code> kulcsot is add meg.</p>
<p>A Linux-gép neve például <code>node_s1</code> legyen: csak betű, szám, pont, aláhúzás és kötőjel használható az API-válaszban.</p>
<p><strong>202:</strong> a Linux-oldali másolás folyamatban. Ugyanazzal az <code>Idempotency-Key</code> fejléccel küldj <code>{literal}{"action":"status"}{/literal}</code> kérést 3 másodpercenként. <strong>201:</strong> a szerver elkészült, a válasz egyszerű szöveg:</p>
<pre>FELH_ID:1731|SZOLG_ID:15366|NODE:node_s1|</pre>
<p>A SZOLG_ID a panel Szerverazonosító mezőjében szereplő szám. A siker a fájlok elkészültét jelenti, a játékot nem indítja el automatikusan. Megszakadt kérés ismétlésekor mindig azonos kulcsot és paramétereket használj; új rendeléshez új kulcs kell.</p>
<a href="/admin.php" class="btn btn-outline-secondary">Vissza az adminfelületre</a></section>
{/block}
