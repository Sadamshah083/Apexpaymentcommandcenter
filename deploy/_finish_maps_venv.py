import paramiko, shlex
REMOTE = "/var/www/apexone"
VENV = f"{REMOTE}/tools/google-maps-scraper/.venv"
TARGETS = [
    ("OLD", "203.215.160.44", "issac", "SadamShah123"),
    ("NEW", "203.215.161.236", "ateg", "balitech1"),
]
for label, host, user, pw in TARGETS:
    print("===", label, "===")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        ssh.connect(host, username=user, password=pw, timeout=40)
    except Exception as e:
        print("CONNECT_FAIL", e)
        continue
    inner = f"""
set -e
test -x {VENV}/bin/python
{VENV}/bin/python -c 'from playwright.sync_api import sync_playwright; import pandas; print("IMPORT_OK")'
# ensure browsers present
{VENV}/bin/playwright install chromium >/tmp/maps-pw2.log 2>&1 || true
tail -n 5 /tmp/maps-pw2.log || true
if grep -q '^MAPS_SCRAPER_PYTHON=' {REMOTE}/.env; then
  sed -i 's|^MAPS_SCRAPER_PYTHON=.*|MAPS_SCRAPER_PYTHON={VENV}/bin/python|' {REMOTE}/.env
else
  echo 'MAPS_SCRAPER_PYTHON={VENV}/bin/python' >> {REMOTE}/.env
fi
grep -q '^MAPS_SCRAPER_ENABLED=' {REMOTE}/.env && sed -i 's|^MAPS_SCRAPER_ENABLED=.*|MAPS_SCRAPER_ENABLED=true|' {REMOTE}/.env || echo 'MAPS_SCRAPER_ENABLED=true' >> {REMOTE}/.env
grep -q '^MAPS_SCRAPER_HEADLESS=' {REMOTE}/.env && sed -i 's|^MAPS_SCRAPER_HEADLESS=.*|MAPS_SCRAPER_HEADLESS=true|' {REMOTE}/.env || echo 'MAPS_SCRAPER_HEADLESS=true' >> {REMOTE}/.env
grep -q '^MAPS_SCRAPER_TIMEOUT=' {REMOTE}/.env && sed -i 's|^MAPS_SCRAPER_TIMEOUT=.*|MAPS_SCRAPER_TIMEOUT=28800|' {REMOTE}/.env || echo 'MAPS_SCRAPER_TIMEOUT=28800' >> {REMOTE}/.env
chown -R www-data:www-data {REMOTE}/tools/google-maps-scraper {REMOTE}/storage/app/maps-scraper
cd {REMOTE}
# ensure latest PHP service is present
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
php -l app/Services/MapsScraper/MapsScraperService.php
sudo -u www-data php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo app(App\\Services\\MapsScraper\\MapsScraperService::class)->resolvePython(), PHP_EOL;'
grep '^MAPS_SCRAPER_' {REMOTE}/.env
ls -la {VENV}/bin/python
echo DONE_{label}
"""
    cmd = f"echo {shlex.quote(pw)} | sudo -S -p '' bash -lc {shlex.quote(inner)}"
    _, o, e = ssh.exec_command(cmd, timeout=600)
    print((o.read()+e.read()).decode(errors='replace')[-3500:])
    ssh.close()
