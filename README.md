# GameDock

PHP 8.2+, MySQL, Smarty és SSH2 alapú, Composer nélkül telepíthető játékszerver-panel. Világos, kék fejlécű Bootstrap 5.3.8 felület, játék szerint csoportosított bal oldali szervermenüvel, szögletes áttekintő kártyákkal és jobb oldali eszközmenüvel. Az admin- és kliensfelület közös megjelenést használ, az adminmenü csak jogosult felhasználóknak látszik.

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
sudo python3 linux/install.py --source-root /home/gamedock/templates --target-root /home/gamedock --ssh-user gamedock
```

A Linux-oldali feltétel Python 3.9+ és pidfd-t támogató Linux-kernel. Az installer nem igényel systemd-t, tmuxot vagy screen-t. Az SSH-fiók meglévő kulcsai és a meglévő szerverbejegyzések megmaradnak. A `/home/gamedock` felső könyvtár root tulajdonú lesz; a benne lévő SSH-kulcsok tulajdonosát nem változtatja meg, a példányok saját alkönyvtárat kapnak.

## Tetszőleges játéktípusok

Az admin **Játéktípusok** menüjében létrehozhatsz, szerkeszthetsz és törölhetsz saját típusokat:

- megjelenített név és stabil, kisbetűs azonosító;
- alapértelmezett Linux template mappa;
- alapértelmezett indítóparancs;
- saját mappás vagy Minecraft-katalógus alapú template-kezelés.

Az öt induló típus csak kezdőbeállítás. Nem korlátozza a panelt Minecraft/CS1.6/CS2/FiveM/CS:Source játékokra. Használatban lévő típus nem törölhető. Saját mappás típusnál az indítóparancs használja a `{port}` helyőrzőt, például `./server -port {port}`, vagy `PORT={port} ./start.sh`. A FiveM alapkonfigurációját a panel közvetlenül írja.

Counter-Strike vagy más saját mappás játéknál a típus alap template útvonala és az új szerver forrásmappája szabadon megadható, de a Linuxon engedélyezett forrásgyökér alatt maradjon. Saját mappás felvételnél a szükséges játékfájloknak már a template-ben kell lenniük; a panel ezekből készít másolatot. Counter-Strike 1.6, Counter-Strike 2 és Counter-Strike: Source esetén a szerverfájlok automatikusan is letölthetők az alábbi módon.

## Counter-Strike template automatikus letöltése

A **Játékszerver-templatek** oldalon válassz Linux-gépet, majd **Counter-Strike 1.6**, **Counter-Strike 2** vagy **Counter-Strike: Source** játéktípust. A **Szerverfájlok letöltése** gomb a kiválasztott gépen háttérben indítja a SteamCMD-t, névtelen belépéssel, az adott játék legfrissebb stabil szerverkiadásához (CS1.6: app 90, `mod cstrike`; CS2: app 730, CS:S: app 232330). Nem indít játékszervert, és nem kér Steam-jelszót. Régebbi, kézzel választott kiadáshoz továbbra is a saját mappás template-felvétel használható.

CS1.6-nál az app 90 teljes `cstrike` fájlkészletét ellenőrizzük; a HLDS sajátossága miatt szükség esetén legfeljebb három ellenőrzött frissítés fut. A 32 bites Steam-klienskönyvtár rendes fájlként a template `.steam/sdk32/steamclient.so` helyére is bekerül, így az új példány nem függ a letöltő ideiglenes mappájától. Hiányos HLDS/mod nem kerül a választható sablonok közé.

A SteamCMD bootstrap a Valve hivatalos HTTPS-címéről érkezik (`steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz`), a SteamCMD `app_update … validate` ellenőrzi a szerverfájlokat. A letöltés és kibontás nem rootként fut. A gép x86_64 Linux legyen, és érje el a Steam hitelesítési, tartalom- és frissítési szolgáltatásait. Elegendő szabad hely kell a teljes letöltéshez; a már elkészült template-ek megmaradnak.

A kész template a `/home/gamedock/templates/cs16/steam-<build>-<azonosító>/`, `/home/gamedock/templates/cs2/steam-<build>-<azonosító>/` vagy `/home/gamedock/templates/css/steam-<build>-<azonosító>/` mappába kerül. A panel automatikusan felveszi, megjeleníti a buildszámot és útvonalat; ezután szerverhez választható. A meglévő template-et nem frissíti és nem írja felül. Egyszerre egy Minecraft- vagy Steam-letöltési feladat futhat gépenként. Részleges/hibás letöltés nem kerül a választható template-ek közé. Állapot a panelen, részletes hibanapló a Linux-gépen: `/var/lib/gamedock/minecraft/steam.log`.

**Meglévő gépnél szükséges frissítés:** töltsd fel a friss panelt, majd a kiválasztott gépnél futtasd újra az **Automatikus Linux-telepítés** műveletet, bekapcsolt csomagtelepítéssel. Az admin **Új szerverfunkciók engedélyezése** adatbázisfrissítése meglévő telepítéshez is hozzáadja a CS1.6 játéktípust, a saját beállításokat megőrizve. A Linux-telepítés telepíti a friss segédprogramot és sudo-jogokat, továbbá Debian/Ubuntu x86_64 alatt a `lib32gcc-s1` és `lib32stdc++6` SteamCMD-függőségeket. Kikapcsolt csomagtelepítésnél ezeket a gépen előre kell biztosítani.

A folyamat fake SteamCMD-vel és böngészős teszttel ellenőrizhető (`tests/test_steam.py`, `tests/browser_release_picker.cjs`). Ezek nem töltenek le valódi játékfájlokat; a teljes Steam-letöltés sikerét a célgép kész állapota igazolja.

## Minecraft-típusok és az utolsó 10 verzió

A **Játékszerver-templatek** adminmenüben válassz Linux-gépet, majd **Játéktípust**. Minecraft esetén a külön **Típus** és **Verzió** választóval add meg a telepítendő stabil kiadást. Kizárólag a **Sablon telepítése** gomb indít telepítést; a választók módosítása nem. A letöltés/fordítás aszinkron, a gépen fut, a lap állapotot mutat.

Támogatott szolgáltatók: **Vanilla, Paper, Forge, Bukkit, Spigot, Purpur, Fabric**. Típusonként a legutóbbi tíz stabil **Minecraft-verzió** kerül a katalógusba, és azon belül a legfrissebb stabil build/loader. Nem tíz külön buildet mutatunk ugyanahhoz az egy Minecraft-verzióhoz.

- Vanilla: Mojang hivatalos manifest és server artifact.
- Paper: a hivatalos Fill v3 letöltési API, kizárólag STABLE build.
- Forge: hivatalos Maven-metaadat és installer, korlátozott felhasználóval futtatva.
- Bukkit/Spigot: hivatalos verziólista és a rögzített sikeres BuildTools build; automatikus CraftBukkit/Spigot-fordítás. Ez tíz verziónál sokáig tarthat és jelentős lemezterületet igényel.
- Purpur: hivatalos verzió-/build API.
- Fabric: hivatalos game/loader/installer metaadat és server installer.

HTTPS-ellenőrzés végig bekapcsolva marad; ahol az upstream közöl ellenőrzőösszeget, letöltés után ellenőrizzük. BuildTools esetén a hivatalos Jenkins buildhez kötött HTTPS-artifactot használjuk. A hiányzó megfelelő JDK a hivatalos Adoptium API-ból, SHA256-ellenőrzéssel készíthető elő. Az archívum kibontása, Forge/Fabric installer és BuildTools **nem rootként** fut. A buildből csak a használható template kerül a katalógusba, a munkakönyvtár eltakarítható.

A szinkronizálás meglévő kész buildet nem ír felül. A régebbi letöltött sablonokat nem törli automatikusan. Hibás vagy folyamatban lévő template nem választható szerverhez. Új szervernél a típus/verzió kiválasztása szükséges. Nincs külön EULA-jelölőnégyzet; az `eula.txt` automatikusan `eula=true` értékkel készül el. A panel ezek alapján beállítja az indítóparancsot, `server.properties` portját és az `eula.txt` fájlt.

A Linuxnak el kell érnie a Mojang, PaperMC, Forge, SpigotMC, Purpur, Fabric, Adoptium/GitHub és a buildeszközök függőségi tárainak HTTPS-végpontjait. Proxy esetén a Java/Git tanúsítvány- és proxybeállítások is legyenek helyesek; TLS-ellenőrzést ne tilts le.

## Csatlakozási cím, használati adatok és limitek

A **Szervereim → Kezelés és konzol** oldalon látható a csatlakozási cím és port, valamint öt másodpercenként frissül a teljes játékfolyamatcsoport CPU- és memóriahasználata. A Linux-gépnél külön megadható nyilvános játék IP/domain; üresen az SSH-címet jelenítjük meg (IPv6 cím szögletes zárójelet kap). NAT esetén a külső porttovábbításról is gondoskodni kell.

Létrehozáskor és szerkesztéskor az admin megadhatja:

- **Memórialimit (MiB):** például 2048 = 2 GiB. A kernel a teljes csoport memóriáját korlátozza; túllépéskor OOM-leállítás történhet. A Java `-Xmx` értékének ennél kisebbnek kell lennie a JVM egyéb memóriaigénye miatt.
- **CPU-limit (%):** 100 egy mag, 150 másfél, 200 két mag CPU-idejének felel meg. A kernel időkvótával korlátozza, nem a magok számát állítja.
- Mindkettőnél **0 = korlátlan**. Módosítás a következő indítás/újraindítás alkalmával érvényesül; az adatlapon a mentett limit látszik.

Mentéskor az indítóparancsba az `export GAMEDOCK_MEMORY_MB=… GAMEDOCK_CPU_PERCENT=…;` előtag kerül. Közvetlen Java-parancsnál a heapet a memórialimit 80%-ára állítjuk, és a CPU-kvótához igazítjuk az `ActiveProcessorCount` értékét; tetszőleges játékhoz nem adunk ismeretlen kapcsolókat. A Forge saját JVM-argumentumfájlját szükség esetén a sablonban lehet testre szabni. A teljes folyamat memóriáját és CPU-idejét minden esetben a kernel korlátozza.

A segédprogram indításkor automatikusan beállítja a saját root-tulajdonú **cgroup v2** csoportját és a CPU/memory vezérlőket. A runner és minden gyermekfolyamata ebbe kerül még a játékfelhasználóra váltás előtt; systemd-szolgáltatás nem kell. A játék nem írhatja felül a limitjeit. A memória-limit mellett a swap limitje 0. Korlátozás kérésekor írhatatlan/hiányzó cgroup v2 vezérlő esetén az indítás hibával leáll: korlátozás nélkül nem indul el. Konténeres/VPS tárhelyen ezt a gazdagépnek is támogatnia kell. Korlátlan futtatás cgroup nélkül is működik; ilyenkor a folyamatfa CPU-idejéből és RSS memóriájából számítjuk a használatot, ami becslés (megosztott lapok többször számíthatnak).

Új szervernél a **szerver neve automatikusan a kiválasztott játék neve**, a **Szerverazonosító** pedig automatikusan generált, egyedi, nyolc számjegyű belső Linux-azonosító. A korábban létrehozott azonosítókat megőrizzük. A célmappa továbbra is az adatbázisbeli számazonosítót használja.

## Minecraft-template törlése és verzióváltás

Az admin **Játékszerver-templatek** táblájában minden nyugalmi sablon mellett **Törlés** gomb van. A katalógusbejegyzést és a hozzá tartozó build könyvtárát törli; a már létrehozott példányok és mentéseik érintetlenek. Folyamatban lévő szinkronizálás vagy az adott sablonról futó másolás/verzióváltás alatt a törlés elutasított. A következő szinkronizálás a legutóbbi tíz kiadás közé tartozó törölt sablont újra letöltheti.

Az ügyfél **Verzióváltás** eszközében típusonként csoportosítva választhat a telepített verziókból, másik Minecraft-típusra is. A szerver legyen offline, a template minimum memóriaigényét teljesítenie kell. Az admin újratelepítési szabályától függően megőrző váltás vagy megerősített tiszta telepítés fut; mindkét esetben teljes mentés készül. A részletes működést és a frissítés lépéseit a Játékszerver-templatek és verzióváltás fejezet tartalmazza. A mentéseket nem töröljük automatikusan; legalább egy további teljes példánynyi szabad tárhely szükséges. A fájlcsere előtti hiba az eredeti példányt érintetlenül hagyja, az átnevezési szakasz megszakadását az állapotlekérés helyreállítja.

Az indítás, leállítás, újraindítás és állapot gomb **AJAX-os**, oldalfrissítés nélkül működik, a módosító kérések POST + CSRF-védettek. A konzol mindig automatikusan frissül öt másodpercenként, átmeneti hiba után is újrapróbálkozik. Az állapot felirata **online / offline**. A CPU-kártyán csak a használat látszik, a beállított limit nem.

## Szerverkönyvtárak, portok és konfiguráció

A template-ek gyökere mindig **`/home/gamedock/templates/`**. A webes Linux-telepítő ezt rögzítetten állítja be, játékprofilonként ez alatti almappát lehet választani. Frissítéskor a korábbi template-gyökeret automatikusan átköltözteti, és a régi Java-elérési utak működéséhez kompatibilitási hivatkozást hagy. Aktív másolás/verzióváltás alatt nem költöztet; két külön, már tartalmas régi és új mappát nem egyesít felülírással.

Új példány létrehozásakor a cél **`/home/gamedock/<adatbázisbeli-szerver-ID>/`**, például `/home/gamedock/42/`. A célmappát nem kell és nem lehet kézzel megválasztani. Meglévő célmappát a másoló nem ír felül. Minden példány külön nologin Linux-felhasználót és privát, 0700 jogosultságú könyvtárat kap.

Létrehozáskor a portmező **üresen hagyható a véletlen kiosztáshoz**, vagy kézzel kitölthető. Az admin később is módosíthatja; az új érték a következő indításkor/újraindításkor kerül a konfigurációba. Minecraftnál a `server-port` és `query.port`, FiveM-nél mindkét endpoint frissül. Egyedi játékoknál a korábban `{{PORT}}` helyőrzővel létrehozott konfigurációkat egy fájllista alapján frissítjük, a többi kézi módosítás megőrzésével.

Portkiosztás: **25000–25999**, üres mezőnél véletlenszerűen választott szabad porttal: az első két számjegy mindig 25, az utolsó három véletlen. A node-adatbázissor zárolása alatt kizárjuk a panelen már kiosztott portokat és SSH-n a Linux `/proc/net/tcp`, `tcp6`, `udp`, `udp6` tábláiban foglalt portokat. Sikertelen portellenőrzésnél nem hozunk létre szervert. Egy később, a panelen kívül elindított program természetesen lefoglalhat még egy jelenleg szabad portot; ezért a játéktartományt célszerű a panelnek fenntartani. A mentett indítóparancs `{port}` helyőrzőjét a kiosztott ötjegyű érték helyettesíti. A Minecraft `server.properties` és a FiveM `server.cfg` TCP/UDP endpoint beállításai automatikusan elkészülnek. Más játékok template-jeinek szöveges cfg/properties/json/yaml/txt fájljaiban `{{PORT}}` is használható, amelyet másoláskor a panel kiír.

A teljes fájlmásolás a saját játékfelhasználóval fut, nem rootként. Nincs symlink/hardlink-alapú közös játékadat. A forrás/célgyökér engedélylistáját a root tulajdonú `/etc/gamedock/servers.json` rögzíti. Nem kerülhet másolat az engedélyezett célgyökéren kívülre, és symlinkes/speciális/üres template elutasításra kerül.

A másolás állapota az admin és tulajdonos kliensoldalán követhető. Indítás csak siker után engedélyezett. Hibás feladat forrása javítható és újrapróbálható. Törlés a panelből nem törli automatikusan a Linux-fájlokat és a Linux-fiókot. Folyamatban lévő másolási bejegyzés törlése tiltott.

## Közvetlen parancsindítás, konzol

Az Indítás a mentett parancsot futtatja `/bin/bash -c` alatt a példány könyvtárában, a saját UID/GID-jával, új háttérfolyamatként. Nincs systemd-szolgáltatás, rendszerindítási unit, tmux vagy screen. Az SSH-bontás nem állítja le. A parancs maradjon előtérben, ne daemonizálja önmagát és ne használjon `nohup`/`&`/`screen`/`tmux` indítást.

A PTY-runner kezeli a konzol bemenetét/kimenetét. Az állapot és leállítás root által rögzített PID+indulási idő+UID alapján, Linux pidfd segítségével történik; a PID újrafelhasználását nem tekintjük ugyanannak a szervernek. A leállítás normál SIGTERM-et ad, majd határidő után a követett folyamatokat lezárja. A napló korlátozott méretű, az utolsó 150 sor látható. A kliens indíthat, leállíthat, újraindíthat, konzolparancsot adhat és kézi/automatikus naplófrissítést használhat.

A gép újraindítása után a példányok a panelről újraindítandók; bootindítást nem állítunk be. Korábban szolgáltatásként vagy más módon futó játékokat frissítés előtt egyszer állíts le; ismeretlen folyamatokat a panel nem vesz át és nem öl meg találomra.

## Jogosultságok és frissítés

Admin: felhasználók, típusok, Linux-gépek, sablonok és szerverek kezelése, tulajdonosváltás, jelszó-reset, műveleti napló. Kliens: kizárólag saját szerverek és fiókadatok. Módosítások CSRF-védettek; jelszócsere/reset érvényteleníti a többi munkamenetet, a bejelentkezések fiók/IP szerint korlátozottak.

Frissítés előtt készíts fájl- és adatbázismentést. Őrizd meg a saját `config/` fájlokat és SSH-kulcsokat, a többi kiadásfájlt azonos elrendezésben töltsd fel. Az admin **Új szerverfunkciók engedélyezése** gombja a hiányzó oszlopokat és a játéktípus-táblát hozzáadja, és a korábbi enumot bővíthető azonosítóra alakítja. ALTER/CREATE/INSERT jogosultság szükséges; a meglévő adatokat nem törli. A panel telepítőjét ne futtasd újra. Az új vezérlési, port-, CPU/memória-, törlési és verzióváltási funkciókhoz minden Linux-gépnél futtasd újra az **Automatikus Linux-telepítés** műveletet a segédprogramok frissítéséhez; majd indítsd újra az érintett játékot.

A régi szerverkönyvtárakat a frissítő nem költözteti át és nem írja felül automatikusan. Az ID-alapú hely az új példányokra vonatkozik. A régi Linux-telepítést a webes Linux-beállítás gombbal frissítsd. A generált Smarty-fordítások a `var/smarty/compile/` alatt törölhetők, a konfiguráció nem.

## Ellenőrzés és korlátok

PHP: `php tests/templates.php`, `php tests/installer.php`, `php tests/dependencies.php`. A `php tests/accounts.php` csak külön teszt-MySQL-lel futtatható (`TEST_MYSQL_HOST`, `TEST_MYSQL_PASSWORD`): saját véletlen nevű adatbázist hoz létre/töröl. Python: `python3 -m unittest discover -s tests -p 'test_*.py'`, nem root felhasználóval. A böngészős tesztek kizárólag izolált loopback tesztpéldányhoz valók.

A közvetlen SSH-indítás, webes Linux-beállítás, egyéni játéktípus, ID-könyvtár, 25xxx port, konfigurációírás, konzol és flat asztali/mobil felület izolált Linux-fixture-rel tesztelve. A Minecraft-katalógus kiválasztása tesztkatalógussal, az upstream-metaadat feldolgozása és checksum-ellenőrzés mockokkal tesztelhető. A fejlesztői környezet hálózati korlátozása miatt a valódi teljes Minecraft/Forge/BuildTools/Fabric letöltés és fordítás nem futott le itt; ezt a tényleges Linux-gép szinkronizálási állapota igazolja majd. Sikertelen artifact nem jelenik meg használható template-ként.

Nincs általános webes fájlkezelő, biztonsági mentés/visszaállítás, számlázás vagy e-mailes jelszó-visszaállítás. Az automatikus konfiguráció a fent ismert fájlokat és porthelyőrzőket kezeli; tetszőleges egyedi játék minden saját beállítását a template-ben/indítóparancsban kell megadni.

500-as hibánál a `/check.php` oldal megmutatja a PHP-verziót, szükséges fő fájlok olvashatóságát és könyvtárjogokat. Jelszót/privát kulcsot nem ír ki. Váratlan hiba esetén a válasz hibaazonosítója alapján keresd a részletet a tárhely PHP error_log naplójában.

## „A Minecraft-katalógus nem érhető el” hiba

Ez a korábbi, általános hibaüzenet az SSH-hitelesítés után keletkezett: a Linux-oldali helper nem futott le sikeresen. Nem jelenti automatikusan azt, hogy a Minecraft letöltési oldal hibás.

A friss panel külön jelzi a hiányzó gamedock-templates programot, a jelszó nélküli sudo-jog hiányát, a hiányzó gamedock_runtime.py fájlt, a Python-futási hibát, az időtúllépést és a hibás JSON-választ. A Játékszerver-templatek oldalon a kiválasztott géphez közvetlen Linux telepítés/javítás gomb is található.

A teljes kiadás feltöltése után a gép szerkesztésénél add meg a telepítési root/admin SSH-hozzáférést, és indítsd a Linux beállítása automatikusan műveletet. A befejező üzenet legyen sikeres, majd térj vissza a katalógushoz. A hiányzó telepített katalógus még nem hiba: első használatkor üres. A Minecraft-kategóriákból választhatsz telepítendő verziót. A segédprogram stderr üzeneteit külön kezeljük, hogy ne keveredjenek a JSON-katalógusba.

A Linux-telepítés hibaüzenete most a sikertelen lépést és a kilépési kódot is jelzi (APT csomaglista, alapcsomag/JDK, Linux-helper/SSH-jogosultság vagy UFW). A telepítési napló a részletes hibát is tartalmazza, és a sudoers-ellenőrzés stderr üzenete nem vész el. Hibakereséshez a telepítő alatti naplódoboz utolsó 20–30 sora szükséges; hitelesítési jelszót/privát kulcsot nem kell elküldeni.

## Külső szerverlétrehozási API

Adminbeállítás: `/api_settings.php`. Itt készíthető vagy visszavonható az API-kulcs, a szükséges ismétlésvédelmi tábla automatikusan létrejön. A kulcs csak egyszer látható, a panel kizárólag SHA-256 lenyomatát tárolja a webgyökéren kívül. Az API az őt létrehozó adminhoz kötődik; az adminjog visszavonása az API-hozzáférést is megszünteti.

Végpont: **`https://dev.vgh.hu/provision_api.php`**, más telepítésnél a saját domainnel. Kizárólag `POST application/json`, `Authorization: Bearer <kulcs>` és kötelező, rendelésenként egyedi, 8–128 karakteres `Idempotency-Key` fejléc támogatott. A kulcsot szerveroldalon kezeld, HTTPS-en küldd. Nginx/FastCGI továbbítsa az Authorization fejlécet PHP-nak (`fastcgi_param HTTP_AUTHORIZATION $http_authorization;`), amennyiben ezt a tárhely nem teszi meg alapból.

