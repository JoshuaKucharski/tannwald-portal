# Fall 06 (Gegenprobe): nginx -t rot → vorheriger Vhost zurück, neuer Link weg, kein reload, Abbruch.
write_env
echo "# alter Vhost" > "$W/etc/nginx/sites-available/mytannwald.getleaven.com.conf"
touch "$FAKE_STATE/nginx_bad"
run_bootstrap
check "Exit 1, „nginx -t rot“" 'rc_is 1 && has "nginx -t rot"'
check "alter Vhost wiederhergestellt" '[ "$(cat "$W/etc/nginx/sites-available/mytannwald.getleaven.com.conf")" = "# alter Vhost" ]'
check "kein Link in sites-enabled, kein Snippet" '[ ! -e "$W/etc/nginx/sites-enabled/mytannwald.getleaven.com.conf" ] && [ ! -e "$W/etc/nginx/snippets/mytannwald-drupal.conf" ]'
check "kein reload nginx" '[ "$(calls "reload nginx")" = 0 ]'
