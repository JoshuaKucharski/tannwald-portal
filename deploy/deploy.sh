#!/usr/bin/env bash
# myTannwald — Deploy auf dem Server (deploy/deploy.sh, 4.1, ADR-012). Auf 91.98.229.15 als root, nachdem der Code
# per rsync in /var/www/mytannwald liegt (4.2: tannwald-run.sh --deploy) und bootstrap.sh grün war:
#   bash /var/www/mytannwald/deploy/deploy.sh [--dry]
# Erster Lauf: composer install → site:install --existing-config → admin-Passwort aus der env-Datei → updatedb →
#   locale:check/update → drush deploy (config:import, cr) → config:status leer → Seeder --skip-summaries → Cron an
#   → Proben (Login-Seite, Mailpit 401). Weitere Läufe: dasselbe ohne Install und Seeder; ist nichts zu tun (keine
#   Updates, Konfiguration gleich, Code gleich), nur composer, Proben und „nichts zu tun“.
# Hält beim ersten Fehler; der Cron bleibt dann aus (ein halbes Install bekommt keinen Cron); ein halbes Install
# meldet das Skript mit dem Aufräum-Befehl, statt darüber zu installieren. Zeigt nie Werte aus der env-Datei.
# --dry druckt jeden Schritt (Plan eines ersten Laufs), ändert nichts.
# Ende: „FERTIG — … in <n> s“ mit Zählungen; Abbruch: „ABBRUCH (…)“, Exit 1.
set -euo pipefail
umask 022
SELF=deploy.sh
STEP_TOTAL=8
DEPLOY_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
. "$DEPLOY_DIR/lib.sh"

DRY=0
for arg in "$@"; do
    case "$arg" in
        --dry) DRY=1 ;;
        -h|--help) sed -n '2,13p' "$0"; exit 0 ;;
        *) say "Unbekannte Angabe: $arg (erlaubt: --dry)"; exit 1 ;;
    esac
done

# Pfade und Namen; TW_* nur für Prüfstand und Generalprobe.
APP="${TW_APP:-/var/www/mytannwald}"
PRIVATE="${TW_PRIVATE:-/var/www/mytannwald-private}"
ETC="${TW_ETC:-/etc}"
ENV_FILE="${TW_ENV_FILE:-$ETC/mytannwald/env}"
RUN_USER="${TW_RUN_USER:-tannwald}"
STATE_DIR="${TW_STATE_DIR:-/var/lib/tannwald}"
PHP_BIN="${TW_PHP:-/usr/bin/php8.4}"
COMPOSER="${TW_COMPOSER:-$(command -v composer 2>/dev/null || echo /usr/local/bin/composer)}"
CLI_INI_DIR="${TW_CLI_INI_DIR:-$ETC/mytannwald/php}"
LE="${TW_LE:-$ETC/letsencrypt}"
WAIT_CRON="${TW_WAIT:-600}"
FINGERPRINT_FILE="$STATE_DIR/deploy.fingerprint"
PHP_DIR="$STATE_DIR/bin"

# Als App-Nutzer ausführen (Server: sudo -u tannwald; Generalprobe: TW_RUN_USER=- → aktueller Nutzer).
as_app() {
    if [ "$RUN_USER" = "-" ]; then "$@"; else sudo -u "$RUN_USER" -H "$@"; fi
}
# drush mit der Umgebung aus der env-Datei: wird erst im Kind-Prozess eingelesen (set -a), nie auf der Befehlszeile.
# vendor/bin/drush startet sich und seine Unterprozesse (drush deploy → updatedb …) über „env php“; $PHP_DIR/php
# zeigt auf php8.4 und steht vorn im PATH — /usr/bin/php der Maschine bleibt 8.3.
drush() {
    if [ "$DRY" = 1 ]; then say "   [dry] drush $(printf "%s " "$@" | sed "s/--account-pass=[^ ]*/--account-pass=<wegwerf>/")"; return 0; fi
    as_app env TANNWALD_DEPLOY=1 ${DRUSH_ENV:-} PRIVATE_PATH="$PRIVATE" PHP_INI_SCAN_DIR=":$CLI_INI_DIR" PATH="$PHP_DIR:$PATH" \
        bash -c 'set -a; . "$0"; set +a; cd "$1"; shift; exec vendor/bin/drush "$@"' "$ENV_FILE" "$APP" "$@"
}
# drush leise: Ausgabe in eine Datei, bei Fehler die letzten Zeilen (ohne Zeilen mit „password“).
drush_quiet() {
    local log="$TMPD/drush.log"
    if [ "$DRY" = 1 ]; then drush "$@"; return 0; fi
    if drush "$@" > "$log" 2>&1; then return 0; fi
    grep -iv 'password' "$log" | tail -n 15; return 1
}
fingerprint() { # Prüfsumme über Code und Konfiguration (nur vorhandene Pfade)
    local p paths=""
    for p in composer.lock config fixtures web/modules/custom web/themes/custom web/sites/default/settings.php; do
        [ -e "$APP/$p" ] && paths="$paths $p"
    done
    (cd "$APP" && find $paths -type f -not -path '*/node_modules/*' -exec cksum {} + | LC_ALL=C sort -k3 | cksum | awk '{print $1}')
}

