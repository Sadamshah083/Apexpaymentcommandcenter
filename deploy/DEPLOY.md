# Production deployment

Target: Ubuntu 24.04 LTS with nginx, PHP 8.3, MySQL, Node 22, Supervisor/systemd.

## Quick deploy (from developer machine)

```bash
python deploy/run_deploy.py
```

Environment overrides:

```bash
set DEPLOY_HOST=203.215.160.44
set DEPLOY_USER=issac
set DEPLOY_PASSWORD=your-ssh-password
python deploy/run_deploy.py
```

## What gets installed

- `/var/www/apexone` — application root
- nginx site on port 80
- MySQL database `apexone`
- systemd service `apexone-queue` (queue workers)
- cron entry for `php artisan schedule:run`

## After deploy

- Health check: `http://<server-ip>/up`
- Admin login: `http://<server-ip>/admin/login`
- Portal login: `http://<server-ip>/portal/login`

Credentials are printed once at the end of deploy (also stored only in server `.env`).

## Manual updates

```bash
cd /var/www/apexone
sudo -u www-data git pull   # if using git
sudo bash deploy/install-app.sh   # re-run with same DB_* env vars
sudo systemctl restart apexone-queue php8.3-fpm nginx
```
