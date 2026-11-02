# Fall 26 (Gegenprobe): bootstrap.sh meldet „nginx -t rot“ → --deploy bricht ab, kein deploy.sh, andere Sites
# werden nicht nachgemessen (nichts geändert).
new_repo
printf 'ABBRUCH (bootstrap.sh, Schritt 10/10): nginx -t rot — nichts aktiviert, vorherige Dateien zurück\n' > "$FAKE_STATE/bootstrap.out"
echo 1 > "$FAKE_STATE/bootstrap.rc"
run_remote
check "Exit 1, bootstrap.sh rot, Grund sichtbar" 'rc_is 1 && has "nginx -t rot" && has "bootstrap.sh rot"'
check "kein deploy.sh" '[ "$(grep -c "bash /var/www/mytannwald/deploy/deploy.sh" "$FAKE_STATE/calls.log")" = 0 ]'