TMPD="$(mktemp -d "${TMPDIR:-/tmp}/tannwald-deploy.XXXXXX")"
chmod 700 "$TMPD"
TIMER_STOPPED=0
WAS_INSTALLED=0
on_exit() {
    rc=$?
    if [ "$rc" -ne 0 ] && [ "$TIMER_STOPPED" = 1 ]; then
        if [ "$WAS_INSTALLED" = 1 ]; then
            say "   Cron wieder an (die Site war vorher installiert)"; systemctl start tannwald-cron.timer || true
        else
            say "   Cron bleibt aus (Erstinstallation nicht fertig)"
        fi
    fi
    rm -rf "$TMPD"
}
trap on_exit EXIT

say "myTannwald deploy.sh — $(date '+%Y-%m-%d %H:%M')$( [ "$DRY" = 1 ] && echo ' (Trockenlauf)')"

step "Vorbedingungen"
ENV_SRC="$ENV_FILE"
if [ ! -f "$ENV_FILE" ]; then
    [ "$DRY" = 1 ] || die "$ENV_FILE fehlt — zuerst bootstrap.sh (legt sie aus deploy/.env.example an), Werte setzen."
    note "$ENV_FILE fehlt — Trockenlauf rechnet mit deploy/.env.example"; ENV_SRC="$DEPLOY_DIR/.env.example"
fi
if env_check; then ok "env-Datei vollständig (nur Namen geprüft)"
elif [ "$DRY" = 1 ]; then note "env-Datei unvollständig — ein echter Lauf bricht hier ab"
else die "env-Datei unvollständig: $ENV_FILE — Namen siehe oben."; fi
DOMAIN=$(env_get DOMAIN); SITE_AUTH=$(env_get SITE_AUTH)
if [ "$DRY" != 1 ]; then
    [ "$RUN_USER" = "-" ] || [ "$(id -u)" = 0 ] || die "deploy.sh läuft als root (sudo bash $0)."
    [ -x "$PHP_BIN" ] || die "$PHP_BIN fehlt — zuerst bootstrap.sh (PHP 8.4)."
    [ -f "$COMPOSER" ] || die "composer nicht gefunden ($COMPOSER)."
    [ -f "$APP/composer.json" ] && [ -f "$APP/composer.lock" ] || die "Code fehlt unter $APP (rsync aus 4.2)."
    mkdir -p "$PHP_DIR" && ln -sfn "$PHP_BIN" "$PHP_DIR/php"
    [ "$(PATH="$PHP_DIR:$PATH" php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" = 8.4 ] || die "$PHP_DIR/php ist nicht PHP 8.4."
    [ -n "$(env_get DASHSCOPE_API_KEY_EU)" ] && ok "DashScope-Schlüssel gesetzt" || note "DASHSCOPE_API_KEY_EU leer — Chat über DashScope nicht nutzbar (Install und Seeder laufen)"
    ok "$("$PHP_BIN" -r 'echo "PHP ", PHP_VERSION;'), Code unter $APP, Domain $DOMAIN"
fi

step "Abhängigkeiten (composer install --no-dev, als $RUN_USER)"
if [ "$DRY" = 1 ]; then say "   [dry] $PHP_BIN $COMPOSER install --no-dev --optimize-autoloader --no-interaction (als $RUN_USER)"
else
    as_app env COMPOSER_NO_INTERACTION=1 PHP_INI_SCAN_DIR=":$CLI_INI_DIR" PATH="$PHP_DIR:$PATH" "$PHP_BIN" "$COMPOSER" --working-dir="$APP" \
        install --no-dev --optimize-autoloader --no-progress > "$TMPD/composer.log" 2>&1 \
        || { tail -n 15 "$TMPD/composer.log"; die "composer install rot."; }
    ok "$(grep -E 'Nothing to install|Installing|Generating optimized' "$TMPD/composer.log" | tail -n 1 | sed 's/^ *//')"
fi

