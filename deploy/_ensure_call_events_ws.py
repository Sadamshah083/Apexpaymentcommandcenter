#!/usr/bin/env python3
"""Ensure call-events-ws is installed and running on NEW."""
from __future__ import annotations

import shlex

import paramiko

NEW = {"host": "203.215.161.236", "user": "ateg", "password": "balitech1"}
REMOTE = "/var/www/apexone"


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW["host"], username=NEW["user"], password=NEW["password"], timeout=40)

    inner = f"""
set -e
cd {REMOTE}/services/call-events-ws
ls -la
# install deps if needed
if [ ! -d node_modules/ws ]; then
  npm install --omit=dev --no-fund --no-audit
fi
cat > /etc/systemd/system/apex-call-events-ws.service <<'EOF'
[Unit]
Description=ApexOne call-events WebSocket bridge
After=network.target
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/apexone/services/call-events-ws
Environment=CALL_EVENTS_WS_HOST=127.0.0.1
Environment=CALL_EVENTS_WS_PORT=8787
Environment=NODE_ENV=production
ExecStart=/usr/bin/node /var/www/apexone/services/call-events-ws/server.mjs
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable apex-call-events-ws
systemctl restart apex-call-events-ws
sleep 1
systemctl --no-pager -l status apex-call-events-ws | head -25
curl -fsS http://127.0.0.1:8787/health; echo
curl -fsS -X POST http://127.0.0.1:8787/push-monitoring -H 'Content-Type: application/json' -d '{{"workspace_id":2,"reason":"smoke"}}'; echo
# confirm nginx location exists
grep -n 'communications-ws' /etc/nginx/sites-enabled/* /etc/nginx/conf.d/* 2>/dev/null | head -10 || true
echo WS_OK
"""
    cmd = f"echo {shlex.quote(NEW['password'])} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=180)
    print((o.read() + e.read()).decode(errors="replace")[-10000:])
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
