# Fall 18: SITE_AUTH=on (Standard, D9 a) verlangt 401 ohne Passwort — Gegenprobe 200 ohne Passwort → Abbruch.
# SITE_AUTH=off verlangt 200 ohne Passwort; Mailpit bleibt in beiden Fällen geschützt.
write_env
echo 200 > "$FAKE_STATE/login_code"
run_deploy
check "SITE_AUTH=on, aber 200 ohne Passwort → Abbruch" 'rc_is 1 && has "statt 401 (SITE_AUTH=on)"'
write_env SITE_AUTH=off
run_deploy
check "SITE_AUTH=off: Login 200 ohne Passwort, Mailpit 401/200" 'rc_is 0 && has "Login-Seite 200 mit „myTannwald“ (SITE_AUTH=off)" && has "Mailpit (mail.mytannwald.getleaven.com) 401 ohne, 200"'
