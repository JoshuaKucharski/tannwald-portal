#!/usr/bin/env bash
# myTannwald — Generalprobe ohne Server auf dem Mac (deploy/generalprobe-mac.sh, 4.1, ADR-012).
# Fährt die Server-Vorlagen mit Werkzeugen, die auf dem Mac schon da sind: Homebrew-nginx und -php-fpm 8.4 als
# EIGENE Instanzen (Prefix im Wegwerf-Ordner, nur 127.0.0.1:8081/8444), MySQL 8.0.46 als Wegwerf-Container
# (127.0.0.1:33080, konfiguriert wie der Server), Ollama des Hosts (bge-m3). Valet, DDEV und die geteilte
# DDEV-Datenbank bleiben unberührt; nichts wird installiert.
# Ablauf: Kopie des Commits (git archive HEAD + deploy/ und settings.php aus dem Arbeitsbaum) → Testwerte
#   (openssl rand) → MySQL-Container, DB und Nutzer (sql_users aus lib.sh) → bootstrap.sh --render → php-fpm, nginx
#   (TLS-Fassung mit Wegwerf-Zertifikat; HTTP-Fassung nur nginx -t) → deploy.sh (1. Lauf) → Proben → Cron-Zeilen
#   aus der gerenderten Unit → deploy.sh (2. Lauf) → Abbau.
# Aufruf: bash deploy/generalprobe-mac.sh [--keep] · Abbau allein: bash deploy/generalprobe-mac.sh --down
# Ende: „FERTIG (Generalprobe) …“ und „aufgeräumt“; Abbruch: „ABBRUCH (…)“ (räumt trotzdem auf, ausser --keep).
set -euo pipefail
SELF=generalprobe-mac.sh
STEP_TOTAL=9
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
# shellcheck source=lib.sh
. "$HERE/lib.sh"
DRY=0

BASE="${TW_PROBE_DIR:-${TMPDIR:-/tmp}}"; BASE="${BASE%/}/tannwald-prod-probe"
NAME=tannwald-prod-probe-mysql
HTTP_PORT=8081; HTTPS_PORT=8444; DB_PORT=33080; UI_PORT=18025
NGINX="${TW_NGINX:-/opt/homebrew/bin/nginx}"
FPM="${TW_FPM:-/opt/homebrew/sbin/php-fpm}"
PHP="${TW_PHP_HOST:-/opt/homebrew/bin/php}"
COMPOSER="$(command -v composer || true)"
URL="https://localhost:$HTTPS_PORT"
KEEP=0; ONLY_DOWN=0
for arg in "$@"; do case "$arg" in --keep) KEEP=1 ;; --down) ONLY_DOWN=1 ;; *) say "Unbekannt: $arg"; exit 1 ;; esac; done

down() {
    case "$BASE" in */tannwald-prod-probe) ;; *) say "BASE unerwartet: $BASE"; return 1 ;; esac
    [ -f "$BASE/nginx/nginx.pid" ] && "$NGINX" -p "$BASE/nginx" -c "$BASE/nginx/nginx.conf" -s quit 2>/dev/null || true
    [ -f "$BASE/fpm/php-fpm.pid" ] && kill "$(cat "$BASE/fpm/php-fpm.pid")" 2>/dev/null || true
    [ -f "$BASE/mailui.pid" ] && kill "$(cat "$BASE/mailui.pid")" 2>/dev/null || true
    docker rm -fv "$NAME" >/dev/null 2>&1 || true
    sleep 2
    # Drupal härtet sites/default (555/444) — vor dem Löschen wieder schreibbar machen.
    chmod -R u+w "$BASE" 2>/dev/null || true
    rm -rf "$BASE"
    left=""
    for p in $HTTP_PORT $HTTPS_PORT $DB_PORT $UI_PORT; do lsof -nP -iTCP:$p -sTCP:LISTEN >/dev/null 2>&1 && left="$left $p"; done
    docker ps -a --format '{{.Names}}' | grep -q "^$NAME\$" && left="$left container"
    [ -e "$BASE" ] && left="$left ordner"
    if [ -z "$left" ]; then say "aufgeräumt: keine Prozesse auf $HTTP_PORT/$HTTPS_PORT/$DB_PORT/$UI_PORT, kein Container, kein Ordner"
    else say "NICHT AUFGERÄUMT:$left"; return 1; fi
}
if [ "$ONLY_DOWN" = 1 ]; then down; exit $?; fi
finish() { rc=$?; if [ "$KEEP" = 1 ]; then say "(--keep: läuft weiter unter $BASE; Abbau: bash $0 --down)"; else down || true; fi; exit $rc; }
trap finish EXIT

