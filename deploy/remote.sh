#!/usr/bin/env bash
# myTannwald — Modus --deploy des Stamm-Skripts (deploy/remote.sh, 4.2, ADR-012). Läuft auf dem Mac des Betreibers;
# tannwald-run.sh --deploy ruft es auf (exec). Ziel aus ~/.config/tannwald/deploy.env (nur Host, Schlüsselpfad, Domain
# — keine Passwörter; anlegen mit --deploy --init).
#   bash tannwald-run.sh --deploy             rsync → bootstrap.sh --tls → deploy.sh → Proben vom Mac
#   bash tannwald-run.sh --deploy --bootstrap rsync → bootstrap.sh (ohne deploy.sh) — z. B. vor dem Füllen der env-Datei
#   bash tannwald-run.sh --deploy --probe     liest nur: Proben vom Mac (fragt das Besucher-Passwort ab), Server-Stand
#   bash tannwald-run.sh --deploy --dry       zeigt jeden Schritt und die Pläne von bootstrap.sh/deploy.sh, ändert nichts
#   bash tannwald-run.sh --deploy --init      legt ~/.config/tannwald/deploy.env an (wenn sie fehlt)
# Ablauf --deploy: (1) Vorbedingungen, SSH lesend (rsync auf dem Server?) · (2) Stand: origin/dev, Abbruch bei
# ungepushten Commits auf dev, Export per git archive (der Arbeitsbaum zählt nicht) · (3) andere Sites der Maschine
# vorher (HTTP-Code) · (4) rsync -rlptz --delete mit deploy/rsync-exclude.txt · (5) DNS beider Namen (dig) → bootstrap.sh
# mit --tls nur, wenn beide auf den Server zeigen · (6) deploy.sh · (7) Proben vom Mac (/health, robots, Login 401,
# TLS gültig mit Aussteller, mail. 401) und Server-Stand („Chunks eingebettet x/575“) · (8) andere Sites nachher.
# Verändernd auf dem Server nur bootstrap.sh und deploy.sh; alle anderen SSH-Aufrufe lesen. Zeigt nie Werte aus
# der Server-env-Datei; das Besucher-Passwort (nur --probe) geht über stdin an curl, nie auf eine Befehlszeile.
# SSH: BatchMode (keine Rückfrage), ServerAliveInterval 30, keine Wiederholschleife (fail2ban der Maschine).
# Ende: „FERTIG (deploy) …“ bzw. „FERTIG (probe) …“; Abbruch: „ABBRUCH (…)“, Exit 1.
set -euo pipefail
SELF="tannwald-run.sh --deploy"
STEP_TOTAL=8
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="${TANNWALD_REPO:-$(cd "$HERE/.." && pwd)}"
# shellcheck source=lib.sh
. "$HERE/lib.sh"

MODE=deploy; DRY=0
for arg in "$@"; do
    case "$arg" in
        --probe) MODE=probe ;;
        --bootstrap) MODE=bootstrap ;;
        --init) MODE=init ;;
        --dry) DRY=1 ;;
        *) say "ABBRUCH: unbekannte Angabe „${arg}“ — erlaubt: --deploy [--init|--probe|--bootstrap|--dry]"; exit 1 ;;
    esac
done
CONF="${TW_DEPLOY_CONF:-$HOME/.config/tannwald/deploy.env}"

write_conf() { # Standardwerte (Nachtrag 4): Ziel, Schlüsselpfad, Domain — keine Passwörter
    mkdir -p "$(dirname "$CONF")"
    cat > "$CONF" <<'EOF'
# myTannwald --deploy (Mac): Ziel, Schlüsselpfad, Domain — KEINE Passwörter (die liegen nur auf dem Server).
DEPLOY_SSH=root@91.98.229.15
DEPLOY_KEY=~/.ssh/hetzner
DEPLOY_DOMAIN=mytannwald.getleaven.com
DEPLOY_PATH=/var/www/mytannwald
DEPLOY_BRANCH=dev
DEPLOY_GUEST_USER=gast
# Andere Sites der Maschine: --deploy misst ihren HTTP-Code vorher und nachher (nur lesen).
DEPLOY_OTHER_SITES="aplus-dental.de dentalplaner.de firstbiodent.de herz-rein.julalosite.com myki.de"
EOF
    chmod 600 "$CONF"
}
if [ "$MODE" = init ]; then
    if [ -f "$CONF" ]; then say "vorhanden: $CONF (unverändert) — Namen: $(grep -oE '^[A-Z_]+' "$CONF" | tr '\n' ' ')"; exit 0; fi
    write_conf; say "FERTIG — $CONF angelegt (Modus 600). Prüfen, dann: tannwald-run.sh --deploy --dry"; exit 0
