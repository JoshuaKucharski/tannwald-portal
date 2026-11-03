#!/usr/bin/env bash
# nginx -t für beide Vhost-Fassungen aus deploy/nginx (4.1, ADR-012) — ohne Server: rendert die Vorlagen mit
# Testwerten über bootstrap.sh --render und prüft sie mit dem nginx der Maschine in einem Wegwerf-Prefix
# (Wegwerf-Zertifikat für die TLS-Fassung). Läuft in CI (Job deploy-config, Ubuntu-nginx) und auf dem Mac
# (Homebrew-nginx). Aufruf: bash scripts/pruefstand/deploy/nginx-check.sh   Ende: „nginx-check: http ok, tls ok“
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../../.." && pwd)"
NGINX="${NGINX:-$(command -v nginx || echo /usr/sbin/nginx)}"
CONF_SRC="${NGINX_CONF_SRC:-$(dirname "$("$NGINX" -V 2>&1 | sed -n 's/.*--conf-path=\([^ ]*\).*/\1/p')")}"
T="$(mktemp -d "${TMPDIR:-/tmp}/tannwald-nginx-check.XXXXXX")"
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/etc/mytannwald" "$T/le/live/mytannwald.getleaven.com" "$T/nginx/tmp" "$T/log"
cat > "$T/etc/mytannwald/env" <<'EOF'
DOMAIN=mytannwald.getleaven.com
DB_PASSWORD=pruefwert0123456789
DB_NAME=mytannwald
DB_USER=mytannwald
DRUPAL_HASH_SALT=pruefwert0123456789abcdef
TRUSTED_HOST_PATTERN='^mytannwald\.getleaven\.com$'
GUEST_USER=gast
GUEST_PASSWORD=pruefwert0123456789
SITE_MAIL=noreply@mytannwald.getleaven.com
SITE_AUTH=on
ADMIN_PASSWORD=pruefwert0123456789
EOF
TW_APP="$REPO" TW_PRIVATE="$T/private" TW_ETC="$T/etc" TW_LE="$T/le" TW_LOG_DIR="$T/log" TW_STATE_DIR="$T/state" \
    TW_LISTEN_HTTP="listen 127.0.0.1:18081;" TW_LISTEN_HTTPS="listen 127.0.0.1:18444 ssl;" \
    bash "$REPO/deploy/bootstrap.sh" --render="$T/r" > "$T/render.log"
mkdir -p "$T/etc/nginx/snippets"
cp "$T/r/mytannwald-drupal.conf" "$T/etc/nginx/snippets/"; cp "$T/r/mytannwald.htpasswd" "$T/etc/nginx/"
cp "$CONF_SRC/fastcgi_params" "$CONF_SRC/mime.types" "$T/nginx/"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=mytannwald.getleaven.com \
    -keyout "$T/le/live/mytannwald.getleaven.com/privkey.pem" -out "$T/le/live/mytannwald.getleaven.com/fullchain.pem" 2>/dev/null
openssl dhparam -dsaparam -out "$T/le/ssl-dhparams.pem" 2048 2>/dev/null
printf 'ssl_session_cache shared:le_nginx_SSL:10m;\nssl_protocols TLSv1.2 TLSv1.3;\n' > "$T/le/options-ssl-nginx.conf"
result=""
for v in http tls; do
    cat > "$T/nginx/nginx-$v.conf" <<EOF
pid $T/nginx/nginx.pid;
error_log $T/log/error.log;
events { worker_connections 64; }
http {
    include mime.types;
    # Standard-Zugriffslog der Maschine (/var/log/nginx/access.log) nie öffnen: nginx -t öffnet es für jeden
    # server-Block ohne eigenes access_log (Umleitung 80 → 443), und der CI-Runner darf dort nicht schreiben.
    access_log $T/log/access.log;
    client_body_temp_path $T/nginx/tmp/body;
    fastcgi_temp_path $T/nginx/tmp/fastcgi;
    proxy_temp_path $T/nginx/tmp/proxy;
    uwsgi_temp_path $T/nginx/tmp/uwsgi;
    scgi_temp_path $T/nginx/tmp/scgi;
    include $T/r/vhost-$v.conf;
}
EOF
    if "$NGINX" -t -e "$T/log/error.log" -p "$T/nginx" -c "$T/nginx/nginx-$v.conf" > "$T/t-$v.log" 2>&1; then result="$result $v ok,"
    else cat "$T/t-$v.log"; echo "nginx-check: Fassung $v ROT"; exit 1; fi
done
echo "nginx-check:${result%,} ($("$NGINX" -v 2>&1 | sed 's#.*/##'), SITE_AUTH=on)"
