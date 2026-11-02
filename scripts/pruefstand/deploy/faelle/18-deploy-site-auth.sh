# Fall 18: SITE_AUTH=on (D9) → Login-Seite 401 hinter Basic-Auth ist grün; 200 wäre rot.
write_env SITE_AUTH=on
echo 401 > "$FAKE_STATE/login_code"
run_deploy
check "Exit 0, 401 hinter Basic-Auth" 'rc_is 0 && has "Login-Seite 401 hinter Basic-Auth"'
echo 200 > "$FAKE_STATE/login_code"
run_deploy
check "SITE_AUTH=on, aber 200 → Abbruch" 'rc_is 1 && has "statt 401"'
