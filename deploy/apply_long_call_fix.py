#!/usr/bin/env python3
import io, sys, paramiko
from pathlib import Path

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

HOST, PORT, USER, PASS = "157.180.56.227", 2223, "root", "AWWZtvksWCWRw5"
DEPLOY = Path(__file__).parent

files = {
    "/tmp/remote_patch_main.py": DEPLOY / "remote_patch_main.py",
}

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect(HOST, port=PORT, username=USER, password=PASS, timeout=30, look_for_keys=False, allow_agent=False)

for remote, local in files.items():
    up_stdin, up_out, up_err = c.exec_command(f"cat > {remote}")
    up_stdin.write(local.read_bytes())
    up_stdin.channel.shutdown_write()
    up_out.channel.recv_exit_status()
    err = up_err.read().decode()
    if err.strip():
        print("upload err", remote, err)
    else:
        print("uploaded", remote)

SCRIPT = b"""#!/bin/bash
set -e
python3 /tmp/remote_patch_main.py

# bridge
sed -i 's/await asyncio.sleep(0.75)/await asyncio.sleep(0.05)/' /opt/voicebot/audiosocket_bridge.py && echo bridge sleep patched || echo bridge skip

# vicidial api faster fail
python3 - <<'PY'
from pathlib import Path
p = Path('/opt/voicebot/vicidial_api.py')
t = p.read_text()
t2 = t.replace('timeout: int = 10', 'timeout: int = 6')
t2 = t2.replace('retries: int = 3', 'retries: int = 2')
if t2 != t:
    p.write_text(t2)
    print('vicidial_api timeouts patched')
else:
    print('vicidial_api skip')
PY

# xfer retry sleep
sed -i 's/time.sleep(0.8)/time.sleep(0.25)/' /opt/voicebot/connect_qualified_call.py && echo connect sleep patched || true

# env
python3 - <<'PY'
import pathlib, re
p = pathlib.Path('/opt/voicebot/.env')
text = p.read_text()
for k,v in [('VICIDIAL_CAMPAIGN_ID','002'),('VICIDIAL_BRIDGE_AGENT_PREFIX','160'),('VICIDIAL_DEFAULT_AGENT','16001')]:
    pat = re.compile('^'+re.escape(k)+'=.*$', re.M)
    line = f'{k}={v}'
    text = pat.sub(line, text) if pat.search(text) else text.rstrip()+'\\n'+line+'\\n'
p.write_text(text)
print('env ok')
PY

systemctl restart voicebot voicebot-bridge
sleep 2
systemctl is-active voicebot voicebot-bridge asterisk
grep 'MAX_CALL_DURATION_SEC =' /opt/voicebot/main.py | head -1
grep VICIDIAL_CAMPAIGN /opt/voicebot/.env
"""

stdin, stdout, stderr = c.exec_command("bash -s", timeout=180)
stdin.write(SCRIPT)
stdin.channel.shutdown_write()
print(stdout.read().decode(errors="replace"))
err = stderr.read().decode(errors="replace")
if err.strip():
    print("STDERR:", err)
print("exit:", stdout.channel.recv_exit_status())
c.close()
