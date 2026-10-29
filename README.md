# GameDock

PHP 8.2+, MySQL, Smarty és SSH2 alapú, Composer nélkül telepíthető játékszerver-panel. Flat, sötét Bootstrap 5.3.8 felület, külön admin- és kliensmenüvel.

## Webes telepítés

1. Csomagold ki a teljes kiadást a tárhelyre. A domain dokumentumgyökere a `web/` könyvtár legyen. Az `autoload.php`, `bootstrap.php`, `src/`, `lib/`, `templates/`, `linux/`, `database/`, `config/`, `var/` a webgyökér mellett, egy szinttel feljebb maradjon.
2. A PHP-folyamat olvashassa a projektet, és írhassa a `config/`, `var/` könyvtárakat. PHP 8.2+ és `pdo_mysql` szükséges; ne adj 777 jogosultságot.
3. Nyisd meg a domaint. A telepítő bekéri az üres MySQL-adatbázis adatait és az első adminfiókot. Létrehozza a táblákat, az alap játéktípusokat, az adminfiókot és a panel SSH-kulcspárját. Nem kell SQL-t importálni vagy Composert futtatni.
4. A telepítő siker után lezárul. Admin: `/admin.php`, kliens: `/`. A konfiguráció és privát kulcs a nem publikus `config/` könyvtárba kerül, 0600 jogosultsággal. Környezeti változó továbbra is felülírhatja a konfigurációt.

Példa a tárhelyre:

```text
/home/www/dev.vgh.hu/
  autoload.php
  bootstrap.php
  src/ lib/ templates/ linux/ database/ config/ var/
  web/
    index.php install.php admin.php api.php admin_api.php check.php
    style.css panel.js vendor/bootstrap/
```

## Linux-gép automatikus beállítása a weben

Az admin Linux-gépek menüjében add meg a címet, SSH-portot és a panel használatára szánt fiókot (alapból `gamedock`). A **Hostkulcs lekérése** gomb megadja az ujjlenyomatot; első alkalommal vesd össze a szolgáltató megbízható adataival. Mentsd a gépet, nyisd meg a szerkesztést, majd az **Automatikus Linux-telepítés** résznél add meg egyszer a telepítési SSH-hozzáférést.

- A telepítési fiók root vagy jelszó nélküli sudo-jogú admin legyen. Jelszóval vagy privát kulccsal hitelesíthető; a privát kulcs passphrase is megadható.
- A telepítési jelszó/privát kulcs nem kerül adatbázisba, szerveroldali munkamenetbe vagy mentett konfigurációba. A folyamat állapotkéréseihez a böngésző memóriájában marad, majd törlődik. Ha bezárod a lapot, a háttérfeladat folytatódik; állapotának folytatásához ismét megadható a hozzáférés.
- A telepítő ellenőrzi a hostkulcsot, SFTP-n feltölti és visszaolvasással ellenőrzi a segédprogramokat, beállítja a panel nyilvános kulcsát és a szükséges korlátozott sudo-jogokat.
- Aktív UFW esetén a 25000–25999 TCP/UDP tartomány megnyitható a telepítési jelölőnégyzettel. Külső szolgáltatói tűzfalhoz a tárhely kezelőfelületén kell hozzáférés; azt a panel nem írja át.
- Debian/Ubuntu alatt bekapcsolható a szükséges csomagok (Python, sudo, Git, JDK, CA-tanúsítványok, archívumkezelők) automatikus telepítése. Más rendszeren a szükséges csomagoknak már rendelkezésre kell állniuk.
- Nyilvános szerveren a panelhez HTTPS-t használj. SSH-/tűzfalszabályzatot és root-hozzáférést a panel nem tud létrehozni egy még elérhetetlen gépen; működő telepítési SSH-hozzáférés előfeltétel.

Nem kell kézzel SSH-val belépni vagy sudoers-/indítószkripteket szerkeszteni a normál webes munkafolyamathoz. A háttérben futó installert a panel végzi. Opcionális CLI-alternatíva:

