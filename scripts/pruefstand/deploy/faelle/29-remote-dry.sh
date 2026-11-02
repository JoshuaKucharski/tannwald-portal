# Fall 29: --dry — kein SSH, kein rsync, kein git fetch; zeigt die acht Schritte und die Pläne von bootstrap/deploy.
new_repo
run_remote --dry
check "Exit 0, FERTIG (dry)" 'rc_is 0 && has "FERTIG (dry)"'
check "kein ssh, kein rsync, kein curl" '[ "$(grep -c "^ssh " "$FAKE_STATE/calls.log")" = 0 ] && [ "$(grep -c "^rsync " "$FAKE_STATE/calls.log")" = 0 ] && [ "$(grep -c "^curl " "$FAKE_STATE/calls.log")" = 0 ]'
check "Plan nennt rsync mit Exclude-Datei, bootstrap --tls, deploy.sh und deren Schritte" 'has "--exclude-from=deploy/rsync-exclude.txt" && has "bootstrap.sh --tls" && has "Plan von bootstrap.sh" && has "| == 10/10 nginx" && has "| == 7/8 Timer an"'