fi

# ---------------------------------------------------------------------------------------------- Werte
if [ ! -f "$CONF" ]; then write_conf; say "Hinweis: $CONF fehlte — mit Standardwerten angelegt (Ziel root@91.98.229.15, Schlüssel ~/.ssh/hetzner)."; fi
ENV_SRC="$CONF"
DEPLOY_SSH=$(env_get DEPLOY_SSH); DEPLOY_KEY=$(env_get DEPLOY_KEY); DOMAIN=$(env_get DEPLOY_DOMAIN)
DEPLOY_PATH=$(env_get DEPLOY_PATH); BRANCH=$(env_get DEPLOY_BRANCH); GUEST_USER=$(env_get DEPLOY_GUEST_USER)
OTHER_SITES=$(env_get DEPLOY_OTHER_SITES)
DEPLOY_PATH=${DEPLOY_PATH:-/var/www/mytannwald}; BRANCH=${BRANCH:-dev}; GUEST_USER=${GUEST_USER:-gast}
case "$DEPLOY_KEY" in "~/"*) DEPLOY_KEY="$HOME/${DEPLOY_KEY#\~/}" ;; esac
[ -n "$DEPLOY_SSH" ] && [ -n "$DOMAIN" ] || { say "ABBRUCH: DEPLOY_SSH oder DEPLOY_DOMAIN fehlt in $CONF"; exit 1; }
SERVER_HOST=${DEPLOY_SSH#*@}
SSH_OPTS="-i $DEPLOY_KEY -o BatchMode=yes -o IdentitiesOnly=yes -o ConnectTimeout=15 -o ServerAliveInterval=30 -o ServerAliveCountMax=6"
rsh() { ssh $SSH_OPTS "$DEPLOY_SSH" "$@"; }

TMPD="$(mktemp -d "${TMPDIR:-/tmp}/tannwald-deploy-mac.XXXXXX")"
trap 'rm -rf "$TMPD"' EXIT
EXPORT="$TMPD/export"

# Proben vom Mac (ohne Passwort): /health, robots, Login 401 bzw. 200, X-Robots-Tag, mail. 401, TLS gültig.
mac_probes() {
    local code issuer
    code=$(curl -s --max-time 20 -o "$TMPD/h" -w '%{http_code}' "https://$DOMAIN/health" || echo 000)
    [ "$code" = 200 ] || die "https://$DOMAIN/health: HTTP $code (TLS ungültig oder Site nicht erreichbar)."
    issuer=$(curl -sv --max-time 20 -o /dev/null "https://$DOMAIN/health" 2>&1 | sed -n 's/^\*  *issuer: *//p' | head -n 1)
    ok "TLS gültig (curl ohne -k), Aussteller: ${issuer:-unbekannt}; /health 200"
    code=$(curl -s --max-time 20 -o "$TMPD/r" -w '%{http_code}' "https://$DOMAIN/robots.txt" || echo 000)
    [ "$code" = 200 ] && grep -q 'Disallow: /' "$TMPD/r" || die "robots.txt: HTTP $code oder ohne „Disallow: /“."
    code=$(curl -s --max-time 20 -D "$TMPD/lh" -o /dev/null -w '%{http_code}' "https://$DOMAIN/user/login" || echo 000)
    case "$code" in 401|200) ;; *) die "Login-Seite: HTTP $code (erwartet 401 hinter dem Besucher-Passwort)." ;; esac
    grep -qi '^x-robots-tag: noindex' "$TMPD/lh" || die "X-Robots-Tag: noindex fehlt auf /user/login."
    ok "robots.txt „Disallow: /“, /user/login $code ohne Passwort$( [ "$code" = 401 ] && echo ' (Besucher-Passwort aktiv)'), X-Robots-Tag noindex"
    code=$(curl -s --max-time 20 -o /dev/null -w '%{http_code}' "https://mail.$DOMAIN/" || echo 000)
    [ "$code" = 401 ] || die "https://mail.$DOMAIN/: HTTP $code statt 401."
    ok "mail.$DOMAIN 401 ohne Passwort"
}
# HTTP-Codes der anderen Sites (nur lesen): „domain=code …“
others() {
    local s out=""
    for s in $OTHER_SITES; do out="$out $s=$(curl -s --max-time 15 -o /dev/null -w '%{http_code}' "https://$s/" || echo 000)"; done
    printf '%s' "${out# }"
}