```sh
curl 'https://dev.vgh.hu/provision_api.php' \
  -H "Authorization: Bearer $GAMEDOCK_API_KEY" \
  -H 'Idempotency-Key: rendeles-20261029-001' \
  -H 'Content-Type: application/json' \
  --data '{"action":"create","user_id":1731,"node_id":1,"game":"cs2","memory_limit_mb":4096,"cpu_limit_percent":200}'
```

A `user_id` a meglévő ügyfél számos adatbázis-azonosítója; `node_id` a Linux-gép adatbázis-azonosítója; `game` az admin játéktípus-azonosítója. Az API ügyfelet nem hoz létre. Opcionális: `port` (25000–25999, elhagyva véletlen), `install_source`, `startup_command`, `memory_limit_mb`, `cpu_limit_percent`, Minecraftnál a már letöltött `minecraft_template` kulcs. A template és indítóparancs alapból a játéktípus beállításából jön. A node neve például `node_s1` legyen (betű/szám/pont/kötőjel/aláhúzás).

A `202` válasz JSON-ban jelzi a folyamatban lévő másolást. Azonos `Idempotency-Key` fejléccel, `{"action":"status"}` törzzsel kérdezd le 3 másodpercenként. Ha a létrehozási kérés hálózati hibával megszakad, az eredeti `create` kérés is biztonságosan megismételhető azonos kulccsal és adatokkal. Más paraméterek azonos kulccsal `409` hibát adnak. A kérés és szerverbejegyzés egy tranzakcióban készül, párhuzamos ismétlés nem hoz létre második szervert. A szerver későbbi törlése után ugyanaz a kulcs nem használható újra.

