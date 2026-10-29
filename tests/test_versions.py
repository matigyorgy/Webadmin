import contextlib,importlib.machinery,importlib.util,json,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
loader=importlib.machinery.SourceFileLoader('versions_helper','linux/gamedock-versions');spec=importlib.util.spec_from_loader(loader.name,loader);v=importlib.util.module_from_spec(spec);loader.exec_module(v)

class VersionTests(unittest.TestCase):
 def test_version_copy_preserves_world_and_configuration(self):
  with tempfile.TemporaryDirectory() as folder:
   root=Path(folder);source=root/'template';source.mkdir();current=root/'current';current.mkdir();stage=root/'stage';stage.mkdir()
   (current/'world').mkdir();(current/'world'/'level.dat').write_text('valuable world');(current/'server.properties').write_text('motd=custom');(current/'server.jar').write_text('old jar')
   (source/'server.jar').write_text('new jar');(source/'server.properties').write_text('default overwrite');(source/'world').mkdir();(source/'world'/'level.dat').write_text('bad overwrite')
   with patch.object(v.os,'geteuid',return_value=1001):v.copy(str(source),str(current),str(stage))
   self.assertEqual((stage/'server.jar').read_text(),'new jar');self.assertEqual((stage/'world'/'level.dat').read_text(),'valuable world');self.assertEqual((stage/'server.properties').read_text(),'motd=custom');self.assertEqual((current/'server.jar').read_text(),'old jar')
 def test_copy_refuses_root(self):
  with patch.object(v.os,'geteuid',return_value=0):
   with self.assertRaises(ValueError):v.copy('/unused','/unused','/unused')
 def test_crash_recovery(self):
  for switched in (True,False):
   with self.subTest(switched=switched),tempfile.TemporaryDirectory() as folder:
    root=Path(folder);backup=root/'backup';backup.mkdir();(backup/'world').write_text('original');target=root/'current';stage=root/'stage'
    if switched:target.mkdir()
    else:stage.mkdir()
    token='a'*32;job={'operation':'version','state':'running','worker':{'pid':99999},'unit':'12345678','target':str(target),'backup':str(backup),'stage':str(stage),'phase':'switching','message':''}
    path=root/(token+'.json');path.write_text(json.dumps(job))
    with patch.object(v.p,'JOBS',str(root)),patch.object(v.p,'locked',side_effect=contextlib.nullcontext),patch.object(v,'unit_lock',side_effect=lambda unit:contextlib.nullcontext()),patch.object(v.p.runtime,'alive',return_value=False):result=v.status(token)
    self.assertEqual(result['state'],'ready' if switched else 'failed');self.assertTrue(target.is_dir())
    if not switched:self.assertEqual((target/'world').read_text(),'original')

if __name__=='__main__':unittest.main()
