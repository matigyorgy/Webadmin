# GameDock

PHP 8.2 + MySQL alapú játékszerver-kezelő kezdeti változata. Minecraft, Counter-Strike 2, FiveM és Counter-Strike: Source már telepített, systemd által kezelt példányaihoz. Egy felhasználóhoz több szerver és több Linux-gép tartozhat. Az admin minden szervert lát, a többi felhasználó csak a sajátját. Nincs szükség Composer-csomagokra.

## Webalkalmazás

Követelmény: PHP 8.2, pdo_mysql és curl PHP-bővítmény, MySQL 8, éles használathoz HTTPS és PHP-FPM. A webszerver dokumentumgyökere kizárólag a `public` könyvtár legyen. A környezeti változókat a szolgáltatás konfigurációjában kell megadni; az alkalmazás nem olvas automatikusan .env fájlt.

1. Hozz létre `webadmin` adatbázist és külön adatbázis-felhasználót. Importáld egyszer a `database/schema.sql` fájlt az üres adatbázisba. A séma importálása nem ismételhető migráció.
2. Állítsd be: `DB_DSN=mysql:host=127.0.0.1;dbname=webadmin;charset=utf8mb4`, `DB_USER`, `DB_PASSWORD`.
3. Biztonságos terminálban olvasd be az első fiók jelszavát (`read -rs NEW_USER_PASSWORD`, majd `export NEW_USER_PASSWORD`), és futtasd: `php bin/create-user.php admin@example.com --admin`. Ezután `unset NEW_USER_PASSWORD`. Ugyanezzel a paranccsal, `--admin` nélkül készül normál fiók.
4. Helyi fejlesztés: a tároló gyökerében `COOKIE_SECURE=0 php -S 127.0.0.1:8080 -t public`. A COOKIE_SECURE=0 kizárólag helyi HTTP-fejlesztéshez való. Élesben maradjon az alapértelmezett biztonságos süti.

A csomópontok és szerverek felvétele egyelőre paraméterezett SQL-lel, adminisztrátori adatbáziseszközből történik. A `nodes` mezői: name, url (HTTPS eredet), token (az ügynök titka). A `servers` mezői: user_id, node_id, name, game (`minecraft`, `cs2`, `fivem`, `css`), unit_name (pl. `minecraft-survival.service`). Ne tárolj titkokat a Gitben. A nodes táblában lévő tokenek miatt az adatbázist és mentéseit korlátozottan kezeld.

## Linux-ügynök

Python 3 standard könyvtár, systemd és sudo szükséges a játékszerver-gépen. Minden játékpéldánynak legyen saját, helyesen konfigurált systemd unitja és jogosultságában korlátozott Linux-felhasználója. A játékok telepítése és licenceinek elfogadása külön üzemeltetési lépés.

1. Az ügynököt külön, jogosultságában korlátozott felhasználóval futtasd.
2. Az `AGENT_TOKEN` titkos környezeti változó legyen legalább 32 karakteres, véletlen érték. Az `AGENT_UNITS` vesszővel elválasztott, pontos unitneveket tartalmazzon, például `minecraft-survival.service,cs2-public.service`. Ne használj wildcardot.
3. A sudoers fájlt `visudo` segítségével állítsd be: kizárólag a felsorolt unitok pontos `/usr/bin/systemctl start ...`, `stop ...`, `restart ...` parancsai legyenek NOPASSWD módon engedélyezve. Ne engedélyezz általános systemctl vagy shell-hozzáférést.
4. Futtasd: `python3 agent/agent.py`. Az ügynök csak 127.0.0.1:9081 címen figyel. Rendszerszolgáltatásként indítsd újraindítás után is.
5. Helyezz elé hiteles tanúsítvánnyal működő HTTPS reverse proxyt; a `/v1/action` útvonalat továbbítsa a loopback portra. Tűzfallal korlátozd a webpanel gépére. A PHP TLS-ellenőrzése maradjon bekapcsolva.

Az API kizárólag POST kérést fogad, `Authorization: Bearer ...` fejléccel és `{"unit":"minecraft-survival.service","action":"status"}` JSON-törzzsel. Műveletek: status/start/stop/restart. A sikeres vezérlési válasz a systemctl parancs sikerét jelenti; utána külön ellenőrizd az állapotot és a játék tényleges elérhetőségét.

## Ellenőrzés és korlátok

Futtasd: `php -l public/index.php`, `php -l bin/create-user.php`, `python3 -m py_compile agent/agent.py`. Teljes integrációs ellenőrzéshez MySQL, PHP curl/pdo_mysql és egy HTTPS mögötti Linux-ügynök kell. Jelentkezz be két normál fiókkal, és ellenőrizd, hogy egymás szerverazonosítójával nem vezérelhetnek szervert; ellenőrizd az indítás/leállítás/újraindítás műveleteket és az audit_log rekordjait.

Ez kezdeti implementáció, nem éles használatra kész termék. Még nincs webes felhasználó-/csomópont-/szerveradminisztráció, játéktelepítés, konzol, fájlkezelés, mérőszám, jelszó-visszaállítás vagy bejelentkezési próbálkozásokat korlátozó védelem. Éles bevezetés előtt ezekről és a biztonsági felülvizsgálatról gondoskodni kell. Az állapot lekérdezése szinkron, sok elérhetetlen csomópont esetén az oldal lassulhat.

## Felület

Bootstrap 5.3.8 (az npm latest kiadása a beépítéskor), helyben kiszolgált CSS-sel a `public/vendor/bootstrap` könyvtárban. A letöltött hivatalos npm-csomag SHA-512 integritása ellenőrizve. A licenc ugyanebben a könyvtárban található. A használt komponensekhez nincs szükség JavaScriptre vagy külső CDN-kapcsolatra.