**Csak a Linux-oldali másolás sikeres befejezése után** érkezik `201`, `text/plain; charset=UTF-8` válasz, pontosan ebben a formában, sortörés nélkül:

```text
FELH_ID:1731|SZOLG_ID:15366|NODE:node_s1|
```

`SZOLG_ID` a panel Szerverazonosító mezőjében látható szám (nem a belső adatbázis-sorszám); `NODE` a Linux-gép mentett neve. A létrehozás nem indítja el automatikusan a játékot. A hibák JSON-t adnak, sosem sikeres szöveget: `401` hibás kulcs, `403` megszűnt adminjog, `409` ütköző/folyamatban feldolgozott kérés, `410` törölt szerver, `422` sikertelen másolás. SSH-időtúllépésnél `500` is előfordulhat, ilyenkor az eredeti kulccsal folytasd; ne hozz létre új rendelést.


## Játékszerver-templatek és verzióváltás

Az admin **Játékszerver-templatek** oldalán Linux-gépenként kezelhetők a sablonok. A Vanilla, Bukkit, Spigot, Paper, Forge, Purpur és Fabric külön típus; kiválasztásuk a hivatalos szolgáltatói katalógusból lekéri az adott típus legfrissebb legfeljebb 10 stabil játékverzióját. A lista 10 percig gyorsítótárazott. A külön **Verzió** választóban kiválasztott kiadáshoz a **Sablon telepítése** gomb indítja a háttérben a letöltést/fordítást. Egyszerre egy telepítési feladat futhat egy gépen. Más játéktípus (például Counter-Strike) kiválasztásakor a verzió és a template mappa elérési útvonala adható meg; a meglévő, teljes Linux-mappából egyedi verziózott template vehető fel.

