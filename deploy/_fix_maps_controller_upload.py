import paramiko, shlex
from pathlib import Path
ROOT = Path(r"C:\Users\dev\Desktop\ApexonecommandCenter")
local = ROOT / "app/Http/Controllers/MapsScraperController.php"
for label, host, user, pw in [
    ("OLD", "203.215.160.44", "issac", "SadamShah123"),
    ("NEW", "203.215.161.236", "ateg", "balitech1"),
]:
    print("===", label, "===")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        ssh.connect(host, username=user, password=pw, timeout=35)
    except Exception as e:
        print("CONNECT_FAIL", e)
        continue
    sftp = ssh.open_sftp()
    remote = "/tmp/MapsScraperController.php"
    sftp.put(str(local), remote)
    sftp.close()
    inner = f"""
cp {remote} /var/www/apexone/app/Http/Controllers/MapsScraperController.php
chown www-data:www-data /var/www/apexone/app/Http/Controllers/MapsScraperController.php
sed -n '250,256p' /var/www/apexone/app/Http/Controllers/MapsScraperController.php
php -l /var/www/apexone/app/Http/Controllers/MapsScraperController.php
php -l /var/www/apexone/app/Services/MapsScraper/MapsScraperService.php
cd /var/www/apexone && sudo -u www-data php artisan view:clear
echo OK_{label}
"""
    cmd = f"echo {shlex.quote(pw)} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=60)
    print((o.read()+e.read()).decode(errors='replace'))
    ssh.close()
