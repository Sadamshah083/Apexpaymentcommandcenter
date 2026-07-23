#!/usr/bin/env python3
"""E2E smoke: connect monitoring WS and receive push-monitoring."""
from __future__ import annotations

import base64
import shlex

import paramiko

NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}

SCRIPT = r'''
import { WebSocket } from 'ws';
const ws = new WebSocket('ws://127.0.0.1:8787/ws?channel=monitoring&workspace_id=2');
ws.on('message', (data) => {
  const s = String(data);
  console.log('GOT', s.slice(0, 240));
  if (s.includes('deploy_e2e') || s.includes('monitoring_')) {
    ws.close();
    process.exit(0);
  }
});
ws.on('open', () => {
  setTimeout(() => {
    fetch('http://127.0.0.1:8787/push-monitoring', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ workspace_id: 2, reason: 'deploy_e2e', version: 99 }),
    });
  }, 150);
});
setTimeout(() => { console.log('TIMEOUT'); process.exit(1); }, 4000);
'''


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)
    enc = base64.b64encode(SCRIPT.encode()).decode()
    inner = f"""
cd /var/www/apexone/services/call-events-ws
echo {enc} | base64 -d > ./e2e-tmp.mjs
sudo -u www-data node ./e2e-tmp.mjs
rm -f ./e2e-tmp.mjs
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=30)
    print((o.read() + e.read()).decode(errors="replace")[-4000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
