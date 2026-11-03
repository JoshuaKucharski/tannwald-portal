# Fall 20 (Gegenprobe): composer install rot → Abbruch vor jedem drush-Aufruf, Cron unberührt.
write_env
touch "$FAKE_STATE/composer_bad"
run_deploy
check "Exit 1, „composer install rot“ mit Ausgabe" 'rc_is 1 && has "composer install rot" && has "could not be resolved"'
check "kein drush, Cron nicht angehalten" '[ "$(calls drush)" = 0 ] && [ "$(calls "stop tannwald-cron")" = 0 ]'
