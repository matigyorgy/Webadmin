import datetime as dt,importlib.machinery,importlib.util,io,json,os,tarfile,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
loader=importlib.machinery.SourceFileLoader('automation','linux/gamedock-automation');spec=importlib.util.spec_from_loader(loader.name,loader);a=importlib.util.module_from_spec(spec);loader.exec_module(a)
class AutomationTests(unittest.TestCase):
 def test_timezones_and_nonexistent_clock_time(self):
  utc=a.schedule_time('2026-11-01T10:00','UTC');budapest=a.schedule_time('2026-11-01T10:00','Europe/Budapest');self.assertEqual(utc-budapest,3600)
  with self.assertRaises(ValueError):a.schedule_time('2027-03-28T02:30','Europe/Budapest')
 def test_daily_preserves_wall_clock_over_dst(self):
  task={'local_time':'2027-03-27T10:00','timezone':'Europe/Budapest','repeat':'daily'}
  before=a.schedule_time(task['local_time'],task['timezone']);after=a.next_run(task,before)
  self.assertEqual(after-before,23*3600)
  self.assertEqual(dt.datetime.fromtimestamp(after,a.ZoneInfo(task['timezone'])).hour,10)
 def test_weekly_and_one_shot(self):
  task={'local_time':'2026-11-01T10:00','timezone':'UTC','repeat':'weekly'};stamp=a.schedule_time(task['local_time'],'UTC')
  self.assertEqual(a.next_run(task,stamp),stamp+7*86400);task['repeat']='once';self.assertIsNone(a.next_run(task,stamp))
 def test_archive_rejects_links_and_escape(self):
  for name,kind in [('../escape',tarfile.REGTYPE),('/escape',tarfile.REGTYPE),('link',tarfile.SYMTYPE),('hard',tarfile.LNKTYPE),('fifo',tarfile.FIFOTYPE)]:
   with self.subTest(name=name),tempfile.TemporaryDirectory() as directory:
    root=Path(directory);archive=root/'bad.tar.gz';stage=root/'out';stage.mkdir()
    with tarfile.open(archive,'w:gz') as stream:
     item=tarfile.TarInfo(name);item.type=kind;stream.addfile(item)
    with open(archive,'rb') as stream,patch.object(a.os,'geteuid',return_value=1000):
     with self.assertRaisesRegex(ValueError,'Unsafe'):a.extract(os.dup(stream.fileno()),str(stage))
    self.assertEqual(list(stage.iterdir()),[])
 def test_compressed_roundtrip(self):
  with tempfile.TemporaryDirectory() as directory:
   root=Path(directory);snapshot=root/'snapshot';snapshot.mkdir();(snapshot/'world').mkdir();(snapshot/'world'/'level.dat').write_bytes(b'server-data'*1000);(snapshot/'empty').mkdir()
   with patch.object(a.os,'geteuid',return_value=1000):a.compress(root)
   self.assertLess((root/'archive.tar.gz').stat().st_size,10000)
   out=root/'out';out.mkdir()
   with open(root/'archive.tar.gz','rb') as stream,patch.object(a.os,'geteuid',return_value=1000):a.extract(os.dup(stream.fileno()),out)
   self.assertEqual((out/'world'/'level.dat').read_bytes(),b'server-data'*1000);self.assertTrue((out/'empty').is_dir())
 def test_root_copy_and_extract_forbidden(self):
  with patch.object(a.os,'geteuid',return_value=0):
   for operation in [lambda:a.copy_archive('/unused','/unused'),lambda:a.compress('/unused'),lambda:a.extract(-1,'/unused')]:
    with self.assertRaises(ValueError):operation()
 def test_invalid_identifiers(self):
  for name in ['../server','bad;id','/root','']:
   with self.assertRaises(ValueError):a.unit_name(name)
  with self.assertRaises(ValueError):a.token('../backup')
 def test_schedule_tick_deduplicates_and_skips_overdue(self):
  with tempfile.TemporaryDirectory() as directory:
   root=Path(directory);unit='12345678';location=root/'units'/unit;location.mkdir(parents=True)
   instance={'user':'game','directory':'/home/gamedock/1','provision_job':'job'}
   tasks=[{'id':'a'*32,'operation':'restart','repeat':'once','next_run':1000,'panel':{},'instance':instance},{'id':'b'*32,'operation':'backup','repeat':'once','next_run':100,'panel':{},'instance':instance}]
   path=location/'schedules.json';path.write_text(json.dumps(tasks))
   with patch.object(a,'ROOT',root),patch.object(a,'BACKUPS',root/'backups'),patch.object(a.p,'secure'),patch.object(a,'server',return_value=({},instance,None,None)),patch.object(a,'recover'),patch.object(a,'request') as request:
    a.tick(1001);a.tick(1002)
   self.assertEqual(request.call_count,1);saved=json.loads(path.read_text());self.assertIsNone(saved[0]['next_run']);self.assertIn('Kimaradt',saved[1]['last_result'])
 def test_scheduled_power_uses_latest_admin_policy_and_port(self):
  panel={'startup':'./server --port {port}','limits':{'port':25556,'memory_limit_mb':512,'cpu_limit_percent':100}}
  with patch.object(a,'folder',return_value=Path('/unused')),patch.object(a,'read',return_value=panel),patch.object(a.l,'execute') as execute:
   a.power('12345678','restart',{'startup':'outdated'})
  command=execute.call_args.args[0]
  self.assertEqual(a.base64.b64decode(command[3]).decode(),'./server --port 25556');self.assertEqual(json.loads(a.base64.b64decode(command[4])),panel['limits'])
 def test_restore_requires_confirmation_before_queue(self):
  with self.assertRaisesRegex(ValueError,'confirmation'):a.request('12345678','restore',{}, {})
