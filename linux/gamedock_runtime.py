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
