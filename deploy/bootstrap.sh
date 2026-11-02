#!/usr/bin/env bash
# myTannwald — Server vorbereiten (deploy/bootstrap.sh, 4.1, ADR-012). Auf 91.98.229.15 als root, nach dem ersten
# rsync des Repos nach /var/www/mytannwald; beliebig oft wiederholbar (idempotent):
#   bash /var/www/mytannwald/deploy/bootstrap.sh [--dry] [--tls] [--render=<ordner>]
# Fasst nichts Fremdes an: keine anderen Vhosts, kein Pool www von PHP 8.3, kein ufw, kein acme.sh, kein
# MySQL-root-Passwort (root über den Socket). /usr/bin/php bleibt 8.3: verschiebt die Installation von php8.4-cli
# die Alternative, stellt das Skript genau den vorherigen Zielpfad wieder her (update-alternatives --set).
# Schritte: 1 Vorbedingungen · 2 Nutzer und Ordner · 3 env-Datei · 4 PHP 8.4 · 5 Pool · 6 MySQL · 7 Mailpit ·
#   8 Ollama + bge-m3 · 9 Cron-/Backup-Units, Logs · 10 nginx (mit --tls: DNS-Prüfung, certbot --dry-run gegen die
#   Test-CA, dann certonly echt)
# --dry       druckt jeden Schritt, ändert nichts (fehlt die env-Datei, rechnet er mit deploy/.env.example)
# --tls       Zertifikat für DOMAIN und mail.DOMAIN (Test-CA zuerst; CERT_STAGING=on → nur Test-Zertifikat)
# --render=D  schreibt nur die fertigen Dateien nach D (Pool und .htpasswd enthalten Werte — D danach löschen)
# Konfiguration wird erst nach `php-fpm8.4 -t` bzw. `nginx -t` aktiv (reload, nie restart); ein roter Test stellt
# die vorherige Datei wieder her. Ende: „FERTIG — Server vorbereitet …“; Abbruch: „ABBRUCH (…)“, Exit 1.
set -euo pipefail
umask 022
SELF=bootstrap.sh
STEP_TOTAL=10
DEPLOY_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
. "$DEPLOY_DIR/lib.sh"

DRY=0; TLS=0; RENDER_DIR=""
for arg in "$@"; do
    case "$arg" in
        --dry) DRY=1 ;;
        --tls) TLS=1 ;;
        --render=*) RENDER_DIR=${arg#--render=} ;;
        -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
        *) say "Unbekannte Angabe: $arg (erlaubt: --dry --tls --render=<ordner>)"; exit 1 ;;
    esac
done

# Pfade und Namen; TW_* nur für Prüfstand und Generalprobe.
APP="${TW_APP:-/var/www/mytannwald}"
PRIVATE="${TW_PRIVATE:-/var/www/mytannwald-private}"
ETC="${TW_ETC:-/etc}"
BIN="${TW_BIN:-/usr/bin}"
ENV_FILE="${TW_ENV_FILE:-$ETC/mytannwald/env}"
RUN_USER="${TW_RUN_USER:-tannwald}"
STATE_DIR="${TW_STATE_DIR:-/var/lib/tannwald}"
PHP_BIN="${TW_PHP:-$BIN/php8.4}"
PHP_FPM="${TW_PHP_FPM:-/usr/sbin/php-fpm8.4}"
SOCK="${TW_SOCK:-/run/php/php8.4-fpm-mytannwald.sock}"
LISTEN_OWNER="${TW_LISTEN_OWNER:-www-data}"
POOL_DIR="$ETC/php/8.4/fpm/pool.d"
CLI_INI_DIR="$ETC/mytannwald/php"
NGINX_DIR="$ETC/nginx"
LE="${TW_LE:-$ETC/letsencrypt}"
LOG_DIR="${TW_LOG_DIR:-/var/log/nginx}"
APP_LOG_DIR="${TW_APP_LOG_DIR:-/var/log/mytannwald}"
BACKUP_DIR="${TW_BACKUP_DIR:-/var/backups/mytannwald}"
UNIT_DIR="$ETC/systemd/system"
MAILPIT_BIN="${TW_MAILPIT_BIN:-/usr/local/bin/mailpit}"
OLLAMA="${TW_OLLAMA:-ollama}"
MAILPIT_UI="${TW_MAILPIT_UI:-127.0.0.1:8025}"
MAILPIT_SMTP=127.0.0.1:1025
MYSQL_ROOT="${TW_MYSQL:-mysql --protocol=socket -uroot}"
DB_ACCOUNT_HOSTS="${TW_DB_ACCOUNT_HOSTS:-localhost 127.0.0.1}"
PHP_PKGS="php8.4-fpm php8.4-cli php8.4-bcmath php8.4-curl php8.4-gd php8.4-intl php8.4-mbstring php8.4-mysql php8.4-opcache php8.4-xml php8.4-zip"
if [ -n "${TW_LISTEN_HTTP:-}" ]; then LISTEN_HTTP="$TW_LISTEN_HTTP"; else LISTEN_HTTP="listen 80;
    listen [::]:80;"; fi
