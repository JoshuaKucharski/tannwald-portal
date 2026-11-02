#!/usr/bin/env bash
# Prüfstand für deploy/bootstrap.sh und deploy/deploy.sh (Programm „myTannwald Phase 2“, Aufgabe 4.1, ADR-012) —
# beweist beide Skripte OHNE Server: gefälschte apt-get, dpkg-query, update-alternatives, systemctl, nginx,
# php-fpm8.4, mysql, certbot, curl, tar, useradd, chown, sudo, php8.4, composer, ollama und drush beantworten die
# Aufrufe aus Zustandsdateien einer Wegwerf-„Maschine“ ($W) und protokollieren jeden Aufruf (calls.log).
# Ein Fall je Datei unter faelle/ (Name = Nummer-Thema). Aufruf (bash 3.2 des Macs und bash 5):
#   bash scripts/pruefstand/deploy/pruefstand-deploy.sh [<fall-muster>]
#   TANNWALD_BASH=/opt/homebrew/bin/bash /opt/homebrew/bin/bash scripts/pruefstand/deploy/pruefstand-deploy.sh
# Grenze: echte Pakete, systemd, nginx, MySQL und certbot misst erst der erste echte Lauf (4.2); nginx- und
# php-fpm-Vorlagen, MySQL 8.0 und deploy.sh gegen echtes Drupal misst deploy/generalprobe-mac.sh.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../.." && pwd)"
RUN_BASH="${TANNWALD_BASH:-/bin/bash}"
PATTERN="${1:-}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/tannwald-pruefstand-deploy.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
FAKES="$WORK/fakes"
mkdir -p "$FAKES"
RESULTS="$WORK/results"
: > "$RESULTS"

# Geheimnis-Marker: tauchen sie in einer Ausgabe oder auf einer Befehlszeile auf, ist der Fall rot.
M_DB=dbpwMARKER5f1e2d
M_SALT=saltMARKER9a8b7c
M_ADMIN=adminMARKER3c4d5e
M_HASH='$6$hashMARKER7e8f9a'
M_KEY=sk-keyMARKER0a1b2c

# --- Fälschungen ------------------------------------------------------------------------------------------
fake() { cat > "$FAKES/$1"; chmod 755 "$FAKES/$1"; }
fake log-call <<'F'
#!/bin/bash
printf '%s\n' "$*" >> "$FAKE_STATE/calls.log"
F
fake id <<'F'
#!/bin/bash
log-call id "$@"
if [ "$1" = -u ]; then cat "$FAKE_STATE/uid" 2>/dev/null || echo 0; exit 0; fi
[ -f "$FAKE_STATE/users/$1" ] && { echo "uid=999($1)"; exit 0; }; exit 1
F
fake useradd <<'F'
#!/bin/bash
log-call useradd "$@"; for a in "$@"; do n=$a; done; mkdir -p "$FAKE_STATE/users"; touch "$FAKE_STATE/users/$n"
F
fake chown <<'F'
#!/bin/bash
log-call chown "$@"
F
fake dpkg <<'F'
#!/bin/bash
echo amd64
F
fake dpkg-query <<'F'
#!/bin/bash
for a in "$@"; do p=$a; done
[ -f "$FAKE_STATE/pkgs/$p" ] && printf 'install ok installed' || printf 'unknown ok not-installed'
F
fake apt-get <<'F'
#!/bin/bash
log-call apt-get "$@"
case " $* " in *" install "*)
    mkdir -p "$FAKE_STATE/pkgs"
    for a in "$@"; do case "$a" in php8.4-*) touch "$FAKE_STATE/pkgs/$a" ;; esac; done
    mkdir -p "$TW_ETC/php/8.4/fpm/pool.d"; [ -f "$FAKE_STATE/www_done" ] || { echo '[www]' > "$TW_ETC/php/8.4/fpm/pool.d/www.conf"; touch "$FAKE_STATE/www_done"; }
    # Paket php8.4-cli im Modus auto: die Alternative springt auf 8.4 (wie auf dem Server zu erwarten).
    [ -f "$FAKE_STATE/alt_auto" ] && ln -sfn "$TW_BIN/php8.4" "$TW_BIN/php" && ln -sfn "$TW_BIN/phar8.4" "$TW_BIN/phar"
    ;; esac