# Umgebung für bootstrap.sh/deploy.sh (Server-Pfade → Wegwerf-Ordner).
export TW_APP="$BASE/app" TW_PRIVATE="$BASE/private" TW_ETC="$BASE/etc" TW_ENV_FILE="$BASE/etc/mytannwald/env"
export TW_STATE_DIR="$BASE/state" TW_SOCK="$BASE/fpm/mytannwald.sock" TW_PHP="$PHP" TW_COMPOSER="$COMPOSER"
export TW_MAILPIT_BIN="$BASE/bin/mailpit" TW_MAILPIT_UI="127.0.0.1:$UI_PORT" TW_LE="$BASE/le" TW_LOG_DIR="$BASE/nginx/log"
export TW_LISTEN_HTTP="listen 127.0.0.1:$HTTP_PORT;" TW_LISTEN_HTTPS="listen 127.0.0.1:$HTTPS_PORT ssl;"
export TW_CLI_INI_DIR="$BASE/etc/mytannwald/php" TW_PROBE_URL="$URL"
TMPREAL="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
export TW_OPEN_BASEDIR="$BASE/app:$BASE/private:/tmp:/private/tmp:$TMPREAL"

say "myTannwald Generalprobe (Mac, ohne Server) — $(date '+%Y-%m-%d %H:%M') — Ordner $BASE"

step "Vorbedingungen"
for c in docker git openssl perl curl python3 "$NGINX" "$FPM" "$PHP"; do command -v "$c" >/dev/null 2>&1 || die "$c fehlt."; done
[ -n "$COMPOSER" ] || die "composer fehlt."
"$PHP" -r 'exit(PHP_MAJOR_VERSION == 8 && PHP_MINOR_VERSION == 4 ? 0 : 1);' || die "$PHP ist nicht PHP 8.4."
curl -s --max-time 5 http://127.0.0.1:11434/api/tags | grep -q '"bge-m3' || die "Ollama des Hosts ohne bge-m3."
for p in $HTTP_PORT $HTTPS_PORT $DB_PORT $UI_PORT; do ! lsof -nP -iTCP:$p -sTCP:LISTEN >/dev/null 2>&1 || die "Port $p belegt."; done
[ ! -e "$BASE" ] || die "$BASE existiert — zuerst: bash $0 --down"
ok "docker, nginx $("$NGINX" -v 2>&1 | sed 's#.*/##'), php-fpm/$("$PHP" -r 'echo PHP_VERSION;'), composer, Ollama mit bge-m3, Ports frei"

step "Kopie des Codes (Commit $(git -C "$REPO" rev-parse --short HEAD) + deploy/ und settings.php aus dem Arbeitsbaum)"
mkdir -p "$BASE"/{app,private,state,bin,mail,mailui,secret,fpm/pool.d,nginx/sites-enabled,nginx/log,nginx/tmp,le/live/localhost,etc/nginx/snippets,etc/mytannwald/php}
chmod 700 "$BASE/secret"
git -C "$REPO" archive HEAD | tar -x -C "$BASE/app"
rm -rf "$BASE/app/deploy"; cp -R "$REPO/deploy" "$BASE/app/deploy"; rm -f "$BASE/app/deploy/.env" "$BASE/app/deploy/.env.probe"
cp "$REPO/web/sites/default/settings.php" "$BASE/app/web/sites/default/settings.php"
ok "$(find "$BASE/app" -type f | wc -l | tr -d ' ') Dateien (rsync-Ersatz; fremde, nicht committete Arbeit bleibt draussen)"