if [ -n "${TW_LISTEN_HTTPS:-}" ]; then LISTEN_HTTPS="$TW_LISTEN_HTTPS"; else LISTEN_HTTPS="listen 443 ssl;
    listen [::]:443 ssl;"; fi

TMPD="$(mktemp -d "${TMPDIR:-/tmp}/tannwald-bootstrap.XXXXXX")"
chmod 700 "$TMPD"
BACKUPS=""
NEW_LINKS=""
WWW_MOVED=0
on_exit() {
    rc=$?
    if [ "$rc" -ne 0 ] && { [ -n "$BACKUPS" ] || [ -n "$NEW_LINKS" ] || [ "$WWW_MOVED" = 1 ]; }; then
        say "   Rückbau: vorherige Dateien wiederhergestellt"; rollback
    fi
    rm -rf "$TMPD"
}
trap on_exit EXIT

# --- Dateien: rendern, vergleichen, mit Sicherung schreiben, bei rotem Test zurück ---------------------------
CHANGED=0
install_file() { # install_file <fertig> <ziel> <modus> [eigentümer]
    local src=$1 dst=$2 mode=$3 owner=${4:-root:root} st
    st=$(file_state "$src" "$dst")
    if [ "$st" = gleich ]; then ok "$dst unverändert"; return 0; fi
    CHANGED=1
    if [ "$DRY" = 1 ]; then say "   [dry] schreibe $dst ($st, Modus $mode, $owner)"; return 0; fi
    mkdir -p "$(dirname "$dst")"
    if [ -e "$dst" ]; then cp -p "$dst" "$dst.tannwald-bak"; fi
    BACKUPS="$BACKUPS $dst"
    cp "$src" "$dst"; chmod "$mode" "$dst"; chown "$owner" "$dst"
    ok "$dst geschrieben ($st)"
}
rollback() {
    local f
    for f in $BACKUPS; do
        if [ -e "$f.tannwald-bak" ]; then mv "$f.tannwald-bak" "$f"; else rm -f "$f"; fi
    done
    for f in $NEW_LINKS; do rm -f "$f"; done
    if [ "$WWW_MOVED" = 1 ]; then mv "$POOL_DIR/www.conf.disabled-by-tannwald" "$POOL_DIR/www.conf"; fi
    BACKUPS=""; NEW_LINKS=""; WWW_MOVED=0
}
commit() {
    local f
    for f in $BACKUPS; do rm -f "$f.tannwald-bak"; done
    BACKUPS=""; NEW_LINKS=""; WWW_MOVED=0; CHANGED=0
}
svc() { # svc <aktion> <unit> — systemctl, im Trockenlauf nur gedruckt
    run systemctl "$@"
}

