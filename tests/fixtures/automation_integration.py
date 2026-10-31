"""Run as root only inside the disposable SSH fixture. No production data."""
import base64,importlib.machinery,importlib.util,json,os,pwd,subprocess,time
from pathlib import Path
if not Path('/etc/gamedock/test-fixture').is_file():raise RuntimeError('Disposable fixture required')
loader=importlib.machinery.SourceFileLoader('automation','/usr/local/sbin/gamedock-automation');spec=importlib.util.spec_from_loader(loader.name,loader);a=importlib.util.module_from_spec(spec);loader.exec_module(a)
unit='880001';target=Path('/home/gamedock')/unit
subprocess.run(['useradd','--system','--gid','minecraft','--home-dir',str(target),'--no-create-home','fixture_instance'],check=True)
account=pwd.getpwnam('fixture_instance');target.mkdir(mode=0o700);os.chown(target,account.pw_uid,account.pw_gid)
for name,content in [('game.py',"import sys\nprint('READY',flush=True)\nfor line in sys.stdin:print(line,flush=True)\n"),('world.txt','original-world')]:
 file=target/name;file.write_text(content);os.chown(file,account.pw_uid,account.pw_gid)
with a.p.locked():
 cfg=a.p.config();cfg[unit]={'launch':True,'user':account.pw_name,'directory':str(target),'game':'custom','port':25555,'provision_job':'fixture-job','template_key':'custom:fixture'};a.p.atomic(a.p.CONFIG,cfg)
panel={'startup':'/usr/bin/python3 -u game.py','limits':{'memory_limit_mb':0,'cpu_limit_percent':0,'port':25555},'template_key':'custom:fixture','template_version':'fixture','minecraft_type':'','minecraft_version':'','install_source':'/home/gamedock/templates/custom'}
def cli(action,data=None):
 payload=base64.b64encode(json.dumps({'data':data or {},'panel':panel}).encode()).decode()
 result=subprocess.run(['sudo','-u','gamedock','sudo','-n','/usr/local/sbin/gamedock-automation',action,unit,payload],capture_output=True,text=True,timeout=25)
 if result.returncode:raise RuntimeError(result.stderr)
 return json.loads(result.stdout)
def wait(expected='ready'):
 deadline=time.monotonic()+20
 while time.monotonic()<deadline:
  state=cli('list')
  if state['job'].get('state') in ('ready','failed'):
   if state['job']['state']!=expected:raise RuntimeError((a.folder(unit)/'worker.log').read_text())
   return state
  time.sleep(.05)
 raise RuntimeError('Job timed out')
def launch(action):
 args=['/usr/local/sbin/gamedock-launch',unit,action]
 if action in ('start','restart'):args.extend([base64.b64encode(panel['startup'].encode()).decode(),base64.b64encode(json.dumps(panel['limits']).encode()).decode()])
 subprocess.run(args,check=True,capture_output=True,text=True,timeout=15)
launch('start');assert cli('list')['server_state']=='active'
cli('backup');saved=wait();assert saved['server_state']=='active';assert len(saved['backups'])==1
backup=saved['backups'][0]['id'];archive=a.BACKUPS/unit/(backup+'.tar.gz');assert archive.read_bytes()[:2]==b'\x1f\x8b';assert archive.stat().st_uid==0;assert archive.stat().st_mode&0o777==0o600
try:cli('restore',{'backup_id':backup,'confirmed':True});raise AssertionError('Online restore accepted')
except RuntimeError as error:assert 'Stop the server' in str(error)
launch('stop')
try:cli('restore',{'backup_id':backup});raise AssertionError('Unconfirmed restore accepted')
except RuntimeError as error:assert 'confirmation' in str(error)
(target/'world.txt').write_text('changed-world')
cli('restore',{'backup_id':backup,'confirmed':True});restored=wait();assert restored['server_state']=='inactive';assert (target/'world.txt').read_text()=='original-world'
try:launch('start');raise AssertionError('Unsynchronized restore started')
except subprocess.CalledProcessError:pass
version_payload=base64.b64encode(json.dumps({'job':'c'*32,'unit':unit,'template_key':'unavailable','reinstall':False,'confirmed':False}).encode()).decode()
blocked=subprocess.run(['/usr/local/sbin/gamedock-versions','create',version_payload],capture_output=True,text=True)
assert blocked.returncode!=0 and 'Backup or restore is running' in blocked.stderr
cli('ack_restore',{'id':restored['job']['id']});launch('start');launch('stop')
# A due cron tick starts the actual PTY game runner once; the browser is absent.
future=time.time()+60
local=time.strftime('%Y-%m-%dT%H:%M',time.gmtime(future))
cli('schedule_add',{'operation':'start','repeat':'once','timezone':'UTC','local_time':local,'keep':2})
state=cli('list');assert state['scheduler_ready'];due=state['schedules'][0]['next_run']
# Verify the installed cron entry, rather than calling tick from the browser/test.
deadline=time.monotonic()+75
while cli('list')['server_state']!='active':
 if time.monotonic()>deadline:raise RuntimeError('Installed cron did not execute the due task')
 time.sleep(.5)
wait();a.tick(due+1)
assert cli('list')['schedules'][0]['next_run'] is None
launch('stop');cli('schedule_delete',{'id':state['schedules'][0]['id']})
# Retention prunes only this recurring task's backups, never a manual backup.
local=time.strftime('%Y-%m-%dT%H:%M',time.gmtime(time.time()+120))
cli('schedule_add',{'operation':'backup','repeat':'daily','timezone':'UTC','local_time':local,'keep':1})
scheduled=cli('list')['schedules'][0];a.tick(scheduled['next_run']);first=wait()['backups'];a.tick(scheduled['next_run']+86400);second=wait()['backups']
assert len(first)==2 and len(second)==2 and backup in [r['id'] for r in second]
assert {r['id'] for r in first}!={r['id'] for r in second}
cli('schedule_delete',{'id':scheduled['id']})
# Corruption fails before touching the current server files.
archive.write_bytes(b'corrupt fixture');cli('restore',{'backup_id':backup,'confirmed':True});wait('failed');assert (target/'world.txt').read_text()=='original-world'
# Simulate process death immediately after moving the old directory aside.
rollback=target.parent/'.gamedock-rollback-fixture';os.rename(target,rollback);original=a.p.config()[unit]
a.p.atomic(str(a.folder(unit)/'job.json'),{'id':'f'*32,'state':'running','operation':'restore','worker':{'pid':999999,'uid':0,'start':'dead'},'rollback':str(rollback),'target':str(target),'original_config':original})
assert cli('list')['job']['state']=='failed';assert (target/'world.txt').read_text()=='original-world'
for record in cli('list')['backups']:cli('backup_delete',{'id':record['id']})
assert cli('list')['backups']==[]
print('Automation integration passed: real sudo, PTY stop/snapshot/resume, gzip archive, offline + confirmation enforcement, exact restore, metadata barrier, actual cron execution, scoped retention, corruption rejection, interrupted-restore recovery and deletion.')