A telepített template minden verziójánál külön menthető a **minimum memória (MiB)** és az **Újratelepítés szükséges** jelölő. A szabály a kiválasztott Linux-gépre és template-verzióra vonatkozik. A minimumot új szerver létrehozásakor és verzióváltáskor szerveroldalon is ellenőrizzük; a túl kicsi memórialimit tiltást eredményez. A 0 memórialimit korlátlant jelent, a 0 minimum nem ír elő minimumot.

Az ügyfél a **Verzióváltás** eszközben a már telepített templatek közül választhat típusonként csoportosítva; a nem elegendő memóriájú választás letiltott. Minecraftnál más típusra (például Vanilla → Paper) is válthat. **Csak offline állapotban** indítható: ezt PHP és a Linux-segédprogram is ellenőrzi, a folyamat idejére a Linux-oldali indítás zárolt.

Ha az admin újratelepítést ír elő, az ügyfél külön jelölőnégyzettel erősíti meg a fájlok cseréjét; enélkül az API is elutasítja a műveletet. Először teljes mentés készül a `/home/gamedock/.gamedock-backups/<szerverazonosító>/<feladatazonosító>/` alatt, majd tiszta template-másolat kerül a példány helyére. A játékport és a szükséges konfiguráció automatikusan beállítódik. Újratelepítés nélkül a korábbi világ és konfiguráció megmarad; a játéktípusok/verziók közötti világ- és modkompatibilitás nem garantálható.

