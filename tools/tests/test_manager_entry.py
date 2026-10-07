"""Simulate listener rollout failures; no real nginx/systemd or service mutation."""
from pathlib import Path
import os
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]

class ManagerEntryTest(unittest.TestCase):
    def scenario(self, mode, script="manager-entry.sh", marker=False):
        with tempfile.TemporaryDirectory() as tmp:
            w = Path(tmp); bins=w/'bin'; bins.mkdir()
            conf=w/'panel.conf'
            original=(ROOT/'server-snapshot/files/etc/nginx/sites-available/alphacp-panel.conf').read_bytes()
            if marker:
                original = original.replace(b'# ACP_PORTS_START', b'# ACP_PORTS_START\n    listen 2087 ssl;')
                if mode == 'shape':
                    original = original.replace(b'SERVER_PORT     $server_port', b'SERVER_PORT $server_port')
            conf.write_bytes(original)
            alias=w/'enabled.conf'
            if mode == 'symlink':
                alias.symlink_to(conf)
            else:
                alias.write_bytes(original)

            stub='''#!/bin/bash
name=${0##*/}
case "$name" in
nginx)
 if [[ "$1" == -T ]]; then
   if [[ "$MODE" == symlink || "$MODE" == copy ]]; then echo "# configuration file ${ACP_ENTRY_CONF%/*}/enabled.conf:";
   elif [[ "$MODE" == unloaded ]]; then echo '# configuration file /missing.conf:';
   else echo "# configuration file $ACP_ENTRY_CONF:"; fi
   cat "$ACP_ENTRY_CONF"; exit
 fi
 if [[ "$MODE" == syntax ]] && grep -q "${FAIL_MATCH:-listen 2087}" "$ACP_ENTRY_CONF"; then exit 1; fi ;;
systemctl)
 if [[ "$MODE" == reload ]] && grep -q "${FAIL_MATCH:-listen 2087}" "$ACP_ENTRY_CONF"; then exit 1; fi ;;
curl)
 if [[ "$*" == *:2087* && "$MODE" == health ]]; then exit 22; fi
 echo AlphaCP ;;
ss)
 if [[ "$MODE" == collision ]] || grep -q 'listen 2087' "$ACP_ENTRY_CONF"; then echo LISTEN; fi ;;
alphacp-sync) echo sync ;;
esac
'''
            for name in ['nginx','systemctl','curl','ss','alphacp-sync']:
                p=bins/name;p.write_text(stub);p.chmod(0o755)
            env=dict(os.environ,PATH=str(bins)+':'+os.environ['PATH'],MODE=mode,
                FAIL_MATCH="ACP_ENTRY_PORT" if marker else "listen 2087",ACP_ENTRY_CONF=str(conf),ACP_ENTRY_BACKUPS=str(w/'backup'),ACP_ENTRY_LOCK=str(w/'lock'))
            run=subprocess.run(['sudo','--preserve-env=PATH,MODE,FAIL_MATCH,ACP_ENTRY_CONF,ACP_ENTRY_BACKUPS,ACP_ENTRY_LOCK','bash',str(ROOT/'installer'/script)],env=env,text=True,capture_output=True)
            current = conf.read_bytes()
            subprocess.run(['sudo', 'chown', '-R', str(os.getuid()) + ':' + str(os.getgid()), str(w)], check=True)
            return run, current, original

    def test_success_preserves_8090_and_adds_only_2087(self):
        run, current, original=self.scenario('ok')
        self.assertEqual(0,run.returncode,run.stdout+run.stderr)
        self.assertIn(b'listen 2087 ssl;',current)
        self.assertIn(b'listen 8090 ssl;',current)
        self.assertNotIn(b'listen 2083',current)
        self.assertIn('MANAGER ENTRY READY',run.stdout)

    def test_failures_preserve_or_restore_original_config(self):
        for mode in ['syntax','reload','health','collision','copy','unloaded']:
            with self.subTest(mode=mode):
                run,current,original=self.scenario(mode)
                self.assertNotEqual(0,run.returncode,run.stdout+run.stderr)
                self.assertEqual(original,current)

    def test_loaded_symlink_is_accepted(self):
        run,current,original=self.scenario('symlink')
        self.assertEqual(0,run.returncode,run.stdout+run.stderr)
        self.assertIn('same file',run.stdout)
        self.assertIn(b'listen 2087 ssl;',current)

class EntryMarkerTest(ManagerEntryTest):
    def test_marker_only_preserves_existing_ports(self):
        for mode in ['ok', 'symlink']:
            with self.subTest(mode=mode):
                run, current, original = self.scenario(mode, 'entry-marker.sh', True)
                self.assertEqual(0, run.returncode, run.stdout + run.stderr)
                self.assertEqual(original.count(b'listen '), current.count(b'listen '))
                self.assertNotIn(b'listen 2083', current)
                self.assertIn(b'fastcgi_param ACP_ENTRY_PORT  $server_port;', current)
                self.assertIn('ENTRY MARKER READY', run.stdout)

    def test_marker_refusals_and_rollback(self):
        for mode in ['syntax', 'reload', 'health', 'copy', 'unloaded', 'shape']:
            with self.subTest(mode=mode):
                run, current, original = self.scenario(mode, 'entry-marker.sh', True)
                self.assertNotEqual(0, run.returncode, run.stdout + run.stderr)
                self.assertEqual(original, current)
