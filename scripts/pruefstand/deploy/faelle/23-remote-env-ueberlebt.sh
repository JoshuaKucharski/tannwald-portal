# Fall 23: rsync --delete schont, was nur auf dem Server lebt (.env, vendor/, files/, web/core/), und löscht
# veraltete Code-Dateien.
new_repo
mkdir -p "$W/server/vendor/x" "$W/server/web/sites/default/files" "$W/server/web/core" "$W/server/deploy"
echo geheim > "$W/server/.env"; echo v > "$W/server/vendor/x/a.php"; echo bild > "$W/server/web/sites/default/files/a.webp"
echo core > "$W/server/web/core/c.php"; echo alt > "$W/server/veraltet.php"; echo alt > "$W/server/deploy/alt.sh"
run_remote
check "Exit 0" 'rc_is 0'
check ".env, vendor/, files/, web/core/ überleben" '[ -f "$W/server/.env" ] && [ -f "$W/server/vendor/x/a.php" ] && [ -f "$W/server/web/sites/default/files/a.webp" ] && [ -f "$W/server/web/core/c.php" ]'
check "veraltete Dateien gelöscht" '[ ! -e "$W/server/veraltet.php" ] && [ ! -e "$W/server/deploy/alt.sh" ]'
