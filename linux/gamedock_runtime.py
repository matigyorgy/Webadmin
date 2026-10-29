"""Trusted Linux process helpers, installed root-owned outside the web root."""
import ctypes
import os
import signal

def identity(pid):
    try:
        with open('/proc/'+str(int(pid))+'/stat') as stream:fields=stream.read().rsplit(') ',1)[1].split()
        if fields[0]=='Z':return None
        return {'pid':int(pid),'start':fields[19],'uid':os.stat('/proc/'+str(int(pid))).st_uid}
    except (OSError,ValueError,IndexError):return None

def alive(record,uid):
    return bool(record and record.get('uid')==uid and identity(record['pid'])==record)

def send(record,uid,signum):
    if not alive(record,uid):return
    try:descriptor=os.pidfd_open(record['pid'])
    except ProcessLookupError:return
    try:
        if alive(record,uid):signal.pidfd_send_signal(descriptor,signum)
    except ProcessLookupError:pass
    finally:os.close(descriptor)

def drop(account):
    def prepare():
        os.setgroups([]);os.setgid(account.pw_gid);os.setuid(account.pw_uid)
        if ctypes.CDLL(None,use_errno=True).prctl(38,1,0,0,0)!=0:raise OSError('Cannot enable no-new-privileges')
    return prepare

def environment(account=None):
    result={'PATH':'/usr/local/bin:/usr/bin:/bin','LANG':'C.UTF-8'}
    if account:result.update(HOME=account.pw_dir,USER=account.pw_name,LOGNAME=account.pw_name)
    return result

# cgroup files remain root-owned: a game cannot lift its own limits or escape.
CGROUP_ROOT='/sys/fs/cgroup'

def cgroup_path(name):
    import hashlib
    return os.path.join(CGROUP_ROOT,'gamedock',hashlib.sha256(name.encode()).hexdigest())

def limits_payload(payload):
    import base64
    import json
    if len(payload)>512:raise ValueError('Invalid limits')
    data=json.loads(base64.b64decode(payload,validate=True))
    if not isinstance(data,dict):raise ValueError('Invalid limits')
    result={}
    for key,maximum in [('memory_limit_mb',1048576),('cpu_limit_percent',102400)]:
        value=data.get(key,0)
        if type(value) is not int or not 0<=value<=maximum:raise ValueError('Invalid limits')
        result[key]=value
    if 'port' in data:
        if type(data['port']) is not int or not 25000<=data['port']<=25999:raise ValueError('Invalid port')
        result['port']=data['port']
    return result

def prepare_cgroup(name,limits):
    from pathlib import Path
    memory=limits.get('memory_limit_mb',0);cpu=limits.get('cpu_limit_percent',0)
    try:
        root=Path(CGROUP_ROOT);parent=root/'gamedock'
        available=(root/'cgroup.controllers').read_text().split()
        if not {'cpu','memory'}.issubset(available):raise ValueError('CPU/memory cgroup v2 controllers unavailable')
        # Enable only the two controllers required by the panel.
        (root/'cgroup.subtree_control').write_text('+cpu +memory')
        parent.mkdir(exist_ok=True)
        if parent.is_symlink() or parent.stat().st_uid!=0 or parent.stat().st_mode & 0o022:raise ValueError('Unsafe cgroup parent')
        (parent/'cgroup.subtree_control').write_text('+cpu +memory')
        group=Path(cgroup_path(name));group.mkdir(exist_ok=True)
        if group.is_symlink() or group.stat().st_uid!=0 or group.stat().st_mode & 0o022:raise ValueError('Unsafe cgroup')
        if (group/'cgroup.procs').read_text().strip():raise ValueError('Server cgroup still has running processes')
        (group/'memory.max').write_text(str(memory*1048576) if memory else 'max')
        swap=group/'memory.swap.max'
        if swap.exists():swap.write_text('0' if memory else 'max')
        (group/'cpu.max').write_text(str(cpu*1000)+' 100000' if cpu else 'max 100000')
        (group/'memory.oom.group').write_text('1')
        return str(group)
    except (OSError,ValueError):
        if memory or cpu:raise ValueError('Resource limits require writable cgroup v2 CPU and memory controllers; rerun Linux setup or check host virtualization')
        return None

def drop_in_cgroup(account,group):
    def prepare():
        if group:
            with open(group+'/cgroup.procs','w') as stream:stream.write(str(os.getpid()))
        drop(account)()
    return prepare

def group_records(name,uid):
    try:
        with open(cgroup_path(name)+'/cgroup.procs') as stream:pids=stream.read().split()
        return [record for pid in pids if (record:=identity(int(pid))) and record['uid']==uid]
    except FileNotFoundError:return []

def usage_snapshot(name,records,use_cgroup=True):
    from pathlib import Path
    group=Path(cgroup_path(name))
    if use_cgroup and group.is_dir():
        stats=dict(line.split() for line in (group/'cpu.stat').read_text().splitlines())
        return int(stats['usage_usec'])/1000000,int((group/'memory.current').read_text()),True
    ticks=0;memory=0
    for record in records:
        if not alive(record,record['uid']):continue
        try:
            fields=Path('/proc/'+str(record['pid'])+'/stat').read_text().rsplit(') ',1)[1].split()
            ticks+=int(fields[11])+int(fields[12]);memory+=int(fields[21])*os.sysconf('SC_PAGE_SIZE')
        except (OSError,ValueError,IndexError):continue
    return ticks/os.sysconf('SC_CLK_TCK'),memory,False


def kill_group(name):
    from pathlib import Path
    group=Path(cgroup_path(name))
    if not group.is_dir():return
    kill=group/'cgroup.kill'
    if kill.exists():kill.write_text('1')
    else:
        # Older cgroup v2 kernels lack cgroup.kill. Identity checks prevent PID reuse.
        for _ in range(4):
            with open(group/'cgroup.procs') as stream:pids=stream.read().split()
            if not pids:return
            for pid in pids:
                record=identity(int(pid))
                if record:send(record,record['uid'],signal.SIGKILL)