# Fertige Dateien aus den Vorlagen (Werte aus $ENV_SRC) nach $1.
render_all() {
    local out=$1 k v lines=""
    export R_PRIVATE="$PRIVATE" R_RUN_USER="$RUN_USER" R_RUN_GROUP="${TW_RUN_GROUP:-$RUN_USER}" R_LISTEN_GROUP="${TW_LISTEN_GROUP:-$LISTEN_OWNER}" R_SOCK="$SOCK" R_LISTEN_OWNER="$LISTEN_OWNER" R_APP="$APP"
    export R_OPEN_BASEDIR="${TW_OPEN_BASEDIR:-$APP:$PRIVATE:/tmp}"
    export R_SENDMAIL="$MAILPIT_BIN sendmail -t --smtp-addr $MAILPIT_SMTP"
    for k in DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD DRUPAL_HASH_SALT TRUSTED_HOST_PATTERN OLLAMA_HOST \
             DASHSCOPE_API_KEY_EU DASHSCOPE_ENDPOINT_EU SITE_MAIL; do
        v=$(env_get "$k")
        case "$k" in DB_HOST) v=${v:-127.0.0.1} ;; DB_PORT) v=${v:-3306} ;; OLLAMA_HOST) v=${v:-http://127.0.0.1:11434} ;; esac
        [ -n "$v" ] || continue
        lines="${lines}env[$k] = \"$v\"
"
    done
    lines="${lines}env[PRIVATE_PATH] = \"$PRIVATE\""
    export R_ENV_LINES="$lines"
    export R_DOMAIN="$DOMAIN" R_LOG_DIR="$LOG_DIR" R_LE="$LE" R_MAILPIT_UI="$MAILPIT_UI" R_MAILPIT_SMTP="$MAILPIT_SMTP"
    export R_SNIPPET="$NGINX_DIR/snippets/mytannwald-drupal.conf" R_HTPASSWD="$NGINX_DIR/mytannwald.htpasswd"
    export R_LISTEN_HTTP="$LISTEN_HTTP" R_LISTEN_HTTPS="$LISTEN_HTTPS"
    if [ "$(env_get SITE_AUTH)" = on ]; then
        export R_SITE_AUTH="auth_basic \"myTannwald Demo\";
    auth_basic_user_file $NGINX_DIR/mytannwald.htpasswd;"
    else
        export R_SITE_AUTH="# SITE_AUTH=off (D9): keine Basic-Auth vor der Site"
    fi
    export R_ENV_FILE="$ENV_FILE" R_CLI_INI_DIR="$CLI_INI_DIR" R_PHP_DIR="$STATE_DIR/bin" R_MAILPIT_BIN="$MAILPIT_BIN"
    R_OLLAMA_BIN="$(command -v "$OLLAMA" 2>/dev/null || echo /usr/local/bin/ollama)"; export R_OLLAMA_BIN
    render "$DEPLOY_DIR/php/mytannwald.conf" "$out/mytannwald.conf"
    render "$DEPLOY_DIR/php/mytannwald-cli.ini" "$out/mytannwald-cli.ini"
    render "$DEPLOY_DIR/nginx/mytannwald-drupal.conf" "$out/mytannwald-drupal.conf"
    render "$DEPLOY_DIR/nginx/mytannwald-http.conf" "$out/vhost-http.conf"
    render "$DEPLOY_DIR/nginx/mytannwald.getleaven.com.conf" "$out/vhost-tls.conf"
    export R_APP_LOG_DIR="$APP_LOG_DIR" R_BACKUP_DIR="$BACKUP_DIR"
    for k in tannwald-cron.service tannwald-cron.timer tannwald-backup.service tannwald-backup.timer mailpit.service ollama.service; do
        render "$DEPLOY_DIR/systemd/$k" "$out/$k"
    done
    render "$DEPLOY_DIR/logrotate/mytannwald" "$out/logrotate-mytannwald"
    htpasswd_line > "$out/mytannwald.htpasswd"
    chmod 600 "$out/mytannwald.conf" "$out/mytannwald.htpasswd"
}

# .htpasswd aus GUEST_USER/GUEST_PASSWORD (apr1, Passwort über stdin). Stimmt das Passwort mit dem vorhandenen Hash
# überein, bleibt die Zeile gleich (gleiches Salz) — sonst würde jeder Lauf nginx neu laden.
htpasswd_line() {
    local user pw cur salt
    user=$(env_get GUEST_USER); pw=$(env_get GUEST_PASSWORD)
    cur=$(sed -n "s/^$user://p" "$NGINX_DIR/mytannwald.htpasswd" 2>/dev/null | head -n 1 || true)
    salt=""; case "$cur" in '$apr1$'*) salt=$(printf '%s' "$cur" | cut -d'$' -f3) ;; esac
    if [ -n "$salt" ] && [ "$(printf '%s' "$pw" | openssl passwd -apr1 -salt "$salt" -stdin)" = "$cur" ]; then
        printf '%s:%s\n' "$user" "$cur"
    else
        printf '%s:%s\n' "$user" "$(printf '%s' "$pw" | openssl passwd -apr1 -stdin)"
    fi
}

