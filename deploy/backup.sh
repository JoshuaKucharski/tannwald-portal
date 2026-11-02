#!/usr/bin/env bash
# myTannwald — Nacht-Backup (deploy/backup.sh, 4.3, ADR-012). Läuft als root über tannwald-backup.service (03:30)
# oder einmal aus deploy.sh: mysqldump der Datenbank (root über den Socket), files/ ohne die erzeugbaren Ordner
# css, js, php, styles, und private/ nach $BACKUP_DIR; älter als KEEP_DAYS Tage wird gelöscht. Ein Minimum, kein
# Versprechen (RunBook §5). Liest aus der env-Datei nur DB_NAME; gibt nie Werte aus.
# Ende (eine Zeile ins Log): „<Zeit> Backup <stempel> in <n> s: db … · files … · private … · insgesamt …“.
set -euo pipefail
umask 077
APP="${APP:-/var/www/mytannwald}"
PRIVATE="${PRIVATE:-/var/www/mytannwald-private}"
ENV_FILE="${ENV_FILE:-/etc/mytannwald/env}"
DIR="${BACKUP_DIR:-/var/backups/mytannwald}"
KEEP_DAYS="${KEEP_DAYS:-7}"
MYSQLDUMP="${TW_MYSQLDUMP:-mysqldump}"

db=$(sed -n 's/^DB_NAME=//p' "$ENV_FILE" | tail -n 1 | tr -d "'\"")
case "$db" in ''|*[!A-Za-z0-9_]*) echo "$(date '+%F %T') ABBRUCH: DB_NAME fehlt oder ungültig in $ENV_FILE"; exit 1 ;; esac
stamp=$(date +%Y%m%d-%H%M)
t0=$(date +%s)
mkdir -p "$DIR"
$MYSQLDUMP --single-transaction --quick --no-tablespaces "$db" | gzip -6 > "$DIR/db-$stamp.sql.gz"
tar -czf "$DIR/files-$stamp.tar.gz" -C "$APP/web/sites/default" \
    --exclude=files/css --exclude=files/js --exclude=files/php --exclude=files/styles files
tar -czf "$DIR/private-$stamp.tar.gz" -C "$(dirname "$PRIVATE")" "$(basename "$PRIVATE")"
find "$DIR" -maxdepth 1 -type f -name '*-[0-9]*.gz' -mtime +"$((KEEP_DAYS - 1))" -delete
sizes=$(cd "$DIR" && du -h "db-$stamp.sql.gz" "files-$stamp.tar.gz" "private-$stamp.tar.gz" | awk '{printf "%s %s · ", $2, $1}')
echo "$(date '+%F %T') Backup $stamp in $(( $(date +%s) - t0 )) s: ${sizes}insgesamt $(du -sh "$DIR" | cut -f1) ($(ls "$DIR" | wc -l | tr -d ' ') Dateien)"
