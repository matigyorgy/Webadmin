import json,os,shutil,subprocess,tempfile,unittest
from pathlib import Path

class BootstrapTests(unittest.TestCase):
    def run_bootstrap(self,exitcode):
        with tempfile.TemporaryDirectory() as folder:
            root=Path(folder);shutil.copy('linux/bootstrap.sh',root/'bootstrap.sh')
            binary=root/'bin';binary.mkdir();fake=binary/'python3'
            fake.write_text('#!/bin/sh\necho "fixture installer result" >&2\nexit '+str(exitcode)+'\n');fake.chmod(0o755)
            env=dict(os.environ,PATH=str(binary)+':'+os.environ['PATH'])
            result=subprocess.run(['/bin/sh',str(root/'bootstrap.sh'),'/home/gamedock/templates','gamedock','0','0'],env=env,capture_output=True,text=True)
            return result,json.loads((root/'setup-state.json').read_text())
    def test_failed_installer_identifies_phase(self):
        result,state=self.run_bootstrap(17)
        self.assertEqual(result.returncode,17);self.assertEqual(state['state'],'failed')
        self.assertIn('Linux-fajlok',state['message']);self.assertIn('17',state['message']);self.assertIn('fixture installer result',result.stderr)
    def test_success_does_not_trigger_failure_trap(self):
        result,state=self.run_bootstrap(0)
        self.assertEqual(result.returncode,0);self.assertEqual(state['state'],'ready')

if __name__=='__main__':unittest.main()
