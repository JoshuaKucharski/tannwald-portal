# Fall 31 (Gegenprobe): eine andere Site antwortet nach dem Lauf schlechter (200 → 502) → lauter Abbruch.
new_repo
echo 200 > "$FAKE_STATE/site_firstbiodent.de"; echo 502 > "$FAKE_STATE/site_firstbiodent.de.after"
run_remote
check "Exit 1, ACHTUNG mit Site und Codes" 'rc_is 1 && has "ACHTUNG" && has "firstbiodent.de(200→502)"'
