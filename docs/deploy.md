# Деплой Sterling Pay на новый сервер

Папка проекта на сервере: **`/var/www/wallets/sp-wallet`**. Ubuntu 24.04 LTS, nginx, PHP 8.4, MySQL 8, Node 22.
Все команды ниже: `&&` склеивает шаги, где важен порядок. Команды `artisan` и `npm` запускайте от пользователя **`www-data`**
(`sudo -u www-data …`), а не от root: так файлы в `storage/` не станут root-овыми, и приложение не потеряет права на запись.

## 0. Что подготовить заранее

- Домен (например `wallet.sterling-pay.com`) с A-записью на IP сервера.
- SSH-доступ, пароль для MySQL, новый пароль администратора.
- Секреты, которые придётся ввести заново: ключи/пароли ботов (Admin → Bots), ключ FinBridges, authenticator-секреты (Admin → Authenticator codes), SMTP для писем с отчётами, `TELEGRAM_TOKEN`.
- **Сразу сохраните в менеджере паролей:** `.env` целиком и особенно `APP_KEY` (без него зашифрованные поля не прочитать).

## 1. Сервер и пакеты

```bash
apt update && apt -y upgrade && apt -y install ca-certificates curl gnupg git unzip ufw fail2ban software-properties-common nginx mysql-server certbot python3-certbot-nginx
ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw --force enable
```

PHP 8.4 (репозиторий Ondřej) и расширения:

```bash
add-apt-repository -y ppa:ondrej/php && apt update && apt -y install php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl php8.4-gd php8.4-opcache
```

Composer и Node 22:

```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && apt -y install nodejs
```

PHP-настройки (загрузка отчётов до 100 МБ, документы до 20 МБ):

```bash
cat > /etc/php/8.4/fpm/conf.d/90-sterling.ini <<'INI'
upload_max_filesize = 100M
post_max_size = 110M
memory_limit = 512M
max_execution_time = 120
opcache.enable = 1
INI
systemctl restart php8.4-fpm
```

## 2. База данных

```bash
mysql -e "CREATE DATABASE sterling_wallet CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'sterling'@'127.0.0.1' IDENTIFIED BY 'СЛОЖНЫЙ_ПАРОЛЬ'; GRANT ALL ON sterling_wallet.* TO 'sterling'@'127.0.0.1'; FLUSH PRIVILEGES;"
```

## 3. Код

```bash
mkdir -p /var/www/wallets && cd /var/www/wallets && git clone https://github.com/SHENiiDEV/sterling-wallet.git sp-wallet && cd sp-wallet && git checkout main
chown -R www-data:www-data /var/www/wallets/sp-wallet
```

## 4. `.env` (продакшен)

```bash
cd /var/www/wallets/sp-wallet && sudo -u www-data cp .env.example .env && sudo -u www-data nano .env
```

Обязательно поменять:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://wallet.sterling-pay.com
LOG_LEVEL=warning

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sterling_wallet
DB_USERNAME=sterling
DB_PASSWORD=СЛОЖНЫЙ_ПАРОЛЬ

QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=1500          # больше таймаута задачи бота (1200 с)

MAIL_MAILER=smtp                   # и ваш SMTP: MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS
ADMIN_NAME="Administrator"
ADMIN_EMAIL=вы@домен
ADMIN_PASSWORD=                    # пусто: пароль сгенерируется и покажется один раз

