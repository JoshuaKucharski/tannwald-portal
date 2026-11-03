# Fall 21: --deploy --init legt die Mac-Konfiguration an (600, nur Host/Pfade, keine Passwörter); ein zweites --init
# überschreibt nichts.
new_repo; rm -f "$W/deploy.env"
run_remote --init
check "Exit 0, Datei angelegt, Modus 600" 'rc_is 0 && [ -f "$W/deploy.env" ] && [ -z "$(find "$W/deploy.env" -perm -040)" ]'
check "nur Ziel, Schlüsselpfad, Domain — kein Passwort-Name" 'grep -q "^DEPLOY_SSH=root@91.98.229.15" "$W/deploy.env" && grep -q "^DEPLOY_KEY=~/.ssh/hetzner" "$W/deploy.env" && ! grep -qE "^[A-Z_]*(PASS|SECRET|TOKEN)" "$W/deploy.env"'
echo "# eigene Zeile" >> "$W/deploy.env"
run_remote --init
check "zweites --init: unverändert" 'rc_is 0 && has "vorhanden" && grep -q "# eigene Zeile" "$W/deploy.env"'
rm -f "$W/deploy.env"
run_remote --dry
check "fehlende Konfiguration: --deploy legt sie mit Standardwerten an und läuft weiter" 'rc_is 0 && has "fehlte — mit Standardwerten angelegt" && has "FERTIG (dry)" && grep -q "^DEPLOY_SSH=root@91.98.229.15" "$W/deploy.env"'
