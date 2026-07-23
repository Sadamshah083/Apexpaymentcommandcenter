import paramiko, shlex
from pathlib import Path
ROOT = Path(r"C:\Users\dev\Desktop\ApexonecommandCenter")
REMOTE = "/var/www/apexone"
FILES = [
    "app/Services/MapsScraper/MapsScraperService.php",
    "app/Http/Controllers/MapsScraperController.php",
    "config/maps_scraper.php",
    "resources/views/maps-scraper/index.blade.php",
    "tools/google-maps-scraper/apex_bridge.py",
]
TARGETS = [
    ("OLD", "203.215.160.44", "issac", "SadamShah123"),
    ("NEW", "203.215.161.236", "ateg", "balitech1"),
]
for label, host, user, pw in TARGETS:
    print("===", label, "===")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username=user, password=pw, timeout=40)
    sftp = ssh.open_sftp()
    for rel in FILES:
        remote = f"{REMOTE}/{rel}"
        # ensure dir
        parts = remote.rsplit("/", 1)[0]
        ssh.exec_command(f"mkdir -p {parts}")
        sftp.put(str(ROOT / rel), remote)
        print("put", rel)
    sftp.close()
    inner = f"""
cd {REMOTE}
chown www-data:www-data {' '.join(REMOTE+'/'+r for r in FILES)}
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
grep -n "resolvePython\\|1_000_000\\|result_limit" app/Services/MapsScraper/MapsScraperService.php | head -10
grep -n "0 = unlimited\\|Max results (0" resources/views/maps-scraper/index.blade.php | head -5
php -l app/Services/MapsScraper/MapsScraperService.php
echo OK_{label}
"""
    cmd = f"echo {shlex.quote(pw)} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=90)
    print((o.read()+e.read()).decode(errors='replace'))
    ssh.close()
