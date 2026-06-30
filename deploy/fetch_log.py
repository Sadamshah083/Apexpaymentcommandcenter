import paramiko
import shlex

PASSWORD = "btdev"
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect("203.215.160.44", username="issac", password="btdev", timeout=20)
cmd = "tail -100 /var/www/apexone/storage/logs/laravel.log"
full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(cmd)}"
_, o, _ = ssh.exec_command(full)
o.channel.recv_exit_status()
print(o.read().decode(errors="replace"))
ssh.close()
