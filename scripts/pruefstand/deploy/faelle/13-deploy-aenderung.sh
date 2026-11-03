# Fall 13: installiert, Konfiguration geändert und Code neu → updatedb, locale, deploy, reload; kein Install/Seeder.
write_env
run_deploy
: > "$FAKE_STATE/calls.log"
echo "views.view.meine_maschinen" > "$FAKE_STATE/cfg_diff"
echo "geändert" >> "$W/app/composer.lock"
run_deploy
check "Exit 0, „aktualisiert“" 'rc_is 0 && has "FERTIG — aktualisiert"'
check "F18: Wartungsmodus an vor composer/updatedb/deploy, aus vor den Proben, Dauer genannt" 'in_order "drush state:set system.maintenance_mode 1 --input-format=integer" "drush cache:rebuild" "drush updatedb -y" "drush deploy -y" "drush state:set system.maintenance_mode 0 --input-format=integer" "drush cache:rebuild" "curl" && has "Wartungsmodus an (Code oder Konfiguration geändert)" && has "s Wartungsseite statt Fehler"'
check "Grund genannt (Konfiguration, Code)" 'has "views.view.meine_maschinen" && has "Code: geändert"'
check "updatedb → locale → deploy, danach reload php8.4-fpm" 'in_order "drush updatedb -y" "drush locale:check" "drush locale:update" "drush deploy -y" "systemctl reload php8.4-fpm"'
check "kein site:install, kein Seeder" '[ "$(calls site:install)" = 0 ] && [ "$(calls demo-install)" = 0 ]'
# F15 (2026-11-03): Ergebnisse der Deploy-Hooks sichtbar (drush_quiet verschluckte sie), höchstens 10 Zeilen, ohne Passwort-Werte.
check "Hook-Meldung sichtbar: Demo accounts: created portal-admin" 'has "Hook: [notice] Demo accounts: created portal-admin (password demo)." && has "Hook: [notice] Deploy hook started: tannwald_demo_deploy_demo_accounts"'
: > "$FAKE_STATE/hook_out"; for i in 1 2 3 4 5 6 7 8 9 10 11 12; do echo " [notice] Deploy hook started: hook_$i" >> "$FAKE_STATE/hook_out"; done
echo " [notice] Demo accounts: reset password=geheimMARKER" >> "$FAKE_STATE/hook_out"
echo "views.view.meine_maschinen" > "$FAKE_STATE/cfg_diff"
run_deploy
check "Gegenprobe: höchstens 10 Hook-Zeilen, Zeile mit password=… fällt weg" '[ "$(grep -c "^   Hook: " "$W/out")" = 10 ] && ! grep -q "geheimMARKER" "$W/out"'
# F18 Gegenprobe: Code geändert, config:status bleibt nach drush deploy nicht leer → Abbruch; die Falle schaltet den
# Wartungsmodus wieder aus (sonst stünde die Wartungsseite bis zum nächsten Lauf).
rm -f "$FAKE_STATE/hook_out"; : > "$FAKE_STATE/calls.log"
echo "noch einmal geändert" >> "$W/app/composer.lock"
echo "language.de" > "$FAKE_STATE/cfg_diff"; touch "$FAKE_STATE/cfg_stuck"
run_deploy
check "F18: Abbruch nach Wartungsmodus an → Falle schaltet ihn aus" 'rc_is 1 && has "Wartungsmodus wieder aus (Abbruch)" && in_order "drush state:set system.maintenance_mode 1 --input-format=integer" "drush state:set system.maintenance_mode 0 --input-format=integer"'
