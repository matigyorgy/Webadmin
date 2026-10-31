import contextlib,errno,importlib.machinery,importlib.util,json,os,tempfile,unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
loader=importlib.machinery.SourceFileLoader('storage','linux/gamedock-storage');spec=importlib.util.spec_from_loader(loader.name,loader);s=importlib.util.module_from_spec(spec);loader.exec_module(s)
class StorageTests(unittest.TestCase):
 def test_unsupported_filesystem_rejected_before_mutation(self):
  for fs,options in [('overlay','rw'),('ext4','rw'),('xfs','rw,pqnoenforce')]:
   with self.subTest(fs=fs,options=options),patch.object(s.subprocess,'run',return_value=SimpleNamespace(stdout=json.dumps({'filesystems':[{'target':'/srv','source':'/dev/fixture','fstype':fs,'options':options}]}))):
    with self.assertRaisesRegex(ValueError,'Project quota support required'):s.mount_info('/srv/game')
 def test_project_quota_mounts_supported(self):
  for fs,options in [('ext4','rw,prjquota'),('xfs','rw,pquota')]:
   with patch.object(s.subprocess,'run',return_value=SimpleNamespace(stdout=json.dumps({'filesystems':[{'target':'/srv','source':'/dev/fixture','fstype':fs,'options':options}]}))):self.assertEqual(s.mount_info('/srv/game')['fstype'],fs)
 def test_no_hard_limit_committed_for_oversized_snapshot(self):
  quota=s.Quota();quota.space=11*1024**2
  with patch.object(s,'prepare'),patch.object(s,'mount_info',return_value={}),patch.object(s,'quota_info'),patch.object(s,'allocate',return_value=1000000001),patch.object(s,'tag_tree'),patch.object(s,'get_quota',return_value=quota),patch.object(s,'set_quota') as set_quota,patch.object(s,'mark_initialized') as mark:
   with self.assertRaisesRegex(ValueError,'smaller than occupied'):s.attach('12345678','/fixture',10)
   set_quota.assert_called_once_with({},1000000001,0);mark.assert_not_called()
 def test_snapshot_project_gets_actual_hard_limit(self):
  quota=s.Quota();quota.space=9*1024**2
  with patch.object(s,'prepare'),patch.object(s,'mount_info',return_value={}),patch.object(s,'quota_info'),patch.object(s,'allocate',return_value=1000000001),patch.object(s,'tag_tree') as tag,patch.object(s,'get_quota',return_value=quota),patch.object(s,'set_quota') as set_quota,patch.object(s,'mark_initialized') as mark:
   self.assertEqual(s.attach('12345678','/fixture',10),1000000001);self.assertEqual(set_quota.call_args.args,({},1000000001,10));tag.assert_called_once();mark.assert_called_once()
 def test_foreign_project_is_never_retagged(self):
  with tempfile.TemporaryDirectory() as directory,patch.object(s,'read',return_value={}),patch.object(s,'attrs',return_value=[0,0,0,42,0,b'\0'*8]),patch.object(s,'set_attrs') as write:
   with self.assertRaisesRegex(ValueError,'Foreign'):s.tag_tree(directory,1000000001,'12345678')
   write.assert_not_called()
 def test_project_quota_uses_kib_and_preserves_unrelated_fields(self):
  with patch.object(s,'quota_call') as call:
   s.set_quota({},1000000001,10240);command=call.call_args.args;self.assertEqual(command[1],0x800008);self.assertEqual(command[2],1000000001);self.assertEqual(command[3].hard,10240*1024);self.assertEqual(command[3].valid,1);self.assertEqual(command[3].ihard,0)
 def test_limit_zero_does_not_require_quota_filesystem(self):
  with tempfile.TemporaryDirectory() as directory:
   target=Path(directory)/'server';target.mkdir();cfg={'12345678':{'directory':str(target),'disk_limit_mb':0}};launch=SimpleNamespace(metadata=lambda unit:{},active=lambda data,account:False);ftp=SimpleNamespace(disconnect_unit=lambda unit:None)
   with patch.object(s,'ROOT',Path(directory)/'storage'),patch.object(s,'CONTROL',Path(directory)/'control'),patch.object(s.p,'secure'),patch.object(s,'instance',return_value=(cfg,cfg['12345678'],SimpleNamespace(),target)),patch.object(s,'module',side_effect=lambda name:launch if name=='gamedock-launch' else ftp),patch.object(s,'project_id',side_effect=OSError(errno.ENOTTY,'unsupported')),patch.object(s.p,'locked',return_value=contextlib.nullcontext()),patch.object(s.p,'config',return_value=cfg),patch.object(s.p,'atomic'),patch.object(s,'mount_info') as mount:
    self.assertEqual(s.apply_limit('12345678',0),{'disk_limit_mb':0,'enforced':False});mount.assert_not_called()
 def test_storage_failure_does_not_zero_cpu_or_other_metrics(self):
  # A failed quota lookup retains the configured cap and reports it as unenforced.
  with tempfile.TemporaryDirectory() as directory:
   target=Path(directory)/'server';target.mkdir();cache={'bytes':8192,'measured_at':int(s.time.time()),'state':'ready','inode':target.stat().st_ino}
   with patch.object(s,'ROOT',Path(directory)/'storage'),patch.object(s,'CONTROL',Path(directory)/'control'),patch.object(s.p,'secure'),patch.object(s,'instance',return_value=({}, {'disk_limit_mb':10},None,target)),patch.object(s,'read',return_value=cache),patch.object(s,'project_id',side_effect=OSError(errno.EOPNOTSUPP,'unsupported')):
    result=s.metrics('12345678');self.assertEqual(result['disk_bytes'],8192);self.assertEqual(result['disk_state'],'unsupported');self.assertFalse(result['disk_enforced']);self.assertEqual(result['disk_limit_mb'],10)
