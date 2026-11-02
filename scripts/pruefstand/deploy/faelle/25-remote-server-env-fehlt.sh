# Fall 25 (Gegenprobe): bootstrap.sh legt die Server-env-Datei an und bricht ab → --deploy nennt die Namen und den
# Befehl zum Bearbeiten, startet deploy.sh nicht.
new_repo
printf '== 3/10 env-Datei /etc/mytannwald/env\n\nABBRUCH (bootstrap.sh, Schritt 3/10): /etc/mytannwald/env neu angelegt (…). Werte setzen\n' > "$FAKE_STATE/bootstrap.out"
echo 1 > "$FAKE_STATE/bootstrap.rc"
run_remote
check "Exit 1, Namen der Variablen und Bearbeiten-Befehl" 'rc_is 1 && has "Namen: DOMAIN" && has "GUEST_PASSWORD" && has "nano /etc/mytannwald/env"'
check "deploy.sh nicht gestartet" '[ "$(grep -c "bash /var/www/mytannwald/deploy/deploy.sh" "$FAKE_STATE/calls.log")" = 0 ]'
