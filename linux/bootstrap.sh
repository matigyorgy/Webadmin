#!/bin/sh
# Uploaded by the authenticated administrator; run as root in a private staging folder.
set -eu
cd "$(dirname "$0")"
STEP='Elokeszites'
fail() {
 code=$?
 printf '{"state":"failed","message":"A Linux-telepítés nem sikerült. Lépés: %s. Kilépési kód: %s. A részletes napló lent olvasható."}\n' "$STEP" "$code" > setup-state.json
 printf '\nGameDock setup failed: step=%s exit=%s\n' "$STEP" "$code" >&2
 exit "$code"
}
trap fail EXIT
printf '{"state":"running","message":"Szükséges csomagok és Linux-segédprogramok telepítése."}\n' > setup-state.json
if [ "$3" = "1" ]; then
 if command -v apt-get >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  STEP='APT csomaglista frissitese';apt-get update
  STEP='Alapcsomagok telepitese';apt-get install -y python3 sudo passwd git default-jdk-headless ca-certificates tar unzip
  STEP='JDK csomagok telepitese'
  for major in 17 21 25; do if apt-cache show "openjdk-$major-jdk-headless" >/dev/null 2>&1; then apt-get install -y "openjdk-$major-jdk-headless"; fi; done
 else
  printf '{"state":"failed","message":"Az automatikus csomagtelepítés Debian/Ubuntu rendszeren támogatott. Más rendszeren a szükséges Python/Java/Git/sudo csomagoknak már telepítve kell lenniük."}\n' > setup-state.json
  trap - EXIT
  exit 1
 fi
fi
STEP='Linux-fajlok, fiokok, SSH-kulcs es jogosultsagok beallitasa'
python3 install.py --source-root "$1" --target-root /home/gamedock --ssh-user "$2" --panel-public-key-file panel.pub
STEP='UFW jatekportok beallitasa'
if [ "${4:-0}" = "1" ] && command -v ufw >/dev/null 2>&1 && ufw status | grep -q '^Status: active'; then
 ufw allow 25000:25999/tcp
 ufw allow 25000:25999/udp
fi
printf '{"state":"ready","message":"A Linux-segédprogramok, kulcsok és jogosultságok beállítása elkészült."}\n' > setup-state.json
trap - EXIT
