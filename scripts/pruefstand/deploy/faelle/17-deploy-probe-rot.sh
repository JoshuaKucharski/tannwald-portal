# Fall 17 (Gegenprobe): Login-Seite ohne „myTannwald“ → Abbruch; HTTP 502 → Abbruch.
write_env
echo "<title>Welcome to nginx</title>" > "$FAKE_STATE/site.html"
run_deploy
check "Exit 1 ohne myTannwald" 'rc_is 1 && has "ohne „myTannwald“"'
echo '<title>myTannwald</title>' > "$FAKE_STATE/site.html"; echo 502 > "$FAKE_STATE/login_code_auth"
run_deploy
check "Exit 1 bei HTTP 502" 'rc_is 1 && has "HTTP 502"'
