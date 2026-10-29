"""Controlled fake templates for isolated tests only. Never production."""
import json
from pathlib import Path
if not Path('/etc/gamedock/test-fixture').is_file():raise RuntimeError('Only disposable test fixtures are permitted')
root=Path('/srv/gamedock/templates/minecraft');entries=[]
script=Path('/srv/gamedock/templates/custom/server.py').read_text()
for kind in ('vanilla','paper','forge','bukkit'):
 for release in range(10,0,-1):
  version='1.21.'+str(release);folder=root/kind/version/'fixture';folder.mkdir(parents=True,exist_ok=True)
  (folder/'server.py').write_text(script);(folder/'server.py').chmod(0o644)
  entries.append({'key':kind+':'+version+':fixture','type':kind,'version':version,'build':'fixture','state':'ready','path':str(folder),'startup':'/usr/bin/python3 -u server.py --port {port}','message':'Controlled test template, not a downloaded real game'})
cache=Path('/var/lib/gamedock/minecraft');cache.mkdir(parents=True,exist_ok=True)
(cache/'catalog.json').write_text(json.dumps({'state':'ready','templates':entries,'message':'Controlled catalog fixture: ten releases per type.'}))
