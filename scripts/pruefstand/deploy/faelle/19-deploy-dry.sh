# Fall 19: deploy.sh --dry ohne env-Datei → Plan eines ersten Laufs, nichts ausgeführt, Wegwerf-Passwort maskiert.
run_deploy --dry
check "Exit 0, Trockenlauf" 'rc_is 0 && has "Trockenlauf, nichts geändert"'
check "kein drush, composer, systemctl, curl ausgeführt" '[ "$(calls drush)" = 0 ] && [ "$(calls composer)" = 0 ] && [ "$(calls systemctl)" = 0 ] && [ "$(calls curl)" = 0 ]'
check "Plan nennt site:install (maskiert), Seeder, Timer" 'has "--account-pass=<wegwerf>" && has "[dry] drush tannwald:demo-install --skip-summaries" && has "[dry] systemctl enable --now tannwald-cron.timer"'