step "Cron anhalten und Zustand messen"
if [ "$DRY" = 1 ]; then say "   [dry] systemctl stop tannwald-cron.timer; laufenden Cron abwarten"
elif systemctl is-active --quiet tannwald-cron.timer 2>/dev/null; then
    systemctl stop tannwald-cron.timer; TIMER_STOPPED=1
    i=0; while systemctl is-active --quiet tannwald-cron.service 2>/dev/null; do
        i=$((i + 2)); [ "$i" -le "$WAIT_CRON" ] || die "tannwald-cron.service läuft seit über $WAIT_CRON s."; sleep 2
    done
    ok "Cron angehalten"
else ok "Cron war aus"; fi
installed=0
if [ "$DRY" != 1 ]; then
    [ "$(drush status --field=bootstrap 2>/dev/null | tr -d '[:space:]')" = Successful ] && installed=1
    if [ "$installed" = 0 ]; then
        tables=$(drush sql:query "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()" 2>/dev/null | tr -d '[:space:]') \
            || die "Datenbank nicht erreichbar (DB_* in der env-Datei, MySQL-Nutzer aus bootstrap.sh)."
        case "$tables" in ''|*[!0-9]*) die "Tabellen-Zählung lieferte keine Zahl — drush/PHP prüfen.";; esac
        if [ "${tables:-0}" != 0 ]; then
            CLEANUP_HINT="halbe Installation entfernen: mysql -e 'DROP DATABASE \`$(env_get DB_NAME)\`' && bash $DEPLOY_DIR/bootstrap.sh && bash $0"
            die "Datenbank hat $tables Tabellen, aber Drupal startet nicht — halbe Installation? Nichts überschrieben."
        fi
    fi
fi
WAS_INSTALLED=$installed
[ "$installed" = 1 ] && ok "Drupal installiert" || ok "Datenbank leer — Erstinstallation"

step "Erstinstallation (site:install --existing-config)"
if [ "$installed" = 1 ]; then ok "übersprungen (installiert)"
else
    CLEANUP_HINT="halbe Installation entfernen: mysql -e 'DROP DATABASE \`$(env_get DB_NAME)\`' && bash $DEPLOY_DIR/bootstrap.sh && bash $0"
    # Wegwerf-Passwort für den Installer; das echte kommt danach aus der env-Datei (nie auf der Befehlszeile).
    drush_quiet site:install --existing-config -y --account-name=admin --account-pass="$(openssl rand -hex 16)" \
        || die "site:install rot."
    drush_quiet php:eval '$u = \Drupal\user\Entity\User::load(1); $u->setPassword((string) getenv("ADMIN_PASSWORD")); $u->save();' \
        || die "admin-Passwort nicht gesetzt."
    ok "installiert; admin-Passwort aus der env-Datei gesetzt (nicht ausgegeben)"
fi

step "Updates und Konfiguration (updatedb → locale → drush deploy)"
fp_now=$( [ "$DRY" = 1 ] && echo dry || fingerprint )
fp_old=$(cat "$FINGERPRINT_FILE" 2>/dev/null || true)
pending=""; cfg=""
if [ "$DRY" != 1 ] && [ "$installed" = 1 ]; then
    drush updatedb:status 2>&1 | grep -q 'No database updates required' || pending="Updates"
    cfg=$(drush config:status --format=list 2>/dev/null | tr '\n' ' ' | sed 's/ *$//')
fi
if [ "$installed" = 1 ] && [ -z "$pending" ] && [ -z "$cfg" ] && [ "$fp_now" = "$fp_old" ]; then
    NOTHING=1; ok "nichts zu tun (keine Updates, Konfiguration gleich, Code gleich)"
else
    NOTHING=0
    [ "$installed" = 1 ] && say "   Grund: ${pending:-keine Updates}; Konfiguration: ${cfg:-gleich}; Code: $( [ "$fp_now" = "$fp_old" ] && echo gleich || echo geändert)"
    drush_quiet updatedb -y || die "updatedb rot."
    drush_quiet locale:check || die "locale:check rot."
    drush_quiet locale:update || die "locale:update rot."
    drush_quiet deploy -y || die "drush deploy (config:import, cr) rot."
    if [ "$DRY" != 1 ]; then
        cfg=$(drush config:status --format=list 2>/dev/null | tr '\n' ' ' | sed 's/ *$//')
        [ -z "$cfg" ] || die "config:status nicht leer nach drush deploy: $cfg"
        if [ "$RUN_USER" != "-" ] && [ "$fp_now" != "$fp_old" ]; then systemctl reload php8.4-fpm; fi
    fi
    ok "updatedb, locale:check/update, config:import, cr — config:status leer"
fi