**Meglévő telepítés frissítése:** töltsd fel a friss panelt, az admin Játékszerverek oldalán kattints az **Új szerverfunkciók engedélyezése** gombra, majd az érintett Linux-gépeknél futtasd az **Automatikus Linux-telepítés** műveletet. Ez felveszi a template-szabályok tábláját, a verziómetaadat-oszlopokat és frissíti a letöltési/újratelepítési segédprogramokat, sudo-jogokat. A meglévő szerverek megmaradnak.

Ellenőrzések: `php tests/template_policy.php` (MySQL-integráció a `TEST_MYSQL_HOST` és `TEST_MYSQL_PASSWORD` változókkal); `tests/browser_templates.cjs` kizárólag a kontrollált, 70 verziós loopback fixture-höz. A böngészőteszt nem tölt le valódi játékfájlokat.

## Ügyfél időzítő és tömörített biztonsági mentés

A kiválasztott szerver **Eszközök → Időzítő** menüjében egyszeri, napi vagy heti indítás, leállítás, újraindítás és biztonsági mentés állítható be. Csak az óra és perc adható meg az **Időpont** mezőben; első dátumot és időzónát nem kell választani. Az új feladatok budapesti idő szerint futnak. A következő futást automatikusan számítjuk: egyszeri/napi feladatnál a következő ilyen időpont, heti feladatnál a létrehozás napján vagy ugyanazon a hétköznapon a következő héten. A napi/heti feladat a választott helyi időt követi az óraátállításkor is. Az óraátállítás miatt nem létező helyi időpont kimarad; új feladatnál a következő érvényes napra/hétre kerül az első futás. A gép kiesése miatt több mint két perce elmulasztott feladatot nem hajtjuk végre késve, az eredmény a listában látszik. Az időzítések törölhetők, szerverenként legfeljebb 50 darab tartható meg.

