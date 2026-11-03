#!/usr/bin/env bash
# Обновление Sterling Pay на сервере. Запускать из /var/www/wallets/sp-wallet от root (или через sudo).
set -euo pipefail

APP=/var/www/wallets/sp-wallet
RUN="sudo -u www-data"

cd "$APP"
git pull
$RUN composer install --no-dev -o --no-interaction
$RUN php artisan migrate --force
$RUN php artisan optimize:clear
$RUN npm ci
$RUN npm run build
$RUN php artisan optimize
$RUN php artisan queue:restart
chown -R www-data:www-data storage bootstrap/cache
systemctl reload php8.4-fpm
echo "Deployed: $(git log --oneline -1)"
