# Fall 09: --dry ändert nichts — keine Datei unter /etc, kein Paket, kein Dienst; druckt jeden Schritt.
run_bootstrap --dry
check "Exit 0, Trockenlauf-Ende" 'rc_is 0 && has "Trockenlauf, nichts geändert"'
check "keine Dateien geschrieben" '[ ! -e "$W/etc/mytannwald" ] && [ ! -e "$W/etc/php" ] && [ -z "$(ls "$W/etc/nginx/sites-available")" ] && [ ! -e "$W/etc/systemd" ]'
check "nichts ausgeführt (kein apt-get, systemctl, useradd, mysql)" '[ "$(calls apt-get)" = 0 ] && [ "$(calls systemctl)" = 0 ] && [ "$(calls useradd)" = 0 ] && [ "$(calls mysql)" = 0 ]'
check "alle zehn Schritte gedruckt" 'has "== 10/10 nginx" && has "[dry] schreibe"'