say "myTannwald --deploy ($MODE$( [ "$DRY" = 1 ] && echo ', Trockenlauf')) — $(date '+%Y-%m-%d %H:%M') — Ziel $DEPLOY_SSH:$DEPLOY_PATH, $DOMAIN"

# ---------------------------------------------------------------------------------------------- --dry
if [ "$DRY" = 1 ]; then
    step "Plan (nichts wird ausgeführt, kein SSH)"
    say "   1 ssh $SSH_OPTS $DEPLOY_SSH 'command -v rsync'   (lesend)"
    say "   2 git fetch origin $BRANCH; Abbruch bei ungepushten Commits; git archive origin/$BRANCH → Export"
    say "   3 curl -s -o /dev/null -w %{http_code} https://<site>/ für: $OTHER_SITES"
    say "   4 rsync -rlptz --delete --exclude-from=deploy/rsync-exclude.txt -e 'ssh …' <export>/ $DEPLOY_SSH:$DEPLOY_PATH/"
    say "   5 dig +short A $DOMAIN mail.$DOMAIN → beide = $SERVER_HOST? dann: ssh … bash $DEPLOY_PATH/deploy/bootstrap.sh --tls"
    say "   6 ssh … bash $DEPLOY_PATH/deploy/deploy.sh"
    say "   7 curl https://$DOMAIN/health · /robots.txt · /user/login (401) · https://mail.$DOMAIN/ (401); ssh … deploy.sh --status"
    say "   8 andere Sites nachher wie 3 — jede Verschlechterung ist ein Abbruch"
    step "Plan von bootstrap.sh (lokal, --dry)"
    bash "$REPO/deploy/bootstrap.sh" --dry --tls 2>&1 | grep -E '^== |\[dry\]|FERTIG' | sed 's/^/   | /'
    step "Plan von deploy.sh (lokal, --dry)"
    bash "$REPO/deploy/deploy.sh" --dry 2>&1 | grep -E '^== |\[dry\]|FERTIG' | sed 's/^/   | /'
    dur; say ""; say "FERTIG (dry) — nichts geändert. Echter Lauf: tannwald-run.sh --deploy"
    exit 0
fi

step "Vorbedingungen (SSH nur lesend)"
for c in ssh rsync curl dig git tar; do command -v "$c" >/dev/null 2>&1 || die "$c fehlt auf dem Mac."; done
[ -r "$DEPLOY_KEY" ] || die "SSH-Schlüssel $DEPLOY_KEY nicht lesbar (Pfad aus $CONF)."
remote_ok=$(rsh 'command -v rsync >/dev/null && command -v nginx >/dev/null && echo bereit' 2>"$TMPD/ssh.err" || true)
[ "$remote_ok" = bereit ] || die "SSH zu $DEPLOY_SSH nicht möglich oder rsync/nginx fehlt dort ($(tail -n 1 "$TMPD/ssh.err")). Keine Wiederholung (fail2ban)."
ok "ssh $DEPLOY_SSH: erreichbar, rsync und nginx vorhanden"

