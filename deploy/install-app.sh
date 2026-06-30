#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/apexone"
APP_URL="${APP_URL:-http://203.215.160.44}"
DB_NAME="${DB_NAME:-apexone}"
DB_USER="${DB_USER:-apexone}"
DB_PASS="${DB_PASS:?DB_PASS required}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:?ADMIN_PASS required}"

cd "$APP_DIR"

PRESERVE_ENV="${PRESERVE_ENV:-0}"
if [[ -f .env ]] && [[ "$PRESERVE_ENV" == "1" ]]; then
  echo "==> Preserving existing .env"
  DB_PASS="$(grep '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"')"
  ADMIN_PASS="${ADMIN_PASS:-$(grep '^PRODUCTION_ADMIN_PASSWORD=' .env | cut -d= -f2- | tr -d '"' || echo "$ADMIN_PASS")}"
else
  echo "==> Writing production .env..."
  cat > .env <<EOF
APP_NAME="ApexOne Command Center"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=${APP_URL}

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=${DB_NAME}
DB_USERNAME=${DB_USER}
DB_PASSWORD=${DB_PASS}

SESSION_DRIVER=database
SESSION_LIFETIME=120

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
QUEUE_WORKERS=2

CACHE_STORE=database

MAIL_MAILER=log

VITE_APP_NAME="ApexOne Command Center"

PRODUCTION_ADMIN_USER=${ADMIN_USER}
PRODUCTION_ADMIN_PASSWORD=${ADMIN_PASS}
PRODUCTION_ADMIN_EMAIL=admin@apexone.local
EOF

  chown www-data:www-data .env
  chmod 640 .env
fi

if [[ ! -f .env ]]; then
  echo "ERROR: .env missing after install bootstrap" >&2
  exit 1
fi

echo "==> Installing PHP dependencies..."
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Building frontend assets..."
npm ci --ignore-scripts
npm run build
rm -rf node_modules

echo "==> Laravel bootstrap..."
php artisan key:generate --force
if [[ "$PRESERVE_ENV" == "1" ]]; then
  php artisan migrate --force
  php artisan db:seed --class=ApexPaymentsWorkspaceSeeder --force || true
else
  php artisan migrate:fresh --seed --force
fi
php artisan storage:link || true

echo "==> Creating production admin..."
php scripts/production-bootstrap.php

php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Permissions..."
chown -R www-data:www-data storage bootstrap/cache public/build .env
chmod -R ug+rwx storage bootstrap/cache
rm -f public/hot

echo "Application install complete."
