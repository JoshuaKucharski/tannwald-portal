# Fall 22: sauberer erster Lauf — SSH lesend, Export von origin/dev, andere Sites vorher, rsync mit Exclude-Datei,
# bootstrap.sh --tls (DNS stimmt), deploy.sh, Proben vom Mac, Server-Stand, andere Sites nachher.
new_repo
run_remote
check "Exit 0, FERTIG (deploy) mit Erwartung" 'rc_is 0 && has "FERTIG (deploy)" && has "Erwartet:" && has "sobald tannwald_embed 0 zeigt"'
check "Reihenfolge: ssh lesend → curl andere Sites → rsync → bootstrap.sh --tls → deploy.sh → curl Proben → deploy.sh --status" 'in_order "command -v rsync" "https://firstbiodent.de/" "rsync -rlptz --delete" "bootstrap.sh --tls" "deploy.sh" "https://mytannwald.getleaven.com/health" "deploy.sh --status" "https://firstbiodent.de/"'
check "rsync mit --exclude-from und ssh-Optionen (BatchMode, ServerAliveInterval)" 'grep "^rsync " "$FAKE_STATE/calls.log" | grep -q -- "--exclude-from=" && grep "^rsync " "$FAKE_STATE/calls.log" | grep -q "BatchMode=yes" && grep "^rsync " "$FAKE_STATE/calls.log" | grep -q "ServerAliveInterval=30"'
check "Export auf dem Server angekommen (deploy/ und web/index.php)" '[ -f "$W/server/deploy/deploy.sh" ] && [ -f "$W/server/web/index.php" ]'
check "TLS-Aussteller und Proben gemeldet" 'has "Aussteller: C=US; O=Let" && has "mail.mytannwald.getleaven.com 401" && has "Chunks eingebettet 120/575"'
check "andere Sites unverändert" 'has "andere Sites unverändert"'
