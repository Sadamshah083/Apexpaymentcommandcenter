#!/usr/bin/env bash
set -euo pipefail

# Ubuntu 24.04 LTS — first-time server provisioning for ApexOne Command Center.
# Run as a sudo-capable user: bash deploy/provision.sh

export DEBIAN_FRONTEND=noninteractive

echo "==> Updating packages..."
apt-get update -y
apt-get upgrade -y

echo "==> Installing base packages..."
apt-get install -y \
    nginx \
    mysql-server \
    git \
    curl \
    unzip \
    software-properties-common \
    supervisor \
    ufw

echo "==> Installing PHP 8.3..."
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y \
    php8.3-fpm \
    php8.3-cli \
    php8.3-mysql \
    php8.3-xml \
    php8.3-mbstring \
    php8.3-curl \
    php8.3-zip \
    php8.3-gd \
    php8.3-bcmath \
    php8.3-intl \
    php8.3-readline \
    php8.3-dom

echo "==> Installing Node.js 22..."
if ! command -v node >/dev/null 2>&1; then
    curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
    apt-get install -y nodejs
fi

echo "==> Installing Composer..."
if ! command -v composer >/dev/null 2>&1; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

echo "==> Creating app directory..."
mkdir -p /var/www/apexone
chown -R www-data:www-data /var/www/apexone

echo "==> Configuring firewall..."
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable || true

echo "==> Enabling services..."
systemctl enable nginx php8.3-fpm mysql supervisor
systemctl start nginx php8.3-fpm mysql supervisor

echo "Provisioning complete."
