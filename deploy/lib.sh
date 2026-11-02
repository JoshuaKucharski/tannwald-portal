# shellcheck shell=bash
# myTannwald — gemeinsame Hilfen für deploy/bootstrap.sh und deploy/deploy.sh (4.1, ADR-012). Wird nur
# eingebunden (source), setzt kein set -e selbst. bash 3.2 (Mac, Prüfstand) und bash 5 (Ubuntu 24.04).
# Grundsätze: nie einen Wert aus der env-Datei ausgeben, nie set -x, Geheimnisse nie auf eine Befehlszeile.

T0=$(date +%s)
STEP_T0=$T0
STEP_N=0
CLEANUP_HINT=""

say()  { printf '%s\n' "$*"; }
ok()   { say "   ok: $*"; }
note() { say "   Hinweis: $*"; }
dur()  { say "   ($(( $(date +%s) - STEP_T0 )) s)"; }
step() {
    [ "$STEP_N" -gt 0 ] && dur
    STEP_N=$((STEP_N + 1)); STEP_T0=$(date +%s)
    say ""; say "== ${STEP_N}/${STEP_TOTAL} $*"
}
die() {
    say ""; say "ABBRUCH (${SELF}, Schritt ${STEP_N}/${STEP_TOTAL}): $*"
    [ -n "$CLEANUP_HINT" ] && say "Aufräumen/weiter: ${CLEANUP_HINT}"
    exit 1
}

# Führt einen Befehl aus — oder druckt ihn nur (--dry). Nur für Befehle ohne Geheimnisse in den Argumenten.
run() {
    if [ "$DRY" = 1 ]; then say "   [dry] $*"; return 0; fi
    "$@"
}

# Wert aus der env-Datei ($ENV_SRC), ohne Ausgabe. Letzte Zeile gewinnt; umschliessende '…' oder "…" fallen weg.
env_get() {
    local line v
    line=$(grep -E "^$1=" "$ENV_SRC" 2>/dev/null | tail -n 1 || true)
    v=${line#*=}
    case "$v" in
        \'*\') v=${v#\'}; v=${v%\'} ;;
        \"*\") v=${v#\"}; v=${v%\"} ;;
    esac
    printf '%s' "$v"
}

# Prüft die Pflichtnamen; meldet fehlende/Platzhalter-Namen (nie Werte). Rückgabe 1, wenn etwas fehlt.
REQUIRED_VARS="DOMAIN DB_NAME DB_USER DB_PASSWORD DRUPAL_HASH_SALT TRUSTED_HOST_PATTERN BASIC_AUTH_USER BASIC_AUTH_HASH SITE_AUTH ADMIN_PASSWORD"
env_check() {
    local name v bad=""
    for name in $REQUIRED_VARS; do
        v=$(env_get "$name")
        case "$v" in ''|*CHANGE_ME*) bad="$bad $name" ;; esac
    done
    if [ -n "$bad" ]; then say "   nicht gesetzt oder Platzhalter:${bad}"; return 1; fi
    v=$(env_get SITE_AUTH); case "$v" in on|off) ;; *) say "   SITE_AUTH muss on oder off sein"; return 1 ;; esac
    v=$(env_get CERT_STAGING); case "$v" in ''|on|off) ;; *) say "   CERT_STAGING muss on oder off sein"; return 1 ;; esac
    v=$(env_get DOMAIN); case "$v" in *[!a-z0-9.-]*) say "   DOMAIN: nur a-z, 0-9, Punkt, Bindestrich"; return 1 ;; esac
    for name in DB_NAME DB_USER; do
        v=$(env_get "$name"); case "$v" in *[!A-Za-z0-9_]*) say "   ${name}: nur Buchstaben, Ziffern, _"; return 1 ;; esac
    done
    v=$(env_get DB_PASSWORD)
    case "$v" in *[!A-Za-z0-9_.,:@%+=-]*) say "   DB_PASSWORD: keine Anführungszeichen, \\, \$ oder Leerzeichen"; return 1 ;; esac
    # Werte, die in den Pool (php-fpm-INI, "…") gehen: kein " und kein \${.
    for name in DB_HOST DB_PORT DRUPAL_HASH_SALT TRUSTED_HOST_PATTERN OLLAMA_HOST DASHSCOPE_API_KEY_EU DASHSCOPE_ENDPOINT_EU; do
        v=$(env_get "$name")
        case "$v" in *\"*|*'${'*) say "   ${name}: kein \" und kein \${ (php-fpm-Pool)"; return 1 ;; esac
    done
    return 0
}

# Ersetzt @NAME@ in einer Vorlage durch $R_NAME (exportiert); ein Platzhalter ohne Wert bricht ab.
render() {
    perl -pe 's/\@([A-Z_]+)\@/defined $ENV{"R_$1"} ? $ENV{"R_$1"} : die("Platzhalter \@$1\@ ohne Wert in $ARGV\n")/ge' "$1" > "$2"
}

# Vergleicht eine fertige Datei mit dem Ziel: neu | gleich | geändert.
file_state() {
    if [ ! -e "$2" ]; then echo neu; elif cmp -s "$1" "$2"; then echo gleich; else echo "geändert"; fi
}

# Unerwartet roter Befehl (set -e): Schritt und Befehlstext nennen (Variablen bleiben unaufgelöst, also ohne Werte).
on_err() {
    local rc=$?
    say ""; say "ABBRUCH (${SELF}, Schritt ${STEP_N}/${STEP_TOTAL}): Befehl rot (Exit ${rc}): ${BASH_COMMAND}"
    [ -n "$CLEANUP_HINT" ] && say "Aufräumen/weiter: ${CLEANUP_HINT}"
    return 0
}
trap on_err ERR

# SQL für den App-Nutzer (bootstrap.sh, Generalprobe): legt ihn je Host an, falls er fehlt, und gibt ihm die
# Datenbank. Ausgabe nach stdout → | mysql (Passwort nie auf einer Befehlszeile). sql_users <db> <nutzer> <pw> <hosts…>
sql_users() {
    local db=$1 user=$2 pw=$3 h; shift 3
    for h in "$@"; do
        printf "CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY '%s';\nGRANT ALL PRIVILEGES ON \`%s\`.* TO '%s'@'%s';\n" \
            "$user" "$h" "$pw" "$db" "$user" "$h"
    done
}