```sh
sudo python3 linux/install.py --source-root /srv/gamedock/templates --target-root /home/gamedock --ssh-user gamedock
```

A Linux-oldali feltétel Python 3.9+ és pidfd-t támogató Linux-kernel. Az installer nem igényel systemd-t, tmuxot vagy screen-t. Az SSH-fiók meglévő kulcsai és a meglévő szerverbejegyzések megmaradnak. A `/home/gamedock` felső könyvtár root tulajdonú lesz; a benne lévő SSH-kulcsok tulajdonosát nem változtatja meg, a példányok saját alkönyvtárat kapnak.

## Tetszőleges játéktípusok

Az admin **Játéktípusok** menüjében létrehozhatsz, szerkeszthetsz és törölhetsz saját típusokat:

- megjelenített név és stabil, kisbetűs azonosító;
- alapértelmezett Linux template mappa;
- alapértelmezett indítóparancs;
- saját mappás vagy Minecraft-katalógus alapú template-kezelés.

A négy induló típus csak kezdőbeállítás. Nem korlátozza a panelt Minecraft/CS2/FiveM/CS:Source játékokra. Használatban lévő típus nem törölhető. Saját mappás típusnál az indítóparancs használja a `{port}` helyőrzőt, például `./server -port {port}`, vagy `PORT={port} ./start.sh`. A FiveM alapkonfigurációját a panel közvetlenül írja.

Counter-Strike vagy más saját mappás játéknál a típus alap template útvonala és az új szerver forrásmappája szabadon megadható, de a Linuxon engedélyezett forrásgyökér alatt maradjon. A szükséges játékfájloknak már ebben a template-ben kell lenniük; a panel ezekből készít másolatot, nem használ SteamCMD-t.

## Minecraft-típusok és az utolsó 10 verzió

A **Minecraft-templatek** adminmenüben válassz Linux-gépet, majd indíts szinkronizálást. A letöltés/fordítás aszinkron, a gépen fut, a lap állapotot mutat.

Támogatott szolgáltatók: **Vanilla, Paper, Forge, Bukkit, Spigot, Purpur, Fabric**. Típusonként a legutóbbi tíz stabil **Minecraft-verzió** kerül a katalógusba, és azon belül a legfrissebb stabil build/loader. Nem tíz külön buildet mutatunk ugyanahhoz az egy Minecraft-verzióhoz.

- Vanilla: Mojang hivatalos manifest és server artifact.
- Paper: a hivatalos Fill v3 letöltési API, kizárólag STABLE build.
- Forge: hivatalos Maven-metaadat és installer, korlátozott felhasználóval futtatva.
- Bukkit/Spigot: hivatalos verziólista és a rögzített sikeres BuildTools build; automatikus CraftBukkit/Spigot-fordítás. Ez tíz verziónál sokáig tarthat és jelentős lemezterületet igényel.
- Purpur: hivatalos verzió-/build API.
- Fabric: hivatalos game/loader/installer metaadat és server installer.

HTTPS-ellenőrzés végig bekapcsolva marad; ahol az upstream közöl ellenőrzőösszeget, letöltés után ellenőrizzük. BuildTools esetén a hivatalos Jenkins buildhez kötött HTTPS-artifactot használjuk. A hiányzó megfelelő JDK a hivatalos Adoptium API-ból, SHA256-ellenőrzéssel készíthető elő. Az archívum kibontása, Forge/Fabric installer és BuildTools **nem rootként** fut. A buildből csak a használható template kerül a katalógusba, a munkakönyvtár eltakarítható.

A szinkronizálás meglévő kész buildet nem ír felül. A régebbi letöltött sablonokat nem törli automatikusan. Hibás vagy folyamatban lévő template nem választható szerverhez. Új szervernél a típus/verzió kiválasztása és a Minecraft EULA kifejezett elfogadása szükséges. A panel ezek alapján beállítja az indítóparancsot, `server.properties` portját és az `eula.txt` fájlt.

