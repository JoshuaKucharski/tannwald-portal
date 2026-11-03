# Fall 01: bash -n (3.2 und 5) auf allen Skripten, keine $VAR direkt vor Nicht-ASCII (bash-3.2-Falle).
for s in bootstrap.sh deploy.sh lib.sh generalprobe-mac.sh; do
    check "bash -n $s" '"$RUN_BASH" -n "$REPO/deploy/$s"'
done
check "keine \$VAR vor Nicht-ASCII" '! perl -ne "print if /\\\$[A-Za-z_]\\w*(?=[^\\x00-\\x7F])/" "$REPO"/deploy/*.sh | grep -q .'
check "Prüfstand selbst bash -n" '"$RUN_BASH" -n "$HERE/pruefstand-deploy.sh"'
