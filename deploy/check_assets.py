import paramiko

c = paramiko.SSHClient()
c.set_missing_host_key_policy(paramiko.AutoAddPolicy())
c.connect("203.215.160.44", username="issac", password="btdev", timeout=20)
cmds = [
    "ls -la /var/www/apexone/public/hot 2>/dev/null; cat /var/www/apexone/public/hot 2>/dev/null",
    "ls -la /var/www/apexone/bootstrap/cache/",
    "php -r \"require '/var/www/apexone/vendor/autoload.php'; \\$a=require '/var/www/apexone/bootstrap/app.php'; \\$a->make('Illuminate\\\\Contracts\\\\Console\\\\Kernel')->bootstrap(); echo file_exists(public_path('build/manifest.json'))?'manifest yes':'manifest no'; echo PHP_EOL; echo app()->environment();\" 2>/dev/null",
    "echo btdev | sudo -S ls -la /var/www/apexone/public/hot /var/www/apexone/public/build/manifest.json",
]
for cmd in cmds:
    _, o, e = c.exec_command(cmd)
    print(">>>", cmd[:60])
    print(o.read().decode() or e.read().decode())
c.close()
