# Fall 02 (Gegenprobe): frische Maschine ohne env-Datei → legt sie aus .env.example an (640) und bricht ab,
# bevor Pakete, Pool, MySQL oder nginx angefasst werden.
run_bootstrap
check "Exit 1" 'rc_is 1'
check "Hinweis „neu angelegt … Werte setzen“" 'has "neu angelegt" && has "Werte setzen"'
check "env-Datei aus der Vorlage, Modus 640" '[ -f "$W/etc/mytannwald/env" ] && [ -z "$(find "$W/etc/mytannwald/env" -perm -004)" ] && grep -q "^DOMAIN=" "$W/etc/mytannwald/env"'
check "kein apt-get, kein nginx, kein mysql" '[ "$(calls apt-get)" = 0 ] && [ "$(calls nginx)" = 0 ] && [ "$(calls mysql-root)" = 0 ]'
check "kein FERTIG" '! has FERTIG'
check "DB_PASSWORD und DRUPAL_HASH_SALT zufällig gesetzt, nie gezeigt; Restliste genannt" 'grep -qE "^DB_PASSWORD=[0-9a-f]{48}$" "$W/etc/mytannwald/env" && grep -qE "^DRUPAL_HASH_SALT=[0-9a-f]{64}$" "$W/etc/mytannwald/env" && has "Noch zu setzen: " && has "GUEST_PASSWORD" && has "ADMIN_PASSWORD" && ! grep -qF "$(sed -n "s/^DB_PASSWORD=//p" "$W/etc/mytannwald/env")" "$W/out" "$FAKE_STATE/calls.log"'
