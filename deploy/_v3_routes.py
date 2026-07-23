import paramiko

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("203.215.161.236", username="ateg", password="balitech1", timeout=30)

cmds = [
    "cd /var/www/apexone && php artisan route:list --path=maps-scraper 2>/dev/null | head -40",
    "cd /var/www/apexone && php artisan route:list --path=agent-access 2>/dev/null | head -20",
    "cd /var/www/apexone && php artisan route:list --path=dispositions 2>/dev/null | head -20",
    "cd /var/www/apexone && php -r \"require 'vendor/autoload.php'; \\$app=require 'bootstrap/app.php'; \\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo Schema::hasTable('workflow_agent_access') ? 'access_ok' : 'access_missing';\"",
    "grep -n 'citySelect\\|per_search\\|Max results' /var/www/apexone/resources/views/maps-scraper/index.blade.php | head -30",
    "grep -n 'Share\\|agent-access\\|import-disposition' /var/www/apexone/resources/views/admin/dashboard/partials/imports-panel.blade.php | head -25",
]

for cmd in cmds:
    print("====", cmd[:90])
    stdin, stdout, stderr = c.exec_command(cmd, timeout=90)
    out = (stdout.read() + stderr.read()).decode("utf-8", "replace")
    print(out[:2500])

c.close()
print("DONE")
