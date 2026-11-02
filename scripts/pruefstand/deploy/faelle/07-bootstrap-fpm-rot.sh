# Fall 07 (Gegenprobe): php-fpm -t rot → neuer Pool entfernt, Pool www zurück, kein reload, Abbruch.
write_env
touch "$FAKE_STATE/fpm_bad"
run_bootstrap
check "Exit 1, „php-fpm-Test rot“" 'rc_is 1 && has "php-fpm-Test rot"'
check "Pool mytannwald nicht aktiv, www.conf zurück" '[ ! -e "$W/etc/php/8.4/fpm/pool.d/mytannwald.conf" ] && [ -f "$W/etc/php/8.4/fpm/pool.d/www.conf" ]'
check "kein reload php8.4-fpm, kein MySQL-Schritt" '[ "$(calls "reload php8.4-fpm")" = 0 ] && [ "$(calls mysql-root)" = 0 ]'