exit 0
F
fake update-alternatives <<'F'
#!/bin/bash
log-call update-alternatives "$@"
[ "$1" = --set ] && ln -sfn "$3" "$TW_BIN/$2"
F
fake systemctl <<'F'
#!/bin/bash
log-call systemctl "$@"
if [ "$1" = is-active ]; then for a in "$@"; do u=$a; done; [ -f "$FAKE_STATE/active/$u" ]; exit $?; fi
if [ "$1" = stop ]; then rm -f "$FAKE_STATE/active/$2"; fi
if [ "$1" = enable ] && [ "$2" = --now ]; then mkdir -p "$FAKE_STATE/active"; touch "$FAKE_STATE/active/$3"; fi
exit 0
F
fake nginx <<'F'
#!/bin/bash
log-call nginx "$@"
if [ -f "$FAKE_STATE/nginx_bad" ]; then echo "nginx: [emerg] unknown directive in mytannwald (Prüfstand)" >&2; exit 1; fi
echo "nginx: configuration file test is successful" >&2
F
fake php-fpm8.4 <<'F'
#!/bin/bash
log-call php-fpm8.4 "$@"
if [ -f "$FAKE_STATE/fpm_bad" ]; then echo "ERROR: [pool mytannwald] bad directive (Prüfstand)" >&2; exit 78; fi
echo "NOTICE: configuration file test is successful" >&2
F
fake mysql-root <<'F'
#!/bin/bash
log-call mysql-root "$@"
case "$*" in
    *SCHEMATA*) [ -f "$FAKE_STATE/db_exists" ] && echo 1 || echo 0 ;;
    *"CREATE DATABASE"*) touch "$FAKE_STATE/db_exists" ;;
    *) cat >> "$FAKE_STATE/sql-stdin.log" ;;
esac
F
fake mysql <<'F'
#!/bin/bash
log-call mysql "$@"
[ -f "$FAKE_STATE/login_ok" ] && exit 0
grep -q 'ALTER USER' "$FAKE_STATE/sql-stdin.log" 2>/dev/null && exit 0
grep -q 'CREATE USER' "$FAKE_STATE/sql-stdin.log" 2>/dev/null && [ ! -f "$FAKE_STATE/login_needs_alter" ] && exit 0
exit 1
F
fake curl <<'F'
#!/bin/bash
log-call curl "$@"
out=""; url=""; w=""; prev=""
for a in "$@"; do
    case "$prev" in -o) out=$a ;; -w) w=$a ;; esac
    case "$a" in http*) url=$a ;; esac; prev=$a
done
case "$url" in
    *ollama.com/install.sh) printf 'mkdir -p "%s" && cp "%s" "%s/ollama"\n' "$FAKE_STATE/bin" "$FAKES_DIR/ollama-fake" "$FAKE_STATE/bin" ;;
    *mailpit-linux-*) echo tar > "$out" ;;
    */user/login)
        code=$(cat "$FAKE_STATE/login_code" 2>/dev/null || echo 200)
        [ -n "$out" ] && { cat "$FAKE_STATE/site.html" 2>/dev/null > "$out" || : > "$out"; }
        printf '%s' "$code" ;;
    *mail.*) [ -n "$out" ] && : > "$out"; printf '%s' "$(cat "$FAKE_STATE/mail_code" 2>/dev/null || echo 401)" ;;
    *) exit 6 ;;
