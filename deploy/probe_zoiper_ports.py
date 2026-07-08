#!/usr/bin/env python3
"""Probe Morpheus ports relevant to Zoiper / SIP softphones."""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

PORTS = [443, 5060, 5061, 5080, 7443, 8089]
HOST = "apexone.morpheus.cx"

WSS_PROBE = r"""python3 -c "import ssl,socket;h='apexone.morpheus.cx';p=7443;c=ssl.create_default_context();s=c.wrap_socket(socket.socket(),server_hostname=h);s.settimeout(10);s.connect((h,p));r='GET / HTTP/1.1\r\nHost: %s:%s\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: sip\r\n\r\n'%(h,p);s.send(r.encode());print(s.recv(256).decode(errors='replace').split('\r\n')[0]);s.close()" """


def main() -> int:
    ssh = connect()
    port_status: dict[str, str] = {}
    for port in PORTS:
        cmd = f"nc -z -w 5 {HOST} {port} && echo open || echo closed"
        port_status[str(port)] = sudo_run(ssh, cmd, check=False).strip().splitlines()[-1]

    udp5060 = sudo_run(
        ssh,
        f"nc -z -u -w 5 {HOST} 5060 && echo open || echo closed",
        check=False,
    ).strip().splitlines()[-1]

    wss_handshake = sudo_run(ssh, WSS_PROBE, check=False).strip()
    env_lines = sudo_run(
        ssh,
        f"grep -E '^MORPHEUS_(HOST|SIP_HOST|WEBRTC_SIP_DOMAIN|SIP_WSS_URL)=' {REMOTE_APP}/.env",
        check=False,
    )
    ssh.close()

    env = {}
    for line in env_lines.splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            env[k] = v

    result = {
        "host": HOST,
        "tcp_ports": port_status,
        "udp_5060": udp5060,
        "wss_7443_handshake": wss_handshake,
        "env": {
            "MORPHEUS_HOST": env.get("MORPHEUS_HOST", ""),
            "MORPHEUS_SIP_HOST": env.get("MORPHEUS_SIP_HOST", ""),
            "MORPHEUS_WEBRTC_SIP_DOMAIN": env.get("MORPHEUS_WEBRTC_SIP_DOMAIN", ""),
            "MORPHEUS_SIP_WSS_URL": env.get("MORPHEUS_SIP_WSS_URL", ""),
        },
    }
    print(json.dumps(result, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