step "Testwerte und MySQL 8.0.46 (Wegwerf-Container $NAME, 127.0.0.1:$DB_PORT)"
umask 077
openssl rand -hex 12 > "$BASE/secret/basic"; openssl rand -hex 16 > "$BASE/secret/admin"
printf 'MYSQL_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 24)" > "$BASE/secret/mysql.env"
dbpw=$(openssl rand -hex 24)
{
    echo "# Generalprobe $(date '+%Y-%m-%d %H:%M') — Testwerte (openssl rand), nie echte"
    echo "DOMAIN=localhost"; echo "CERT_STAGING=on"
    echo "DB_HOST=127.0.0.1"; echo "DB_PORT=$DB_PORT"; echo "DB_NAME=mytannwald"; echo "DB_USER=mytannwald"; echo "DB_PASSWORD=$dbpw"
    echo "DRUPAL_HASH_SALT=$(openssl rand -hex 32)"; echo "TRUSTED_HOST_PATTERN='^localhost\$'"
    echo "DASHSCOPE_API_KEY_EU="; echo "DASHSCOPE_ENDPOINT_EU="; echo "OLLAMA_HOST=http://127.0.0.1:11434"
    # macOS-crypt kennt $6$ nicht — die Probe nimmt apr1 (nginx eigen); der Server nimmt openssl passwd -6.
    echo "BASIC_AUTH_USER=probe"; echo "BASIC_AUTH_HASH='$(openssl passwd -apr1 -stdin < "$BASE/secret/basic")'"
    echo "SITE_AUTH=off"; echo "ADMIN_PASSWORD=$(cat "$BASE/secret/admin")"
} > "$TW_ENV_FILE"
umask 022
docker run -d --name "$NAME" --label tannwald-prod-probe=1 -p "127.0.0.1:$DB_PORT:3306" --env-file "$BASE/secret/mysql.env" \
    mysql:8.0.46 --character-set-server=utf8mb4 --innodb-ft-min-token-size=3 \
    --sql-mode=ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION >/dev/null