A Linuxnak el kell érnie a Mojang, PaperMC, Forge, SpigotMC, Purpur, Fabric, Adoptium/GitHub és a buildeszközök függőségi tárainak HTTPS-végpontjait. Proxy esetén a Java/Git tanúsítvány- és proxybeállítások is legyenek helyesek; TLS-ellenőrzést ne tilts le.

## Szerverkönyvtárak, portok és konfiguráció

Új példány létrehozásakor a cél **`/home/gamedock/<adatbázisbeli-szerver-ID>/`**, például `/home/gamedock/42/`. A célmappát nem kell és nem lehet kézzel megválasztani. Meglévő célmappát a másoló nem ír felül. Minden példány külön nologin Linux-felhasználót és privát, 0700 jogosultságú könyvtárat kap.

Portkiosztás: **25000–25999**, egy Linux-gépen a panelen belül egyedi porttal. A mentett indítóparancs `{port}` helyőrzőjét a kiosztott ötjegyű érték helyettesíti. A Minecraft `server.properties` és a FiveM `server.cfg` TCP/UDP endpoint beállításai automatikusan elkészülnek. Más játékok template-jeinek szöveges cfg/properties/json/yaml/txt fájljaiban `{{PORT}}` is használható, amelyet másoláskor a panel kiír.

A teljes fájlmásolás a saját játékfelhasználóval fut, nem rootként. Nincs symlink/hardlink-alapú közös játékadat. A forrás/célgyökér engedélylistáját a root tulajdonú `/etc/gamedock/servers.json` rögzíti. Nem kerülhet másolat az engedélyezett célgyökéren kívülre, és symlinkes/speciális/üres template elutasításra kerül.

A másolás állapota az admin és tulajdonos kliensoldalán követhető. Indítás csak siker után engedélyezett. Hibás feladat forrása javítható és újrapróbálható. Törlés a panelből nem törli automatikusan a Linux-fájlokat és a Linux-fiókot. Folyamatban lévő másolási bejegyzés törlése tiltott.

## Közvetlen parancsindítás, konzol

Az Indítás a mentett parancsot futtatja `/bin/bash -c` alatt a példány könyvtárában, a saját UID/GID-jával, új háttérfolyamatként. Nincs systemd-szolgáltatás, rendszerindítási unit, tmux vagy screen. Az SSH-bontás nem állítja le. A parancs maradjon előtérben, ne daemonizálja önmagát és ne használjon `nohup`/`&`/`screen`/`tmux` indítást.

A PTY-runner kezeli a konzol bemenetét/kimenetét. Az állapot és leállítás root által rögzített PID+indulási idő+UID alapján, Linux pidfd segítségével történik; a PID újrafelhasználását nem tekintjük ugyanannak a szervernek. A leállítás normál SIGTERM-et ad, majd határidő után a követett folyamatokat lezárja. A napló korlátozott méretű, az utolsó 150 sor látható. A kliens indíthat, leállíthat, újraindíthat, konzolparancsot adhat és kézi/automatikus naplófrissítést használhat.

A gép újraindítása után a példányok a panelről újraindítandók; bootindítást nem állítunk be. Korábban szolgáltatásként vagy más módon futó játékokat frissítés előtt egyszer állíts le; ismeretlen folyamatokat a panel nem vesz át és nem öl meg találomra.

## Jogosultságok és frissítés

Admin: felhasználók, típusok, Linux-gépek, sablonok és szerverek kezelése, tulajdonosváltás, jelszó-reset, műveleti napló. Kliens: kizárólag saját szerverek és fiókadatok. Módosítások CSRF-védettek; jelszócsere/reset érvényteleníti a többi munkamenetet, a bejelentkezések fiók/IP szerint korlátozottak.

Frissítés előtt készíts fájl- és adatbázismentést. Őrizd meg a saját `config/` fájlokat és SSH-kulcsokat, a többi kiadásfájlt azonos elrendezésben töltsd fel. Az admin **Új szerverfunkciók engedélyezése** gombja a hiányzó oszlopokat és a játéktípus-táblát hozzáadja, és a korábbi enumot bővíthető azonosítóra alakítja. ALTER/CREATE/INSERT jogosultság szükséges; a meglévő adatokat nem törli. A telepítőt ne futtasd újra.