pkg_installed() {
    command -v dpkg-query >/dev/null 2>&1 || return 1
    [ "$(dpkg-query -W -f='${Status}' "$1" 2>/dev/null)" = "install ok installed" ]
}
alt_links() { local n; for n in php phar phar.phar; do printf '%s=%s ' "$n" "$(readlink -f "$BIN/$n" 2>/dev/null || echo -)"; done; }

# ------------------------------------------------------------------------------------------------------------
say "myTannwald bootstrap.sh — $(date '+%Y-%m-%d %H:%M')$( [ "$DRY" = 1 ] && echo ' (Trockenlauf)')$( [ -n "$RENDER_DIR" ] && echo " (nur Dateien nach $RENDER_DIR)")"

step "Vorbedingungen"
if [ "$DRY" = 1 ] || [ -n "$RENDER_DIR" ]; then
    note "Trockenlauf/Render: root, Ubuntu 24.04 und Dienste werden nicht verlangt"
else
    [ "$(id -u)" = 0 ] || die "bootstrap.sh läuft als root (sudo bash $0)."
    grep -q '^ID=ubuntu' "$ETC/os-release" 2>/dev/null && grep -q '^VERSION_ID="24.04"' "$ETC/os-release" \
        || die "Ubuntu 24.04 erwartet ($ETC/os-release)."
    for c in nginx mysql systemctl perl curl; do command -v "$c" >/dev/null 2>&1 || die "$c fehlt auf dem Server."; done
    ok "root, Ubuntu 24.04, nginx, mysql, systemctl, perl, curl"
fi
[ -f "$APP/composer.json" ] && ok "Code unter $APP" || note "Code unter $APP fehlt noch (rsync aus 4.2 --deploy); Vorlagen kommen aus $DEPLOY_DIR"

step "Nutzer und Ordner"
if [ -n "$RENDER_DIR" ]; then note "Render: übersprungen"
elif id "$RUN_USER" >/dev/null 2>&1; then ok "Nutzer $RUN_USER vorhanden"
else run useradd --system --create-home --home-dir "$STATE_DIR" --shell /usr/sbin/nologin "$RUN_USER"; fi
if [ -z "$RENDER_DIR" ]; then
    for d in "$APP" "$APP/web/sites/default/files" "$PRIVATE" "$STATE_DIR"; do
        if [ -d "$d" ]; then ok "$d vorhanden"; else run mkdir -p "$d"; fi
    done
    run chown "$RUN_USER:$RUN_USER" "$APP/web/sites/default/files" "$PRIVATE" "$STATE_DIR"
    # drush startet über „env php“ — dieser Ordner steht für drush und Cron vorn im PATH (php → php8.4).
    run mkdir -p "$STATE_DIR/bin"
    run ln -sfn "$PHP_BIN" "$STATE_DIR/bin/php"
    run chmod 750 "$PRIVATE"
fi

