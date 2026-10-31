"""Only run inside the disposable SSH/Pure-FTPd fixture, with no public ports."""
import ftplib,importlib.machinery,importlib.util,io,json,os,pwd,socket,ssl,subprocess,time
from pathlib import Path
if not Path('/etc/gamedock/test-fixture').is_file():raise RuntimeError('Disposable fixture required')
loader=importlib.machinery.SourceFileLoader('ftp','/usr/local/sbin/gamedock-ftp');spec=importlib.util.spec_from_loader(loader.name,loader);f=importlib.util.module_from_spec(spec);loader.exec_module(f)
units=['990001','990002']
for i,unit in enumerate(units):
 target=Path('/home/gamedock')/unit;target.mkdir(mode=0o700)
 subprocess.run(['useradd','--system','--gid','minecraft','--home-dir',str(target),'--no-create-home','ftp_instance'+str(i)],check=True)
 account=pwd.getpwnam('ftp_instance'+str(i));os.chown(target,account.pw_uid,account.pw_gid)
 with f.p.locked():
  cfg=f.p.config();cfg[unit]={'launch':True,'user':account.pw_name,'directory':str(target),'game':'custom','port':25555+i,'provision_job':'ftp-fixture-'+str(i)};f.p.atomic(f.p.CONFIG,cfg)
f.install('localhost')
subprocess.run(['/usr/sbin/service','cron','start'],check=True,capture_output=True)
assert f.process_ready()
password='Fixture-FTP-password-123';f.create(units[0],{'username':'my_server','password':password})
state=f.listing(units[0]);assert state['account']['last_login'] is None
serialized=(f.ROOT/'users.json').read_text();assert password not in serialized and '"hash"' in serialized
assert (f.ROOT/'users.json').stat().st_mode&0o777==0o600
try:f.create(units[1],{'username':'my_server','password':password});raise AssertionError('Other instance account overwritten')
except ValueError as error:assert 'already in use' in str(error)
context=ssl.create_default_context(cafile=str(f.ROOT/'server.pem'))
def connect(password=password):
 client=ftplib.FTP_TLS(context=context,timeout=8);client.connect('localhost',2121);client.login('my_server',password);client.prot_p();return client
client=connect();assert f.listing(units[0])['account']['last_login'] is not None
client.storbinary('STOR world.txt',io.BytesIO(b'FTP upload fixture'));download=io.BytesIO();client.retrbinary('RETR world.txt',download.write);assert download.getvalue()==b'FTP upload fixture'
assert (Path('/home/gamedock')/units[0]/'world.txt').read_bytes()==b'FTP upload fixture'
client.cwd('..');assert client.pwd()=='/'
try:client.retrbinary('RETR /etc/passwd',lambda b:None);raise AssertionError('Chroot escaped')
except ftplib.error_perm:pass
rows=f.read(f.ROOT/'users.json',{});before=int(time.time())-86400;rows['my_server']['last_login']=before;f.p.atomic(str(f.ROOT/'users.json'),rows)
client.pwd();assert f.listing(units[0])['account']['last_login']==before
try:connect('incorrect-password');raise AssertionError('Bad password accepted')
except ftplib.error_perm:pass
assert f.listing(units[0])['account']['last_login']==before
# A plain FTP login is refused before credentials are sent; the client must use TLS.
plain=ftplib.FTP(timeout=8);plain.connect('localhost',2121)
try:plain.login('my_server',password);raise AssertionError('Plaintext FTP accepted')
except ftplib.Error as error:assert str(error).startswith('421')
finally:plain.close()
# The same routine is called before snapshot/restore/version replacement.
folder=Path('/var/lib/gamedock/automation/units')/units[0];folder.mkdir(parents=True,mode=0o700)
f.p.atomic(str(folder/'job.json'),{'state':'queued','operation':'backup'})
f.disconnect_unit(units[0])
try:connect();raise AssertionError('Login during maintenance accepted')
except ftplib.error_perm:pass
try:client.pwd();raise AssertionError('Existing session survived maintenance')
except (OSError,EOFError,ftplib.Error):pass
client.close();(folder/'job.json').unlink();client=connect()
# Simulate 15 days since the last successful login; cron removes only credentials.
rows=f.read(f.ROOT/'users.json',{});rows['my_server']['last_login']=int(time.time())-f.TTL;f.p.atomic(str(f.ROOT/'users.json'),rows)
deadline=time.monotonic()+75
while 'my_server' in f.read(f.ROOT/'users.json',{}):
 if time.monotonic()>deadline:raise AssertionError('Cron did not delete the expired FTP account')
 time.sleep(.5)
assert f.listing(units[0])['account'] is None
try:client.pwd();raise AssertionError('Expired active session survived')
except (OSError,EOFError,ftplib.Error):pass
assert (Path('/home/gamedock')/units[0]/'world.txt').exists()
try:connect();raise AssertionError('Expired login accepted')
except ftplib.error_perm:pass
client.close()
f.create(units[0],{'username':'replacement','password':password});f.remove_unit(units[0]);assert f.listing(units[0])['account'] is None
# Repeated setup keeps the certificate and account store; no global FTP configuration is replaced.
fingerprint=f.certificate_fingerprint();f.install('localhost');assert f.certificate_fingerprint()==fingerprint
print('Pure-FTPd integration passed: verified TLS upload/download, chroot, custom credentials, hashed storage, username isolation, successful-login tracking, plaintext rejection, maintenance session revocation, actual cron-based 15-day expiry and repeatable setup.')
