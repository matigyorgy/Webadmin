import base64,json,os,tempfile,unittest
from pathlib import Path
from unittest.mock import patch
from test_launch import launch
runtime=launch.runtime

class ResourceTests(unittest.TestCase):
    def test_limits_validation(self):
        def encode(value):return base64.b64encode(json.dumps(value).encode()).decode()
        self.assertEqual(runtime.limits_payload(encode({'memory_limit_mb':2048,'cpu_limit_percent':150})),{'memory_limit_mb':2048,'cpu_limit_percent':150})
        for bad in [-1,True,1.2,'1024',1048577]:
            with self.assertRaises(ValueError):runtime.limits_payload(encode({'memory_limit_mb':bad}))
        with self.assertRaises(ValueError):runtime.limits_payload(encode([]))
    def test_unavailable_cgroup_fails_closed(self):
        with tempfile.TemporaryDirectory() as folder,patch.object(runtime,'CGROUP_ROOT',folder):
            self.assertIsNone(runtime.prepare_cgroup('test',{}))
            with self.assertRaisesRegex(ValueError,'Resource limits require'):runtime.prepare_cgroup('test',{'memory_limit_mb':1024})
            with self.assertRaises(ValueError):runtime.prepare_cgroup('test',{'cpu_limit_percent':50})
    def test_cgroup_limits_and_usage(self):
        # A regular-file model verifies exact kernel interface values without changing the host.
        with tempfile.TemporaryDirectory() as folder,patch.object(runtime,'CGROUP_ROOT',folder):
            root=Path(folder);(root/'cgroup.controllers').write_text('cpu memory io pids');(root/'cgroup.subtree_control').touch()
            parent=root/'gamedock';parent.mkdir();(parent/'cgroup.subtree_control').touch()
            group=Path(runtime.cgroup_path('test'));group.mkdir()
            for name in ('cgroup.procs','memory.max','memory.swap.max','cpu.max','memory.oom.group'):(group/name).touch()
            real_stat=Path.stat
            def root_stat(path,*args,**kwargs):
                info=real_stat(path,*args,**kwargs)
                if path in (parent,group):
                    class RootInfo:st_uid=0;st_mode=0o40755
                    return RootInfo()
                return info
            with patch.object(Path,'stat',root_stat):
                self.assertEqual(runtime.prepare_cgroup('test',{'memory_limit_mb':2048,'cpu_limit_percent':150}),str(group))
                self.assertEqual((group/'memory.max').read_text(),'2147483648')
                self.assertEqual((group/'memory.swap.max').read_text(),'0')
                self.assertEqual((group/'cpu.max').read_text(),'150000 100000')
                (group/'cpu.stat').write_text('usage_usec 1250000\nuser_usec 1000000\nsystem_usec 250000\n')
                (group/'memory.current').write_text('16777216')
                self.assertEqual(runtime.usage_snapshot('test',[]),(1.25,16777216,True))
                (group/'cgroup.procs').write_text('123')
                with self.assertRaises(ValueError):runtime.prepare_cgroup('test',{'memory_limit_mb':1024})
    def test_unlimited_proc_usage(self):
        with tempfile.TemporaryDirectory() as folder,patch.object(runtime,'CGROUP_ROOT',folder):
            record=runtime.identity(os.getpid());cpu,memory,enforced=runtime.usage_snapshot('test',[record])
            self.assertGreaterEqual(cpu,0);self.assertGreater(memory,0);self.assertFalse(enforced)
            self.assertEqual(runtime.usage_snapshot('test',[dict(record,start='wrong')]),(0,0,False))
    def test_cgroup_name_is_opaque(self):
        self.assertTrue(runtime.cgroup_path('../../escape').startswith(runtime.CGROUP_ROOT+'/gamedock/'))
        self.assertNotIn('..',runtime.cgroup_path('../../escape'))

if __name__=='__main__':unittest.main()
