"""Remove Vite hot file and fix asset permissions on production."""
import paramiko
import shlex

PASSWORD = "btdev"
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect("203.215.160.44", username="issac", password="btdev", timeout=20)

cmds = [
    "rm -f /var/www/apexone/public/hot",
    "chown -R www-data:www-data /var/www/apexone/public/build",
    "systemctl reload php8.3-fpm",
]
for cmd in cmds:
    full = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(cmd)}"
    _, o, e = ssh.exec_command(full)
    o.channel.recv_exit_status()
    print(cmd, "OK")

_, o, _ = ssh.exec_command("curl -sS http://127.0.0.1/admin/login | grep -E 'build/assets|5173' | head -5")
print("HTML assets:", o.read().decode())
ssh.close()
