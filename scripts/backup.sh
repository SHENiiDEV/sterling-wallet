#!/usr/bin/env bash
# Полный слепок сервера: /var/www, конфиги (nginx, systemd, cron, php, letsencrypt, ssh) и дамп ВСЕХ баз MySQL.
# Шифрует gpg, хранит KEEP_DAYS дней локально и копирует в Google Drive через rclone.
# Запуск: cron от root (см. docs/deploy.md). Пароль шифрования: /root/.sterling-backup-pass (chmod 600).
set -euo pipefail

APP=/var/www/wallets/sp-wallet
DEST=/var/backups/sterling
KEEP_DAYS=${KEEP_DAYS:-7}
PASS_FILE=/root/.sterling-backup-pass
# Куда отправлять (вне этого сервера). По умолчанию Google Drive, remote "gdrive" из `rclone config`.
REMOTE="${REMOTE:-rclone copy $DEST gdrive:server-backups --transfers 2}"
# Папки, которые не копируем: восстанавливаются командой (composer/npm/playwright) и весят много.
EXCLUDES=(--exclude='node_modules' --exclude='vendor' --exclude='.browsers' --exclude='storage/framework/cache' --exclude='storage/logs')

STAMP=$(date +%F_%H%M)
mkdir -p "$DEST" && chmod 700 "$DEST"

# 1) Все базы MySQL (root через unix-сокет; иначе берём логин из .env приложения)
if mysqldump --all-databases --single-transaction --quick --routines --events --triggers 2>/dev/null | gzip > "$DEST/mysql_all_$STAMP.sql.gz" && [ "${PIPESTATUS[0]}" -eq 0 ]; then
  :
else
  U=$(grep -E '^DB_USERNAME=' "$APP/.env" | cut -d= -f2-); P=$(grep -E '^DB_PASSWORD=' "$APP/.env" | cut -d= -f2-)
  mysqldump -h 127.0.0.1 -u "$U" -p"$P" --all-databases --single-transaction --quick --routines --events --triggers | gzip > "$DEST/mysql_all_$STAMP.sql.gz"
fi

# 2) Содержимое /var/www (код, .env, storage/app, загрузки)
tar -czf "$DEST/var_www_$STAMP.tar.gz" "${EXCLUDES[@]}" -C /var www

# 3) Конфиги сервера
CONF=()
for p in /etc/nginx /etc/php /etc/mysql /etc/letsencrypt /etc/systemd/system /etc/cron.d /etc/crontab /etc/supervisor /etc/ssh /etc/fail2ban /etc/ufw /var/spool/cron; do
  [ -e "$p" ] && CONF+=("$p")
done
crontab -l > /tmp/root_crontab_$STAMP 2>/dev/null && CONF+=("/tmp/root_crontab_$STAMP") || true
dpkg --get-selections > /tmp/packages_$STAMP.txt && CONF+=("/tmp/packages_$STAMP.txt")
tar -czf "$DEST/server_config_$STAMP.tar.gz" "${CONF[@]}" 2>/dev/null || true
rm -f /tmp/root_crontab_$STAMP /tmp/packages_$STAMP.txt

# 4) Шифрование
if [ -f "$PASS_FILE" ]; then
  for f in "$DEST"/*_"$STAMP".*; do
    case "$f" in *.gpg) continue;; esac
    gpg --batch --yes --pinentry-mode loopback --passphrase-file "$PASS_FILE" -c -o "$f.gpg" "$f" && rm -f "$f"
  done
else
  echo "ERROR: $PASS_FILE not found — copies contain passwords and are NOT encrypted, not sending them" >&2
  exit 1
fi

find "$DEST" -type f -mtime +"$KEEP_DAYS" -delete

eval "$REMOTE"
# На Drive старше KEEP_DAYS не держим (локально и там)
rclone delete gdrive:server-backups --min-age "${KEEP_DAYS}d" 2>/dev/null || true
echo "Backup done: $STAMP ($(du -sh "$DEST" | cut -f1))"
