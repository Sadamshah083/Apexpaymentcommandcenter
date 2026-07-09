#!/usr/bin/env python3
import io, sys, paramiko
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
test_py = r'''
import dialogue as d

def run(name="Emily"):
    hist = []
    def say(bot):
        hist.append({"role": "assistant", "content": bot})
    def user(text):
        hist.append({"role": "user", "content": text})
        return d.get_local_router_response(text, hist, name)

    print("OPENING:", d.opening_greeting(name))
    say(d.opening_greeting(name))
    print("USER: good ->", user("I'm doing good"))
    print("USER: 9 meds ->", user("I take nine medications every day"))
    print("USER: 5 meds ->", user("about five"))
    print("---")
    hist2 = []
    say(d.opening_greeting(name))
    hist2.append({"role": "user", "content": "fine"})
    r = d.get_local_router_response("fine", hist2, name)
    say(r)
    print("after fine:", r[:120], "...")
    print("USER: 12 meds ->", d.get_local_router_response("twelve", hist2, name))

run("Daniel")
run("Sarah")
'''
c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect('157.180.56.227', 2223, 'root', 'AWWZtvksWCWRw5', timeout=30, look_for_keys=False, allow_agent=False)
_, o, e = c.exec_command(f"cd /opt/voicebot && python3 -c {test_py!r}", timeout=60)
print(o.read().decode(errors='replace'))
err = e.read().decode(errors='replace')
if err: print('ERR', err)
c.close()
