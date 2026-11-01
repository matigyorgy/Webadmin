#!/usr/bin/python3
"""One-time local Linux setup. Run from the downloaded release as root, never from the web."""
import importlib.machinery
import importlib.util
import argparse
import fcntl
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import tempfile
import signal
import sys

CONFIG=Path('/etc/webadmin/servers.json')

def safe(path):
    info=path.lstat()
    if stat.S_ISLNK(info.st_mode) or info.st_uid!=0 or info.st_mode & (stat.S_IWGRP|stat.S_IWOTH):raise ValueError('Unsafe root-owned path: '+str(path))

def save(path,data,mode):
    descriptor,temporary=tempfile.mkstemp(dir=path.parent,prefix='.webadmin-')
    try:
        with os.fdopen(descriptor,'wb') as stream:stream.write(data)
        os.chmod(temporary,mode);os.replace(temporary,path)
    finally:
        if os.path.exists(temporary):os.unlink(temporary)

def ftp_sudo_input_tag(version_output):
    # sudo-rs rejects NOLOG_INPUT. It has no sudo-compatible I/O logging tag.
    return '' if 'sudo-rs' in version_output.lower() else 'NOLOG_INPUT: '

def ensure_user(name,game=False):
    if not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}',name) or name=='root':raise ValueError('Non-root Linux username required')
    try:account=pwd.getpwnam(name)
    except KeyError:
        subprocess.run(['useradd','--create-home','--shell','/usr/sbin/nologin' if game else '/bin/bash',name],check=True)
        account=pwd.getpwnam(name)
    if account.pw_uid==0 or (game and account.pw_gid==0):raise ValueError('Root game/SSH user or game group is forbidden')
    return account

