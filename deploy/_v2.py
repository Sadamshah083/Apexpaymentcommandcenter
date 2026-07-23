import paramiko
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import deploy._ssh as m
from deploy._ssh import sudo_run

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(m.HOST, username=m.USER, password=m.PASSWORD, timeout=40)
out = sudo_run(ssh, r"""
cd /var/www/apexone
php artisan migrate --force --no-interaction 2>&1 | tail -20
php artisan view:clear
php artisan route:clear
php artisan config:clear
npm run build --silent 2>&1 | tail -15
grep -n "citiesForState\|workflow_agent_access\|Dispositions\|import-disposition-btn\|maps-scraper/cities" routes/web.php resources/views/admin/dashboard/partials/imports-panel.blade.php app/Services/MapsScraper/MapsScraperService.php | head -25
echo DONE_VERIFY
""")
print(out.encode("ascii", "replace").decode("ascii"))
ssh.close()
