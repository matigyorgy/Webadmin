import contextlib,importlib.machinery,importlib.util,json,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
from types import SimpleNamespace
loader=importlib.machinery.SourceFileLoader('versions_helper','linux/gamedock-versions');spec=importlib.util.spec_from_loader(loader.name,loader);v=importlib.util.module_from_spec(spec);loader.exec_module(v)

class VersionTests(unittest.TestCase):
 def test_version_copy_preserves_world_and_configuration(self):
  with tempfile.TemporaryDirectory() as folder:
   root=Path(folder);source=root/'template';source.mkdir();current=root/'current';current.mkdir();stage=root/'stage';stage.mkdir()
   (current/'world').mkdir();(current/'world'/'level.dat').write_text('valuable world');(current/'server.properties').write_text('motd=custom');(current/'server.jar').write_text('old jar')
   (source/'server.jar').write_text('new jar');(source/'server.properties').write_text('default overwrite');(source/'world').mkdir();(source/'world'/'level.dat').write_text('bad overwrite')
   with patch.object(v.os,'geteuid',return_value=1001):v.copy(str(source),str(current),str(stage))
   self.assertEqual((stage/'server.jar').read_text(),'new jar');self.assertEqual((stage/'world'/'level.dat').read_text(),'valuable world');self.assertEqual((stage/'server.properties').read_text(),'motd=custom');self.assertEqual((current/'server.jar').read_text(),'old jar')
 def test_clean_reinstall_uses_only_template_and_rebuilds_port_config(self):
  with tempfile.TemporaryDirectory() as folder:
   root=Path(folder);source=root/'template';source.mkdir();current=root/'current';current.mkdir();stage=root/'stage';stage.mkdir()
   (current/'world').mkdir();(current/'world'/'level.dat').write_text('valuable world');(current/'server.properties').write_text('motd=old');(source/'server.jar').write_text('new jar');(source/'server.properties').write_text('motd=new')
   with patch.object(v.os,'geteuid',return_value=1001):v.copy(str(source),str(current),str(stage),True,{'game':'minecraft','port':25577,'minecraft':True,'eula':True})
   self.assertFalse((stage/'world').exists());self.assertEqual((current/'world'/'level.dat').read_text(),'valuable world')
   self.assertIn('server-port=25577',(stage/'server.properties').read_text());self.assertIn('motd=new',(stage/'server.properties').read_text());self.assertEqual((stage/'eula.txt').read_text(),'eula=true\n')
 def test_reinstall_requires_explicit_confirmation_before_touching_files(self):
  for value in (None,False,'1',1):
   with self.subTest(value=value),self.assertRaisesRegex(ValueError,'explicit confirmation'):
    v.create({'job':'a'*32,'unit':'12345678','template_key':'paper:1.0:1','reinstall':True,'confirmed':value})
 def test_linux_helper_rejects_live_instance_before_staging(self):
  with tempfile.TemporaryDirectory() as folder:
   unit='12345678';server={'user':'game'}
   with patch.object(v.p,'prepare'),patch.object(v,'create_lock',side_effect=contextlib.nullcontext),patch.object(v,'unit_lock',side_effect=lambda *a:contextlib.nullcontext()),patch.object(v.p,'config',return_value={unit:server}),patch.object(v.p,'JOBS',folder),patch.object(v,'CONTROL',folder),patch.object(v.pwd,'getpwnam',return_value=SimpleNamespace(pw_uid=1001)),patch.object(v.p.runtime,'alive',return_value=False),patch.object(v.p.runtime,'group_records',return_value=[{'pid':123}]):
    with self.assertRaisesRegex(ValueError,'Stop the server'):v.create({'job':'a'*32,'unit':unit,'template_key':'paper:1.0:1'})
 def test_copy_refuses_root(self):
  with patch.object(v.os,'geteuid',return_value=0):
   with self.assertRaises(ValueError):v.copy('/unused','/unused','/unused')
 def test_crash_recovery(self):
  for switched in (True,False):
   with self.subTest(switched=switched),tempfile.TemporaryDirectory() as folder:
    root=Path(folder);backup=root/'backup';backup.mkdir();(backup/'world').write_text('original');target=root/'current';stage=root/'stage'
    if switched:target.mkdir()
    else:stage.mkdir()
    token='a'*32;job={'template':{'key':'paper:1.21.1:1','type':'paper','version':'1.21.1'},'operation':'version','state':'running','worker':{'pid':99999},'unit':'12345678','target':str(target),'backup':str(backup),'stage':str(stage),'phase':'switching','message':''}
    path=root/(token+'.json');path.write_text(json.dumps(job))
    with patch.object(v.p,'CONFIG',str(root/'servers.json')),patch.object(v.p,'config',return_value={'12345678':{}}),patch.object(v.p,'JOBS',str(root)),patch.object(v.p,'locked',side_effect=contextlib.nullcontext),patch.object(v,'unit_lock',side_effect=lambda unit:contextlib.nullcontext()),patch.object(v.p.runtime,'alive',return_value=False):result=v.status(token)
    self.assertEqual(result['state'],'ready' if switched else 'failed');self.assertTrue(target.is_dir())
    if not switched:self.assertEqual((target/'world').read_text(),'original')

if __name__=='__main__':unittest.main()