A feladatokat a Linux-gép **cron** szolgáltatása futtatja percenként, bezárt böngésző és inaktív panel mellett is. A feladatlista, mentésmetaadatok és eredmények root által írható fájlokban maradnak meg; újraindítás után a cron folytatja a figyelést. Az automatikus telepítő telepíti/indítja a cron szolgáltatást és létrehozza a `/etc/cron.d/gamedock-automation` bejegyzést. A panel jelzi, ha a cron nem fut. Más Linux-disztribúción a cron/crond szolgáltatást és a tzdata adatbázist előre biztosítani kell. A szolgáltatásnak gépindításkor is el kell indulnia.

Az **Eszközök → Biztonsági mentés** alatt kézzel is indítható teljes, gzip-tömörített mentés. Futó szervert a másolat elkészítéséig leállítunk, majd újraindítunk; a tömörítés már a másolaton, háttérben fut. Leállított szerver leállítva marad. A mentések a Linux-gép `/var/lib/gamedock/backups/<szerverazonosító>/<mentésazonosító>.tar.gz` fájljaiba kerülnek, a webgyökéren kívül, 0600 jogosultsággal. A másolás, tömörítés és kibontás a szerver saját Linux-felhasználójával történik. A mentési folyamat állapota a felületen, a részletes hiba a `/var/lib/gamedock/automation/units/<szerverazonosító>/worker.log` naplóban látszik. A cron naplója `/var/log/gamedock-automation.log`.

