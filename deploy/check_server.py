import paramiko

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("203.215.160.44", username="issac", password="btdev", timeout=20)
cmds = [
    "curl -fsS http://127.0.0.1/up",
    "systemctl is-active nginx php8.3-fpm mysql apexone-queue",
    "curl -fsS -o /dev/null -w '%{http_code}' http://127.0.0.1/admin/login",
]
for cmd in cmds:
    _, o, e = c.exec_command(cmd)
    print(cmd, "=>", o.read().decode().strip(), e.read().decode().strip())
c.close()
