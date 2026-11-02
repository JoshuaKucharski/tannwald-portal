# Fall 15 (Gegenprobe): Seeder rot beim ersten Lauf → Abbruch, Cron bleibt aus, Aufräum-Hinweis.
write_env
mkdir -p "$FAKE_STATE/active"; touch "$FAKE_STATE/active/tannwald-cron.timer"
touch "$FAKE_STATE/seed_bad"
run_deploy
check "Exit 1, „Seeder rot“, Zeilen des Seeders gezeigt" 'rc_is 1 && has "Seeder rot" && has "3 failed"'
check "Cron bleibt aus" 'has "Cron bleibt aus" && [ "$(calls "enable --now tannwald-cron.timer")" = 0 ] && [ "$(calls "start tannwald-cron.timer")" = 0 ]'
check "Aufräum-Hinweis" 'has "Aufräumen/weiter"'