esac
F
fake ollama-fake <<'F'
#!/bin/bash
log-call ollama "$@"
case "$1" in
    list) echo "NAME ID SIZE"; [ -f "$FAKE_STATE/model_bge" ] && echo "bge-m3:latest 7907646 1.2 GB" ;;
    pull) touch "$FAKE_STATE/model_bge" ;;
esac
exit 0
F
fake tar <<'F'
#!/bin/bash
log-call tar "$@"
prev=""; for a in "$@"; do [ "$prev" = -C ] && d=$a; prev=$a; done
printf '#!/bin/sh\n' > "$d/mailpit"
F
fake certbot <<'F'
#!/bin/bash
log-call certbot "$@"
ALL="$*"
[ -f "$FAKE_STATE/certbot_bad" ] && { echo "Challenge failed (Prüfstand)" >&2; exit 1; }
prev=""; doms=""; for a in "$@"; do [ "$prev" = -d ] && doms="$doms $a"; prev=$a; done
set -- $doms; d=$1
mkdir -p "$TW_LE/live/$d" "$TW_LE/renewal"; touch "$TW_LE/live/$d/fullchain.pem"
case " $ALL " in
    *" --test-cert "*) echo "server = https://acme-staging-v02.api.letsencrypt.org/directory" > "$TW_LE/renewal/$d.conf" ;;
    *) echo "server = https://acme-v02.api.letsencrypt.org/directory" > "$TW_LE/renewal/$d.conf" ;;
esac
F
fake getent <<'F'
#!/bin/bash
echo "91.98.229.15    STREAM $2"
F
fake sudo <<'F'
#!/bin/bash
log-call sudo "$1" "$2" "$3"
shift 3; exec "$@"
F
# php8.4: beantwortet die -r-Fragen der Skripte; mit composer als erstem Argument spielt es composer.
fake php-fake <<'F'
#!/bin/bash
case "$1" in
    -r) case "$2" in
            *PHP_MAJOR_VERSION*) printf '8.4' ;;
            *'"PHP "'*) printf 'PHP 8.4.27' ;;
            *gd_info*) exit 0 ;;
            *PHP_VERSION*) printf '8.4.27' ;;
        esac ;;
    *composer) log-call composer "${@:2}"
        [ -f "$FAKE_STATE/composer_bad" ] && { echo "Your requirements could not be resolved (Prüfstand)"; exit 2; }
        if [ -f "$FAKE_STATE/vendor_done" ]; then echo "Nothing to install, update or remove"; else echo "Installing dependencies from lock file"; touch "$FAKE_STATE/vendor_done"; fi
        echo "Generating optimized autoload files" ;;
esac
F
fake composer <<'F'
#!/bin/bash
echo "composer-Platzhalter (wird über php8.4 aufgerufen)"
F
# drush: Zustand der Site in $FAKE_STATE; prüft, dass Geheimnisse aus der Umgebung kommen.
fake drush-fake <<'F'
#!/bin/bash
log-call drush "$@"
[ "${TANNWALD_DEPLOY:-}" = 1 ] || { echo "TANNWALD_DEPLOY fehlt" >&2; exit 9; }
[ -n "${DB_PASSWORD:-}" ] || { echo "DB_PASSWORD fehlt in der Umgebung" >&2; exit 9; }
case "$1" in
    status) [ -f "$FAKE_STATE/installed" ] && echo Successful || echo "" ;;
    sql:query) case "$2" in
            *information_schema.TABLES*) cat "$FAKE_STATE/tables" 2>/dev/null || echo 0 ;;
            *"type = 'dokument'"*) cat "$FAKE_STATE/docs" 2>/dev/null || echo 0 ;;
            *CONCAT*) echo "10 Maschinen, 20 Dokumente, 575 Chunks, 6 Konten" ;;
        esac ;;
    site:install) [ -f "$FAKE_STATE/si_bad" ] && { echo "SQLSTATE[HY000] (Prüfstand)"; exit 1; }
        touch "$FAKE_STATE/installed"; echo 42 > "$FAKE_STATE/tables" ;;
    php:eval) [ -n "${ADMIN_PASSWORD:-}" ] && echo set > "$FAKE_STATE/admin_pw_from_env" ;;
    updatedb:status) [ -f "$FAKE_STATE/pending" ] && echo "tannwald_assistant 10007" || echo " [success] No database updates required." ;;
    updatedb) rm -f "$FAKE_STATE/pending" ;;
    config:status) cat "$FAKE_STATE/cfg_diff" 2>/dev/null || true ;;
    deploy) [ -f "$FAKE_STATE/cfg_stuck" ] || rm -f "$FAKE_STATE/cfg_diff" ;;
    tannwald:demo-install)
        [ "${TANNWALD_DEMO:-}" = 1 ] || { echo "[error] Demo data only in DDEV …"; exit 1; }
        [ -f "$FAKE_STATE/seed_bad" ] && { echo "Migration tannwald_dokumente: 3 failed (Prüfstand)"; exit 1; }
        echo 29 > "$FAKE_STATE/docs"; printf '  firmen   7\n  maschinen   10\n  users   6\n  dokumente   20\n  chunks   575\nChanges: 80. Done in 64.8 s.\n' ;;
