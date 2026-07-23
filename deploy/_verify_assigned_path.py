import paramiko, shlex
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect("203.215.160.44", username="issac", password="SadamShah123", timeout=35)
inner = """cd /var/www/apexone
grep -n assigned-leads routes/web.php | head
sudo -u www-data php artisan route:list --path=assigned-leads 2>&1 | head -30
"""
cmd = "echo SadamShah123 | sudo -S -p '' bash -lc " + shlex.quote(inner)
_, o, e = ssh.exec_command(cmd, timeout=60)
print(o.read().decode(errors="replace"))
err = e.read().decode(errors="replace")
if err.strip():
    print("---stderr---")
    print(err[-1500:])
ssh.close()