A régi szerverkönyvtárakat a frissítő nem költözteti át és nem írja felül automatikusan. Az ID-alapú hely az új példányokra vonatkozik. A régi Linux-telepítést a webes Linux-beállítás gombbal frissítsd. A generált Smarty-fordítások a `var/smarty/compile/` alatt törölhetők, a konfiguráció nem.

## Ellenőrzés és korlátok

PHP: `php tests/templates.php`, `php tests/installer.php`, `php tests/dependencies.php`. A `php tests/accounts.php` csak külön teszt-MySQL-lel futtatható (`TEST_MYSQL_HOST`, `TEST_MYSQL_PASSWORD`): saját véletlen nevű adatbázist hoz létre/töröl. Python: `python3 -m unittest discover -s tests -p 'test_*.py'`, nem root felhasználóval. A böngészős tesztek kizárólag izolált loopback tesztpéldányhoz valók.

A közvetlen SSH-indítás, webes Linux-beállítás, egyéni játéktípus, ID-könyvtár, 25xxx port, konfigurációírás, konzol és flat asztali/mobil felület izolált Linux-fixture-rel tesztelve. A Minecraft-katalógus kiválasztása tesztkatalógussal, az upstream-metaadat feldolgozása és checksum-ellenőrzés mockokkal tesztelhető. A fejlesztői környezet hálózati korlátozása miatt a valódi teljes Minecraft/Forge/BuildTools/Fabric letöltés és fordítás nem futott le itt; ezt a tényleges Linux-gép szinkronizálási állapota igazolja majd. Sikertelen artifact nem jelenik meg használható template-ként.

Nincs általános webes fájlkezelő, biztonsági mentés/visszaállítás, számlázás vagy e-mailes jelszó-visszaállítás. Az automatikus konfiguráció a fent ismert fájlokat és porthelyőrzőket kezeli; tetszőleges egyedi játék minden saját beállítását a template-ben/indítóparancsban kell megadni.

500-as hibánál a `/check.php` oldal megmutatja a PHP-verziót, szükséges fő fájlok olvashatóságát és könyvtárjogokat. Jelszót/privát kulcsot nem ír ki. Váratlan hiba esetén a válasz hibaazonosítója alapján keresd a részletet a tárhely PHP error_log naplójában.

## „A Minecraft-katalógus nem érhető el” hiba

Ez a korábbi, általános hibaüzenet az SSH-hitelesítés után keletkezett: a Linux-oldali helper nem futott le sikeresen. Nem jelenti automatikusan azt, hogy a Minecraft letöltési oldal hibás.

A friss panel külön jelzi a hiányzó gamedock-templates programot, a jelszó nélküli sudo-jog hiányát, a hiányzó gamedock_runtime.py fájlt, a Python-futási hibát, az időtúllépést és a hibás JSON-választ. A Minecraft-templatek oldalon a kiválasztott géphez közvetlen Linux telepítés/javítás gomb is található.

A teljes kiadás feltöltése után a gép szerkesztésénél add meg a telepítési root/admin SSH-hozzáférést, és indítsd a Linux beállítása automatikusan műveletet. A befejező üzenet legyen sikeres, majd térj vissza a katalógushoz. A hiányzó katalógus még nem hiba: első használatkor az állapot üres, és a szinkronizálás indítható. A segédprogram stderr üzeneteit külön kezeljük, hogy ne keveredjenek a JSON-katalógusba.

A Linux-telepítés hibaüzenete most a sikertelen lépést és a kilépési kódot is jelzi (APT csomaglista, alapcsomag/JDK, Linux-helper/SSH-jogosultság vagy UFW). A telepítési napló a részletes hibát is tartalmazza, és a sudoers-ellenőrzés stderr üzenete nem vész el. Hibakereséshez a telepítő alatti naplódoboz utolsó 20–30 sora szükséges; hitelesítési jelszót/privát kulcsot nem kell elküldeni.
