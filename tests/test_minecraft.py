import hashlib,importlib.machinery,importlib.util,io,tempfile,unittest,tarfile,os
from pathlib import Path
from unittest.mock import patch
loader=importlib.machinery.SourceFileLoader('mc_templates','linux/gamedock-templates');spec=importlib.util.spec_from_loader(loader.name,loader);mc=importlib.util.module_from_spec(spec);loader.exec_module(mc)
class MinecraftTests(unittest.TestCase):
 def test_stable_version_order(self):self.assertEqual(mc.versions(['1.21.9','1.21.10','1.22-pre1','1.21.8']),['1.21.10','1.21.9','1.21.8'])
 def test_vanilla_last_ten(self):
  releases=[{'id':'1.21.'+str(i),'type':'release','url':'https://piston-meta.mojang.com/'+str(i)} for i in range(12,0,-1)]
  def metadata(url):
   if url.endswith('version_manifest_v2.json'):return {'versions':releases}
   return {'downloads':{'server':{'url':'https://piston-data.mojang.com/server.jar','sha1':'a'*40}},'javaVersion':{'majorVersion':21}}
  with patch.object(mc,'metadata',side_effect=metadata):result=mc.vanilla()
  self.assertEqual(len(result),10);self.assertEqual(result[0]['version'],'1.21.12');self.assertEqual(result[-1]['version'],'1.21.3')
 def test_paper_stable_channel(self):
  def metadata(url):
   if url.endswith('/paper'):return {'versions':{'1.21':['1.21.9','1.21.10']}}
   return [{'id':8,'channel':'STABLE','downloads':{'server:default':{'url':'https://fill-data.papermc.io/file.jar','checksums':{'sha256':'a'*64}}}},{'id':9,'channel':'ALPHA'}]
  with patch.object(mc,'metadata',side_effect=metadata):result=mc.paper()
  self.assertEqual(result[0]['version'],'1.21.10');self.assertEqual(result[0]['build'],'8')
 def test_forge_latest_build(self):
  xml='<metadata><versioning><versions><version>1.21.1-52.0.9</version><version>1.21.1-52.0.10</version></versions></versioning></metadata>'
  with patch.object(mc,'text',side_effect=lambda url:xml if url.endswith('.xml') else 'a'*40):result=mc.forge()
  self.assertEqual(result[0]['build'],'52.0.10')
 def test_bukkit_ten_supported_versions(self):
  listing=''.join('<a href="1.21.'+str(i)+'.json">release</a>' for i in range(1,13))
  with patch.object(mc,'text',return_value=listing),patch.object(mc,'metadata',return_value={'number':99}):rows=mc.bukkit()
  self.assertEqual(len(rows),10);self.assertEqual(rows[0]['version'],'1.21.12')
 def test_purpur(self):
  def metadata(url):
   if url.endswith('/purpur'):return {'versions':['1.21.9']}
   if url.endswith('/7'):return {'md5':'a'*32}
   return {'builds':{'latest':'7'}}
  with patch.object(mc,'metadata',side_effect=metadata):rows=mc.purpur()
  self.assertEqual(rows[0]['algorithm'],'md5');self.assertTrue(rows[0]['url'].endswith('/7/download'))
 def test_fabric(self):
  def metadata(url):
   if url.endswith('/game'):return [{'version':'1.21.9','stable':True}]
   if url.endswith('/installer'):return [{'stable':True,'version':'1.1.0','url':'https://maven.fabricmc.net/installer.jar'}]
   return [{'loader':{'stable':True,'version':'0.17.3'}}]
  with patch.object(mc,'metadata',side_effect=metadata),patch.object(mc,'text',return_value='a'*40):rows=mc.fabric()
  self.assertEqual(rows[0]['loader'],'0.17.3');self.assertEqual(rows[0]['type'],'fabric')
 def test_jdk_archive_traversal(self):
  with tempfile.TemporaryDirectory() as folder:
   archive=Path(folder)/'jdk.tar';destination=Path(folder)/'extract';destination.mkdir()
   with tarfile.open(archive,'w') as stream:
    member=tarfile.TarInfo('../escaped');member.size=1;stream.addfile(member,io.BytesIO(b'x'))
   with patch.object(mc.os,'geteuid',return_value=1001):
    with self.assertRaises(ValueError):mc.extract_jdk(str(archive),str(destination))
   self.assertFalse((Path(folder)/'escaped').exists())
 def test_https_official_only(self):
  for url in ['http://piston-meta.mojang.com/a','https://example.com/a','https://mojang.com.evil/a']:
   with self.assertRaises(ValueError):mc.allowed(url)
 def test_checksum(self):
  content=b'verified artifact';row={'url':'https://piston-data.mojang.com/a','algorithm':'sha256','digest':hashlib.sha256(content).hexdigest()}
  with tempfile.TemporaryDirectory() as folder:
   with patch.object(mc,'response',return_value=io.BytesIO(content)):mc.download(row,str(Path(folder)/'server.jar'))
   with patch.object(mc,'response',return_value=io.BytesIO(b'corrupt')):
    with self.assertRaises(ValueError):mc.download(row,str(Path(folder)/'bad.jar'))
if __name__=='__main__':unittest.main()
