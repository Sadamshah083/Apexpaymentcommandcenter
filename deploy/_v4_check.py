import paramiko

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("203.215.161.236", username="ateg", password="balitech1", timeout=30)

cmds = [
    "grep -n \"cities\\|agent-access\\|dispositions\" /var/www/apexone/routes/web.php | head -40",
    "grep -n \"city\\|State\\|City\\|Max results\" /var/www/apexone/resources/views/maps-scraper/index.blade.php | head -40",
    "ls -la /var/www/apexone/database/migrations/*workflow_agent_access* 2>/dev/null",
    "sudo -u www-data php /var/www/apexone/artisan tinker --execute=\"echo Schema::hasTable('workflow_agent_access') ? 'access_ok' : 'access_missing';\"",
]

for cmd in cmds:
    print("====", cmd[:100])
    stdin, stdout, stderr = c.exec_command(cmd, timeout=90)
    out = (stdout.read() + stderr.read()).decode("utf-8", "replace")
    print(out[:2500])

c.close()
print("DONE")
