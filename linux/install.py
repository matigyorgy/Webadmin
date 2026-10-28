#!/usr/bin/python3
"""One-time local Linux setup. Run from the downloaded release as root, never from the web."""
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

CONFIG=Path('/etc/gamedock/servers.json')

def safe(path):
    info=path.lstat()
    if stat.S_ISLNK(info.st_mode) or info.st_uid!=0 or info.st_mode & (stat.S_IWGRP|stat.S_IWOTH):raise ValueError('Unsafe root-owned path: '+str(path))

def save(path,data,mode):
    descriptor,temporary=tempfile.mkstemp(dir=path.parent,prefix='.gamedock-')
    try:
        with os.fdopen(descriptor,'wb') as stream:stream.write(data)
        os.chmod(temporary,mode);os.replace(temporary,path)
    finally:
        if os.path.exists(temporary):os.unlink(temporary)

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
    for executable in ('python3','systemctl','systemd-run','runuser','sudo','visudo','useradd','bash'):
        if not shutil.which(executable):raise ValueError('Required Linux executable is missing: '+executable)
    account=ensure_user(args.ssh_user)
    for folder in (Path('/etc/gamedock'),Path('/usr/local/libexec'),Path('/usr/local/sbin')):
        folder.mkdir(parents=True,exist_ok=True);safe(folder)
    with open(str(CONFIG)+'.lock','a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        cfg={}
        if CONFIG.exists():
            safe(CONFIG);cfg=json.loads(CONFIG.read_text())
        old=cfg.get('_provision',{})
        source=Path(args.source_root or old.get('source_root','/srv/gamedock/templates'))
        target=Path(args.target_root or old.get('target_root','/srv/games'))
        if not source.is_absolute() or not target.is_absolute() or source==target:raise ValueError('Distinct absolute source/target roots required')
        dangerous={'/','/etc','/root','/usr','/bin','/sbin','/var','/var/lib','/home','/run','/tmp','/dev','/proc','/sys'}
        if str(source) in dangerous or str(target) in dangerous:raise ValueError('Choose dedicated game directories, not a system directory')
        for folder in (source,target):
            if not folder.exists():folder.mkdir(parents=True,mode=0o755);os.chmod(folder,0o755)
            safe(folder)
        if source.resolve()==target.resolve() or source.resolve() in target.resolve().parents or target.resolve() in source.resolve().parents:raise ValueError('Source/target roots must not overlap')
        users={}
        for game in ('minecraft','cs2','fivem','css'):
            users[game]=old.get('users',{}).get(game,game);ensure_user(users[game],True)
            folder=source/game
            if not folder.exists():folder.mkdir(mode=0o755);os.chmod(folder,0o755)
        cfg['_provision']={'source_root':str(source),'target_root':str(target),'users':users}
        save(CONFIG,(json.dumps(cfg,indent=2)+'\n').encode(),0o600)
    root=Path(__file__).resolve().parent
    for name,destination in [('gamedock-provision','/usr/local/sbin/gamedock-provision'),('gamedock-launch','/usr/local/sbin/gamedock-launch'),('gamedock-console','/usr/local/sbin/gamedock-console'),('gamedock-runner','/usr/local/libexec/gamedock-runner')]:
        save(Path(destination),(root/name).read_bytes(),0o755)
    # Broad helper access is confined by immutable root policy; no shell/systemctl sudo is granted.
    sudoers=(args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/gamedock-provision create *\n'+
             args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/gamedock-provision status *\n'+
             args.ssh_user+' ALL=(root) NOPASSWD: /usr/local/sbin/gamedock-launch *\n')
    folder=Path('/etc/sudoers.d');folder.mkdir(exist_ok=True);safe(folder)
    descriptor,temporary=tempfile.mkstemp(dir=folder,prefix='gamedock-check-')
    try:
        with os.fdopen(descriptor,'w') as stream:stream.write(sudoers)
        subprocess.run(['visudo','-cf',temporary],check=True,capture_output=True)
        os.chmod(temporary,0o440);os.replace(temporary,folder/'gamedock-panel')
    finally:
        if os.path.exists(temporary):os.unlink(temporary)
    ssh=Path(account.pw_dir)/'.ssh'
    if not ssh.exists():
        ssh.mkdir(mode=0o700);os.chown(ssh,account.pw_uid,account.pw_gid)
    print('Linux helpers installed. Existing server records and SSH authorized_keys were preserved.')
    print('Template root: '+str(source));print('New server root: '+str(target))
    print('Put complete game template files into each game subfolder; game users need read access.')
    print('Add the panel public SSH key to '+str(ssh/'authorized_keys')+' if not already present.')

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--source-root');parser.add_argument('--target-root');parser.add_argument('--ssh-user',default='gamedock')
    try:install(parser.parse_args())
    except (ValueError,OSError,subprocess.SubprocessError) as error:parser.exit(1,str(error)+'\n')