STERLING_REPORT_TIMEZONE=Europe/Riga
BOT_REPORTS_API_KEY=               # длинный случайный токен (openssl rand -hex 32), если нужен внешний API
BOTS_NODE_BINARY=node
BOTS_HEADLESS=true
BOTS_HTTP_PROXY=                   # прокси для ботов, если нужен (http://host:8888)
TELEGRAM_TOKEN=
TELEGRAM_CHAT_ID=
```

## 5. Установка

```bash
cd /var/www/wallets/sp-wallet && sudo -u www-data composer install --no-dev -o --no-interaction && sudo -u www-data php artisan key:generate --force && sudo -u www-data php artisan migrate --force && sudo -u www-data php artisan db:seed --force && sudo -u www-data npm ci && sudo -u www-data npm run build && sudo -u www-data php artisan optimize
```

`db:seed` создаёт суперадмина из `ADMIN_*` (пароль, если не задан, выводится **один раз**: сохраните) и статусы Document Center.
Новый `APP_KEY` создан здесь: сохраните `.env` в менеджер паролей сразу после этого шага.

## 6. nginx

```bash
cat > /etc/nginx/sites-available/sterling-wallet <<'NGINX'
server {
    server_name wallet.sterling-pay.com;
    root /var/www/wallets/sp-wallet/public;
    index index.php;
    client_max_body_size 110M;

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location /build/ {
        expires 1y;
        add_header Cache-Control "public, max-age=31536000, immutable";
        try_files $uri =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 120;
        # Страницы в новой вкладке приходят с большими заголовками Link (предзагрузка): без этого nginx отвечает 502.
        fastcgi_buffer_size 32k;
        fastcgi_buffers 16 16k;
        fastcgi_busy_buffers_size 32k;
    }

    location ~ /\.(?!well-known) { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/sterling-wallet /etc/nginx/sites-enabled/sterling-wallet && rm -f /etc/nginx/sites-enabled/default && nginx -t && systemctl reload nginx
certbot --nginx -d wallet.sterling-pay.com --redirect -m ВАШ_EMAIL --agree-tos -n
```

Проверка: `curl -sI https://wallet.sterling-pay.com/up` должен вернуть 200.

## 7. Очередь (воркер)

Воркер выполняет боты, импорт отчётов, генерацию PDF и рассылку. Без него система «стоит».

```bash
cat > /etc/systemd/system/sterling-worker.service <<'UNIT'
[Unit]
Description=Sterling Pay queue worker
After=network.target mysql.service

[Service]
User=www-data
WorkingDirectory=/var/www/wallets/sp-wallet
ExecStart=/usr/bin/php artisan queue:work --memory=768 --sleep=3 --tries=3 --timeout=1200 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload && systemctl enable --now sterling-worker && systemctl status sterling-worker --no-pager
```

После каждого деплоя: `php artisan queue:restart` (воркер перезапустится сам).

## 8. Планировщик (cron, каждую минуту)

```bash
cat > /etc/cron.d/sterling <<'CRON'
* * * * * www-data cd /var/www/wallets/sp-wallet && php artisan schedule:run >> /dev/null 2>&1
CRON
chmod 644 /etc/cron.d/sterling
```

Именно **каждую минуту**: от этого зависят боты с расписанием «каждые N минут» и «раз в день».
Проверка: `sudo -u www-data php artisan schedule:list`.

## 9. Боты (Playwright + Chromium)

```bash
cd /var/www/wallets/sp-wallet/bots && sudo -u www-data npm ci && npx playwright install-deps chromium && sudo -u www-data npm run install-browser
```

Браузер ставится в `bots/.browsers` (внутри проекта), поэтому от какого пользователя запускать, не важно.
Аккаунты ботов (логин, пароль, 2FA, настройки) вводятся в Admin → Bots. Время запуска каждого аккаунта: поле **Schedule**.

## 10. Проверка после установки

```bash
cd /var/www/wallets/sp-wallet && sudo -u www-data php artisan about && sudo -u www-data php artisan schedule:list && systemctl is-active sterling-worker nginx php8.4-fpm mysql
```

1. Откройте сайт, войдите суперадмином, включите двухфакторную защиту (Settings → Security).
2. Admin → Team: создайте сотрудников и выдайте права (в том числе «Authenticator codes»).
3. Admin → Bots → любой аккаунт → Run now: убедитесь, что запуск проходит (`succeeded` или `skipped`, не `failed`).

## 11. Наполнение данными (порядок восстановления)

1. **Провайдеры** (Providers): Cardaq, Madfin, Corefy, FinBridges, Oxen и т.д. с формой отчёта, часовым поясом, задержкой (Cardaq 2, Corefy 1) и условиями.
2. **Банки, клиенты, компании и MID под Corefy одной командой:** `sudo -u www-data php artisan db:seed --class=BanksAndMidsSeeder --force`. Создаст Madfin, PayAlly, Cardaq с их условиями, провайдера Corefy, клиентов (1Cap, BazPay, DG, Gold, KK, Payscrow, Zenithal), компании и сайты под ними, записи онбординга банков и по одному MID к Corefy для каждой готовой компании. MID с названием `TBD-…` нужно переименовать на реальный номер (Merchants → MID). Команду можно запускать повторно: ничего не дублируется. Обновить условия банков позже: `php artisan banks:import /путь/banks.csv --apply`.
3. **Клиенты → компании → мерчанты → MID** (Admin → Clients, Companies, Merchants); тарифы мерчантов, график выплат T+N, кошельки для выплат, e-mail для счетов.
4. **Праздники** (Admin → Bank holidays) и курсы FX, если вы ведёте их вручную.
5. **Аккаунты ботов** и **Authenticator codes** (секреты из платформ заново).
6. **История:** `php artisan finbridges:reload --from=ГГГГ-ММ-ДД --apply` для C2B; для Corefy и Cardaq: Admin → Bots → Run now по датам (или Report Center → Upload). Затем `php artisan reports:reconcile --days=60`.
7. **Отчёты и выплаты:** пересоберите отчёты (`php artisan reports:regenerate --apply`), сверьте по `php artisan reports:pairing`, а выплаты и резерв сверьте с вашими банковскими и крипто-выписками, прежде чем создавать settlement (чтобы не выплатить дважды).

## 12. Обновление (каждый деплой)

```bash
cd /var/www/wallets/sp-wallet && ./deploy.sh
```

Скрипт `deploy.sh` делает: `git pull`, `composer install --no-dev -o`, `migrate --force`, `optimize:clear`, `npm ci`, `npm run build`, `optimize`, `queue:restart`, правка прав, перезагрузка php-fpm. Вручную одной строкой:

```bash
cd /var/www/wallets/sp-wallet && git pull && sudo -u www-data composer install --no-dev -o && sudo -u www-data php artisan migrate --force && sudo -u www-data php artisan optimize:clear && sudo -u www-data npm ci && sudo -u www-data npm run build && sudo -u www-data php artisan optimize && sudo -u www-data php artisan queue:restart && chown -R www-data:www-data storage bootstrap/cache && systemctl reload php8.4-fpm
```

## 13. Резервные копии (обязательно настроить в первый же день)

Прошлый сервер умер без копий. Минимум:

1. **Полный слепок сервера** скриптом `scripts/backup.sh`: `/var/www` (без `vendor`, `node_modules`, `.browsers`), конфиги (nginx, php, mysql, letsencrypt, systemd, cron, ssh), список пакетов и дамп **всех** баз MySQL. Файлы шифруются gpg, локально хранятся 7 дней и отправляются в Google Drive через rclone (папка `server-backups`).

    ```bash
    curl https://rclone.org/install.sh | bash
    rclone config            # n → имя gdrive → тип drive → scope drive → auto config: n → токен из `rclone authorize "drive"` на вашем компьютере
    openssl rand -base64 32 > /root/.sterling-backup-pass && chmod 600 /root/.sterling-backup-pass   # СОХРАНИТЕ пароль отдельно (менеджер паролей)
    cd /var/www/wallets/sp-wallet && chmod +x deploy.sh scripts/backup.sh && scripts/backup.sh && rclone ls gdrive:server-backups
    cat > /etc/cron.d/sterling-backup <<'CRON'
    20 */6 * * * root /var/www/wallets/sp-wallet/scripts/backup.sh >> /var/log/sterling-backup.log 2>&1
    CRON
    ```

    Без пароля шифрования скрипт ничего не отправляет (в копии есть `.env` и ключи).

2. **Снапшот диска** у провайдера VPS (включите автоматические), раз в неделю: это единственное, что вернёт сервер целиком вместе с ОС.
3. **Пароль шифрования** и `APP_KEY` храните в менеджере паролей, не на сервере.
4. **Раз в месяц проверяйте восстановление** на чистой машине.

Восстановление: скачать файлы с Drive (`rclone copy gdrive:server-backups /root/restore`), расшифровать и распаковать:

```bash
cd /root/restore && for f in *.gpg; do gpg --batch --pinentry-mode loopback --passphrase-file /root/.sterling-backup-pass -o "${f%.gpg}" -d "$f"; done
gunzip -c mysql_all_ДАТА.sql.gz | mysql && tar -xzf var_www_ДАТА.tar.gz -C /var && tar -xzf server_config_ДАТА.tar.gz -C / && cd /var/www/wallets/sp-wallet && composer install --no-dev && npm ci && npm run build && chown -R www-data:www-data /var/www/wallets/sp-wallet
```

Конфиги из `server_config` проверьте перед перезаписью `/etc` на новой ОС; список пакетов: `dpkg --set-selections < packages.txt && apt-get dselect-upgrade`.

## 14. Безопасность и эксплуатация

- SSH: вход только по ключу (`PasswordAuthentication no` в `/etc/ssh/sshd_config`), `fail2ban` уже стоит.
- `APP_DEBUG=false`, HTTPS через certbot (продлевается сам; проверка `certbot renew --dry-run`).
- Автоматические обновления безопасности: `apt -y install unattended-upgrades && dpkg-reconfigure -plow unattended-upgrades`.
- Мониторинг: в `.env` задайте `TELEGRAM_TOKEN` и `TELEGRAM_CHAT_ID`: система предупредит, если бот падает несколько раз подряд. Дополнительно проверяйте `/up` внешним сервисом (UptimeRobot).
- Логи: `/var/www/wallets/sp-wallet/storage/logs/laravel-*.log`, воркер: `journalctl -u sterling-worker -n 100`, nginx: `/var/log/nginx/error.log`.

## 15. Частые проблемы

| Симптом                                          | Причина и решение                                                                                                                       |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------- |
| 500 после деплоя, в логе «Permission denied»     | файлы в `storage` или `bootstrap/cache` стали root-овыми: `chown -R www-data:www-data storage bootstrap/cache`                          |
| «Unknown column …»                               | не применены миграции: `sudo -u www-data php artisan migrate --force && sudo -u www-data php artisan optimize:clear`                    |
| Боты не запускаются / ничего не обрабатывается   | воркер не работает (`systemctl status sterling-worker`) или нет cron (`cat /etc/cron.d/sterling`)                                       |
| Загрузка файла: 413                              | `client_max_body_size` в nginx или `upload_max_filesize` в PHP меньше файла                                                             |
| Белый экран, `Vite manifest not found`           | не выполнен `npm run build`                                                                                                             |
| 502 только при открытии страницы в новой вкладке | большие заголовки `Link` не помещаются в буфер nginx: добавьте `fastcgi_buffer_size 32k; fastcgi_buffers 16 16k;` в `location ~ \.php$` |
| «Provider … has no report format set»            | у провайдера не заполнен Report format (Admin → Providers): cardaq / corefy / madfin / finbridges                                       |
| Бот Cardaq «skipped»                             | письма ещё нет: нормально, повторит по расписанию                                                                                       |
