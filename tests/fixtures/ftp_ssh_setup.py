"""Private, disposable SSH target for verifying the PHP password stdin transport."""
import importlib.machinery,importlib.util,os,pwd,subprocess
from pathlib import Path
if not Path('/etc/gamedock/test-fixture').is_file():raise RuntimeError('Disposable fixture required')
loader=importlib.machinery.SourceFileLoader('ftp','/usr/local/sbin/gamedock-ftp');spec=importlib.util.spec_from_loader(loader.name,loader);f=importlib.util.module_from_spec(spec);loader.exec_module(f)
unit='990003';target=Path('/home/gamedock')/unit;target.mkdir(mode=0o700)
subprocess.run(['useradd','--system','--gid','minecraft','--home-dir',str(target),'--no-create-home','ftp_ssh_instance'],check=True)
account=pwd.getpwnam('ftp_ssh_instance');os.chown(target,account.pw_uid,account.pw_gid)
with f.p.locked():
 cfg=f.p.config();cfg[unit]={'launch':True,'user':account.pw_name,'directory':str(target),'game':'custom','port':25555,'provision_job':'ftp-ssh-fixture'};f.p.atomic(f.p.CONFIG,cfg)
f.install('localhost')
key=Path('/fixture/panel-key.pem');subprocess.run(['openssl','genpkey','-algorithm','RSA','-pkeyopt','rsa_keygen_bits:2048','-out',str(key)],check=True,capture_output=True);key.chmod(0o600)
public=subprocess.run(['ssh-keygen','-y','-f',str(key)],check=True,capture_output=True).stdout
folder=Path('/home/gamedock/.ssh');folder.mkdir(mode=0o700,exist_ok=True);owner=pwd.getpwnam('gamedock');os.chown(folder,owner.pw_uid,owner.pw_gid)
(folder/'authorized_keys').write_bytes(public);os.chown(folder/'authorized_keys',owner.pw_uid,owner.pw_gid);(folder/'authorized_keys').chmod(0o600)
fingerprint=subprocess.run(['ssh-keygen','-lf','/etc/ssh/ssh_host_ed25519_key.pub'],check=True,capture_output=True,text=True).stdout.split()[1]
# Probe the exact key advertised by phpseclib instead of assuming its host-key preference.
# All fixture host keys can be offered; force this known Ed25519 key for the test server.
Path('/fixture/fingerprint').write_text(fingerprint)
(Path('/run/sshd')).mkdir(exist_ok=True)
server=subprocess.Popen(['/usr/sbin/sshd','-D','-e','-h','/etc/ssh/ssh_host_ed25519_key'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
Path('/fixture/ready').touch();raise SystemExit(server.wait())