esac
exit 0
F

# --- Welt je Fall -----------------------------------------------------------------------------------------
new_world() {
    W="$WORK/w$(date +%s)$RANDOM"; export FAKE_STATE="$W/state-fake"
    mkdir -p "$FAKE_STATE/bin" "$W/etc/apt/sources.list.d" "$W/etc/nginx/sites-available" "$W/etc/nginx/sites-enabled" \
        "$W/etc/nginx/snippets" "$W/usr/bin" "$W/usr/local/bin" "$W/app/vendor/bin" "$W/log"
    printf 'ID=ubuntu\nVERSION_ID="24.04"\n' > "$W/etc/os-release"
    : > "$W/etc/apt/sources.list.d/ondrej-ubuntu-php-noble.list"
    : > "$FAKE_STATE/calls.log"
    for b in php8.3 php8.4 phar8.3 phar8.4; do cp "$FAKES/php-fake" "$W/usr/bin/$b"; done
    ln -s "$W/usr/bin/php8.3" "$W/usr/bin/php"; ln -s "$W/usr/bin/phar8.3" "$W/usr/bin/phar"
    cp -R "$REPO/deploy" "$W/app/deploy"; rm -f "$W/app/deploy/.env" "$W/app/deploy/.env.probe"
    echo '{}' > "$W/app/composer.json"; echo '{}' > "$W/app/composer.lock"
    cp "$FAKES/drush-fake" "$W/app/vendor/bin/drush"
    echo '<title>Anmelden | myTannwald</title>' > "$FAKE_STATE/site.html"
    touch "$FAKE_STATE/alt_auto"
    export TW_APP="$W/app" TW_PRIVATE="$W/private" TW_ETC="$W/etc" TW_BIN="$W/usr/bin" TW_STATE_DIR="$W/state"
    export TW_PHP="$W/usr/bin/php8.4" TW_PHP_FPM="$FAKES/php-fpm8.4" TW_MAILPIT_BIN="$W/usr/local/bin/mailpit"
    export TW_MYSQL="$FAKES/mysql-root" TW_MYSQL_APP="$FAKES/mysql" TW_LE="$W/etc/letsencrypt" TW_LOG_DIR="$W/log"
    export TW_COMPOSER="$FAKES/composer" TW_WAIT=4 FAKES_DIR="$FAKES" TW_OLLAMA="$FAKE_STATE/bin/ollama"
    unset TW_ENV_FILE TW_RUN_USER || true
}
write_env() { # write_env [NAME=wert …] — vollständige env-Datei mit Markern; Angaben ersetzen/ergänzen Zeilen
    local f="$W/etc/mytannwald/env" kv
    mkdir -p "$W/etc/mytannwald"
    cat > "$f" <<EOF
DOMAIN=mytannwald.getleaven.com
CERT_STAGING=on
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=mytannwald
DB_USER=mytannwald
DB_PASSWORD=$M_DB
DRUPAL_HASH_SALT=$M_SALT
TRUSTED_HOST_PATTERN='^mytannwald\.getleaven\.com\$'
DASHSCOPE_API_KEY_EU=$M_KEY
DASHSCOPE_ENDPOINT_EU=https://ws-test.eu-central-1.maas.aliyuncs.com/compatible-mode/v1
OLLAMA_HOST=http://127.0.0.1:11434
BASIC_AUTH_USER=tannwald
BASIC_AUTH_HASH='$M_HASH'
SITE_AUTH=off
ADMIN_PASSWORD=$M_ADMIN
EOF
    for kv in "$@"; do
        grep -v "^${kv%%=*}=" "$f" > "$f.n" || true; echo "$kv" >> "$f.n"; mv "$f.n" "$f"
    done
    chmod 640 "$f"
}
run_script() { # run_script <bootstrap|deploy> [args] → $W/out, $W/rc
    local s=$1; shift
    set +e
    PATH="$FAKE_STATE/bin:$FAKES:$PATH" "$RUN_BASH" "$W/app/deploy/$s.sh" "$@" > "$W/out" 2>&1
    echo $? > "$W/rc"
    set -e
}
run_bootstrap() { run_script bootstrap "$@"; }
run_deploy() { run_script deploy "$@"; }

