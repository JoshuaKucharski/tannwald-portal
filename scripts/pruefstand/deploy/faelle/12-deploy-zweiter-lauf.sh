# Fall 12: zweiter Lauf ohne Änderung → „nichts zu tun“: kein Install, kein deploy, kein Seeder; Cron wieder an.
write_env
run_deploy
: > "$FAKE_STATE/calls.log"
run_deploy
check "Exit 0, „nichts zu tun“" 'rc_is 0 && has "FERTIG — nichts zu tun"'
check "kein site:install, kein drush deploy, kein Seeder, kein locale" '[ "$(calls site:install)" = 0 ] && [ "$(calls "drush deploy")" = 0 ] && [ "$(calls demo-install)" = 0 ] && [ "$(calls locale:)" = 0 ]'
check "Cron angehalten und wieder an" 'in_order "systemctl stop tannwald-cron.timer" "systemctl enable --now tannwald-cron.timer"'
check "F18: „nichts zu tun“ ohne Wartungsmodus" '[ "$(calls maintenance_mode)" = 0 ]'
check "kein reload php8.4-fpm (Code gleich)" '[ "$(calls "reload php8.4-fpm")" = 0 ]'