step "env-Datei $ENV_FILE"
ENV_SRC="$ENV_FILE"
if [ ! -f "$ENV_FILE" ]; then
    if [ "$DRY" = 1 ] || [ -n "$RENDER_DIR" ]; then
        note "$ENV_FILE fehlt — echter Lauf legt sie aus deploy/.env.example an und bricht ab; hier gelten die Beispielwerte"
        ENV_SRC="$DEPLOY_DIR/.env.example"
    else
        mkdir -p "$(dirname "$ENV_FILE")"
        (umask 077; cp "$DEPLOY_DIR/.env.example" "$ENV_FILE")
        # Zufallswerte, die niemand kennen muss, setzt bootstrap selbst (auf dem Server erzeugt, nie ausgegeben).
        # Werte über die Umgebung an perl (nie in den Argumenten — die sieht ps auf dem Server).
        TW_DBPW="$(openssl rand -hex 24)" TW_SALT="$(openssl rand -hex 32)" perl -pi -e \
            's/^DB_PASSWORD=CHANGE_ME$/DB_PASSWORD=$ENV{TW_DBPW}/; s/^DRUPAL_HASH_SALT=CHANGE_ME$/DRUPAL_HASH_SALT=$ENV{TW_SALT}/' "$ENV_FILE"
        chown "root:$RUN_USER" "$ENV_FILE"; chmod 640 "$ENV_FILE"
        say "   DB_PASSWORD und DRUPAL_HASH_SALT: Zufallswerte gesetzt (nicht gezeigt)."
        say "   Noch zu setzen: $(grep -E '^[A-Z_]+=(CHANGE_ME|.CHANGE_ME.)?$' "$ENV_FILE" | cut -d= -f1 | tr '\n' ' ')"
        die "$ENV_FILE neu angelegt (aus deploy/.env.example, Modus 640, root:$RUN_USER). Die genannten Werte setzen, dann erneut."
    fi
fi
if env_check; then ok "env-Datei vollständig (nur Namen geprüft, Werte nie gezeigt)"
elif [ "$DRY" = 1 ] || [ -n "$RENDER_DIR" ]; then note "env-Datei unvollständig — ein echter Lauf bricht hier ab"
else die "env-Datei unvollständig: $ENV_FILE — Namen siehe oben."; fi
if [ "$DRY" != 1 ] && [ -z "$RENDER_DIR" ] && [ -n "$(find "$ENV_FILE" -perm -004 2>/dev/null)" ]; then
    chmod 640 "$ENV_FILE"; note "$ENV_FILE war für alle lesbar — jetzt 640"
fi
DOMAIN=$(env_get DOMAIN)
DB_NAME=$(env_get DB_NAME); DB_USER=$(env_get DB_USER)
DB_HOST=$(env_get DB_HOST); DB_HOST=${DB_HOST:-127.0.0.1}
DB_PORT=$(env_get DB_PORT); DB_PORT=${DB_PORT:-3306}
CERT_STAGING=$(env_get CERT_STAGING); CERT_STAGING=${CERT_STAGING:-on}

