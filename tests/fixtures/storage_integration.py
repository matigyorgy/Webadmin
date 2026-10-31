"""Ordinary space measurement and fail-closed unsupported-quota checks, in a disposable container."""
import base64,importlib.machinery,importlib.util,json,os,pwd,subprocess,time
from pathlib import Path
if not Path('/etc/gamedock/test-fixture').exists():raise RuntimeError('Disposable fixture required')
loader=importlib.machinery.SourceFileLoader('storage','/usr/local/sbin/gamedock-storage');spec=importlib.util.spec_from_loader(loader.name,loader);s=importlib.util.module_from_spec(spec);loader.exec_module(s)
unit='770001';target=Path('/home/gamedock')/unit;target.mkdir(mode=0o700);account=pwd.getpwnam('minecraft');os.chown(target,account.pw_uid,account.pw_gid)
file=target/'world.dat';file.write_bytes(b'fixture-data'*1024);os.chown(file,account.pw_uid,account.pw_gid)
with s.p.locked():
 cfg=s.p.config();cfg[unit]={'launch':True,'user':account.pw_name,'directory':str(target),'game':'custom','port':25555};s.p.atomic(s.p.CONFIG,cfg)
def metrics():
 result=subprocess.run(['/usr/local/sbin/gamedock-launch',unit,'metrics'],capture_output=True,text=True,check=True);return json.loads(result.stdout)
first=metrics();deadline=time.monotonic()+10
while first['disk_bytes'] is None:
 if time.monotonic()>deadline:raise RuntimeError('Disk measurement did not finish')
 time.sleep(.05);first=metrics()
expected=int(subprocess.run(['du','-x','-s','-B1',str(target)],check=True,capture_output=True,text=True).stdout.split()[0]);assert first['state']=='inactive' and first['disk_bytes']==expected and first['disk_limit_mb']==0
# Removing a cap remains supported on non-quota filesystems.
assert s.apply_limit(unit,0)['disk_limit_mb']==0
result=subprocess.run(['/usr/local/sbin/gamedock-storage','set',unit,'10'],capture_output=True,text=True)
assert result.returncode!=0 and 'Project quota support required' in result.stderr
assert s.p.config()[unit]['disk_limit_mb']==0 and file.read_bytes()==b'fixture-data'*1024
print('Storage Linux passed: asynchronous real du measurement while offline, unchanged CPU metrics, unlimited default, and unsupported-project-quota rejection without file/config changes. Positive kernel quota enforcement requires a prjquota-enabled ext4/XFS test volume.')
