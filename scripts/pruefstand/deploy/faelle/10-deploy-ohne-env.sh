# Fall 10 (Gegenprobe): deploy.sh ohne env-Datei → Abbruch mit Hinweis auf bootstrap.sh, kein drush/composer.
run_deploy
check "Exit 1, Hinweis bootstrap.sh" 'rc_is 1 && has "zuerst bootstrap.sh"'
check "kein drush, kein composer" '[ "$(calls drush)" = 0 ] && [ "$(calls composer)" = 0 ]'
