# Fall 24 (Gegenprobe): lokales dev hat einen ungepushten Commit → Abbruch „erst … pushen“, kein rsync.
new_repo
(cd "$W/repo" && echo neu > neu.txt && git add neu.txt && git -c user.name=p -c user.email=p@x commit -qm neu)
run_remote
check "Exit 1, „nicht gepusht“" 'rc_is 1 && has "nicht gepusht" && has "erst committen und pushen"'
check "kein rsync, kein bootstrap" '[ "$(grep -c "^rsync " "$FAKE_STATE/calls.log")" = 0 ] && [ "$(calls bootstrap.sh)" = 0 ]'
(cd "$W/repo" && git push -q origin dev 2>/dev/null)
echo dreckig > "$W/repo/fremd.txt"
run_remote
check "fremde Änderung im Arbeitsbaum zählt nicht (Export), fremd.txt nicht übertragen" 'rc_is 0 && has "fremde Änderungen" && [ ! -e "$W/server/fremd.txt" ]'
