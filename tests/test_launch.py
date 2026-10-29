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
    def test_pid_identity(self):
        record=launch.runtime.identity(os.getpid())
        self.assertTrue(launch.runtime.alive(record,os.geteuid()))
        self.assertFalse(launch.runtime.alive(dict(record,start='wrong'),os.geteuid()))
        self.assertFalse(launch.runtime.alive(record,os.geteuid()+1))
    def test_runner_refuses_root(self):
        with patch.object(runner.os,'geteuid',return_value=0):
            with self.assertRaises(ValueError):runner.serve('/tmp/unused.sock','id')
    def test_payload_preserved(self):
        command='JAVA_HOME=/path java -jar "my server.jar"; echo finished'
        self.assertEqual(launch.decode(base64.b64encode(command.encode()).decode(),4096),command)
    def test_multiline_rejected(self):
        with self.assertRaises(ValueError):launch.decode(base64.b64encode(b'java\nreboot').decode(),4096)

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
