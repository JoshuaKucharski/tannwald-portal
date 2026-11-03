# Fall 22: sauberer erster Lauf — SSH lesend, Export von origin/dev, andere Sites vorher, rsync mit Exclude-Datei,
# bootstrap.sh --tls (DNS stimmt), deploy.sh, Server-Stand (SITE_AUTH), Proben vom Mac, andere Sites nachher.
# Dazu SITE_AUTH=off (D9 seit 2026-11-03): Login 200 ohne Passwort erwartet, Schlusszeile „offen“; Gegenprobe 401 → Abbruch.
new_repo
run_remote
check "Exit 0, FERTIG (deploy) mit Erwartung" 'rc_is 0 && has "FERTIG (deploy)" && has "Erwartet:" && has "sobald tannwald_embed 0 zeigt"'
check "Reihenfolge: ssh lesend → curl andere Sites → rsync → bootstrap.sh --tls → deploy.sh → deploy.sh --status → curl Proben → andere Sites" 'in_order "command -v rsync" "https://firstbiodent.de/" "rsync -rlptz --delete" "bootstrap.sh --tls" "deploy.sh" "deploy.sh --status" "https://mytannwald.getleaven.com/health" "https://firstbiodent.de/"'
check "rsync mit --exclude-from und ssh-Optionen (BatchMode, ServerAliveInterval)" 'grep "^rsync " "$FAKE_STATE/calls.log" | grep -q -- "--exclude-from=" && grep "^rsync " "$FAKE_STATE/calls.log" | grep -q "BatchMode=yes" && grep "^rsync " "$FAKE_STATE/calls.log" | grep -q "ServerAliveInterval=30"'
check "Export auf dem Server angekommen (deploy/ und web/index.php)" '[ -f "$W/server/deploy/deploy.sh" ] && [ -f "$W/server/web/index.php" ]'
check "TLS-Aussteller und Proben gemeldet" 'has "Aussteller: C=US; O=Let" && has "mail.mytannwald.getleaven.com 401" && has "Chunks eingebettet 120/575"'
check "andere Sites unverändert" 'has "andere Sites unverändert"'
check "DNS über 1.1.1.1 (nie Systemresolver), Proben vom Mac fest auf 91.98.229.15 (--resolve)" '[ "$(grep -c "^dig +short A .*getleaven.com @1.1.1.1" "$FAKE_STATE/calls.log")" = 2 ] && [ "$(grep "^dig " "$FAKE_STATE/calls.log" | grep -vc "@")" = 0 ] && grep "^curl .*https://mytannwald.getleaven.com/health" "$FAKE_STATE/calls.log" | grep -q -- "--resolve mytannwald.getleaven.com:443:91.98.229.15"'
check "SITE_AUTH=on: 401 ohne Passwort, Schlusszeile fragt nach dem Besucher-Passwort" 'has "SITE_AUTH=on" && has "/user/login 401 ohne Passwort (Besucher-Passwort aktiv)" && has "fragt nach dem Besucher-Passwort (gast)"'
echo off > "$FAKE_STATE/site_auth"; echo 200 > "$FAKE_STATE/login_code"
echo '<title>Anmelden | myTannwald</title>' > "$FAKE_STATE/site.html"
run_remote
check "SITE_AUTH=off: 200 mit myTannwald ohne Passwort, Schlusszeile „offen“, Mailpit hinter gast" 'rc_is 0 && has "/user/login 200 ohne Passwort mit „myTannwald“ (Site offen)" && has "ist offen (SITE_AUTH=off, D9)" && has "Mailpit hinter dem Besucher-Passwort (gast)" && ! has "fragt nach dem Besucher-Passwort"'
echo 401 > "$FAKE_STATE/login_code"
run_remote
check "Gegenprobe SITE_AUTH=off, aber 401 ohne Passwort → Abbruch" 'rc_is 1 && has "statt 200 (SITE_AUTH=off: Site offen erwartet)"'
