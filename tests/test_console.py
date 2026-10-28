import base64
import importlib.machinery
import importlib.util
import io
import json
import unittest
from types import SimpleNamespace
from unittest.mock import patch, mock_open

loader = importlib.machinery.SourceFileLoader('console_helper', 'linux/gamedock-console')
spec = importlib.util.spec_from_loader(loader.name, loader)
helper = importlib.util.module_from_spec(spec)
loader.exec_module(helper)

class ConsoleTests(unittest.TestCase):
    def setUp(self):
        self.config = {'minecraft.service': {'user':'minecraft', 'tmux_target':'survival:0.0'}}
        self.stat = patch.object(helper.os, 'stat', return_value=SimpleNamespace(st_uid=0, st_mode=0o100644))
        self.file = patch('builtins.open', mock_open(read_data=json.dumps(self.config)))
        self.run = patch.object(helper.subprocess, 'run', return_value=SimpleNamespace(returncode=0, stdout=b'log line'))
        self.stat.start(); self.file.start(); self.mock_run = self.run.start()
        self.addCleanup(patch.stopall)

    def test_unknown_unit(self):
        with self.assertRaises(ValueError): helper.execute(['helper','other.service','logs'])
        self.mock_run.assert_not_called()

    def test_shell_metacharacters_in_unit(self):
        with self.assertRaises(ValueError): helper.execute(['helper','x;id.service','logs'])
        self.mock_run.assert_not_called()

    def test_newline_command_rejected(self):
        payload=base64.b64encode(b'list\nstop').decode()
        with self.assertRaises(ValueError): helper.execute(['helper','minecraft.service','command',payload])
        self.mock_run.assert_not_called()

    def test_literal_command(self):
        payload=base64.b64encode(b'say hello; $(id)').decode()
        with patch.object(helper.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=1001)), patch('sys.stdout', new_callable=io.StringIO):
            helper.execute(['helper','minecraft.service','command',payload])
        args=self.mock_run.call_args_list[0].args[0]
        self.assertEqual(args[-3:], ['-l','--','say hello; $(id)'])
        self.assertNotIn('shell', self.mock_run.call_args_list[0].kwargs)

    def test_journal_unit_bounded(self):
        with patch('sys.stdout', new_callable=io.StringIO) as output:
            helper.execute(['helper','minecraft.service','logs'])
            self.assertEqual(output.getvalue(),'log line')
        self.assertIn('--unit=minecraft.service',self.mock_run.call_args.args[0])
        self.assertIn('--lines=150',self.mock_run.call_args.args[0])

if __name__ == '__main__': unittest.main()
