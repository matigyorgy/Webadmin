import base64
import importlib.machinery
import importlib.util
import io
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from types import SimpleNamespace
from unittest.mock import patch, mock_open

def module(name,path):
    loader=importlib.machinery.SourceFileLoader(name,path)
    spec=importlib.util.spec_from_loader(name,loader)
    result=importlib.util.module_from_spec(spec);loader.exec_module(result);return result

launch=module('launch_helper','linux/gamedock-launch')
runner=module('game_runner','linux/gamedock-runner')

class LaunchTests(unittest.TestCase):
    def setUp(self):
        self.config={'minecraft-panel.service':{'launch':True,'user':'minecraft','directory':'/srv/games/minecraft'}}
        patch.object(launch.os,'stat',return_value=SimpleNamespace(st_uid=0,st_mode=0o100644)).start()
        patch('builtins.open',mock_open(read_data=json.dumps(self.config))).start()
        patch.object(launch.pwd,'getpwnam',return_value=SimpleNamespace(pw_uid=1001,pw_gid=1001)).start()
        patch.object(launch.os.path,'isdir',return_value=True).start()
        self.run=patch.object(launch.subprocess,'run',return_value=SimpleNamespace(returncode=0,stdout='LoadState=loaded\nTransient=no\nActiveState=active\n')).start()
        self.addCleanup(patch.stopall)
    def test_persistent_unit_rejected(self):
        with self.assertRaises(ValueError): launch.execute(['helper','minecraft-panel.service','stop'])
        self.assertEqual(self.run.call_count,1)
    def test_bad_action(self):
        with self.assertRaises(ValueError):launch.execute(['helper','minecraft-panel.service','shell'])
        self.run.assert_not_called()
    def test_root_user_rejected(self):
        with patch.object(launch.pwd,'getpwnam',return_value=SimpleNamespace(pw_uid=0,pw_gid=0)):
            with self.assertRaises(ValueError):launch.execute(['helper','minecraft-panel.service','stop'])
        self.run.assert_not_called()
    def test_multiline_start_rejected(self):
        command=base64.b64encode(b'java\nreboot').decode()
        with self.assertRaises(ValueError):launch.execute(['helper','minecraft-panel.service','start',command])
        self.run.assert_not_called()
    def test_unknown_unit_rejected(self):
        with self.assertRaises(KeyError):launch.execute(['helper','other.service','stop'])
        self.run.assert_not_called()
    def test_start_uses_pinned_uid_and_directory(self):
        command='java -Xmx4G -jar server.jar nogui';payload=base64.b64encode(command.encode()).decode()
        with patch.object(launch,'state',return_value={'LoadState':'not-found','ActiveState':'inactive'}), \
             patch.object(launch.os,'makedirs'),patch.object(launch.os,'chmod'),patch.object(launch.os,'chown'), \
             patch.object(launch.os.path,'islink',return_value=False),patch.object(launch.os.path,'lexists',return_value=False), \
             patch.object(launch,'run') as run,patch('sys.stdout',new_callable=io.StringIO):
            launch.execute(['helper','minecraft-panel.service','start',payload])
        args=run.call_args.args[0]
        self.assertIn('--uid=minecraft',args);self.assertIn('--gid=1001',args)
        self.assertIn('--working-directory=/srv/games/minecraft',args)
        self.assertIn('--property=NoNewPrivileges=yes',args)
        self.assertEqual(args[-1],payload)
        self.assertNotIn(command,args)
    def test_active_start_does_not_restart(self):
        payload=base64.b64encode(b'java -jar server.jar').decode()
        with patch.object(launch,'state',return_value={'LoadState':'loaded','Transient':'yes','ActiveState':'active'}), \
             patch.object(launch,'run') as run,patch('sys.stdout',new_callable=io.StringIO):
            launch.execute(['helper','minecraft-panel.service','start',payload])
        run.assert_not_called()
    def test_payload_preserved(self):
        command='JAVA_HOME=/path java -jar "my server.jar"; echo finished'
        self.assertEqual(launch.decode(base64.b64encode(command.encode()).decode(),4096),command)
    def test_runner_refuses_root(self):
        with patch.object(runner.os,'geteuid',return_value=0):
            with self.assertRaises(ValueError):runner.serve('/tmp/unused.sock','id')

class RunnerIntegration(unittest.TestCase):
    @unittest.skipIf(os.geteuid()==0,'Run as an unprivileged test user')
    def test_pty_console_and_output(self):
        with tempfile.TemporaryDirectory() as directory:
            game=Path(directory)/'game.py';sock=str(Path(directory)/'console.sock')
            game.write_text("import sys\nprint('READY',flush=True)\nfor line in sys.stdin:\n print('EXECUTED:'+line.strip(),flush=True)\n if line.strip()=='quit': break\n")
            import shlex
            command=shlex.join([sys.executable,'-u',str(game)])
            output=open(Path(directory)/'output.log','wb')
            process=subprocess.Popen([sys.executable,'linux/gamedock-runner','--socket',sock,'--command',base64.b64encode(command.encode()).decode()],stdout=output,stderr=subprocess.STDOUT)
            try:
                deadline=time.monotonic()+5
                while not os.path.exists(sock):
                    if process.poll() is not None or time.monotonic()>deadline:self.fail('Runner did not start')
                    time.sleep(.02)
                def send(command):
                    with socket.socket(socket.AF_UNIX,socket.SOCK_STREAM) as connection:
                        connection.settimeout(3);connection.connect(sock);connection.sendall(command.encode()+b'\n');connection.shutdown(socket.SHUT_WR)
                        self.assertEqual(connection.recv(16),b'OK')
                send('say literal ; $(id)');send('quit');self.assertEqual(process.wait(timeout=5),0)
            finally:
                if process.poll() is None:process.kill();process.wait()
                output.close()
            logs=(Path(directory)/'output.log').read_text()
            self.assertIn('EXECUTED:say literal ; $(id)',logs)
            self.assertIn('EXECUTED:quit',logs)
            self.assertNotIn('uid=',logs)
            self.assertFalse(os.path.exists(sock))

if __name__=='__main__':unittest.main()
