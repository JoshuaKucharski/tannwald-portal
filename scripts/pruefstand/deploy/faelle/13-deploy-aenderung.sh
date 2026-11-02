# Fall 13: installiert, Konfiguration geändert und Code neu → updatedb, locale, deploy, reload; kein Install/Seeder.
write_env
run_deploy
: > "$FAKE_STATE/calls.log"
echo "views.view.meine_maschinen" > "$FAKE_STATE/cfg_diff"
echo "geändert" >> "$W/app/composer.lock"
run_deploy
check "Exit 0, „aktualisiert“" 'rc_is 0 && has "FERTIG — aktualisiert"'
check "Grund genannt (Konfiguration, Code)" 'has "views.view.meine_maschinen" && has "Code: geändert"'
check "updatedb → locale → deploy, danach reload php8.4-fpm" 'in_order "drush updatedb -y" "drush locale:check" "drush locale:update" "drush deploy -y" "systemctl reload php8.4-fpm"'
check "kein site:install, kein Seeder" '[ "$(calls site:install)" = 0 ] && [ "$(calls demo-install)" = 0 ]'