Az időzített mentéshez megadható a megőrzött példányok száma (1–100, alapból 7). Csak az adott időzítő régebbi mentései törlődnek, a kézi és más időzítőből származó mentések megmaradnak. Összesen legfeljebb 100 mentés tárolható szerverenként; a régi kézi mentések a felületről megerősítéssel törölhetők. A mentések helyi példányok, géphiba ellen külön külső másolat szükséges. A másolathoz, tömörítéshez és visszaállításhoz további szabad lemezterület kell; nem töröljük a meglévő szervermappát egy sikertelen kibontás miatt.

**Visszaállítás:** csak leállított szerveren, a fájlcserét megerősítő jelölőnégyzettel lehetséges. Ezt a PHP és a Linux-segédprogram is ellenőrzi. SHA-256 ellenőrzés után külön mappába bontjuk a mentést, majd zárolás alatt cseréljük a szervermappát. A korábbi fájlok csak a sikeres csere után törlődnek; megszakadt fájlcsere esetén az állapotlekérés/cron visszahelyezi az eredeti mappát. A visszaállított szerver nem indul el automatikusan. A verzió/template metaadatokat is visszaállítjuk, az aktuális admin CPU-/memóriakeret és port megmarad. Az időzítések és a többi mentés nem részei a visszaállított szervermappának.

