# Fall 08: --tls holt zuerst ein Staging-Zertifikat (certonly --nginx, beide Namen, deploy-hook) und schaltet auf
# die TLS-Fassung; mit CERT_STAGING=off ersetzt ein weiterer Lauf das Staging- durch ein echtes Zertifikat.
write_env
run_bootstrap --tls
check "Exit 0" 'rc_is 0'
check "certbot certonly --nginx --test-cert mit beiden Namen und deploy-hook" 'grep -q "certbot certonly --nginx --non-interactive --agree-tos --register-unsafely-without-email --test-cert -d mytannwald.getleaven.com -d mail.mytannwald.getleaven.com --deploy-hook systemctl reload nginx" "$FAKE_STATE/calls.log"'
check "Vhost jetzt TLS-Fassung (ssl_certificate, 443, HSTS)" 'v="$W/etc/nginx/sites-available/mytannwald.getleaven.com.conf"; grep -q "ssl_certificate $W/etc/letsencrypt/live/mytannwald.getleaven.com/fullchain.pem" "$v" && grep -q "listen 443 ssl;" "$v" && grep -q Strict-Transport-Security "$v"'
check "nie acme.sh" '[ "$(calls acme.sh)" = 0 ]'
: > "$FAKE_STATE/calls.log"; touch "$FAKE_STATE/login_ok"
run_bootstrap --tls
check "zweiter --tls-Lauf: kein certbot (Staging vorhanden)" 'rc_is 0 && [ "$(calls certbot)" = 0 ] && has "Zertifikat vorhanden (Staging)"'
write_env CERT_STAGING=off
run_bootstrap --tls
check "CERT_STAGING=off: certbot ohne --test-cert, mit --force-renewal" 'grep "certbot certonly" "$FAKE_STATE/calls.log" | tail -n 1 | grep -q -- "--force-renewal" && ! grep "certbot certonly" "$FAKE_STATE/calls.log" | tail -n 1 | grep -q -- "--test-cert"'