def install(args):
    if os.geteuid()!=0:raise ValueError('Run this installer with sudo/root on the game host')
    for executable in ('python3','sudo','visudo','useradd','bash','setfacl'):
        if not shutil.which(executable):raise ValueError('Required Linux executable is missing: '+executable)
    if not hasattr(os,'pidfd_open') or not hasattr(signal,'pidfd_send_signal'):raise ValueError('Python 3.9+ and Linux pidfd support required')
    descriptor=os.pidfd_open(os.getpid());os.close(descriptor)
    account=ensure_user(args.ssh_user)
    for folder in (Path('/etc/webadmin'),Path('/usr/local/libexec'),Path('/usr/local/sbin')):
        folder.mkdir(parents=True,exist_ok=True);safe(folder)
    with open(str(CONFIG)+'.lock','a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        cfg={}
        if CONFIG.exists():
            safe(CONFIG);cfg=json.loads(CONFIG.read_text())
        old=cfg.get('_provision',{})
        source=Path('/home/webadmin/templates')
        if args.source_root and Path(args.source_root)!=source:raise ValueError('Template root is fixed at /home/webadmin/templates')
        target=Path(args.target_root or old.get('target_root','/home/webadmin/servers'))
        if not source.is_absolute() or not target.is_absolute() or source==target:raise ValueError('Distinct absolute source/target roots required')
        dangerous={'/','/etc','/root','/usr','/bin','/sbin','/var','/var/lib','/home','/run','/tmp','/dev','/proc','/sys'}
        if str(source) in dangerous or str(target) in dangerous:raise ValueError('Choose dedicated game directories, not a system directory')
        previous_source=Path(old.get('source_root',str(source)))
        if previous_source!=source and previous_source.is_dir() and not previous_source.is_symlink():
            catalog_file=Path('/var/lib/webadmin/minecraft/catalog.json')
            if catalog_file.exists() and json.loads(catalog_file.read_text()).get('state')=='running':raise ValueError('Wait for Minecraft synchronization before moving templates')
            for job_id in list(cfg.get('_copy_jobs',{}).values())+list(cfg.get('_version_jobs',{}).values()):
                job_file=Path('/var/lib/webadmin/provision/jobs')/(job_id+'.json')
                if job_file.exists() and json.loads(job_file.read_text()).get('state') in ('queued','running'):raise ValueError('Wait for active server copies/version changes before moving templates')
            safe(previous_source)
            if source.exists():
                if any(source.iterdir()):raise ValueError('Both old and new template roots contain files; automatic merge would overwrite templates')
                source.rmdir()
            if target.exists() and str(target)=='/home/webadmin/servers' and target.stat().st_uid==account.pw_uid:os.chown(target,0,0);os.chmod(target,0o755)
            source.parent.mkdir(parents=True,exist_ok=True)
            shutil.move(str(previous_source),str(source))
            # Existing Java launch paths keep working after the relocation.
            previous_source.symlink_to(source,target_is_directory=True)
            catalog=Path('/var/lib/webadmin/minecraft/catalog.json')
            if catalog.exists():save(catalog,catalog.read_text().replace(str(previous_source),str(source)).encode(),0o600)
        elif previous_source!=source and previous_source.is_symlink() and previous_source.resolve()==source:
            previous_source.unlink()
        for folder in (target,source):
            if not folder.exists():folder.mkdir(parents=True,mode=0o755);os.chmod(folder,0o755)
            if folder==target and str(folder)=='/home/webadmin/servers' and folder.stat().st_uid==account.pw_uid:
                os.chown(folder,0,0);os.chmod(folder,0o755)
            safe(folder)
        if source.resolve()!=source or target.resolve()!=target or source!=Path('/home/webadmin/templates'):raise ValueError('Templates must use the protected /home/webadmin/templates directory')
        users={}
        for game in ('minecraft','cs16','cs2','fivem','css'):
            users[game]=old.get('users',{}).get(game,game);ensure_user(users[game],True)
            folder=source/game
            if not folder.exists():folder.mkdir(mode=0o755);os.chmod(folder,0o755)
        cfg['_provision']={'source_root':str(source),'target_root':str(target),'users':users,'allow_custom_games':True}
        save(CONFIG,(json.dumps(cfg,indent=2)+'\n').encode(),0o600)
    root=Path(__file__).resolve().parent
    for name,destination in [('webadmin-storage','/usr/local/sbin/webadmin-storage'),('webadmin-ftp','/usr/local/sbin/webadmin-ftp'),('webadmin-automation','/usr/local/sbin/webadmin-automation'),('webadmin-versions','/usr/local/sbin/webadmin-versions'),('webadmin-provision','/usr/local/sbin/webadmin-provision'),('webadmin-launch','/usr/local/sbin/webadmin-launch'),('webadmin_runtime.py','/usr/local/libexec/webadmin_runtime.py'),('webadmin-templates','/usr/local/sbin/webadmin-templates'),('webadmin-runner','/usr/local/libexec/webadmin-runner')]:
        save(Path(destination),(root/name).read_bytes(),0o755)
    cron=next((path for path in ('/usr/sbin/cron','/usr/sbin/crond') if Path(path).is_file()),None)
    if not cron or not Path('/usr/sbin/service').is_file():raise ValueError('A running cron service is required; install cron and tzdata before setup')
    cron_dir=Path('/etc/cron.d');cron_dir.mkdir(exist_ok=True);safe(cron_dir)
    save(cron_dir/'webadmin-automation',b'SHELL=/bin/sh\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n* * * * * root /usr/bin/python3 -I /usr/local/sbin/webadmin-automation tick >> /var/log/webadmin-automation.log 2>&1\n* * * * * root /usr/bin/python3 -I /usr/local/sbin/webadmin-ftp maintain >> /var/log/webadmin-automation.log 2>&1\n',0o644)
    log=Path('/var/log/webadmin-automation.log')
    if not log.exists():
        descriptor=os.open(log,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600);os.close(descriptor)
    subprocess.run(['/usr/sbin/service',Path(cron).name,'start'],check=True,capture_output=True,timeout=20)
    # Broad helper access is confined by immutable root policy; no shell/systemctl sudo is granted.
    sudo_info=subprocess.run(['sudo','--version'],capture_output=True,text=True,check=False)
    sudo_version=(sudo_info.stdout or '')+' '+(sudo_info.stderr or '')
    ftp_tag=ftp_sudo_input_tag(sudo_version)
    sudoers=(args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/webadmin-versions create *\n'+args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/webadmin-versions status *\n'+args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/webadmin-provision create *\n'+
             args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/webadmin-provision status *\n'+
             args.ssh_user+' ALL=(root) NOPASSWD: '+ftp_tag+'/usr/local/sbin/webadmin-ftp list *, /usr/local/sbin/webadmin-ftp create *, /usr/local/sbin/webadmin-ftp delete *\n'+args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/webadmin-automation list *, /usr/local/sbin/webadmin-automation schedule_add *, /usr/local/sbin/webadmin-automation schedule_delete *, /usr/local/sbin/webadmin-automation backup *, /usr/local/sbin/webadmin-automation restore *, /usr/local/sbin/webadmin-automation backup_delete *, /usr/local/sbin/webadmin-automation ack_restore *, /usr/local/sbin/webadmin-automation policy *, /usr/local/sbin/webadmin-automation clear_schedules *\n'+args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/webadmin-launch *\n'+args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/webadmin-templates catalog\n'+args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/webadmin-templates sync\n'+args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/webadmin-templates delete *, /usr/local/sbin/webadmin-templates releases *, /usr/local/sbin/webadmin-templates install *, /usr/local/sbin/webadmin-templates custom-save *, /usr/local/sbin/webadmin-templates steam-install *\n')
    folder=Path('/etc/sudoers.d');folder.mkdir(exist_ok=True);safe(folder)
    descriptor,temporary=tempfile.mkstemp(dir=folder,prefix='webadmin-check-')
    try:
        with os.fdopen(descriptor,'w') as stream:stream.write(sudoers)
        subprocess.run(['visudo','-cf',temporary],check=True,capture_output=True)
        os.chmod(temporary,0o440);os.replace(temporary,folder/'webadmin-panel')
    finally:
        if os.path.exists(temporary):os.unlink(temporary)
    # Syntax alone is insufficient: a later sudoers rule can override this grant.
    try:
        subprocess.run(['sudo','-n','-l','-U',args.ssh_user,'--','/usr/local/sbin/webadmin-templates','delete','webadmin-policy-check'],check=True,capture_output=True)
    except subprocess.CalledProcessError as error:
        raise ValueError('The effective sudo policy denies webadmin-templates delete for '+args.ssh_user+'. Check conflicting sudoers rules; the helper has not been executed.') from error
    if args.setup_ftp:
        loader=importlib.machinery.SourceFileLoader('ftp_setup',str(root/'webadmin-ftp'));spec=importlib.util.spec_from_loader(loader.name,loader);ftp=importlib.util.module_from_spec(spec);loader.exec_module(ftp);ftp.install(args.ftp_host)
    ssh=Path(account.pw_dir)/'.ssh'
    if not ssh.exists():
        ssh.mkdir(mode=0o700);os.chown(ssh,account.pw_uid,account.pw_gid)
    if args.panel_public_key_file:
        import base64
        key=Path(args.panel_public_key_file).read_text().strip()
        if not re.fullmatch(r'(?:ssh-rsa|ssh-ed25519|ecdsa-sha2-nistp256) [A-Za-z0-9+/=]+(?: [^\r\n]*)?',key):raise ValueError('Invalid public SSH key')
        if ssh.is_symlink():raise ValueError('Unsafe SSH directory')
        os.chown(ssh,account.pw_uid,account.pw_gid);os.chmod(ssh,0o700)
        directory=os.open(ssh,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:
            descriptor=os.open('authorized_keys',os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600,dir_fd=directory)
            with os.fdopen(descriptor,'r+') as stream:
                current=stream.read()
                if key not in current.splitlines():stream.write(('\n' if current and not current.endswith('\n') else '')+key+'\n')
                os.fchown(stream.fileno(),account.pw_uid,account.pw_gid);os.fchmod(stream.fileno(),0o600)
        finally:os.close(directory)
    print('Linux helpers installed. Existing server records and SSH authorized_keys were preserved.')
    print('Template root: '+str(source));print('New server root: '+str(target))
    print('Put complete game template files into each game subfolder; game users need read access.')
    print('Add the panel public SSH key to '+str(ssh/'authorized_keys')+' if not already present.')

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--setup-ftp',action='store_true');parser.add_argument('--ftp-host');parser.add_argument('--source-root');parser.add_argument('--target-root');parser.add_argument('--ssh-user',default='webadmin');parser.add_argument('--panel-public-key-file')
    try:install(parser.parse_args())
    except (ValueError,OSError,subprocess.SubprocessError) as error:
        detail=getattr(error,'stderr',None)
        if isinstance(detail,bytes):detail=detail.decode('utf-8',errors='replace')
        if detail:print(str(detail)[-12000:],file=sys.stderr)
        parser.exit(1,str(error)+'\n')
