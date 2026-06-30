import paramiko
import shlex

PASSWORD = "btdev"
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect("203.215.160.44", username="issac", password="btdev", timeout=20)
cmds = [
    "grep -n route /var/www/apexone/resources/views/business-research/index.blade.php | head -5",
    "curl -sS -o /dev/null -w '%{http_code}' http://127.0.0.1/admin/business-research",
]
for cmd in cmds:
    full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(cmd)}"
    _, o, _ = ssh.exec_command(full)
    o.channel.recv_exit_status()
    print(cmd, "=>", o.read().decode().strip())
ssh.close()
