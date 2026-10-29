#!/bin/sh
# Uploaded by the authenticated administrator; run as root in a private staging folder.
set -eu
cd "$(dirname "$0")"
printf '{"state":"running","message":"Szükséges csomagok és Linux-segédprogramok telepítése."}\n' > setup-state.json
trap 'printf '\''{"state":"failed","message":"A Linux-telepítés nem sikerült. Ellenőrizd a telepítési naplót."}\n'\'' > setup-state.json' EXIT
if [ "$3" = "1" ]; then
 if command -v apt-get >/dev/null 2>&1; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y python3 sudo passwd git default-jdk-headless ca-certificates tar unzip
  for major in 17 21 25; do if apt-cache show "openjdk-$major-jdk-headless" >/dev/null 2>&1; then apt-get install -y "openjdk-$major-jdk-headless"; fi; done
 else
  printf '{"state":"failed","message":"Az automatikus csomagtelepítés Debian/Ubuntu rendszeren támogatott. Más rendszeren a szükséges Python/Java/Git/sudo csomagoknak már telepítve kell lenniük."}\n' > setup-state.json
  trap - EXIT
  exit 1
 fi
fi
python3 install.py --source-root "$1" --target-root /home/gamedock --ssh-user "$2" --panel-public-key-file panel.pub
if [ "${4:-0}" = "1" ] && command -v ufw >/dev/null 2>&1 && ufw status | grep -q '^Status: active'; then
 ufw allow 25000:25999/tcp
 ufw allow 25000:25999/udp
fi
printf '{"state":"ready","message":"A Linux-segédprogramok, kulcsok és jogosultságok beállítása elkészült."}\n' > setup-state.json
trap - EXIT