# --- Prüfungen -------------------------------------------------------------------------------------------
FALL=""
good() { echo "ok   $FALL: $1" >> "$RESULTS"; }
bad()  { echo "ROT  $FALL: $1" >> "$RESULTS"; }
check() { if eval "$2"; then good "$1"; else bad "$1"; fi; }
has()    { grep -qF -- "$1" "${2:-$W/out}"; }
calls()  { grep -cF -- "$1" "$FAKE_STATE/calls.log" || true; }
rc_is()  { [ "$(cat "$W/rc")" = "$1" ]; }
in_order() { # in_order <muster…> — Muster erscheinen in calls.log in dieser Reihenfolge
    local last=0 n p
    for p in "$@"; do
        n=$(grep -nF -- "$p" "$FAKE_STATE/calls.log" | awk -F: -v l="$last" '$1 > l {print $1; exit}')
        [ -n "$n" ] || return 1; last=$n
    done
}
no_secrets() { # keine Marker in Ausgabe und auf Befehlszeilen
    ! grep -qE "$M_DB|$M_SALT|$M_ADMIN|hashMARKER|$M_KEY" "$W/out" "$FAKE_STATE/calls.log"
}

# --- Fälle ausführen (je Datei eine Subshell) -------------------------------------------------------------
n=0
for f in "$HERE"/faelle/*.sh; do
    case "$(basename "$f")" in *"$PATTERN"*) ;; *) continue ;; esac
    n=$((n + 1))
    ( FALL="$(basename "$f" .sh)"; new_world; . "$f"
      if [ -n "${PRUEF_VERBOSE:-}" ] && grep -q "^ROT  $FALL" "$RESULTS" && [ -f "$W/out" ]; then tail -n 25 "$W/out" | sed "s/^/      | /" >> "$RESULTS"; tail -n 12 "$FAKE_STATE/calls.log" | sed "s/^/      calls: /" >> "$RESULTS"; fi ) || echo "ROT  $(basename "$f" .sh): Fall brach ab (Exit $?)" >> "$RESULTS"
done
cat "$RESULTS"
ok=$(grep -c '^ok ' "$RESULTS" || true); rot=$(grep -c '^ROT ' "$RESULTS" || true)
echo ""
echo "Prüfstand deploy: $n Fälle, $ok Prüfungen grün, $rot rot ($("$RUN_BASH" -c 'echo "$BASH_VERSION"'))"
[ "$rot" = 0 ]