if [ "$MODE" = probe ]; then
    step "Proben vom Mac (ohne Passwort)"
    mac_probes
    step "Proben mit Besucher-Passwort ($GUEST_USER)"
    if [ -t 0 ]; then printf '   Besucher-Passwort für %s (wird nicht angezeigt): ' "$GUEST_USER"; read -r -s pw; echo
    else read -r pw; fi
    code=$(printf 'user = "%s:%s"\n' "$GUEST_USER" "$pw" | curl -s --max-time 20 -K - -o "$TMPD/l" -w '%{http_code}' "https://$DOMAIN/user/login" || echo 000)
    [ "$code" = 200 ] && grep -q 'myTannwald' "$TMPD/l" || die "Login-Seite mit Besucher-Passwort: HTTP $code oder ohne „myTannwald“."
    code=$(printf 'user = "%s:%s"\n' "$GUEST_USER" "$pw" | curl -s --max-time 20 -K - -o /dev/null -w '%{http_code}' "https://mail.$DOMAIN/" || echo 000)
    unset pw
    [ "$code" = 200 ] || die "mail.$DOMAIN mit Besucher-Passwort: HTTP $code statt 200."
    ok "Login-Seite 200 mit „myTannwald“, mail.$DOMAIN 200 — mit Besucher-Passwort"
    step "Stand auf dem Server (deploy.sh --status, liest nur)"
    rsh "bash $DEPLOY_PATH/deploy/deploy.sh --status" 2>&1 | sed 's/^/   | /' || true
    step "Andere Sites der Maschine"
    say "   $(others)"
    dur; say ""; say "FERTIG (probe) in $(( $(date +%s) - T0 )) s — nichts geändert."
    exit 0
fi

step "Stand: origin/$BRANCH exportieren (git archive)"
git -C "$REPO" fetch -q origin "$BRANCH" 2>"$TMPD/git.err" || die "git fetch origin $BRANCH rot: $(tail -n 1 "$TMPD/git.err")"
ahead=$(git -C "$REPO" rev-list --count "origin/$BRANCH..$BRANCH" 2>/dev/null || echo 0)
[ "$ahead" = 0 ] || die "lokales $BRANCH hat $ahead Commit(s), die nicht gepusht sind — erst committen und pushen (deployt wird origin/$BRANCH)."
rev=$(git -C "$REPO" rev-parse --short "origin/$BRANCH")
mkdir -p "$EXPORT"
git -C "$REPO" archive "origin/$BRANCH" | tar -x -C "$EXPORT"
[ -f "$EXPORT/deploy/rsync-exclude.txt" ] || die "deploy/rsync-exclude.txt fehlt im Export — origin/$BRANCH ohne 4.2?"
dirty=$(git -C "$REPO" status --porcelain 2>/dev/null | wc -l | tr -d ' ')
ok "origin/$BRANCH = $rev, $(find "$EXPORT" -type f | wc -l | tr -d ' ') Dateien$( [ "$dirty" != 0 ] && echo " (Arbeitsbaum hat $dirty fremde Änderungen — zählt nicht)")"

step "Andere Sites der Maschine (vorher)"
BEFORE=$(others); say "   $BEFORE"

step "rsync nach $DEPLOY_SSH:$DEPLOY_PATH (--delete, Excludes aus deploy/rsync-exclude.txt)"
rsync -rlptz --delete --stats --exclude-from="$EXPORT/deploy/rsync-exclude.txt" -e "ssh $SSH_OPTS" \
    "$EXPORT/" "$DEPLOY_SSH:$DEPLOY_PATH/" > "$TMPD/rsync.log" 2>&1 || { tail -n 5 "$TMPD/rsync.log"; die "rsync rot."; }
ok "übertragen: $(grep -iE 'files transferred|Number of (regular )?files transferred' "$TMPD/rsync.log" | head -n 1 | sed 's/^ *//')"

step "bootstrap.sh auf dem Server"
tls=""; ip_server="$SERVER_HOST"
case "$SERVER_HOST" in *[!0-9.]*) ip_server=$(dig +short A "$SERVER_HOST" | tail -n 1) ;; esac
dns_ok=1
for h in "$DOMAIN" "mail.$DOMAIN"; do
    ip=$(dig +short A "$h" @1.1.1.1 2>/dev/null | tail -n 1 || true)
    say "   DNS $h → ${ip:-fehlt}"
    [ "$ip" = "$ip_server" ] || dns_ok=0
