# Fall 28: --probe liest nur — kein rsync, kein bootstrap/deploy (nur deploy.sh --status); Besucher-Passwort über
# stdin an curl (-K -), nie auf einer Befehlszeile.
new_repo
echo '<title>Anmelden | myTannwald</title>' > "$FAKE_STATE/site.html"
set +e
printf '%s\n' "$M_GUEST" | PATH="$FAKE_STATE/bin:$FAKES:$PATH" "$RUN_BASH" "$W/repo/deploy/remote.sh" --probe > "$W/out" 2>&1
echo $? > "$W/rc"; set -e
check "Exit 0, FERTIG (probe), nichts geändert" 'rc_is 0 && has "FERTIG (probe)" && has "nichts geändert"'
check "kein rsync, kein bootstrap.sh, kein deploy.sh ohne --status" '[ "$(grep -c "^rsync " "$FAKE_STATE/calls.log")" = 0 ] && [ "$(calls bootstrap.sh)" = 0 ] && [ "$(grep -c "deploy.sh\$" "$FAKE_STATE/calls.log")" = 0 ] && [ "$(calls "deploy.sh --status")" = 1 ]'
check "mit Passwort: Login 200 mit myTannwald, mail. 200" 'has "Login-Seite 200 mit „myTannwald“, mail.mytannwald.getleaven.com 200"'
check "Passwort nie in Ausgabe oder Befehlszeilen" '! grep -q "$M_GUEST" "$W/out" "$FAKE_STATE/calls.log" && [ "$(calls "-K -")" -ge 2 ]'
