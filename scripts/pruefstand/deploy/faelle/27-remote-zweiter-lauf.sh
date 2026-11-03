# Fall 27: zweiter Lauf — rsync überträgt keine Datei, deploy.sh meldet „nichts zu tun“, FERTIG.
new_repo
run_remote
: > "$FAKE_STATE/calls.log"; rm -f "$FAKE_STATE/after_deploy"
echo "FERTIG — nichts zu tun, Proben grün, in 6 s." > "$FAKE_STATE/deploy.out"
run_remote
check "Exit 0, „nichts zu tun“ durchgereicht" 'rc_is 0 && has "nichts zu tun" && has "FERTIG (deploy)"'
check "rsync: 0 Dateien übertragen" 'has "übertragen:" && grep "übertragen:" "$W/out" | grep -qE ": 0$|transferred: 0"'