Mentés/visszaállítás közben az új indítás és a verzióváltás zárolt. Az időzített vezérlés a panelről legutóbb szinkronizált admin indítóparancsot és erőforrás-keretet használja; az admin szervermentése és az Időzítő megnyitása frissíti ezeket. Ha az admin módosításakor a Linux-szinkronizálás nem igazolt, a panel jelzi: a gép elérhetőségének helyreállása után újra el kell menteni a szervert. Ha a játékváltozat a mentett parancs óta megváltozott, a feladat hibát jelez, nem indít el egy korábbi parancsot. A panelből törölt szervernél az időzítéseket előbb töröljük; futó háttérfeladat mellett a törlés elutasított.

**Meglévő telepítés frissítése:** frissítsd a panel fájljait, az adminban futtasd az **Új szerverfunkciók engedélyezése** adatbázisfrissítést, majd minden érintett Linux-gépnél az **Automatikus Linux-telepítés** műveletet bekapcsolt csomagtelepítéssel. Ez hozzáadja a `last_restore_job` oszlopot, a `gamedock-automation` segédprogramot, a sudo-jogokat és a cron-bejegyzést. A konfiguráció, szerverfájlok és meglévő SSH-kulcsok megmaradnak.

Ellenőrzés: `tests/test_automation.py` (időzónák, óraátállítás, egyszeri futás, archívumvédelem), `php tests/automation.php` (külön teszt-MySQL-lel), `tests/browser_automation.cjs` (izolált HTML-fixture), valamint `tests/fixtures/automation_integration.py` kizárólag a disposable SSH-tesztkonténerben, tényleges cron- és PTY-folyamatokkal. Ezekhez nem szükséges valódi játékszerver vagy upstream játékletöltés.
