import importlib.machinery
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch

loader=importlib.machinery.SourceFileLoader('provision_helper','linux/gamedock-provision')
spec=importlib.util.spec_from_loader(loader.name,loader)
provision=importlib.util.module_from_spec(spec);loader.exec_module(provision)

class ProvisionTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name);self.source=self.root/'templates';self.source.mkdir();(self.source/'minecraft').mkdir()
        self.target=self.root/'games';self.target.mkdir();(self.root/'outside').mkdir()
        self.policy={'_provision':{'source_root':str(self.source),'target_root':str(self.target),'users':{'minecraft':'minecraft'}}}
        self.payload={'job':'a'*32,'unit':'minecraft-panel.service','game':'minecraft','folder':'survival','source':str(self.source/'minecraft'),'port':25000}
        patch.object(provision,'secure').start();patch.object(provision.pwd,'getpwnam',return_value=SimpleNamespace(pw_uid=1001,pw_gid=1001)).start()
        self.addCleanup(patch.stopall)
    def test_valid_paths(self):
        data=provision.validate(self.payload,self.policy)
        self.assertEqual(data['target'],str(self.target/'survival'));self.assertEqual(data['uid'],1001)
    def test_source_escape(self):
        with self.assertRaises(ValueError):provision.validate(dict(self.payload,source=str(self.root/'outside')),self.policy)
    def test_source_symlink_escape(self):
        (self.source/'linked').symlink_to(self.root/'outside',target_is_directory=True)
        with self.assertRaises(ValueError):provision.validate(dict(self.payload,source=str(self.source/'linked')),self.policy)
    def test_target_escape(self):
        with self.assertRaises(ValueError):provision.validate(dict(self.payload,folder='../escape'),self.policy)
    def test_existing_target_preserved(self):
        (self.target/'survival').mkdir();file=self.target/'survival'/'keep';file.write_text('existing')
        with self.assertRaises(ValueError):provision.validate(self.payload,self.policy)
        self.assertEqual(file.read_text(),'existing')
    def test_root_game_rejected(self):
        with patch.object(provision.pwd,'getpwnam',return_value=SimpleNamespace(pw_uid=0,pw_gid=0)):
            with self.assertRaises(ValueError):provision.validate(self.payload,self.policy)
    def test_copy_is_independent(self):
        source=self.source/'minecraft';(source/'server.cfg').write_text('original')
        target=self.target/'copy';target.mkdir()
        with patch.object(provision.os,'geteuid',return_value=1001):provision.copy_files(str(source),str(target))
        (target/'server.cfg').write_text('modified')
        self.assertEqual((source/'server.cfg').read_text(),'original')
        self.assertNotEqual(os.stat(source/'server.cfg').st_ino,os.stat(target/'server.cfg').st_ino)
    def test_instance_identity_changes_with_destination(self):
        data=provision.validate(self.payload,self.policy)
        account=SimpleNamespace(pw_uid=999,pw_gid=1001,pw_dir=data['target'])
        with patch.object(provision.pwd,'getpwnam',return_value=account):
            first=provision.isolated_user(data)
        other=dict(data,target=str(self.target/'another'))
        with patch.object(provision.pwd,'getpwnam',return_value=SimpleNamespace(pw_uid=998,pw_gid=1001,pw_dir=other['target'])):
            second=provision.isolated_user(other)
        self.assertNotEqual(first['user'],second['user']);self.assertNotEqual(first['uid'],second['uid'])
    def test_empty_template_rejected(self):
        with patch.object(provision.os,'geteuid',return_value=1001):
            with self.assertRaises(ValueError):provision.copy_files(str(self.source/'minecraft'),str(self.target/'copy'))
    def test_nested_symlink_rejected(self):
        source=self.source/'minecraft';(source/'bad').symlink_to(self.root/'outside')
        with patch.object(provision.os,'geteuid',return_value=1001):
            with self.assertRaises(ValueError):provision.copy_files(str(source),str(self.target/'copy'))

if __name__=='__main__':unittest.main()
