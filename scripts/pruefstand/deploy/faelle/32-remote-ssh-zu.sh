# Fall 32 (Gegenprobe): SSH abgelehnt → Abbruch ohne Wiederholung (fail2ban), kein git/rsync.
new_repo
touch "$FAKE_STATE/ssh_down"
run_remote
check "Exit 1, „SSH … nicht möglich“, Hinweis fail2ban" 'rc_is 1 && has "nicht möglich" && has "fail2ban"'
check "genau ein SSH-Versuch, kein rsync" '[ "$(grep -c "^ssh " "$FAKE_STATE/calls.log")" = 1 ] && [ "$(grep -c "^rsync " "$FAKE_STATE/calls.log")" = 0 ]'
