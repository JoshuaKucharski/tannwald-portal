# Fall 05: zweiter Lauf ändert nichts (idempotent): keine Pakete, kein useradd, keine DB, kein reload.
write_env
run_bootstrap
: > "$FAKE_STATE/calls.log"; touch "$FAKE_STATE/login_ok"
run_bootstrap
check "Exit 0 und FERTIG" 'rc_is 0 && has "FERTIG — Server vorbereitet"'
check "kein apt-get, kein useradd, kein CREATE DATABASE" '[ "$(calls apt-get)" = 0 ] && [ "$(calls useradd)" = 0 ] && [ "$(calls "CREATE DATABASE")" = 0 ]'
check "kein reload von nginx und php-fpm" '[ "$(calls "reload nginx")" = 0 ] && [ "$(calls "reload php8.4-fpm")" = 0 ]'
check "Dateien unverändert gemeldet" 'has "mytannwald.conf unverändert" && has "nginx unverändert"'
check "kein ollama pull, kein Mailpit-Download" '[ "$(calls "ollama pull")" = 0 ] && [ "$(calls mailpit-linux)" = 0 ]'