done
if [ "$dns_ok" = 1 ]; then tls="--tls"; else note "A-Record fehlt oder zeigt woanders hin — bootstrap ohne --tls (Fassung http); Betreiber setzt ihn, dann --deploy erneut"; fi
# rc über „|| rc=$?“ (pipefail): PIPESTATUS würde die ERR-Falle aus lib.sh überschreiben.
rc=0
rsh "bash $DEPLOY_PATH/deploy/bootstrap.sh $tls" 2>&1 | tee "$TMPD/bootstrap.log" | sed 's/^/   | /' || rc=$?
if [ "$rc" != 0 ]; then
    if grep -q 'neu angelegt' "$TMPD/bootstrap.log" || grep -q 'unvollständig' "$TMPD/bootstrap.log"; then
        say ""
        say "Die Server-env-Datei /etc/mytannwald/env braucht Werte (Betreiber; nie in den Chat, nie ins Repo):"
        grep -E '^[A-Z_]+=' "$EXPORT/deploy/.env.example" | cut -d= -f1 | tr '\n' ' ' | sed 's/^/   Namen: /'; echo
        say "   Bedeutung je Name: deploy/.env.example. Bearbeiten: ssh -i $DEPLOY_KEY $DEPLOY_SSH nano /etc/mytannwald/env"
        die "env-Datei auf dem Server füllen, dann: tannwald-run.sh --deploy"
    fi
    die "bootstrap.sh rot (Schritt und Grund oben) — nichts weiter ausgeführt."
fi
if [ "$MODE" = bootstrap ]; then dur; say ""; say "FERTIG (bootstrap) in $(( $(date +%s) - T0 )) s — weiter: tannwald-run.sh --deploy"; exit 0; fi

step "deploy.sh auf dem Server"
rc=0
rsh "bash $DEPLOY_PATH/deploy/deploy.sh" 2>&1 | tee "$TMPD/deploy.log" | sed 's/^/   | /' || rc=$?
[ "$rc" = 0 ] || die "deploy.sh rot (Schritt und Grund oben)."

step "Proben vom Mac und Stand auf dem Server"
if [ -n "$tls" ]; then mac_probes; else note "ohne Zertifikat keine HTTPS-Proben vom Mac"; fi
state=$(rsh "bash $DEPLOY_PATH/deploy/deploy.sh --status" 2>/dev/null | grep -E 'Chunks eingebettet|Zählungen' | sed 's/^ *//' || true)
[ -n "$state" ] && printf '%s\n' "$state" | sed 's/^/   /'

step "Andere Sites der Maschine (nachher)"
AFTER=$(others); say "   $AFTER"
worse=""
for pair in $BEFORE; do
    s=${pair%%=*}; b=${pair#*=}; a=$(printf '%s\n' $AFTER | sed -n "s/^$s=//p")
    case "$b" in 2*|3*) case "$a" in 2*|3*) ;; *) worse="$worse ${s}(${b}→${a})" ;; esac ;; esac
done
[ -z "$worse" ] || die "ACHTUNG — andere Site antwortet schlechter als vorher:$worse. Sofort prüfen (nginx -t, systemctl status nginx)."
ok "andere Sites unverändert"

dur
say ""
say "FERTIG (deploy) in $(( $(date +%s) - T0 )) s — origin/$BRANCH $rev auf $DOMAIN."
say "Erwartet: https://$DOMAIN fragt nach dem Besucher-Passwort ($GUEST_USER), dahinter Login mit kunde-a/demo;"
say "  https://mail.$DOMAIN zeigt Mailpit (gleiches Passwort); Zertifikat von Let's Encrypt; Cron alle 5 min, Backup 03:30."
say "  Suche und Assistent sind vollständig, sobald tannwald_embed 0 zeigt — tannwald-run.sh --deploy --probe zeigt den Stand."
