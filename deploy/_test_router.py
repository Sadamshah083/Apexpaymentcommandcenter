#!/usr/bin/env python3
import base64
import io
import sys

import paramiko

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

TEST = b"""
import dialogue as d

def flow(name, med_answer, label):
    hist = [{"role": "assistant", "content": d.opening_greeting(name)}]
    r1 = d.get_local_router_response("I'm doing good", hist, name)
    hist += [{"role": "user", "content": "I'm doing good"}, {"role": "assistant", "content": r1}]
    r2 = d.get_local_router_response(med_answer, hist, name)
    print(f"{label} | mood->reason: {'Medicare members' in (r1 or '')} | meds answer: {r2}")

flow("Daniel", "nine medications daily", "9 meds")
flow("Sarah", "about 5 pills", "5 meds")
flow("Emily", "ten or eleven", "10-11 meds")
"""

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("157.180.56.227", 2223, "root", "AWWZtvksWCWRw5", timeout=30, look_for_keys=False, allow_agent=False)
b64 = base64.b64encode(TEST).decode()
cmd = f"cd /opt/voicebot && python3 -c \"import base64; exec(base64.b64decode('{b64}'))\""
_, o, e = c.exec_command(cmd, timeout=60)
print(o.read().decode(errors="replace"))
err = e.read().decode(errors="replace")
if err.strip():
    print("ERR:", err)
c.close()
