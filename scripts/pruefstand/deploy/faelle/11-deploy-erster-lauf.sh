# Fall 11: erster Lauf — Reihenfolge, Opt-in TANNWALD_DEMO nur beim Seeder (--skip-queues), Passwörter nur aus der
# Umgebung bzw. netrc, beide Timer und das erste Backup, Proben mit und ohne Besucher-Passwort.
write_env
mkdir -p "$FAKE_STATE/active"; touch "$FAKE_STATE/active/tannwald-cron.timer"
run_deploy
check "Exit 0, „erstinstalliert“, Chunks-Stand und Hinweis auf den Cron" 'rc_is 0 && has "FERTIG — erstinstalliert" && has "Chunks eingebettet 0/575" && has "sobald tannwald_embed 0 zeigt"'
check "Reihenfolge chown → composer → si → php:eval → updatedb → locale → deploy → Seeder → Timer → Probe" 'in_order "chown -R tannwald:tannwald" "composer --working-dir" "systemctl stop tannwald-cron.timer" "drush status" "drush site:install --existing-config" "drush php:eval" "drush updatedb -y" "drush locale:check" "drush locale:update" "drush deploy -y" "drush config:status" "drush tannwald:demo-install --skip-queues" "systemctl enable --now tannwald-cron.timer tannwald-backup.timer" "curl"'
check "Seeder mit --skip-queues (Embeddings eingereiht, nicht verarbeitet)" '[ "$(cat "$FAKE_STATE/seed_mode" 2>/dev/null)" = skip-queues ]'
check "erstes Backup ausgelöst" '[ "$(calls "systemctl start tannwald-backup.service")" = 1 ]'
check "composer --no-dev --optimize-autoloader als tannwald" 'grep -q "composer --working-dir=$W/app install --no-dev --optimize-autoloader" "$FAKE_STATE/calls.log" && [ "$(calls "sudo -u tannwald")" -ge 1 ]'
check "admin-Passwort aus der Umgebung gesetzt" '[ "$(cat "$FAKE_STATE/admin_pw_from_env" 2>/dev/null)" = set ]'
check "keine Geheimnisse in Ausgabe und Befehlszeilen (auch nicht das Besucher-Passwort)" 'no_secrets'
check "Proben: /health, robots, Login 401/200, Mailpit 401/200, Passwort nur per netrc" 'has "/health 200" && has "Login-Seite 401 ohne, 200 mit Besucher-Passwort" && has "401 ohne, 200 mit Besucher-Passwort; X-Robots-Tag" && [ "$(calls "--netrc-file")" -ge 2 ]'
check "Fingerabdruck geschrieben" '[ -s "$W/state/deploy.fingerprint" ]'
check "php-Shim zeigt auf php8.4" '[ "$(readlink "$W/state/bin/php")" = "$W/usr/bin/php8.4" ]'
