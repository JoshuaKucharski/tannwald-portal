#!/usr/bin/env bash
# Geheimnis-Suche im Repo (4.5, CI-Job secrets-scan): git grep über alle versionierten Dateien nach Mustern echter
# Schlüssel und Passwörter. Erlaubt sind nur offensichtliche Testwerte: CHANGE_ME, *MARKER*, pruefwert*, die
# Folge 0123456789abcdef (Unit-Tests) und das Demo-Passwort demo. Zeigt nur Datei:Zeile und das Muster, nie den
# Wert. Aufruf: bash scripts/pruefstand/deploy/secrets-scan.sh [<repo>]
# Ende: „secrets-scan: sauber (<n> Muster)“ (Exit 0) oder „secrets-scan: <n> Fund(e)“ (Exit 1).
set -euo pipefail
REPO="${1:-$(cd "$(dirname "$0")/../../.." && pwd)}"
ALLOW='CHANGE_ME|MARKER|pruefwert|0123456789abcdef|=\$|password: .demo.'
PATTERNS=(
    'sk-[A-Za-z0-9]{20,}'
    'DASHSCOPE_API_KEY_EU=[^[:space:]$"'"'"'`<]+'
    "password: '[^']+'"
    '(DB|ADMIN|GUEST|DB_ROOT)_PASSWORD=[^[:space:]$"'"'"'<]{8,}'
    'DRUPAL_HASH_SALT=[A-Za-z0-9+/=_-]{16,}'
    '-----BEGIN [A-Z ]*PRIVATE KEY-----'
    'BASIC_AUTH_HASH=.?\$(2[aby]|apr1|6)\$'
)
found=0
for p in "${PATTERNS[@]}"; do
    hits=$(git -C "$REPO" grep -nIE -e "$p" -- . 2>/dev/null | grep -vE "$ALLOW" || true)
    [ -n "$hits" ] || continue
    while IFS= read -r line; do
        found=$((found + 1))
        loc=${line%%:*}; rest=${line#*:}; lno=${rest%%:*}
        echo "Fund: $loc:$lno (Muster ${p:0:24}…)"
    done <<< "$hits"
done
if [ "$found" = 0 ]; then echo "secrets-scan: sauber (${#PATTERNS[@]} Muster)"; else echo "secrets-scan: $found Fund(e)"; exit 1; fi
