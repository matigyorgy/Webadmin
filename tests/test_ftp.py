import contextlib,copy,hashlib,importlib.machinery,importlib.util,os,tempfile,unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
loader=importlib.machinery.SourceFileLoader('ftp','linux/gamedock-ftp');spec=importlib.util.spec_from_loader(loader.name,loader);f=importlib.util.module_from_spec(spec);loader.exec_module(f)
class FtpTests(unittest.TestCase):
 def record(self):
  password='Fixture-FTP-password-123';salt=b'0123456789abcdef'
  return {'username':'server_user','unit':'12345678','created_at':1000,'last_login':None,'binding':{'user':'game','directory':'/home/gamedock/1','provision_job':'job'},'salt':salt.hex(),'hash':hashlib.scrypt(password.encode(),salt=salt,n=16384,r=8,p=1,dklen=32).hex()}
 def test_expiry_uses_successful_login_or_creation(self):
  record=self.record();self.assertEqual(f.expires(record),1000+15*86400);record['last_login']=9000;self.assertEqual(f.expires(record),9000+15*86400)
 def test_clean_deletes_at_exact_boundary_and_preserves_fresh_accounts(self):
  first=self.record();second=copy.deepcopy(first);second['created_at']=2000;rows={'old':first,'fresh':second}
  with patch.object(f,'instance',return_value=(second['binding'],None,None)):
   removed=f.clean(rows,1000+f.TTL)
  self.assertEqual(removed,['old']);self.assertEqual(list(rows),['fresh'])
 def test_instance_replacement_revokes_old_access(self):
  rows={'server_user':self.record()}
  with patch.object(f,'instance',return_value=({'user':'different','directory':'/home/gamedock/2','provision_job':'new'},None,None)):self.assertEqual(f.clean(rows,1001),['server_user'])
 def test_plain_and_tls_auth_only_updates_last_login(self):
  with tempfile.TemporaryDirectory() as folder:
   rows={'server_user':self.record()};server=rows['server_user']['binding'];account=SimpleNamespace(pw_uid=1001,pw_gid=1001)
   env={'AUTHD_ACCOUNT':'server_user','AUTHD_PASSWORD':'Fixture-FTP-password-123','AUTHD_ENCRYPTED':'1'}
   with patch.object(f,'CONTROL',Path(folder)),patch.object(f,'locked',return_value=contextlib.nullcontext()),patch.object(f,'read',side_effect=lambda path,default:rows),patch.object(f,'instance',return_value=(server,account,Path(server['directory']))),patch.object(f,'busy',return_value=False),patch.object(f.p,'secure'),patch.object(f.p,'atomic'),patch.object(f.time,'time',return_value=2000):
    self.assertIsNone(f.authenticate(dict(env,AUTHD_PASSWORD='incorrect')));self.assertIsNone(rows['server_user']['last_login'])
    self.assertEqual(f.authenticate(dict(env,AUTHD_ENCRYPTED='0'))['uid'],1001);self.assertEqual(rows['server_user']['last_login'],2000)
    result=f.authenticate(env);self.assertEqual(result['uid'],1001);self.assertEqual(result['dir'],'/home/gamedock/1/./');self.assertEqual(rows['server_user']['last_login'],2000)
 def test_expired_password_cannot_revive_account(self):
  record=self.record();rows={'server_user':record}
  with patch.object(f,'locked',return_value=contextlib.nullcontext()),patch.object(f,'read',return_value=rows),patch.object(f.p,'atomic'),patch.object(f,'disconnect_names'),patch.object(f.time,'time',return_value=1000+f.TTL):
   self.assertIsNone(f.authenticate({'AUTHD_ACCOUNT':'server_user','AUTHD_PASSWORD':'Fixture-FTP-password-123','AUTHD_ENCRYPTED':'1'}))
  self.assertEqual(rows,{})
 def test_invalid_names_and_passwords_are_rejected(self):
  with patch.object(f,'instance',return_value=({},None,None)):
   for name in ['root','ftp','anonymous','bad;id','../server','ab','User']:
    with self.assertRaisesRegex(ValueError,'username'):f.create('12345678',{'username':name,'password':'Fixture-FTP-password-123'})
   for password in ['short','x'*129,'password\nsecret123']:
    with self.assertRaisesRegex(ValueError,'password'):f.create('12345678',{'username':'valid_user','password':password})
 def test_session_revocation_never_signals_another_daemon(self):
  master={'pid':100,'uid':0,'start':'master'};record={'pid':200,'uid':1001,'start':'client'}
  with patch.object(f,'read',return_value={'server':master}),patch.object(f.p.runtime,'alive',return_value=True),patch.object(f.subprocess,'run',return_value=SimpleNamespace(stdout='200|server_user|0|IDLE|none|ip|ip|2121|0|0|0|0\n')),patch.object(f.p.runtime,'identity',return_value=record),patch.object(f.Path,'read_text',return_value='pure-ftpd'),patch.object(f,'descendant',return_value=False),patch.object(f.p.runtime,'send') as send:
   f.disconnect_names(['server_user']);send.assert_not_called()
