# Fall 16 (Gegenprobe): config:status bleibt nach drush deploy nicht leer → Abbruch mit Namen; Cron wieder an
# (Site war installiert).
write_env
run_deploy
: > "$FAKE_STATE/calls.log"
mkdir -p "$FAKE_STATE/active"; touch "$FAKE_STATE/active/tannwald-cron.timer"
echo "language.de" > "$FAKE_STATE/cfg_diff"; touch "$FAKE_STATE/cfg_stuck"
run_deploy
check "Exit 1, nennt language.de" 'rc_is 1 && has "config:status nicht leer nach drush deploy: language.de"'
check "Cron wieder an (Site war installiert)" '[ "$(calls "systemctl start tannwald-cron.timer")" = 1 ]'
