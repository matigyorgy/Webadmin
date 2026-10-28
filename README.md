# Egyszerű webes telepítés

1. Töltsd le a GitHub ZIP-et, és csomagold ki a tárhelyre. PHP 8.2+ és a **pdo_mysql** bővítmény szükséges; Composer nem kell.
2. A domain dokumentumgyökere a projekt **web/** könyvtára legyen. A **config/** és **var/** könyvtár a PHP-folyamat számára írható legyen; ne használj 777 jogosultságot. Nyilvános szerveren HTTPS-t használj, és a frissen feltöltött telepítőt csak te érhesd el a telepítés befejezéséig.
3. Nyisd meg a domaint: automatikusan a webes telepítőre irányít. Add meg az előzetesen létrehozott, **üres** MySQL-adatbázis címét, portját, nevét, felhasználóját, jelszavát, valamint az első admin e-mail-címét és jelszavát. Opcionálisan megadhatod az első Linux-gépet és a már telepített játékszerverét is.
4. Kattints a **Telepítés indítása** gombra. A táblák, adminfiók, SSH-kulcspár és a helyi konfiguráció automatikusan elkészülnek. Nem kell SQL-t importálni, környezeti változókat megadni vagy parancssorban adminfiókot készíteni. Siker után a telepítő lezárul.
5. Másold a sikeroldalon megjelenő nyilvános SSH-kulcsot a Linux-gép kijelölt SSH-felhasználójának `~/.ssh/authorized_keys` fájljába. A nyilvános kulcs később a `config/ssh-public.pub` fájlból is kiolvasható. A privát kulcsot ne másold és ne tedd a webgyökérbe.

A MySQL-felhasználónak CREATE, SELECT, INSERT, UPDATE, DELETE, valamint sikertelen telepítés visszavonásához DROP jogosultság kell az adott adatbázison. Az adatbázist és felhasználót a tárhely kezelőfelületén kell előre létrehozni. Meglévő táblákat a telepítő nem ír felül; korábbi telepítés frissítéséhez ne futtasd újra.

A konfiguráció a `config/local.json` fájlba kerül, 0600 jogosultsággal, a publikus könyvtáron kívül. Az SSH privát kulcs ugyanitt található, szintén korlátozott jogosultsággal. A környezeti változók továbbra is felülírják a fájl értékeit. A konfigurációt a PHP-folyamat felhasználójának kell tudnia olvasni. A `config/` könyvtárról biztonságos, nem nyilvános mentést készíts.

A `config/local.json` jelenléte lezárja a telepítőt; külön telepítőzárat nem kell kézzel törölni. A párhuzamos telepítéseket fájlzár akadályozza meg. Normál hiba esetén a telepítő csak az adott próbálkozás során létrehozott táblákat és kulcsokat takarítja el. Folyamatleállás vagy adatbázis-kapcsolatszakadás után maradhatnak részleges táblák: ilyenkor csak a telepítéshez fenntartott üres adatbázist állítsd helyre, más adatokat ne törölj.

A webes telepítő **a panelt** állítja be. A játékszerverek telepítése, az SSH-kulcs engedélyezése, a systemd/sudoers és konzolhoz a tmux-segédprogram beállítása a Linux-gépen továbbra is szükséges; ezek lépései lejjebb találhatók. A beállított hostkulcs-ujjlenyomatot a telepítő nem ellenőrzi távoli kapcsolattal.

Reverse proxy mögötti HTTPS-nél a webszerver/PHP-FPM felé helyesen jelezni kell a HTTPS-t, vagy a `COOKIE_SECURE=1` környezeti változót kell beállítani. A kliens által küldött X-Forwarded-Proto fejlécben az alkalmazás önmagában nem bízik meg.

---

# GameDock — PHP + Smarty + SSH2

Kezdeti, objektumorientált PHP 8.2+ / MySQL 8 játékszerver-kezelő. Játékok: Minecraft, Counter-Strike 2, FiveM, Counter-Strike: Source. Felhasználónként több szerver, több Linux-gép; az admin minden szervert lát. Bootstrap 5.3.8, helyben kiszolgálva.

## Kézi telepítés (alternatíva a webes telepítő helyett)

1. PHP 8.2+, pdo_mysql és mbstring szükséges. Composer nem kell.
2. Töltsd le és csomagold ki a teljes projektet. A `lib/` könyvtár már tartalmazza a szükséges Smarty és SSH2 könyvtárakat; nincs függőségtelepítési lépés.
3. Hozz létre MySQL adatbázist és külön felhasználót. Üres adatbázisba egyszer importáld a `database/schema.sql` fájlt. Korábbi HTTP-ügynökös telepítésnél mentés után a `database/migrate-ssh.sql` fájlt használd helyette, majd töltsd ki az új SSH mezőket minden csomóponthoz.
4. A PHP folyamat környezetében add meg: `DB_DSN=mysql:host=localhost;dbname=webadmin;charset=utf8mb4`, `DB_USER`, `DB_PASSWORD`, `SSH_PRIVATE_KEY_PATH` (abszolút útvonal a webgyökéren kívül), opcionálisan `SSH_KEY_PASSPHRASE`. Nincs automatikus .env-betöltés. Titkokat ne tegyél Gitbe.
5. Első admin: biztonságos terminálban `read -rs NEW_USER_PASSWORD`, majd `export NEW_USER_PASSWORD`, aztán `php bin/create-user.php admin@example.com --admin`. Végül `unset NEW_USER_PASSWORD`. Legalább 12 karakteres jelszó kell. Normál felhasználóhoz hagyd el a `--admin` kapcsolót.
6. A `var/smarty/compile` könyvtár legyen a PHP-FPM felhasználójának írható, de ne legyen 777. A dokumentumgyökér kizárólag `web/`. Élesben HTTPS és PHP-FPM szükséges.
7. Helyi fejlesztés a projekt gyökeréből: `COOKIE_SECURE=0 php -S 127.0.0.1:8080 -t web`. A COOKIE_SECURE=0 csak helyi HTTP-hez való.

## SSH2 Linux-kapcsolat

A phpseclib 3 SSH2 protokollon kommunikál; nem igényli a PHP ssh2 kiterjesztését és nincs szükség HTTP-ügynökre. A korábbi HTTP-ügynököt az SSH2-kommunikáció váltotta fel.

- Hozz létre külön `gamedock` SSH-felhasználót minden Linux-gépen. A nyilvános kulcsát add az authorized_keys fájlhoz; a hozzáférést forrás-IP-vel, port-/agent-/X11-forwarding és PTY tiltásával korlátozd.
- A panel privát kulcsa a webgyökéren kívül legyen, kizárólag a PHP-szolgáltatás által olvasható. A jelenlegi verzió közös kulcsot használ a csomópontokhoz.
- Minden játékpéldány külön, korlátozott felhasználóval futó systemd-szolgáltatás legyen. Unitnév: csak betű, szám, kötőjel, aláhúzás, majd `.service`.
- `visudo` segítségével csak az adott unitok pontos parancsait engedélyezd. Például:

```sudoers
gamedock ALL=(root) NOPASSWD: /usr/bin/systemctl start minecraft-survival.service
gamedock ALL=(root) NOPASSWD: /usr/bin/systemctl stop minecraft-survival.service
gamedock ALL=(root) NOPASSWD: /usr/bin/systemctl restart minecraft-survival.service
```

Ne adj általános sudo vagy systemctl jogosultságot. Ellenőrizd a gépen a `/usr/bin/systemctl` és `/usr/bin/sudo` útvonalakat. A státuszlekérdezés sudo nélkül fut.

A hostkulcs SHA256 ujjlenyomatát a Linux-gép megbízható konzolján kérdezd le, például `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256`. Az SSH által kiválasztott hostkulcshoz tartozó teljes `SHA256:...` értéket kell rögzíteni. Ne bízz ellenőrizetlen ssh-keyscan eredményben. A panel még hitelesítés előtt ellenőrzi az ujjlenyomatot; eltéréskor megtagadja a kapcsolatot.

## Csomópont és szerver felvétele

Jelenleg adminisztrátori adatbáziseszközből történik, nem webes űrlapról:

```sql
INSERT INTO nodes (name, ssh_host, ssh_port, ssh_user, ssh_fingerprint)
VALUES ('Linux 1', 'games.example.com', 22, 'gamedock', 'SHA256:ELLENORZOTT_UJJLENYOMAT');

SELECT id, email FROM users;
SELECT id, name FROM nodes;

INSERT INTO servers (user_id, node_id, name, game, unit_name)
VALUES (1, 1, 'Survival', 'minecraft', 'minecraft-survival.service');
```

A game értékei: minecraft, cs2, fivem, css. Az SSH host és user megbízható adminisztrátori beállítás. A gép TCP/22 portja legyen a panelből elérhető; felhőkörnyezetben ehhez külön TCP-hálózati engedély kellhet.

## Felépítés

- `web/index.php`: alkalmazásindítás, session és HTTP-fejlécek.
- `src/PanelController.php`: kérések, hitelesítés, tulajdonosi ellenőrzés, auditálás.
- `src/Database.php`: PDO-adatelérés.
- `src/SshClient.php`: kulcsos SSH2, hostkulcs-ellenőrzés, korlátozott systemctl parancsok.
- `src/SmartyView.php`: Smarty, automatikus HTML-escape, oldalak gyorsítótárazása kikapcsolva.
- `templates/panel.tpl`, `templates/partials/csrf.tpl`: HTML / Smarty, PHP és hálózati hívások nélkül.
- `web/vendor/bootstrap`: Bootstrap CSS és licenc; `lib/`: mellékelt PHP-könyvtárak; `autoload.php`: saját osztálybetöltő.

## Ellenőrzés és korlátok

`php tests/templates.php` hat renderelési ellenőrzést futtat: login, CSRF, üres lista, admin fejléc, HTML-escape, szerverműveletek. A PHP-fájlokat `php -l` paranccsal ellenőrizd. A teljes integrációhoz MySQL és valódi SSH/systemd gép kell: két normál felhasználóval ellenőrizd az elszigetelést, majd a vezérlést, állapotot és audit_log rekordokat.

Nem éles használatra kész termék. Nincs még webes fiók-/csomópont-/szerveradminisztráció, játéktelepítés, fájlkezelés, mérőszám, jelszó-visszaállítás vagy login rate limit. Az állapot szinkron lekérdezése sok nem elérhető csomóponttal lassú lehet. Az elfogadott systemctl parancs nem bizonyítja a játék hálózati elérhetőségét. A környezetből végzett teljes integrációs teszt külön szükséges.

## Konzol és naplók

A szerverkártyák **Konzol** gombja az utolsó 150 systemd-journalsort jeleníti meg (legfeljebb 128 KiB). A napló kézzel frissül; ez nem folyamatos WebSocket-stream. Az oldal automatikusan HTML-escape-eli a naplót. A konzolparancs-küldés egyetlen, legfeljebb 500 bájtos sort fogad, CSRF- és tulajdonosi ellenőrzéssel. A művelet és eredménye auditálódik; a parancsszöveg nem kerül az auditba, mert titkot tartalmazhat.

A Linux-gépen rendszergazdaként:

```bash
apt install tmux python3
install -d -o root -g root -m 755 /etc/gamedock
install -o root -g root -m 755 linux/gamedock-console /usr/local/sbin/gamedock-console
install -o root -g root -m 644 linux/servers.example.json /etc/gamedock/servers.json
```

A `/etc/gamedock/servers.json` fájlban kizárólag a ténylegesen kezelt unitokat, nem root játékfelhasználókat és pontos tmux paneleket hagyd meg. A könyvtár és a segédprogram sem lehet a játék-/SSH-felhasználók által írható. A sudoers-ben unitonként engedélyezd a segédprogramot, például:

```sudoers
gamedock ALL=(root) NOPASSWD: /usr/local/sbin/gamedock-console minecraft-survival.service logs
gamedock ALL=(root) NOPASSWD: /usr/local/sbin/gamedock-console minecraft-survival.service command *
```

A parancsparamétert a segédprogram szigorúan dekódolja és validálja; nem futtat shellt. Az engedélyezési listát a root által kezelt JSON adja. Más unitokhoz külön sudoers-sorok kellenek. A játékparancsok magán a játékszerveren nagy jogosultságúak lehetnek, ezért a játékpluginokat és a játékfelhasználó fájlhozzáféréseit is korlátozd.

**Konzolbemenethez a játékot előre elkészített tmux munkamenetben kell futtatni**, ugyanazzal a Linux-felhasználóval, `-L gamedock` socketnévvel, és a JSON-ban megadott pontos `session:0.0` célponttal. A tmux panelben közvetlenül a játékfolyamat fusson; ne maradjon interaktív shell a játék kilépése után. Például a tmux `new-session -d -s minecraft-survival -- /usr/bin/java -jar server.jar nogui` közvetlen programindítás, ha a megfelelő felhasználóval, munkakönyvtárban és `-L gamedock` kapcsolóval fut. A meglévő systemd unitot ehhez az üzemeltetőnek kell illesztenie: a leállítás a tényleges játékfolyamatot is állítsa le, ne csak a tmux indítófolyamatát. A játék konzolkimenetét a journalba kell irányítani (például üzemeltető által beállított tmux pipe-pane segítségével), különben a konzolnézet csak a systemd naplót mutatja.

A tmux-/journald-integráció és a játékok telepítése nem automatikus. A segédprogram nem biztosít terminál-/shell-hozzáférést a webfelületen. Ha a játék nem tmux alatt fut, az indítás/leállítás és journald-napló továbbra is használható, a parancsküldés hibával leáll.

További ellenőrzések: a `php tests/templates.php` SSH-parancs-engedélyezési teszteket is futtat. A Linux-segédprogram tesztjei: `python3 -m unittest discover -s tests -p "test_*.py"` (mockolt rendszerparancsok).

## Composer nélküli kiadás

A telepítés és futtatás teljesen Composer nélkül történik. A mellékelt verziókat és upstream commitokat a `lib/dependencies.json` rögzíti, a licencek a csomagkönyvtárakban találhatók. A saját osztálybetöltő a projekt és a függőségek névtereit kezeli. A `lib/checksums.sha256` a beépített könyvtárfájlok ellenőrzőösszegeit tartalmazza: ellenőrzés a projekt gyökerében `sha256sum -c lib/checksums.sha256`. A függőségek biztonsági frissítéseit ezután a projekt új kiadásával kell telepíteni.

Frissítéskor a korábbi `vendor/` könyvtár már nem használt. Az adatbázis- és SSH-környezeti változók, a dokumentumgyökér és a Smarty írható fordítási könyvtára változatlan marad.

A mellékelt könyvtárak és az SSH-kulcsok betöltésének önálló tesztje: `php tests/dependencies.php` (hálózati kapcsolat nélkül).

## Webes telepítő tesztelése

`php tests/installer.php` a mezők ellenőrzését teszteli. A MySQL-integrációhoz add meg a `TEST_MYSQL_HOST` és `TEST_MYSQL_PASSWORD` környezeti változókat egy **kizárólag tesztelésre használt**, root felhasználóval elérhető MySQL-példányhoz. A teszt saját véletlen nevű adatbázist készít és eltakarít. A `php tests/templates.php` a telepítő űrlapját, zárolt állapotát és sikeroldalát is ellenőrzi.

A webes telepítési folyamat PHP 8.2 + MySQL 8.4 alatt ellenőrizve: űrlap, CSRF, hibás SQL-adatok, táblák létrehozása és hiba utáni visszavonása, admin/node/server felvétel, konfigurációs és kulcsjogosultságok, telepítőzár, első bejelentkezés. Valódi játékszerverhez SSH-kapcsolatot ezek a tesztek nem létesítenek.

## 500-as hiba diagnosztikája

A teljes legfrissebb ZIP-et töltsd fel, a `lib/` könyvtárral és a gyökérben lévő `bootstrap.php` fájllal együtt. Meglévő telepítés frissítésekor őrizd meg a saját `config/local.json`, `config/ssh-private.pem` és `config/ssh-public.pub` fájljaidat. A telepítőt ne futtasd újra a meglévő adatbázison.

A webes PHP-verziót a domainhez tartozó tárhelybeállításban ellenőrizd: PHP 8.2+ szükséges. A parancssori PHP verziója ettől eltérhet. A dokumentumgyökér a projekt `web/` könyvtára legyen, a teljes projekt többi könyvtára pedig egy szinttel feljebb maradjon.

Az indulás már olvasható üzenettel jelzi az alacsony PHP-verziót, hiányzó fő fájlokat, nem írható Smarty-könyvtárat, nem olvasható/hibás konfigurációt és nem működő PHP sessiont. Váratlan hibánál a válaszban hibaazonosító jelenik meg; ugyanaz az azonosító megtalálható a szerver PHP error_log naplójában a részletes okkal. A hibaoldal nem ír ki jelszót, privát kulcsot vagy stack trace-t. A webszerver által még a PHP indulása előtt visszaadott hibákat továbbra is a webszerver error_log naplójában kell keresni.

A minimális `/check.php` oldal autoload és adatbázis nélkül megmutatja a webes PHP-verziót, a pdo_mysql meglétét, a fő fájlok olvashatóságát és a könyvtárak írhatóságát. Jelszót és privát kulcsot nem olvas. Ha ez az oldal is 500-at ad, a hibát a PHP/webszerver tárhelybeállításaiban és error_log naplójában kell keresni.

### Ha a tárhely webgyökere `web/`

A projekt már `web/` könyvtárat tartalmaz. Ezt másold a tárhely azonos nevű `web/` könyvtárába. A projekt többi elemét a `web/` mellé, egy szinttel feljebb töltsd fel. Például:

```text
/home/www/dev.vgh.hu/
  autoload.php
  bootstrap.php
  src/
  lib/
  templates/
  database/
  config/
  var/
  web/                 ← a domain dokumentumgyökere
    index.php
    install.php
    check.php
    style.css
    vendor/bootstrap/
```

Ha a naplóban `Failed opening required '/home/www/dev.vgh.hu/autoload.php'` szerepel, a gyökérben lévő fájlokat is fel kell tölteni, nem elég csak a `web/` tartalma. A betöltési útvonalat ne irányítsd át egy véletlen másik autoload.php fájlra. A `lib/`, `config/` és más belső könyvtárakat ne tedd a nyilvános `web/` alá.