myroot() { docker exec -i "$NAME" sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -h127.0.0.1 "$@" 2>/dev/null' _ "$@"; }
i=0; until myroot -N -e 'SELECT 1' >/dev/null 2>&1; do i=$((i + 2)); [ "$i" -le 180 ] || die "MySQL startet nicht."; sleep 2; done
myroot -e "CREATE DATABASE IF NOT EXISTS \`mytannwald\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"
sql_users mytannwald mytannwald "$dbpw" "%" | myroot
unset dbpw
ok "$(myroot -N -e "SELECT CONCAT('MySQL ', VERSION(), ', ', @@character_set_server, ', ft_min_token ', @@innodb_ft_min_token_size)")"
say "   sql_mode: $(myroot -N -e 'SELECT @@sql_mode')"

step "bootstrap.sh --render (Pool, CLI-ini, nginx, Units, .htpasswd) und Wegwerf-Zertifikat"
TW_RUN_USER="$(id -un)" TW_RUN_GROUP="$(id -gn)" TW_LISTEN_OWNER="$(id -un)" TW_LISTEN_GROUP="$(id -gn)" bash "$TW_APP/deploy/bootstrap.sh" --render="$BASE/rendered" | tail -n 1
cp "$BASE/rendered/mytannwald.conf" "$BASE/fpm/pool.d/"
cp "$BASE/rendered/mytannwald-cli.ini" "$TW_CLI_INI_DIR/"
cp "$BASE/rendered/mytannwald-drupal.conf" "$BASE/etc/nginx/snippets/"
cp "$BASE/rendered/mytannwald.htpasswd" "$BASE/etc/nginx/"
cp "$BASE/rendered/vhost-tls.conf" "$BASE/nginx/sites-enabled/localhost.conf"
cat > "$BASE/bin/mailpit" <<EOF
#!/bin/sh
# Attrappe für „mailpit sendmail“ (Generalprobe): legt die Mail als Datei ab und merkt sich die Argumente.
[ "\$1" = sendmail ] || exit 64
n=\$(date +%s)-\$\$
cat > "$BASE/mail/\$n.eml"; echo "\$*" > "$BASE/mail/\$n.args"
EOF
chmod 755 "$BASE/bin/mailpit"
printf '<!doctype html><title>Mailpit</title><p>Mailpit (Attrappe der Generalprobe)</p>\n' > "$BASE/mailui/index.html"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext "subjectAltName=DNS:localhost,DNS:mail.localhost" \
    -keyout "$BASE/le/live/localhost/privkey.pem" -out "$BASE/le/live/localhost/fullchain.pem" 2>/dev/null
openssl dhparam -dsaparam -out "$BASE/le/ssl-dhparams.pem" 2048 2>/dev/null
# Inhalt wie certbots options-ssl-nginx.conf (Abschnitt TLS-Protokolle, Sitzungen).
printf 'ssl_session_cache shared:le_nginx_SSL:10m;\nssl_session_timeout 1440m;\nssl_session_tickets off;\nssl_protocols TLSv1.2 TLSv1.3;\nssl_prefer_server_ciphers off;\n' > "$BASE/le/options-ssl-nginx.conf"
ok "$(ls "$BASE/rendered" | wc -l | tr -d ' ') Dateien gerendert, Zertifikat für localhost und mail.localhost"

step "php-fpm 8.4 (Pool mytannwald) und nginx (TLS-Fassung) als eigene Instanzen"
cat > "$BASE/fpm/php-fpm.conf" <<EOF
[global]
pid = $BASE/fpm/php-fpm.pid
error_log = $BASE/fpm/php-fpm.log
daemonize = yes
include = $BASE/fpm/pool.d/*.conf
EOF
"$FPM" -t -y "$BASE/fpm/php-fpm.conf" 2>&1 | grep -q 'test is successful' || { "$FPM" -t -y "$BASE/fpm/php-fpm.conf" || true; die "php-fpm -t rot."; }
"$FPM" -y "$BASE/fpm/php-fpm.conf"
cp /opt/homebrew/etc/nginx/fastcgi_params /opt/homebrew/etc/nginx/mime.types "$BASE/nginx/"
nginx_conf() { # nginx_conf <datei> <sites-glob>
    cat > "$1" <<EOF
worker_processes 1;
pid $BASE/nginx/nginx.pid;
error_log $BASE/nginx/log/error.log;
events { worker_connections 256; }
http {
    include mime.types;
    default_type application/octet-stream;
    access_log $BASE/nginx/log/access.log;
    client_body_temp_path $BASE/nginx/tmp/body;
    fastcgi_temp_path $BASE/nginx/tmp/fastcgi;
    proxy_temp_path $BASE/nginx/tmp/proxy;
    uwsgi_temp_path $BASE/nginx/tmp/uwsgi;
    scgi_temp_path $BASE/nginx/tmp/scgi;
    sendfile on;
    include $2;
}
EOF
}
nginx_conf "$BASE/nginx/nginx.conf" "$BASE/nginx/sites-enabled/*"
mkdir -p "$BASE/nginx/http-only"; cp "$BASE/rendered/vhost-http.conf" "$BASE/nginx/http-only/localhost.conf"
nginx_conf "$BASE/nginx/nginx-http.conf" "$BASE/nginx/http-only/*"
"$NGINX" -t -p "$BASE/nginx" -c "$BASE/nginx/nginx-http.conf" 2>&1 | grep -q 'test is successful' || die "nginx -t rot (Fassung http)."
"$NGINX" -t -p "$BASE/nginx" -c "$BASE/nginx/nginx.conf" 2>&1 | grep -q 'test is successful' || { "$NGINX" -t -p "$BASE/nginx" -c "$BASE/nginx/nginx.conf" || true; die "nginx -t rot (Fassung tls)."; }
"$NGINX" -p "$BASE/nginx" -c "$BASE/nginx/nginx.conf"
nohup python3 -m http.server "$UI_PORT" --bind 127.0.0.1 --directory "$BASE/mailui" > "$BASE/mailui.log" 2>&1 &
echo $! > "$BASE/mailui.pid"
sleep 1
ok "php-fpm -t grün (Pool mytannwald), nginx -t grün (Fassungen http und tls), läuft: $URL, http://localhost:$HTTP_PORT"

step "deploy.sh — erster Lauf (TW_RUN_USER=-: aktueller Nutzer statt tannwald, kein systemd)"
t=$(date +%s)
TW_RUN_USER=- bash "$TW_APP/deploy/deploy.sh" 2>&1 | tee "$BASE/deploy1.log" | sed 's/^/   | /'
[ "${PIPESTATUS[0]}" = 0 ] || die "deploy.sh (1. Lauf) rot."
RUN1=$(( $(date +%s) - t )); ok "erster Lauf in $RUN1 s"

drush() { (cd "$TW_APP" && env TANNWALD_DEPLOY=1 PRIVATE_PATH="$TW_PRIVATE" PHP_INI_SCAN_DIR=":$TW_CLI_INI_DIR" PATH="$TW_STATE_DIR/bin:$PATH" bash -c 'set -a; . "$0"; set +a; exec vendor/bin/drush "$@"' "$TW_ENV_FILE" "$@"); }

step "Proben (Login, Maschinenliste, Bild, Sperren, admin, Mail, Mailpit, Cron, FULLTEXT)"
c() { curl -sk --max-time 30 "$@"; }
form_id_of() { grep -o 'name="form_build_id" value="[^"]*"' | head -n 1 | sed 's/.*value="//; s/"$//'; }
jar="$BASE/kunde.jar"
fb=$(c -c "$jar" "$URL/user/login" | form_id_of)
code=$(c -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' --data-urlencode name=kunde-a --data-urlencode pass=demo \
    --data-urlencode "form_build_id=$fb" --data-urlencode form_id=user_login_form --data-urlencode op=Anmelden \
    -H 'X-Forwarded-For: 203.0.113.9' "$URL/user/login")
[ "$code" = 303 ] || die "Login kunde-a: HTTP $code statt 303."
ok "Login kunde-a/demo → 303"
c -b "$jar" -o "$BASE/maschinen.html" -w '%{http_code}' "$URL/meine-maschinen" | grep -q 200 || die "/meine-maschinen nicht 200."
grep -q 'TB200-2021-0417' "$BASE/maschinen.html" || die "/meine-maschinen ohne TB200-2021-0417."
ok "/meine-maschinen 200 mit TB200-2021-0417"
img=$(grep -o '/sites/default/files/styles/[^" ,]*' "$BASE/maschinen.html" | head -n 1 | sed 's/&amp;/\&/g')
[ -n "$img" ] || die "keine Bildstil-URL in /meine-maschinen."
h1=$(c -b "$jar" -o /dev/null -D - "$URL$img" | tr -d '\r')
h2=$(c -b "$jar" -o /dev/null -D - "$URL$img" | tr -d '\r')
echo "$h1" | grep -q '^HTTP/[0-9.]* 200' && echo "$h1" | grep -qi '^content-type: image/webp' || die "Bild ($img) nicht 200 image/webp."
ok "Bild 200 image/webp: ${img%%\?*}"
say "   1. Abruf: $(echo "$h1" | grep -iE '^(x-generator|cache-control|etag):' | tr '\n' ' ')"
say "   2. Abruf: $(echo "$h2" | grep -iE '^(x-generator|cache-control|etag):' | tr '\n' ' ')"
blocked=""
for p in /core/install.php /core/rebuild.php /sites/default/settings.php /core/core.services.yml /.htaccess /INSTALL.txt \
         /web.config /core/modules/system/system.info.yml /sites/default/files/private/x /modules/custom/tannwald_access/tannwald_access.module; do
    code=$(c -o /dev/null -w '%{http_code}' "$URL$p"); [ "$code" = 404 ] || blocked="$blocked $p=$code"
done
[ -z "$blocked" ] || die "nicht gesperrt:$blocked"
ok "10 Pfade 404 (install.php, rebuild.php, settings.php, *.yml, .htaccess, *.txt, web.config, *.info.yml, files/private, *.module)"
say "   robots.txt $(c -o /dev/null -w '%{http_code}' "$URL/robots.txt") · update.php $(c -o /dev/null -w '%{http_code}' "$URL/update.php") · http→https $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "http://localhost:$HTTP_PORT/user/login")"
say "   HSTS: $(c -o /dev/null -D - "$URL/user/login" | tr -d '\r' | grep -i '^strict-transport-security' || echo fehlt)"
ajar="$BASE/admin.jar"
fb=$(c -c "$ajar" "$URL/user/login" | form_id_of)
code=$(c -b "$ajar" -c "$ajar" -o /dev/null -w '%{http_code}' --data-urlencode name=admin --data-urlencode "pass@$BASE/secret/admin" \
    --data-urlencode "form_build_id=$fb" --data-urlencode form_id=user_login_form --data-urlencode op=Anmelden "$URL/user/login")
[ "$code" = 303 ] || die "Login admin mit ADMIN_PASSWORD: HTTP $code."
ok "Login admin mit ADMIN_PASSWORD aus der env-Datei → 303 (Passwort nie ausgegeben)"
host=$(drush sql:query "SELECT hostname FROM watchdog WHERE type='user' AND message LIKE 'Session opened%' ORDER BY wid ASC LIMIT 1" 2>/dev/null | tr -d '[:space:]')
[ "$host" = 127.0.0.1 ] || die "REMOTE_ADDR beim Login war $host (X-Forwarded-For geglaubt?)."
ok "Login mit gefälschtem X-Forwarded-For 203.0.113.9 → Drupal sieht 127.0.0.1 (reverse_proxy aus)"
fb=$(c "$URL/user/password" | form_id_of)
c -o /dev/null --data-urlencode name=kunde-a --data-urlencode "form_build_id=$fb" --data-urlencode form_id=user_pass "$URL/user/password"
sleep 1
mails=$(ls "$BASE/mail"/*.eml 2>/dev/null | wc -l | tr -d ' ')
[ "$mails" -ge 1 ] || die "keine Mail über sendmail_path angekommen."
ok "Passwort-Mail kunde-a über php_mail → sendmail_path: $(cat "$BASE"/mail/*.args | head -n 1) ($mails Mail)"
code1=$(c -o /dev/null -w '%{http_code}' --resolve "mail.localhost:$HTTPS_PORT:127.0.0.1" "https://mail.localhost:$HTTPS_PORT/")
code2=$(c -o /dev/null -w '%{http_code}' --resolve "mail.localhost:$HTTPS_PORT:127.0.0.1" -u "probe:$(cat "$BASE/secret/basic")" "https://mail.localhost:$HTTPS_PORT/")
[ "$code1" = 401 ] && [ "$code2" = 200 ] || die "Mailpit: ohne $code1 / mit $code2 (erwartet 401/200)."
ok "mail.localhost: 401 ohne, 200 mit Basic-Auth"
cron_t=$(date +%s)
grep -E '^(ExecCondition|ExecStart)=' "$BASE/rendered/tannwald-cron.service" | while IFS= read -r line; do
    cmd=${line#*=}; cmd=${cmd#-}
    (cd "$TW_APP" && env TANNWALD_DEPLOY=1 PRIVATE_PATH="$TW_PRIVATE" PHP_INI_SCAN_DIR=":$TW_CLI_INI_DIR" PATH="$TW_STATE_DIR/bin:$PATH" bash -c 'set -a; . "$0"; set +a; eval "$1"' "$TW_ENV_FILE" "$cmd") > "$BASE/cron.log" 2>&1 \
        && say "   cron ok: ${line%%=*} $(echo "$cmd" | sed 's#.*vendor/bin/drush ##; s#2>/dev/null.*##' | cut -c1-60)" \
        || say "   cron ROT: $cmd — $(tail -n 2 "$BASE/cron.log" | tr '\n' ' ')"
done
ok "Cron-Unit-Zeilen in $(( $(date +%s) - cron_t )) s; system.cron_last $(drush php:eval 'echo date("H:i:s", \Drupal::state()->get("system.cron_last"));' 2>/dev/null)"
ft=$(drush sql:query "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tannwald_chunk' AND INDEX_TYPE='FULLTEXT'" 2>/dev/null | tr -d '[:space:]')
hits=$(drush php:eval '$k = \Drupal::database()->query("SELECT access_keys FROM {tannwald_chunk} LIMIT 1")->fetchField(); $keys = json_decode($k, TRUE) ?: array_filter(preg_split("/[\s,]+/", (string) $k)); echo count(\Drupal::service("tannwald_ingest.chunk_store")->keyword("Siegeltemperatur", $keys, 5));' 2>&1 | tail -n 1)
ok "FULLTEXT auf MySQL 8.0: $ft Index-Spalten in tannwald_chunk (ALTER TABLE aus hook_install), keyword(\"Siegeltemperatur\") → $hits Treffer"

step "deploy.sh — zweiter Lauf (idempotent)"
t=$(date +%s)
TW_RUN_USER=- bash "$TW_APP/deploy/deploy.sh" 2>&1 | tee "$BASE/deploy2.log" | sed 's/^/   | /'
[ "${PIPESTATUS[0]}" = 0 ] || die "deploy.sh (2. Lauf) rot."
RUN2=$(( $(date +%s) - t ))
grep -q 'nichts zu tun' "$BASE/deploy2.log" || die "zweiter Lauf nicht „nichts zu tun“."
ok "zweiter Lauf in $RUN2 s, „nichts zu tun“"

step "Speicher (RSS) nach den Läufen"
say "   php-fpm (Master + Kinder): $(ps -axo rss=,command= | grep "[p]hp-fpm: .*$BASE\|[p]hp-fpm: pool mytannwald" | awk '{s+=$1} END{printf "%.0f MB", s/1024}')"
say "   nginx (Master der Probe): $(ps -axo rss=,command= | grep -F "$BASE/nginx/nginx.conf" | grep -v grep | awk '{s+=$1} END{printf "%.0f MB", s/1024}')"
say "   MySQL-Container: $(docker stats --no-stream --format '{{.MemUsage}}' "$NAME")"
say "   Ollama (Host): $(curl -s http://127.0.0.1:11434/api/ps | perl -ne 'while(/"name":"([^"]+)".*?"size":(\d+)/g){printf "%s %.0f MB ", $1, $2/1048576}')"
# Modelle, die die Probe im Host-Ollama geladen hat (bge-m3, qwen2.5 durch den Zusammenfassungs-Cron), entladen.
for m in $(curl -s http://127.0.0.1:11434/api/ps | perl -ne 'print "$1\n" while /"name":"([^"]+)"/g'); do
    curl -s http://127.0.0.1:11434/api/generate -d "{\"model\":\"$m\",\"keep_alive\":0}" >/dev/null || true
done

dur
say ""
say "FERTIG (Generalprobe): erster Lauf $RUN1 s, zweiter Lauf $RUN2 s; Proben grün."
