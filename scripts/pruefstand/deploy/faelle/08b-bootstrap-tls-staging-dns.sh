# Fall 08b: CERT_STAGING=on → nur Test-Zertifikat (--test-cert, kein --dry-run); danach off → --dry-run und echtes
# mit --force-renewal. Gegenprobe: A-Record von mail. zeigt woanders hin → Abbruch vor jedem certbot-Aufruf,
# Fassung http bleibt aktiv.
write_env CERT_STAGING=on
run_bootstrap --tls
check "Staging: certonly --test-cert, kein --dry-run" 'rc_is 0 && grep "certbot certonly" "$FAKE_STATE/calls.log" | grep -q -- "--test-cert" && ! grep -q -- "--dry-run" "$FAKE_STATE/calls.log"'
write_env CERT_STAGING=off
touch "$FAKE_STATE/login_ok"
run_bootstrap --tls
check "off nach Staging: echt mit --force-renewal, ohne --test-cert" 'rc_is 0 && grep "certbot certonly" "$FAKE_STATE/calls.log" | tail -n 1 | grep -q -- "--force-renewal" && ! grep "certbot certonly" "$FAKE_STATE/calls.log" | tail -n 1 | grep -q -- "--test-cert"'
new_world; write_env
echo 203.0.113.7 > "$FAKE_STATE/dns_mail.mytannwald.getleaven.com"
run_bootstrap --tls
check "Gegenprobe DNS: Abbruch mit Hinweis, kein certbot, Fassung http aktiv" 'rc_is 1 && has "A-Record für mail.mytannwald.getleaven.com zeigt nicht auf diese Maschine" && [ "$(calls certbot)" = 0 ] && grep -q "listen 80;" "$W/etc/nginx/sites-available/mytannwald.getleaven.com.conf"'
