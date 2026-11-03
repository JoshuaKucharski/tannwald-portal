# Fall 18: SITE_AUTH=on (D9 a) verlangt 401 ohne Passwort — Gegenprobe 200 ohne Passwort → Abbruch.
# SITE_AUTH=off (D9 seit 2026-11-03) verlangt 200 ohne Passwort; Mailpit bleibt in beiden Fällen geschützt;
# deploy.sh --status nennt SITE_AUTH (remote.sh liest ihn daraus).
write_env
echo 200 > "$FAKE_STATE/login_code"
run_deploy
check "SITE_AUTH=on, aber 200 ohne Passwort → Abbruch" 'rc_is 1 && has "statt 401 (SITE_AUTH=on)"'
write_env SITE_AUTH=off
run_deploy
check "SITE_AUTH=off: Login 200 ohne Passwort, Mailpit 401/200" 'rc_is 0 && has "Login-Seite 200 mit „myTannwald“ (SITE_AUTH=off)" && has "Mailpit (mail.mytannwald.getleaven.com) 401 ohne, 200"'
run_deploy --status
check "deploy.sh --status nennt SITE_AUTH=off (kein Geheimnis), keine Werte" 'rc_is 0 && has "Besucher-Passwort vor der Site: SITE_AUTH=off" && no_secrets'
# F12 (2026-11-03): Zusammenfassungen als Fassungen und Sprachfassungen (zweite Zählung ohne default_langcode).
check "--status: 22 Zusammenfassungen (31 Sprachfassungen), zweite Zählung ohne default_langcode" 'has "22 Zusammenfassungen (31 Sprachfassungen)" && grep -qF "(SELECT COUNT(*) FROM node_field_data WHERE type='"'"'zusammenfassung'"'"'), '"'"' Sprachfassungen)" "$FAKE_STATE/calls.log"'
