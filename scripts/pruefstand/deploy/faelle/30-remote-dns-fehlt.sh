# Fall 30: A-Record für mail. fehlt → bootstrap.sh ohne --tls (Fassung http) mit Hinweis; keine HTTPS-Proben.
new_repo
echo - > "$FAKE_STATE/dns_mail.mytannwald.getleaven.com"
run_remote
check "Exit 0, Hinweis A-Record" 'rc_is 0 && has "A-Record fehlt oder zeigt woanders hin"'
check "bootstrap.sh ohne --tls" '[ "$(calls "bootstrap.sh --tls")" = 0 ] && [ "$(calls "bootstrap.sh")" = 1 ]'
check "keine HTTPS-Proben der Demo" '[ "$(calls "https://mytannwald.getleaven.com/health")" = 0 ] && has "ohne Zertifikat keine HTTPS-Proben"'
