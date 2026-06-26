"""Finish partial deployment on server."""
import paramiko
import shlex

PASSWORD = "btdev"
HOST = "203.215.160.44"
USER = "issac"
APP = "/var/www/apexone"
ADMIN_PASS = "rwlt4NBN2MtIbQ0A"


def run(ssh, cmd, sudo=False):
    if sudo:
        cmd = f"echo {shlex.quote(PASSWORD)} | sudo -S bash -lc {shlex.quote(cmd)}"
    print("$", cmd[:120])
    _, o, e = ssh.exec_command(cmd, get_pty=True)
    code = o.channel.recv_exit_status()
    out = o.read().decode(errors="replace")
    err = e.read().decode(errors="replace")
    print(out)
    if err:
        print(err)
    if code != 0:
        raise SystemExit(code)
    return out


ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASSWORD, timeout=30)

run(ssh, f"cd {APP} && php artisan config:clear", sudo=True)
run(ssh, f"cd {APP} && php scripts/production-bootstrap.php", sudo=True)
run(ssh, f"cd {APP} && php artisan config:cache && php artisan route:cache && php artisan view:cache", sudo=True)
run(ssh, f"cp {APP}/deploy/nginx-apexone.conf /etc/nginx/sites-available/apexone", sudo=True)
run(ssh, "rm -f /etc/nginx/sites-enabled/default", sudo=True)
run(ssh, "ln -sf /etc/nginx/sites-available/apexone /etc/nginx/sites-enabled/apexone", sudo=True)
run(ssh, "nginx -t && systemctl reload nginx", sudo=True)
run(ssh, f"cp {APP}/deploy/apexone-queue.service /etc/systemd/system/apexone-queue.service", sudo=True)
run(ssh, "systemctl daemon-reload && systemctl enable apexone-queue && systemctl restart apexone-queue", sudo=True)
cron = f"* * * * * www-data cd {APP} && /usr/bin/php artisan schedule:run >> /dev/null 2>&1"
run(ssh, f"grep -q 'artisan schedule:run' /etc/crontab || echo {shlex.quote(cron)} >> /etc/crontab", sudo=True)
run(ssh, "chown -R www-data:www-data /var/www/apexone/storage /var/www/apexone/bootstrap/cache", sudo=True)
_, out, _ = ssh.exec_command("curl -fsS http://127.0.0.1/up")
health = _.read().decode() if False else ""
stdin, stdout, stderr = ssh.exec_command("curl -fsS http://127.0.0.1/up")
print("HEALTH:", stdout.read().decode())
ssh.close()

print("\nDEPLOYMENT COMPLETE")
print(f"URL: http://{HOST}")
print(f"Admin: http://{HOST}/admin/login")
print(f"Username: admin")
print(f"Password: {ADMIN_PASS}")
