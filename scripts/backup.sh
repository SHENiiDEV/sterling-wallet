#!/usr/bin/env bash
# Ежедневная резервная копия: база, storage/app и .env, шифрование gpg, хранение 14 дней, копия на другой сервер.
# Запуск: cron от root (см. docs/deploy.md, раздел 13). Пароль шифрования: файл /root/.sterling-backup-pass (chmod 600).
set -euo pipefail

APP=/var/www/wallets/sp-wallet
DEST=/var/backups/sterling
KEEP_DAYS=14
PASS_FILE=/root/.sterling-backup-pass
# Куда отправлять копию (обязательно вне этого сервера), например:
#   REMOTE="rsync -a --delete $DEST/ backup@storage.example.com:sterling/"
#   REMOTE="rclone copy $DEST remote:sterling-backups"
REMOTE="${REMOTE:-}"

STAMP=$(date +%F_%H%M)
mkdir -p "$DEST" && chmod 700 "$DEST"
cd "$APP"

DB=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)
USER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2-)
PASS=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)

mysqldump --single-transaction --quick --routines -h 127.0.0.1 -u "$USER" -p"$PASS" "$DB" | gzip > "$DEST/sterling_db_$STAMP.sql.gz"
tar -czf "$DEST/sterling_storage_$STAMP.tar.gz" -C "$APP/storage/app" .
cp .env "$DEST/sterling_env_$STAMP"

if [ -f "$PASS_FILE" ]; then
  for f in "$DEST"/sterling_*_"$STAMP"*; do
    case "$f" in *.gpg) continue;; esac
    gpg --batch --yes --pinentry-mode loopback --passphrase-file "$PASS_FILE" -c -o "$f.gpg" "$f" && rm -f "$f"
  done
else
  echo "WARNING: $PASS_FILE not found, backups are NOT encrypted" >&2
fi

find "$DEST" -type f -mtime +"$KEEP_DAYS" -delete

if [ -n "$REMOTE" ]; then
  eval "$REMOTE"
else
  echo "WARNING: REMOTE is empty, the backups stay on this server only" >&2
fi
echo "Backup done: $STAMP"