render_all "$TMPD"
if [ -n "$RENDER_DIR" ]; then
    mkdir -p "$RENDER_DIR"; cp -p "$TMPD"/* "$RENDER_DIR"/
    say ""; say "FERTIG — Dateien geschrieben nach $RENDER_DIR ($(ls "$RENDER_DIR" | wc -l | tr -d ' ') Dateien; mytannwald.conf und .htpasswd enthalten Werte — danach löschen)."
    exit 0
fi

step "PHP 8.4 neben 8.3 (ondrej/php)"
missing=""
for p in $PHP_PKGS; do pkg_installed "$p" || missing="$missing $p"; done
if [ -z "$missing" ]; then ok "Pakete vollständig: $PHP_PKGS"
else
    if [ "$DRY" != 1 ]; then
        ls "$ETC/apt/sources.list.d" 2>/dev/null | grep -q ondrej || die "ondrej/php-PPA fehlt — PHP 8.4 nicht installierbar (Master fragen)."
    fi
    alt_before=$(alt_links)
    say "   Alternativen vorher: $alt_before"
    run apt-get update -q
    run env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ${missing# }
    alt_after=$(alt_links)
    if [ "$DRY" = 1 ]; then say "   [dry] prüfe $BIN/php, phar, phar.phar — bei Änderung update-alternatives --set auf den vorherigen Pfad"
    elif [ "$alt_after" != "$alt_before" ]; then
        for pair in $alt_before; do
            n=${pair%%=*}; target=${pair#*=}
            [ "$target" = - ] && continue
            [ "$(readlink -f "$BIN/$n" 2>/dev/null || echo -)" = "$target" ] && continue
            update-alternatives --set "$n" "$target" >/dev/null
            note "Alternative $n zurück auf $target (die Installation hatte sie verschoben)"
        done
        [ "$(alt_links)" = "$alt_before" ] || die "Alternativen nicht wiederhergestellt: $(alt_links) statt $alt_before"
        ok "Alternativen wie vorher: $alt_before"
    else ok "Alternativen unverändert: $alt_before"; fi
fi
if [ "$DRY" != 1 ]; then
    "$PHP_BIN" -r 'exit(function_exists("gd_info") && gd_info()["WebP Support"] && extension_loaded("intl") && extension_loaded("pdo_mysql") ? 0 : 1);' \
        || die "$PHP_BIN ohne gd/WebP, intl oder pdo_mysql."
    ok "$("$PHP_BIN" -r 'echo PHP_VERSION;') mit gd/WebP, intl, pdo_mysql"
fi

step "php-fpm-Pool mytannwald (Socket $SOCK)"
install_file "$TMPD/mytannwald.conf" "$POOL_DIR/mytannwald.conf" 600
install_file "$TMPD/mytannwald-cli.ini" "$CLI_INI_DIR/mytannwald-cli.ini" 644
# Den Pool www von PHP 8.4 hat erst unsere Installation angelegt; er läuft leer mit (RAM) — darum aus.
if [ -f "$POOL_DIR/www.conf" ]; then
    run mv "$POOL_DIR/www.conf" "$POOL_DIR/www.conf.disabled-by-tannwald"
    [ "$DRY" = 1 ] || WWW_MOVED=1
    CHANGED=1
fi
if [ "$CHANGED" = 1 ]; then
    if [ "$DRY" = 1 ]; then say "   [dry] $PHP_FPM -t, dann systemctl reload php8.4-fpm"
    else
        if ! out=$("$PHP_FPM" -t 2>&1); then
            say "$out" | tail -n 5; die "php-fpm-Test rot — Pool nicht aktiviert, vorherige Dateien zurück."
        fi
        commit
        if systemctl is-active --quiet php8.4-fpm; then svc reload php8.4-fpm; else svc enable --now php8.4-fpm; fi
        ok "php-fpm-Test grün, php8.4-fpm neu geladen"
    fi
    CHANGED=0
fi

step "MySQL: Datenbank $DB_NAME und Nutzer $DB_USER"
if [ "$DRY" = 1 ]; then
    say "   [dry] CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` (utf8mb4_general_ci), nur wenn sie fehlt"
    say "   [dry] CREATE USER IF NOT EXISTS '$DB_USER'@{$DB_ACCOUNT_HOSTS}, GRANT ALL ON \`$DB_NAME\`.* — Passwort über stdin, nie auf der Befehlszeile"
else
    exists=$($MYSQL_ROOT -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$DB_NAME'") \
        || die "mysql als root über den Socket nicht erreichbar."
    if [ "$exists" = 1 ]; then ok "Datenbank $DB_NAME vorhanden (unverändert)"
    else
        $MYSQL_ROOT -e "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
        ok "Datenbank $DB_NAME angelegt"
    fi
    printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\n' "$DB_USER" "$(env_get DB_PASSWORD)" "$DB_HOST" "$DB_PORT" > "$TMPD/my.cnf"
    chmod 600 "$TMPD/my.cnf"
    login_ok() { ${TW_MYSQL_APP:-mysql} --defaults-extra-file="$TMPD/my.cnf" -N -e 'SELECT 1' "$DB_NAME" >/dev/null 2>&1; }
    pw=$(env_get DB_PASSWORD)
    sql_users "$DB_NAME" "$DB_USER" "$pw" $DB_ACCOUNT_HOSTS | $MYSQL_ROOT
    if login_ok; then ok "Anmeldung $DB_USER@$DB_HOST:$DB_PORT mit dem Passwort aus der env-Datei"
    else
        for h in $DB_ACCOUNT_HOSTS; do printf "ALTER USER '%s'@'%s' IDENTIFIED BY '%s';\n" "$DB_USER" "$h" "$pw"; done | $MYSQL_ROOT
        login_ok || die "Anmeldung $DB_USER@$DB_HOST:$DB_PORT schlägt fehl (auch nach ALTER USER)."
        note "Passwort von $DB_USER an die env-Datei angeglichen"
    fi
    unset pw
fi

step "Mailpit (UI $MAILPIT_UI, SMTP $MAILPIT_SMTP)"
if [ -x "$MAILPIT_BIN" ]; then ok "$MAILPIT_BIN vorhanden"
else
    arch=$(dpkg --print-architecture 2>/dev/null || echo amd64)
    run curl -fsSL -o "$TMPD/mailpit.tar.gz" "https://github.com/axllent/mailpit/releases/latest/download/mailpit-linux-$arch.tar.gz"
    run tar -xzf "$TMPD/mailpit.tar.gz" -C "$TMPD" mailpit
    run cp "$TMPD/mailpit" "$MAILPIT_BIN"
    run chmod 755 "$MAILPIT_BIN"
fi
install_file "$TMPD/mailpit.service" "$UNIT_DIR/mailpit.service" 644
if [ "$CHANGED" = 1 ]; then svc daemon-reload; [ "$DRY" = 1 ] || commit; svc enable mailpit; svc restart mailpit
else svc enable --now mailpit; fi
CHANGED=0

step "Ollama nur mit bge-m3 (127.0.0.1:11434)"
if command -v "$OLLAMA" >/dev/null 2>&1; then ok "ollama vorhanden ($(command -v "$OLLAMA"))"
else run sh -c 'curl -fsSL https://ollama.com/install.sh | sh'; fi
# Pfad des Binaries erst nach der Installation sicher — Unit neu rendern.
R_OLLAMA_BIN="$(command -v "$OLLAMA" 2>/dev/null || echo /usr/local/bin/ollama)"; export R_OLLAMA_BIN
render "$DEPLOY_DIR/systemd/ollama.service" "$TMPD/ollama.service"
install_file "$TMPD/ollama.service" "$UNIT_DIR/ollama.service" 644
if [ "$CHANGED" = 1 ]; then svc daemon-reload; [ "$DRY" = 1 ] || commit; svc enable ollama; svc restart ollama
else svc enable --now ollama; fi
CHANGED=0
if [ "$DRY" = 1 ]; then say "   [dry] warte auf ollama list; ollama pull bge-m3, falls es fehlt"
else
    i=0; until OLLAMA_HOST=127.0.0.1:11434 "$OLLAMA" list >/dev/null 2>&1; do
        i=$((i + 1)); [ "$i" -le "${TW_WAIT:-60}" ] || die "Ollama antwortet nicht (systemctl status ollama)."; sleep 1
    done
    if OLLAMA_HOST=127.0.0.1:11434 "$OLLAMA" list | grep -q '^bge-m3'; then ok "bge-m3 vorhanden"
    else OLLAMA_HOST=127.0.0.1:11434 "$OLLAMA" pull bge-m3 >/dev/null && ok "bge-m3 gezogen (≈ 1.2 GB)"; fi
fi

step "Cron- und Backup-Units, Logs (Timer schaltet deploy.sh ein)"
for d in "$APP_LOG_DIR" "$BACKUP_DIR"; do
    if [ -d "$d" ]; then ok "$d vorhanden"; else run mkdir -p "$d"; fi
done
run chown "$RUN_USER:$RUN_USER" "$APP_LOG_DIR"
run chmod 700 "$BACKUP_DIR"
for k in tannwald-cron.service tannwald-cron.timer tannwald-backup.service tannwald-backup.timer; do
    install_file "$TMPD/$k" "$UNIT_DIR/$k" 644
done
install_file "$TMPD/logrotate-mytannwald" "$ETC/logrotate.d/mytannwald" 644
if [ "$CHANGED" = 1 ]; then svc daemon-reload; [ "$DRY" = 1 ] || commit; fi
CHANGED=0

step "nginx: Vhost $DOMAIN und mail.$DOMAIN"
nginx_apply() { # schreibt Snippet, .htpasswd, Vhost (Fassung $1), testet, lädt neu oder baut zurück
    local avail="$NGINX_DIR/sites-available/$DOMAIN.conf" link="$NGINX_DIR/sites-enabled/$DOMAIN.conf" out
    install_file "$TMPD/mytannwald-drupal.conf" "$NGINX_DIR/snippets/mytannwald-drupal.conf" 644
    install_file "$TMPD/mytannwald.htpasswd" "$NGINX_DIR/mytannwald.htpasswd" 640 "root:$LISTEN_OWNER"
    install_file "$TMPD/vhost-$1.conf" "$avail" 644
    if [ ! -L "$link" ]; then
        run ln -s "$avail" "$link"; [ "$DRY" = 1 ] || NEW_LINKS="$NEW_LINKS $link"; CHANGED=1
    fi
    if [ "$CHANGED" = 0 ]; then ok "nginx unverändert (Fassung $1)"; return 0; fi
    if [ "$DRY" = 1 ]; then say "   [dry] nginx -t, nur bei grün systemctl reload nginx (Fassung $1)"; CHANGED=0; return 0; fi
    if ! out=$(nginx -t 2>&1); then
        say "$out" | tail -n 5; die "nginx -t rot — nichts aktiviert, vorherige Dateien zurück (andere Sites unberührt)."
    fi
    commit; svc reload nginx; ok "nginx -t grün, reload (Fassung $1)"
}
cert="$LE/live/$DOMAIN/fullchain.pem"
if [ -f "$cert" ]; then nginx_apply tls; else nginx_apply http; note "noch kein Zertifikat — Fassung http (für certbot); weiter mit --tls"; fi

if [ "$TLS" = 1 ]; then
    want=Produktion; [ "$CERT_STAGING" = on ] && want=Staging
    have=""
    if [ -f "$cert" ]; then grep -q 'acme-staging' "$LE/renewal/$DOMAIN.conf" 2>/dev/null && have=Staging || have=Produktion; fi
    if [ "$have" = "$want" ] || [ "$have" = Produktion ]; then ok "Zertifikat vorhanden ($have)"
    else
        # Beide Namen müssen auf eine Adresse dieser Maschine zeigen, sonst scheitert die Challenge (Rate-Limit schonen).
        mine=" $(hostname -I 2>/dev/null || true) "
        for h in "$DOMAIN" "mail.$DOMAIN"; do
            ip=$(getent ahostsv4 "$h" 2>/dev/null | awk 'NR==1{print $1}' || true)
            say "   DNS $h → ${ip:-keine Adresse}"
            [ "$DRY" = 1 ] && continue
            case "$mine" in *" ${ip:-none} "*) ;; *)
                die "A-Record für $h zeigt nicht auf diese Maschine (${ip:-fehlt}) — Betreiber setzt ihn; die Fassung http bleibt aktiv." ;;
            esac
        done
        base="certonly --nginx --non-interactive --agree-tos --register-unsafely-without-email -d $DOMAIN -d mail.$DOMAIN"
        if [ "$want" = Staging ]; then
            run certbot $base --test-cert --deploy-hook "systemctl reload nginx" \
                || die "certbot (Test-CA) rot — Fassung http bleibt aktiv."
        else
            # Zuerst gegen die Test-CA (--dry-run speichert nichts, kein Rate-Limit), dann echt.
            run certbot $base --dry-run || die "certbot --dry-run gegen die Test-CA rot — kein echter Versuch; Fassung http bleibt aktiv."
            extra=""; [ "$have" = Staging ] && extra="--force-renewal"
            run certbot $base $extra --deploy-hook "systemctl reload nginx" \
                || die "certbot rot (echtes Zertifikat) — Fassung http bleibt aktiv."
        fi
        ok "Zertifikat geholt ($want, $DOMAIN und mail.$DOMAIN)"
        if [ -f "$cert" ] || [ "$DRY" = 1 ]; then nginx_apply tls; fi
    fi
fi

dur
say ""
say "FERTIG — Server vorbereitet in $(( $(date +%s) - T0 )) s$( [ "$DRY" = 1 ] && echo ' (Trockenlauf, nichts geändert)'): PHP 8.4 (Pool mytannwald, $SOCK), MySQL $DB_NAME, Mailpit, Ollama (bge-m3), Cron-Units, nginx ($( [ -f "$cert" ] && echo tls || echo http))."
say "Weiter: bash $APP/deploy/deploy.sh"
