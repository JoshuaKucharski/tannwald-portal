# Fall 03 (Gegenprobe): env-Datei mit CHANGE_ME → Abbruch mit Namen, ohne Werte; ungültiges SITE_AUTH → Abbruch.
write_env DB_PASSWORD=CHANGE_ME ADMIN_PASSWORD=CHANGE_ME
run_bootstrap
check "Exit 1, nennt DB_PASSWORD und ADMIN_PASSWORD" 'rc_is 1 && has "DB_PASSWORD" && has "ADMIN_PASSWORD" && has "unvollständig"'
check "keine Werte in der Ausgabe" 'no_secrets'
check "nichts installiert" '[ "$(calls apt-get)" = 0 ]'
write_env SITE_AUTH=ja
run_bootstrap
check "SITE_AUTH=ja → Abbruch" 'rc_is 1 && has "SITE_AUTH muss on oder off sein"'
write_env "DB_PASSWORD=ab'c"
run_bootstrap
check "DB_PASSWORD mit Anführungszeichen → Abbruch" 'rc_is 1 && has "DB_PASSWORD: keine"'
