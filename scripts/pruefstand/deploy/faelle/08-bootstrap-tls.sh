# Fall 08: --tls prüft DNS beider Namen gegen die Adressen der Maschine, dann certbot --dry-run (Test-CA, kein
# Rate-Limit), dann certonly echt (beide Namen, deploy-hook) und schaltet auf die TLS-Fassung; zweiter Lauf ohne
# certbot.
write_env
run_bootstrap --tls
check "Exit 0" 'rc_is 0'
check "certbot erst --dry-run, dann certonly echt mit deploy-hook, nie --test-cert" 'in_order "-d mail.mytannwald.getleaven.com --dry-run" "-d mail.mytannwald.getleaven.com --deploy-hook systemctl reload nginx" && grep -q "certbot certonly --nginx --non-interactive --agree-tos --register-unsafely-without-email -d mytannwald.getleaven.com -d mail.mytannwald.getleaven.com" "$FAKE_STATE/calls.log" && ! grep -q -- "--test-cert" "$FAKE_STATE/calls.log"'
check "Vhost jetzt TLS-Fassung (ssl_certificate, 443, HSTS)" 'v="$W/etc/nginx/sites-available/mytannwald.getleaven.com.conf"; grep -q "ssl_certificate $W/etc/letsencrypt/live/mytannwald.getleaven.com/fullchain.pem" "$v" && grep -q "listen 443 ssl;" "$v" && grep -q Strict-Transport-Security "$v"'
check "nie acme.sh" '[ "$(calls acme.sh)" = 0 ]'
: > "$FAKE_STATE/calls.log"; touch "$FAKE_STATE/login_ok"
run_bootstrap --tls
check "zweiter --tls-Lauf: kein certbot (Produktion vorhanden)" 'rc_is 0 && [ "$(calls certbot)" = 0 ] && has "Zertifikat vorhanden (Produktion)"'