step "Demo-Daten (Seeder tannwald:demo-install --skip-summaries)"
docs=0
if [ "$DRY" != 1 ]; then docs=$(drush sql:query "SELECT COUNT(*) FROM node_field_data WHERE type = 'dokument'" 2>/dev/null | tr -d '[:space:]'); fi
if [ "${docs:-0}" != 0 ]; then ok "vorhanden ($docs Dokument-Fassungen) — übersprungen; Neuaufbau nur mit tannwald-run.sh --reset"
else
    seed_t0=$(date +%s)
    if [ "$DRY" = 1 ]; then drush tannwald:demo-install --skip-summaries
    else
        # TANNWALD_DEMO=1: ausdrückliches Opt-in des Seeders für den Demo-Server (nur hier gesetzt).
        DRUSH_ENV="TANNWALD_DEMO=1" drush tannwald:demo-install --skip-summaries > "$TMPD/seed.log" 2>&1 || { tail -n 20 "$TMPD/seed.log"; die "Seeder rot."; }
        grep -E '^\s*(firmen|maschinen|users|dokumente|revisionen|bulletins|chunks|lesebestaetigungen)\b|Done in|Changes:' "$TMPD/seed.log" | sed 's/^/   /'
    fi
    ok "Seeder in $(( $(date +%s) - seed_t0 )) s (Embeddings über Ollama bge-m3)"
fi

step "Cron an und Proben"
if [ "$DRY" = 1 ]; then say "   [dry] systemctl enable --now tannwald-cron.timer"
elif [ "$RUN_USER" != "-" ]; then systemctl enable --now tannwald-cron.timer >/dev/null 2>&1; TIMER_STOPPED=0; ok "tannwald-cron.timer an"
else TIMER_STOPPED=0; note "Generalprobe: kein systemd — Cron nicht geschaltet"; fi
scheme=http; [ -f "$LE/live/$DOMAIN/fullchain.pem" ] && scheme=https
url="${TW_PROBE_URL:-$scheme://$DOMAIN}"
host=${url#*://}; host=${host%%/*}; hostname=${host%%:*}; port=${host##*:}
[ "$port" = "$host" ] && { [ "$scheme" = https ] && port=443 || port=80; }
probe() { # probe <pfad> <host> → HTTP-Code; Antwort in $TMPD/probe.html
    curl -sk --max-time 20 --resolve "$2:$port:127.0.0.1" -o "$TMPD/probe.html" -w '%{http_code}' "${url%%://*}://$2:$port$1" || echo 000
}
if [ "$DRY" = 1 ]; then say "   [dry] curl $url/user/login → 200 mit „myTannwald“ (SITE_AUTH=on: 401); curl mail.$hostname → 401"
else
    code=$(probe /user/login "$hostname")
    if [ "$SITE_AUTH" = on ]; then
        [ "$code" = 401 ] || die "Login-Seite: HTTP $code statt 401 (SITE_AUTH=on)."
        ok "Login-Seite 401 hinter Basic-Auth (SITE_AUTH=on) — Inhalt nur mit Passwort"
    else
        [ "$code" = 200 ] && grep -q 'myTannwald' "$TMPD/probe.html" || die "Login-Seite: HTTP $code oder ohne „myTannwald“ ($url/user/login)."
        ok "Login-Seite 200 mit „myTannwald“ ($url/user/login)"
    fi
    code=$(probe / "mail.$hostname")
    [ "$code" = 401 ] && ok "Mailpit (mail.$hostname) 401 ohne Passwort" || die "Mailpit: HTTP $code statt 401."
    mkdir -p "$STATE_DIR" && printf '%s\n' "$fp_now" > "$FINGERPRINT_FILE"
fi
counts=""
if [ "$DRY" != 1 ]; then
    counts=$(drush sql:query "SELECT CONCAT((SELECT COUNT(*) FROM node_field_data WHERE type='maschine' AND default_langcode=1), ' Maschinen, ', (SELECT COUNT(*) FROM node_field_data WHERE type='dokument' AND default_langcode=1), ' Dokumente, ', (SELECT COUNT(*) FROM tannwald_chunk), ' Chunks, ', (SELECT COUNT(*) FROM users_field_data WHERE uid > 1), ' Konten')" 2>/dev/null | tr -d '\r' || true)
fi

dur
say ""
if [ "${NOTHING:-0}" = 1 ]; then
    say "FERTIG — nichts zu tun, Proben grün, in $(( $(date +%s) - T0 )) s. ${counts}"
else
    say "FERTIG — $( [ "$installed" = 1 ] && echo aktualisiert || echo 'erstinstalliert') in $(( $(date +%s) - T0 )) s$( [ "$DRY" = 1 ] && echo ' (Trockenlauf, nichts geändert)'). ${counts}"
fi
