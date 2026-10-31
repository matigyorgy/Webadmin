"""SteamCMD workflow with fake downloads; never downloads game depots."""
import importlib.machinery,importlib.util,io,json,os,subprocess,tarfile,tempfile,unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
loader=importlib.machinery.SourceFileLoader('steam_templates','linux/gamedock-templates')
spec=importlib.util.spec_from_loader(loader.name,loader);mc=importlib.util.module_from_spec(spec);loader.exec_module(mc)

class SteamTests(unittest.TestCase):
 def test_only_supported_games(self):
  with self.assertRaisesRegex(ValueError,'Unsupported Steam game'):mc.steam_start('cs2;id')
 def test_start_is_serialized_and_launch_failure_recovers(self):
  for succeeds in [True,False]:
   with self.subTest(succeeds=succeeds),tempfile.TemporaryDirectory() as folder:
    original_exists=Path.exists
    def exists(path):return True if str(path)=='/lib/ld-linux.so.2' else original_exists(path)
    with patch.object(mc,'CACHE',folder),patch.object(mc,'cfg',return_value={'_provision':{'users':{'cs2':'fixture'}}}),patch.object(mc.platform,'machine',return_value='x86_64'),patch.object(mc.Path,'exists',exists),patch.object(mc.pwd,'getpwnam',return_value=SimpleNamespace(pw_uid=1001)),patch.object(mc.rt,'identity',return_value={'pid':222,'uid':0,'start':'1'}),patch.object(mc.rt,'alive',return_value=True),patch.object(mc.subprocess,'Popen',side_effect=None if succeeds else OSError('cannot spawn'),return_value=SimpleNamespace(pid=222)) as launch:
     if succeeds:
      result=mc.steam_start('cs2');self.assertEqual(result['state'],'running');self.assertTrue(result['selection'].startswith('steam:cs2:'))
      with self.assertRaisesRegex(ValueError,'Another template installation'):mc.steam_start('cs2')
      self.assertEqual(launch.call_count,1);self.assertEqual(launch.call_args.args[0][3:5],['steam-worker','cs2'])
     else:
      with self.assertRaises(OSError):mc.steam_start('cs2')
      self.assertEqual(json.loads((Path(folder)/'catalog.json').read_text())['state'],'failed')
 def test_root_download_forbidden(self):
  with patch.object(mc.os,'geteuid',return_value=0):
   with self.assertRaisesRegex(ValueError,'non-root'):mc.steam_build('cs2','/tmp/unused')
 def test_unsafe_bootstrap_archive_rejected(self):
  for name,kind in [('../escape',tarfile.REGTYPE),('/absolute',tarfile.REGTYPE),('link',tarfile.SYMTYPE)]:
   with tempfile.TemporaryDirectory() as folder:
    archive=Path(folder)/'steam.tar'
    with tarfile.open(archive,'w') as stream:
     member=tarfile.TarInfo(name);member.type=kind;stream.addfile(member)
    with self.assertRaisesRegex(ValueError,'Unsafe'):mc.steam_extract(archive,Path(folder)/'out')
 def test_anonymous_app_download_and_validation(self):
  for game,app in [('cs2',730),('css',232330)]:
   with self.subTest(game=game),tempfile.TemporaryDirectory() as folder:
    stage=Path(folder)
    def download(row,path):
     self.assertEqual(row['url'],'https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz');Path(path).touch()
    def build(command,cwd,timeout,env):
     self.assertIn('+app_update',command);self.assertIn(str(app),command);self.assertIn('validate',command)
     self.assertEqual(command[command.index('+login')+1],'anonymous');self.assertEqual(env['HOME'],folder)
     target=stage/'template';binary=target/mc.STEAM_GAMES[game]['binary'];binary.parent.mkdir(parents=True,exist_ok=True);binary.write_text('fixture');binary.chmod(0o755)
     (target/'steamapps').mkdir();(target/'steamapps'/f'appmanifest_{app}.acf').write_text('"buildid" "123456"')
    with patch.object(mc.os,'geteuid',return_value=1000),patch.object(mc,'download',side_effect=download),patch.object(mc,'steam_extract'),patch.object(mc,'build_command',side_effect=build):mc.steam_build(game,folder)
    self.assertEqual(json.loads((stage/'result.json').read_text())['build'],'123456')
 def test_missing_server_is_not_success(self):
  with tempfile.TemporaryDirectory() as folder:
   with patch.object(mc.os,'geteuid',return_value=1000),patch.object(mc,'download',side_effect=lambda row,path:Path(path).touch()),patch.object(mc,'steam_extract'),patch.object(mc,'build_command'):
    with self.assertRaisesRegex(ValueError,'executable missing'):mc.steam_build('cs2',folder)
 def test_worker_publishes_only_finished_template(self):
  for succeeds in [True,False]:
   with self.subTest(succeeds=succeeds),tempfile.TemporaryDirectory() as folder:
    root=Path(folder);cache=root/'cache';cache.mkdir();templates=root/'templates';(templates/'cs2').mkdir(parents=True)
    existing={'key':'paper:1.21.1:1','state':'ready'}
    (cache/'catalog.json').write_text(json.dumps({'state':'running','templates':[existing]}))
    account=SimpleNamespace(pw_uid=os.getuid(),pw_gid=os.getgid(),pw_dir=folder,pw_name='fixture')
    def run(command,**kwargs):
     stage=Path(command[-1]);(stage/'template').mkdir();(stage/'template'/'server').write_text('fixture')
     if not succeeds:raise subprocess.CalledProcessError(1,command)
     (stage/'result.json').write_text('{"build":"123456"}')
    with patch.object(mc,'CACHE',str(cache)),patch.object(mc,'cfg',return_value={'_provision':{'source_root':str(templates),'users':{'cs2':'fixture'}}}),patch.object(mc.pwd,'getpwnam',return_value=account),patch.object(mc,'public_directory'),patch.object(mc.os,'chown'),patch.object(mc.rt,'drop',return_value=None),patch.object(mc.subprocess,'run',side_effect=run):mc.steam_worker('cs2','a'*24)
    saved=json.loads((cache/'catalog.json').read_text());self.assertEqual(saved['templates'],[existing]);self.assertEqual(saved['state'],'ready' if succeeds else 'failed')
    if succeeds:
     row=json.loads((cache/'custom.json').read_text())[0];self.assertEqual(row['game'],'cs2');self.assertEqual(row['version'],'Steam build 123456');self.assertEqual(row['provider'],'folder');self.assertTrue(Path(row['path']).is_dir())
    else:self.assertFalse((cache/'custom.json').exists())
    self.assertEqual(list((templates/'cs2').glob('.steam-build-*')),[])
