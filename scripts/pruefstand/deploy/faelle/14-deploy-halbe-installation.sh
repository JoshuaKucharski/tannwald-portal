# Fall 14 (Gegenprobe): Tabellen da, Drupal startet nicht → Abbruch mit Aufräum-Befehl, nichts überschrieben.
write_env
echo 17 > "$FAKE_STATE/tables"
run_deploy
check "Exit 1, „halbe Installation“ mit DROP-DATABASE-Hinweis" 'rc_is 1 && has "halbe Installation" && has "DROP DATABASE"'
check "kein site:install" '[ "$(calls site:install)" = 0 ]'
