import importlib.machinery
import importlib.util
import unittest

loader=importlib.machinery.SourceFileLoader('gamedock_installer','linux/install.py')
spec=importlib.util.spec_from_loader(loader.name,loader)
installer=importlib.util.module_from_spec(spec);loader.exec_module(installer)

class SudoersCompatibility(unittest.TestCase):
 def test_sudo_rs_does_not_receive_unsupported_tag(self):
  self.assertEqual(installer.ftp_sudo_input_tag('sudo-rs 0.2.5'),'')
 def test_classic_sudo_disables_input_logging_for_ftp_secret(self):
  self.assertEqual(installer.ftp_sudo_input_tag('Sudo version 1.9.13'),'NOLOG_INPUT: ')

if __name__=='__main__':unittest.main()
