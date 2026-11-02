# Fall 11: erster Lauf — Reihenfolge, Opt-in TANNWALD_DEMO nur beim Seeder, Passwort nur aus der Umgebung.
write_env
mkdir -p "$FAKE_STATE/active"; touch "$FAKE_STATE/active/tannwald-cron.timer"
run_deploy
check "Exit 0, „erstinstalliert“ mit Zählungen" 'rc_is 0 && has "FERTIG — erstinstalliert" && has "575 Chunks"'
check "Reihenfolge composer → si → php:eval → updatedb → locale → deploy → Seeder → Timer → Probe" 'in_order "composer --working-dir" "systemctl stop tannwald-cron.timer" "drush status" "drush site:install --existing-config" "drush php:eval" "drush updatedb -y" "drush locale:check" "drush locale:update" "drush deploy -y" "drush config:status" "drush tannwald:demo-install --skip-summaries" "systemctl enable --now tannwald-cron.timer" "curl"'
check "composer --no-dev --optimize-autoloader als tannwald" 'grep -q "composer --working-dir=$W/app install --no-dev --optimize-autoloader" "$FAKE_STATE/calls.log" && [ "$(calls "sudo -u tannwald")" -ge 1 ]'
check "admin-Passwort aus der Umgebung gesetzt" '[ "$(cat "$FAKE_STATE/admin_pw_from_env" 2>/dev/null)" = set ]'
check "keine Geheimnisse in Ausgabe und Befehlszeilen" 'no_secrets'
check "Proben: Login 200 mit myTannwald, Mailpit 401" 'has "Login-Seite 200" && has "Mailpit (mail.mytannwald.getleaven.com) 401"'
check "Fingerabdruck geschrieben" '[ -s "$W/state/deploy.fingerprint" ]'
check "php-Shim zeigt auf php8.4" '[ "$(readlink "$W/state/bin/php")" = "$W/usr/bin/php8.4" ]'
