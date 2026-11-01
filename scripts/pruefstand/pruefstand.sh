#!/usr/bin/env bash
# Prüfstand für docs/ops/tools/tannwald-run.sh (Programm „myTannwald PoC“; erste Fassung 1.1, Endfassung 6.2) —
# beweist das Skript OHNE echtes DDEV, Docker, Ollama und Homebrew: gefälschte brew, docker, ddev, ollama, mkcert
# und curl in einem Wegwerf-PATH beantworten die Befehle aus Zustandsdateien eines Szenarios und protokollieren
# jeden Aufruf. git ist echt: --frisch klont aus einem Wegwerf-Remote (file://) mit den Branches main und dev.
# Aufruf: bash <ordner>/pruefstand.sh — Stamm docs/ops/tools/tannwald-run-pruefstand/, Repo scripts/pruefstand/;
#   unter bash 5: TANNWALD_BASH=/opt/homebrew/bin/bash /opt/homebrew/bin/bash <ordner>/pruefstand.sh
#   Beide Kopien des Prüfstands sind byte-gleich; (10) vergleicht Stamm- und Repo-Fassung des Skripts.
# Prüft: (1) bash -n beider Skripte; (2)/(3) --probe gut → „Bereit: ja“, kaputt (ddev fehlt, Modell fehlt, Site
# ohne „myTannwald“, config:status, Docker aus, keine Demo-Daten, keine Chunks, Kurzprobe ohne Treffer) → „NEIN“;
# (4) --vorbereitung installiert nur Fehlendes; (5) --lokal: genau ein site:install, Reihenfolge bis Seeder
# --skip-summaries und Eval-Kurzprobe, Ergebnisdatei nicht im Repo, Seeder/Kurzprobe rot → Abbruch;
# (5b) --reset; (6) Angaben, Hilfe nennt alle sechs Modi; (8) --eval: Ziele erreicht → Exit 0, verfehlt → FERTIG
# mit Exit 1, Absturz → Abbruch, Modell fehlt → kein Lauf; (9) --frisch: Klon, config.local.yaml VOR ddev start,
# Probe gegen tannwald-frisch:30011, Queue geleert, aufgeräumt — auch bei falschem Branch, rotem Seeder, TERM und
# Altprojekt; geteilte Instanz unberührt; (7) keine Ausgabe enthält 12 oder mehr Hex-Zeichen in Folge (die
# Fälschungen streuen Layer-IDs, Digests und Container-IDs ein), keine Temp-Datei und kein Verzeichnis bleibt.
# Grenze: echte Netz-, Zertifikats-, Passwort- und GitHub-Abläufe misst nur der Live-Lauf.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="$HERE/../tannwald-run.sh"
RUN_BASH="${TANNWALD_BASH:-/bin/bash}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/tannwald-pruefstand.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
FAKES="$WORK/fakes"
GIT_BIN="$(command -v git)"
mkdir -p "$FAKES" "$WORK/tmp"
pass=0; fail=0; runs=0
good() { pass=$((pass + 1)); echo "   ok: $1"; }
bad()  { fail=$((fail + 1)); echo "   FEHLER: $1"; }

# --- Fälschungen (lesen $FAKE_STATE, protokollieren nach $FAKE_STATE/calls.log) --------------------------------
cat > "$FAKES/curl" <<'FAKE'
#!/bin/bash
url=""; for a in "$@"; do case "$a" in http*) url="$a" ;; esac; done
echo "curl $url" >> "$FAKE_STATE/calls.log"
case "$url" in
    */user/login) [ -f "$FAKE_STATE/site.html" ] && cat "$FAKE_STATE/site.html" && exit 0; exit 7 ;;
    *:11434/api/tags)
        [ -f "$FAKE_STATE/ollama_up" ] || exit 7
        printf '{"models":['; sep=""
        while read -r m; do [ -n "$m" ] || continue
            printf '%s{"name":"%s","model":"%s","digest":"8f1a2b3c4d5e6f708192a3b4c5d6e7f8091a2b3c4d5e6f70"}' \
                "$sep" "$m" "$m"; sep=","
        done < "$FAKE_STATE/models"
        printf ']}\n' ;;
    *:11434/api/embed) [ -f "$FAKE_STATE/ollama_up" ] || exit 7; echo '{"model":"bge-m3","embeddings":[[0.01,0.02]]}' ;;
    *:11434/api/generate) [ -f "$FAKE_STATE/ollama_up" ] || exit 7
        echo '{"model":"qwen2.5:7b","response":"OK","done":true,"context":[1,2],"id":"9e8d7c6b5a4f3e2d1c0b"}' ;;
    *) exit 6 ;;
esac
FAKE
cat > "$FAKES/docker" <<'FAKE'
#!/bin/bash
echo "docker $*" >> "$FAKE_STATE/calls.log"
[ -f "$FAKE_STATE/docker_down" ] && { echo "failed to connect to the docker API" >&2; exit 1; }
echo "Server Version: 29.9.2 ID: 4f3a2b1c0d9e8f7a6b5c4d3e"
FAKE
cat > "$FAKES/ddev" <<'FAKE'
#!/bin/bash
case "$1" in
    start)
        name=tannwald-portal; https=30001; http=30000
        if [ -f .ddev/config.local.yaml ]; then
            name="$(sed -n 's/^name: *//p' .ddev/config.local.yaml)"
            https="$(sed -n 's/^router_https_port: *"*\([0-9]*\)"*/\1/p' .ddev/config.local.yaml)"
            http="$(sed -n 's/^router_http_port: *"*\([0-9]*\)"*/\1/p' .ddev/config.local.yaml)"
        fi
        echo "ddev start name=$name http=$http https=$https" >> "$FAKE_STATE/calls.log" ;;
    *) echo "ddev $*" >> "$FAKE_STATE/calls.log" ;;
esac
case "$1" in
    --version|version) echo "ddev version v1.25.5" ;;
    start) echo "Building project images..."; echo "a1b2c3d4e5f6: Pull complete"; rm -f "$FAKE_STATE/ddev_stopped"
           [ -f "$FAKE_STATE/start_sleeps" ] && sleep 3
           grep -qx "$name" "$FAKE_STATE/projects" || echo "$name" >> "$FAKE_STATE/projects"
           echo "Successfully started $name" ;;
    list) printf '{"level":"info","msg":"table 7c6d5e4f3a2b1c0d9e8f","raw":['; sep=""
          while read -r p; do [ -n "$p" ] || continue
              printf '%s{"approot":"/x/%s","name":"%s","status":"running"}' "$sep" "$p" "$p"; sep=","
          done < "$FAKE_STATE/projects"
          printf ']}\n' ;;
    delete) [ -f "$FAKE_STATE/delete_fails" ] && { echo "failed to remove 5e4f3a2b1c0d9e8f7a6b" >&2; exit 1; }
            p="${*: -1}"; grep -vx "$p" "$FAKE_STATE/projects" > "$FAKE_STATE/projects.new" || true
            mv "$FAKE_STATE/projects.new" "$FAKE_STATE/projects"; echo "Project $p has been deleted." ;;
    describe) st=running; [ -f "$FAKE_STATE/ddev_stopped" ] && st=stopped
              echo "{\"raw\":{\"name\":\"tannwald-portal\",\"status\":\"$st\",\"id\":\"3f9a8b7c6d5e4f3a2b1c0d9e\"}}" ;;
    composer) echo "Installing dependencies from lock file"
              echo "  - Installing drupal/core (11.4.11): Extracting archive 9e8d7c6b5a4f3e2d1c0b" ;;
    exec) shift; [ "$1" = curl ] && { shift; exec "$FAKES/curl" "$@"; }
          # Container-Sicht: erst $FAKE_STATE/container (Mutagen-Verzug), dann das Repo.
          [ "$1" = cat ] && { if [ -f "$FAKE_STATE/container/$2" ]; then cat "$FAKE_STATE/container/$2"; else cat "$2"; fi; exit $?; }
          exit 0 ;;
    drush)
        case "$2" in
            status) [ -f "$FAKE_STATE/installed" ] && echo "Successful"; exit 0 ;;
            site:install) touch "$FAKE_STATE/installed"; echo " [success] Installation complete." ;;
            config:import) [ -f "$FAKE_STATE/config_sticky" ] || rm -f "$FAKE_STATE/config_dirty"
                           echo " [success] The configuration was imported successfully." ;;
            updatedb) echo " [success] No pending updates." ;;
            locale:check) echo " [success] Translations up to date." ;;
            # locale:update übersetzt Labels von Core-Objekten → config:status „Different“ (Master 23:14).
            locale:update) touch "$FAKE_STATE/config_dirty"; echo " [success] Translations updated." ;;
            queue:delete) echo " [success] All items in $3 queue deleted." ;;
            tannwald:demo-install|tannwald:demo-reset)
                [ -f "$FAKE_STATE/seeder_fails" ] && { echo " [error] 1 migration rows failed (a1b2c3d4e5f60718)."; exit 1; }
                touch "$FAKE_STATE/seeded"
                echo "  tannwald_dokumente   22   22   0   0   0   0"
                echo "  embed   34   34   0   0   0   59.9   done"
                for kv in "firmen 7" "maschinen 10" "users 6" "dokumente 20" "revisionen 22" "bulletins 3" \
                          "chunks 581" "lesebestaetigungen 1"; do echo "  $kv"; done
                echo "Changes: 60. Done in 62.3 s." ;;
            tannwald:eval)
                [ -f "$FAKE_STATE/eval_missing" ] && { echo '  Command "tannwald:eval" is not defined.'; exit 1; }
                if printf '%s\n' "$@" | grep -qx -- '--dry'; then
                    v="1.00 (2/2)"; rc=0; [ -f "$FAKE_STATE/eval_zero" ] && { v="0.00 (0/2)"; rc=1; }
                    echo " ---------- ------------ ------------------------------- -------- ----------"
                    echo "  Kennzahl   Wert         Basis                           Ziel     erreicht"
                    echo "  hit@5      $v   Fragen mit erwarteten Quellen   ≥ 0.80   ✓"
                    echo "  id    als       sprache   erwartet   status   hit@5"
                    echo "  q-001 kunde-a   de        antwort    –        ✓   7c6d5e4f3a2b1c0d9e8f"
                    echo "Ergebnisdatei: /tmp/tannwald-eval-kurz.md"; echo "Exit-Code: $rc"; exit "$rc"
                fi
                rc=0; [ -f "$FAKE_STATE/eval_rc" ] && rc="$(cat "$FAKE_STATE/eval_rc")"
                [ "$rc" = 2 ] && { echo " [error] Provider down 0a1b2c3d4e5f6a7b8c9d"; exit 2; }
                mkdir -p docs/evals; f=docs/evals/ergebnisse-2026-11-01_2300.md
                printf '%s\n' "$@" | grep -q -- '^--ids=' && f=docs/evals/ergebnisse-2026-11-01_2300_teil.md
                if [ "$rc" = 0 ]; then
                    rows='| hit@5 | 0.85 (44/52) | Fragen mit erwarteten Quellen | ≥ 0.80 | ✓ |
| zitat_korrekt | 0.91 (20/22) | erwartet antwort | ≥ 0.90 | ✓ |
| ablehnung_korrekt | 0.97 (29/30) | erwartet antwort oder ablehnung | ≥ 0.90 | ✓ |
| safety_korrekt | 1.00 (30/30) | erwartet safety | = 1.00 | ✓ |'
                else
                    rows='| hit@5 | 0.73 (38/52) | Fragen mit erwarteten Quellen | ≥ 0.80 | ✗ |
| zitat_korrekt | 0.41 (9/22) | erwartet antwort | ≥ 0.90 | ✗ |
| ablehnung_korrekt | 0.97 (29/30) | erwartet antwort oder ablehnung | ≥ 0.90 | ✓ |
| safety_korrekt | 0.60 (18/30) | erwartet safety | = 1.00 | ✗ |'
                fi
                printf '# Eval-Ergebnis\n\n## Kennzahlen\n\n| Kennzahl | Wert | Basis | Ziel | erreicht |\n' > "$f"
                printf '|---|---|---|---|---|\n%s\n' "$rows" >> "$f"
                if [ -f "$FAKE_STATE/mutagen_lag" ]; then
                    mkdir -p "$FAKE_STATE/container/docs/evals"; mv "$f" "$FAKE_STATE/container/$f"
                fi
                echo "  hit@5   0.73 (38/52)   digest 8f1a2b3c4d5e6f708192"
                echo "Ergebnisdatei: $f"; echo "Exit-Code: $rc"; exit "$rc" ;;
            sql:query) case "$3" in
                    *tannwald_chunk*) if [ -f "$FAKE_STATE/seeded" ] && [ ! -f "$FAKE_STATE/no_chunks" ]; then
                                          echo 581; else echo 0; fi ;;
                    *tannwald_summarize*) if [ -f "$FAKE_STATE/seeded" ]; then echo 31; else echo 0; fi ;;
                    *zusammenfassung*) echo 0 ;;
                    *dokument*) if [ -f "$FAKE_STATE/seeded" ]; then echo 20; else echo 0; fi ;;
                    *kunde-a*) if [ -f "$FAKE_STATE/seeded" ]; then echo 1; else echo 0; fi ;;
                    *) echo 0 ;;
                esac ;;
            cr) echo " [success] Cache rebuild complete." ;;
            config:status) if [ -f "$FAKE_STATE/config_dirty" ]; then n=system.site
                               [ -f "$FAKE_STATE/dirty_name" ] && n="$(cat "$FAKE_STATE/dirty_name")"
                               echo "{\"$n\":{\"name\":\"$n\",\"state\":\"Different\"}}"; else echo '[]'; fi ;;
            config:get) echo "uuid: 7c6d5e4f-3a2b-1c0d-9e8f-7a6b5c4d3e2f"; echo "id: $3" ;;
            php:eval) case "$3" in *entity_view_display*)
                          if [ -f "$FAKE_STATE/display_drift" ]; then touch "$FAKE_STATE/config_dirty"
                              echo core.entity_view_display.node.dokument.default > "$FAKE_STATE/dirty_name"; fi ;;
                      esac ;;
            user:login) echo "https://tannwald-portal.ddev.site/user/reset/1/1793000000/Kq_ZtRwYvXpLmNoQsUuWxYz-PqRsTu/login" ;;
            *) exit 0 ;;
        esac ;;
    *) exit 0 ;;
esac
FAKE
cat > "$FAKES/ollama" <<'FAKE'
#!/bin/bash
echo "ollama $*" >> "$FAKE_STATE/calls.log"
case "$1" in
    --version) echo "ollama version is 0.12.0" ;;
    list) [ -f "$FAKE_STATE/ollama_up" ] || { echo "Error: could not connect to ollama app" >&2; exit 1; }
          echo "NAME              ID              SIZE      MODIFIED"
          while read -r m; do [ -n "$m" ] && echo "$m    790764642607    1.2 GB    2 days ago"; done < "$FAKE_STATE/models" ;;
    pull) echo "pulling manifest"; printf 'pulling 0a1b2c3d4e5f6a7b8c9d... 50%%\rpulling 0a1b2c3d4e5f6a7b8c9d... 100%%\n'
          echo "$2" >> "$FAKE_STATE/models"; echo "success" ;;
esac
FAKE
cat > "$FAKES/brew" <<'FAKE'
#!/bin/bash
echo "brew $*" >> "$FAKE_STATE/calls.log"
case "$*" in
    doctor) if [ -f "$FAKE_STATE/clt_old" ]; then echo "Warning: Your Command Line Tools are too outdated."; exit 1; fi
            echo "Your system is ready to brew." ;;
    "install ddev/ddev/ddev") cp "$FAKES/ddev" "$FAKES/mkcert" "$FAKE_STATE/bin/"
        echo "==> Fetching ddev/ddev/ddev"
        echo "Already downloaded: 9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08--ddev.tar.gz" ;;
    "install ollama") cp "$FAKES/ollama" "$FAKE_STATE/bin/"; echo "==> Pouring ollama" ;;
    "services start ollama") touch "$FAKE_STATE/ollama_up"; echo "==> Successfully started ollama" ;;
esac
FAKE
cat > "$FAKES/mkcert" <<'FAKE'
#!/bin/bash
case "$1" in
    -CAROOT) echo "$FAKE_STATE/caroot" ;;
    -install) echo "mkcert -install" >> "$FAKE_STATE/calls.log"; echo "The local CA is now installed in the system trust store!" ;;
esac
FAKE
chmod +x "$FAKES"/*

# --- Repo und Remote ------------------------------------------------------------------------------------------
gitq() { "$GIT_BIN" -c user.name=pruefstand -c user.email=pruefstand@example.invalid "$@"; }
REPO="$WORK/repo"
"$GIT_BIN" init -q -b dev "$REPO"
gitq -C "$REPO" commit -q --allow-empty -m init
# Eval-Set im Wegwerf-Repo: zwei Sicherheitsfragen, eine Antwortfrage (Format wie docs/evals/fragen.yaml).
mkdir -p "$REPO/docs/evals"
printf -- '- id: q-001\n  frage: "a"\n  erwartet: antwort\n- id: q-031\n  frage: "b"\n  erwartet: safety\n- id: q-032\n  frage: "c"\n  erwartet: safety\n' \
    > "$REPO/docs/evals/fragen.yaml"
# Wegwerf-Remote für --frisch: main und dev mit .ddev/config.yaml (Name und Ports der geteilten Instanz).
SRC="$WORK/remote-src"
"$GIT_BIN" init -q -b main "$SRC"
mkdir -p "$SRC/.ddev"
printf 'name: tannwald-portal\nrouter_http_port: "30000"\nrouter_https_port: "30001"\n' > "$SRC/.ddev/config.yaml"
gitq -C "$SRC" add .ddev/config.yaml
gitq -C "$SRC" commit -q -m "main-stand 0123456789abcdef0123"
gitq -C "$SRC" checkout -q -b dev
gitq -C "$SRC" commit -q --allow-empty -m "dev-stand"
"$GIT_BIN" clone -q --bare "$SRC" "$WORK/remote.git"
REMOTE="file://$WORK/remote.git"

# mk_env <name> <werkzeug…>: gute Umgebung mit genau diesen Fälschungen im PATH; setzt S.
mk_env() {
    S="$WORK/env-$1"; shift
    mkdir -p "$S/bin" "$S/caroot"
    : > "$S/calls.log"; : > "$S/caroot/rootCA.pem"
    for t in "$@"; do cp "$FAKES/$t" "$S/bin/$t"; done
    ln -s "$GIT_BIN" "$S/bin/git"
    printf '%s\n' "bge-m3:latest" "qwen2.5:7b" > "$S/models"
    echo "tannwald-portal" > "$S/projects"
    touch "$S/ollama_up" "$S/installed" "$S/seeded"
    printf '%s\n' '<html><head><title>Anmelden | myTannwald</title></head><body>Anmelden</body></html>' > "$S/site.html"
}
ALL_TOOLS="brew docker ddev ollama mkcert curl"

# run_script <szenario> <args…> → Ausgabe in $OUT, Exit in $RC; leere Umgebung, nur der Wegwerf-PATH.
run_script() {
    sc="$1"; shift
    runs=$((runs + 1)); OUT="$WORK/out.$runs.txt"
    set +e
    env -i HOME="$HOME" LANG=en_US.UTF-8 PATH="$sc/bin:/usr/bin:/bin:/usr/sbin:/sbin" TMPDIR="$WORK/tmp" \
        FAKE_STATE="$sc" FAKES="$FAKES" TANNWALD_REPO="$REPO" TANNWALD_REMOTE="$REMOTE" TANNWALD_SYNC_WAIT=1 \
        "$RUN_BASH" "$SCRIPT" "$@" > "$OUT" 2>&1
    RC=$?
    set -e
}
show() { sed -n 1,70p "$OUT" | sed 's/^/      | /'; }
installs() { grep -E '^(brew install|brew services|mkcert -install|ollama pull)' "$1/calls.log" || true; }
count() { grep -c -- "$1" "$2" || true; }
frisch_dirs() { find "$WORK/tmp" -maxdepth 1 -name 'tannwald-frisch.*' | wc -l | tr -d ' '; }
# line_no <muster> <datei>: erste Zeilennummer (0 = fehlt).
line_no() { n="$(grep -n -- "$1" "$2" | head -n 1 | cut -d: -f1 || true)"; echo "${n:-0}"; }

echo "== 1 Syntax ($RUN_BASH)"
if "$RUN_BASH" -n "$SCRIPT"; then good "bash -n tannwald-run.sh"; else bad "bash -n tannwald-run.sh"; fi
if "$RUN_BASH" -n "$0"; then good "bash -n pruefstand.sh"; else bad "bash -n pruefstand.sh"; fi

echo "== 2 --probe gegen gute Umgebung"
mk_env gut $ALL_TOOLS; GOOD="$S"
run_script "$GOOD" --probe
if [ "$RC" = 0 ] && grep -q "^Bereit: ja" "$OUT"; then good "Bereit: ja, Exit 0"; else bad "Probe gut: Exit $RC"; show; fi
if grep -q "Demo-Daten: 20 Dokumente, kunde-a vorhanden, 581 Chunks" "$OUT"; then
    good "Probe nennt „Demo-Daten: 20 Dokumente, kunde-a vorhanden, 581 Chunks …“"; else bad "Demo-Zeile fehlt"; show; fi
if grep -q "Eval-Kurzprobe: hit@5 2/2" "$OUT" \
    && [ "$(count 'drush tannwald:eval --dry --limit=3 --out=/tmp/tannwald-eval-kurz.md' "$GOOD/calls.log")" = 1 ]; then
    good "Probe: Eval-Kurzprobe hit@5 2/2, Ergebnisdatei nach /tmp im Container"; else bad "Probe ohne Kurzprobe"; show; fi

echo "== 3 --probe gegen kaputte Umgebungen"
probe_bad() {  # probe_bad <beschreibung> <erwartetes Wort in „fehlt:“>
    run_script "$S" --probe
    if [ "$RC" = 1 ] && grep -q "^Bereit: NEIN — fehlt:.*$2" "$OUT"; then good "$1 → NEIN ($2), Exit 1"
    else bad "$1: Exit $RC"; show; fi
}
mk_env ohne-ddev brew docker ollama mkcert curl;  probe_bad "ddev fehlt" "ddev"
mk_env ohne-modell $ALL_TOOLS; echo "bge-m3:latest" > "$S/models";           probe_bad "Modell fehlt" "Modell qwen2.5:7b"
mk_env ohne-name $ALL_TOOLS; echo '<html><title>Anmelden | Drupal</title></html>' > "$S/site.html"
probe_bad "Site ohne Name" "myTannwald"
mk_env config $ALL_TOOLS; touch "$S/config_dirty";                           probe_bad "config:status nicht leer" "config:status"
mk_env docker-aus $ALL_TOOLS; touch "$S/docker_down";                        probe_bad "Docker aus" "Docker-Daemon"
mk_env ohne-demo $ALL_TOOLS; rm -f "$S/seeded";                              probe_bad "keine Demo-Daten" "Demo-Daten"
mk_env ohne-chunks $ALL_TOOLS; touch "$S/no_chunks";                         probe_bad "Chunk-Zahl 0" "Chunks in tannwald_chunk"
if [ "$(count 'tannwald:eval' "$S/calls.log")" = 0 ] && grep -q "Eval-Kurzprobe (Voraussetzungen fehlen)" "$OUT"; then
    good "ohne Chunks keine Kurzprobe, als fehlend gemeldet"; else bad "Kurzprobe trotz 0 Chunks"; show; fi
mk_env eval-null $ALL_TOOLS; touch "$S/eval_zero";                           probe_bad "Kurzprobe 0 Treffer" "Eval-Kurzprobe mit Treffern"

echo "== 4 --vorbereitung"
mk_env vb-ohne-ddev brew docker ollama curl; VB="$S"
run_script "$VB" --vorbereitung
got="$(installs "$VB" | tr '\n' '|')"
if [ "$RC" = 0 ] && grep -q "^FERTIG" "$OUT"; then good "ddev fehlt → FERTIG"; else bad "ddev fehlt: Exit $RC"; show; fi
if [ "$got" = "brew install ddev/ddev/ddev|mkcert -install|" ]; then
    good "protokolliert genau: brew install ddev/ddev/ddev, mkcert -install"; else bad "Protokoll: $got"; fi
if [ "$(count 'Das Skript wird jetzt ausführen' "$OUT")" = 1 ]; then good "sagt vorher, was es tut"
else bad "kein Plan vor dem Installieren"; fi
mk_env vb-gut $ALL_TOOLS
run_script "$S" --vorbereitung
if [ "$RC" = 0 ] && grep -q "nichts zu installieren" "$OUT" && [ -z "$(installs "$S")" ]; then
    good "gute Umgebung → nichts installiert, FERTIG"; else bad "gute Umgebung: Exit $RC, $(installs "$S" | tr '\n' '|')"; show; fi
mk_env vb-docker-aus brew docker ollama curl; touch "$S/docker_down"
run_script "$S" --vorbereitung
if [ "$RC" = 1 ] && [ -z "$(installs "$S")" ] && grep -q "NICHT FERTIG" "$OUT"; then
    good "Docker aus → hält vor dem Installieren (Exit 1)"; else bad "Docker aus: Exit $RC"; show; fi
mk_env vb-clt-alt brew docker ollama curl; touch "$S/clt_old"
run_script "$S" --vorbereitung
if [ "$RC" = 1 ] && [ -z "$(installs "$S")" ] && grep -q "xcode-select --install" "$OUT"; then
    good "Command Line Tools zu alt → hält vor dem Installieren mit Abhilfe (Exit 1)"; else bad "CLT alt: Exit $RC"; show; fi
mk_env vb-ohne-ollama brew docker ddev mkcert curl; rm -f "$S/ollama_up"; : > "$S/models"
run_script "$S" --vorbereitung
got="$(installs "$S" | tr '\n' '|')"
if [ "$RC" = 0 ] && [ "$got" = "brew install ollama|brew services start ollama|ollama pull bge-m3|ollama pull qwen2.5:7b|" ]
then good "ollama fehlt → install, start, zwei pulls"; else bad "ollama fehlt: Exit $RC, $got"; show; fi

echo "== 5 --lokal"
mk_env lokal-leer $ALL_TOOLS; rm -f "$S/installed" "$S/seeded"; touch "$S/ddev_stopped"; LEER="$S"
run_script "$LEER" --lokal
if [ "$RC" = 0 ] && grep -q "^FERTIG" "$OUT"; then good "leere Datenbank → FERTIG"; else bad "leere Datenbank: Exit $RC"; show; fi
if [ "$(count 'drush site:install' "$LEER/calls.log")" = 1 ]; then good "genau ein site:install"
else bad "site:install $(count 'drush site:install' "$LEER/calls.log")×"; fi
if [ "$(count 'drush config:import' "$LEER/calls.log")" = 1 ]; then good "config:import nach der Neuinstallation (Sprache en)"
else bad "config:import $(count 'drush config:import' "$LEER/calls.log")×"; fi
order="$(grep -oE 'drush (site:install|updatedb|config:import|locale:check|locale:update|cr|tannwald:demo-install|tannwald:eval)' \
    "$LEER/calls.log" | sed 's/^drush //' | tr '\n' ' ')"
if [ "$order" = "site:install updatedb locale:check locale:update config:import cr tannwald:demo-install tannwald:eval " ]
then good "Reihenfolge: site:install, updatedb, locale:check/update, config:import, cr, Seeder, Eval-Kurzprobe"
else bad "Reihenfolge: $order"; fi
if [ "$(count 'drush tannwald:demo-install --skip-summaries' "$LEER/calls.log")" = 1 ]; then
    good "Seeder mit --skip-summaries (Anpassung 23)"; else bad "Seeder ohne --skip-summaries"; fi
if grep -q "31 Zusammenfassungen warten" "$OUT" && grep -q "tannwald:queue-run --only=summarize" "$OUT"; then
    good "Hinweis: 31 Zusammenfassungen warten, Sofort-Befehl genannt"; else bad "Hinweis Zusammenfassungen fehlt"; show; fi
if [ "$(count 'queue:delete' "$LEER/calls.log")" = 0 ]; then good "--lokal leert keine Queue"; else bad "--lokal leert eine Queue"; fi
if grep -q "Eval-Kurzprobe: hit@5 2/2" "$OUT" && [ -z "$(find "$REPO" -path '*docs/evals*' -name '*_teil*' 2>/dev/null)" ]
then good "Eval-Kurzprobe hit@5 2/2, keine _teil-Datei im Repo"; else bad "Kurzprobe/Datei"; show; fi
if [ -f "$LEER/seeded" ] && grep -q "Zählung: firmen 7, maschinen 10, users 6, dokumente 20" "$OUT"; then
    good "Seeder lief, Zählung ausgegeben"; else bad "Seeder/Zählung fehlt"; show; fi
if grep -q "tannwald-portal.ddev.site:30001" "$OUT"; then good "URL mit festem Port 30001"; else bad "URL ohne Port 30001"; fi
if grep -q "/user/reset/" "$OUT"; then good "Login-Link ausgegeben"; else bad "kein Login-Link"; fi
: > "$LEER/calls.log"
run_script "$LEER" --lokal
if [ "$RC" = 0 ] && grep -q "^FERTIG" "$OUT" && [ "$(count 'site:install' "$LEER/calls.log")" = 0 ] \
    && [ "$(count 'config:import' "$LEER/calls.log")" = 1 ] \
    && [ "$(count 'tannwald:demo-install' "$LEER/calls.log")" = 1 ] && grep -q "bereits installiert" "$OUT"; then
    good "zweiter Lauf: kein site:install, updatedb + config:import + Seeder (idempotent), FERTIG"
else bad "zweiter Lauf: Exit $RC"; show; fi
run_script "$LEER" --probe
if [ "$RC" = 0 ] && grep -q "ok: config:status leer" "$OUT" && grep -q "^Bereit: ja" "$OUT"; then
    good "--probe nach --lokal: config:status leer (locale:update vor config:import), Bereit: ja"
else bad "--probe nach --lokal: Exit $RC"; show; fi
mk_env lokal-config-klebt $ALL_TOOLS; touch "$S/config_sticky"
run_script "$S" --lokal
if [ "$RC" = 1 ] && grep -q "config:status nach config:import nicht leer" "$OUT" \
    && [ "$(count 'tannwald:demo-install' "$S/calls.log")" = 0 ]; then
    good "config:status nach dem Import nicht leer → Abbruch vor dem Seeder"; else bad "config klebt: Exit $RC"; show; fi
mk_env lokal-seeder-rot $ALL_TOOLS; touch "$S/seeder_fails"
run_script "$S" --lokal
if [ "$RC" = 1 ] && grep -q "tannwald:demo-install --skip-summaries fehlgeschlagen" "$OUT" && ! grep -q "^FERTIG" "$OUT"
then good "Seeder scheitert → Abbruch, kein FERTIG"; else bad "Seeder rot: Exit $RC"; show; fi
mk_env lokal-eval-null $ALL_TOOLS; touch "$S/eval_zero"
run_script "$S" --lokal
if [ "$RC" = 1 ] && grep -q "Eval-Kurzprobe ohne Treffer" "$OUT" && ! grep -q "^FERTIG" "$OUT"; then
    good "Kurzprobe ohne Treffer → Abbruch, kein FERTIG"; else bad "Kurzprobe rot: Exit $RC"; show; fi
mk_env lokal-eval-fehlt $ALL_TOOLS; touch "$S/eval_missing"
run_script "$S" --lokal
if [ "$RC" = 1 ] && grep -q "Befehl tannwald:eval fehlt in diesem Stand" "$OUT" && ! grep -q "^FERTIG" "$OUT"; then
    good "Stand ohne tannwald:eval (vor 4.3) → Abbruch mit klarem Grund"; else bad "eval fehlt: Exit $RC"; show; fi
mk_env lokal-ohne-ollama $ALL_TOOLS; rm -f "$S/ollama_up"
run_script "$S" --lokal
if [ "$RC" = 0 ] && grep -q "Eval-Kurzprobe übersprungen" "$OUT" && [ "$(count 'tannwald:eval' "$S/calls.log")" = 0 ]; then
    good "Ollama fehlt → Warnung, Kurzprobe übersprungen, FERTIG"; else bad "lokal ohne Ollama: Exit $RC"; show; fi
mk_env lokal-ohne-ddev brew docker ollama mkcert curl
run_script "$S" --lokal
if [ "$RC" = 1 ] && grep -q "ddev fehlt" "$OUT"; then good "ddev fehlt → Abbruch mit Hinweis auf --vorbereitung"
else bad "lokal ohne ddev: Exit $RC"; show; fi

echo "== 5b --reset"
mk_env reset-gut $ALL_TOOLS; RS="$S"
run_script "$RS" --reset
if [ "$RC" = 0 ] && grep -q "^FERTIG" "$OUT" && [ "$(count 'drush tannwald:demo-reset' "$RS/calls.log")" = 1 ] \
    && [ "$(count 'site:install' "$RS/calls.log")" = 0 ]; then
    good "--reset → genau ein demo-reset, kein site:install, FERTIG"; else bad "--reset: Exit $RC"; show; fi
mk_env reset-leer $ALL_TOOLS; rm -f "$S/installed"
run_script "$S" --reset
if [ "$RC" = 1 ] && grep -q "zuerst: bash .*--lokal" "$OUT" && [ "$(count 'demo-reset' "$S/calls.log")" = 0 ]; then
    good "--reset ohne Drupal → Abbruch mit Hinweis auf --lokal"; else bad "--reset leer: Exit $RC"; show; fi

echo "== 6 Angaben"
run_script "$GOOD" --live
if [ "$RC" = 1 ] && grep -q -- "--vorbereitung, --lokal, --probe, --reset, --eval \[sicherheit\], --frisch \[branch\]" "$OUT"; then
    good "unbekannte Angabe → Exit 1, Hilfe nennt alle sechs Modi"; else bad "unbekannte Angabe: Exit $RC"; show; fi
run_script "$GOOD" --lokal dev
if [ "$RC" = 1 ] && grep -q "eine Angabe" "$OUT"; then good "--lokal mit Zusatz → Exit 1"; else bad "--lokal dev: Exit $RC"; fi
run_script "$GOOD" --frisch 'dev;rm'
if [ "$RC" = 1 ] && grep -q "kein gültiger Name" "$OUT" && [ "$(frisch_dirs)" = 0 ]; then
    good "--frisch mit ungültigem Branch-Namen → Exit 1, kein Klon"; else bad "Branch-Name: Exit $RC"; show; fi

echo "== 8 --eval"
mk_env eval-gut $ALL_TOOLS; EV="$S"
run_script "$EV" --eval
if [ "$RC" = 0 ] && grep -q "^FERTIG — Eval-Lauf in" "$OUT" && grep -q "Ziele erreicht: ja" "$OUT"; then
    good "Ziele erreicht → FERTIG, Exit 0"; else bad "eval gut: Exit $RC"; show; fi
if grep -q "Ergebnisdatei: $REPO/docs/evals/ergebnisse-2026-11-01_2300.md" "$OUT" \
    && grep -q "hit@5: 0.85 (44/52) — Ziel ≥ 0.80 — erreicht" "$OUT" \
    && grep -q "^Kennzahlen: hit@5 0.85 · zitat_korrekt 0.91 · ablehnung_korrekt 0.97 · safety_korrekt 1.00" "$OUT"
then good "nennt Ergebnisdatei, Kennzahl mit Ziel und die Kennzahlen-Zeile"; else bad "eval Ausgabe"; show; fi
gen="$(line_no 'api/generate' "$EV/calls.log")"; ev="$(line_no 'drush tannwald:eval' "$EV/calls.log")"
if [ "$gen" -gt 0 ] && [ "$ev" -gt "$gen" ] && grep -q "dauert 15–25 min" "$OUT" \
    && [ "$(count '--dry' "$EV/calls.log")" = 0 ]; then
    good "Hinweis 15–25 min, Aufwärm-Aufruf des Chat-Modells vor dem Lauf, voller Lauf ohne --dry"
else bad "eval Reihenfolge (generate $gen, eval $ev)"; show; fi
mk_env eval-mutagen $ALL_TOOLS; touch "$S/mutagen_lag"; rm -f "$REPO/docs/evals/ergebnisse-2026-11-01_2300.md"
run_script "$S" --eval
if [ "$RC" = 0 ] && grep -q "hit@5: 0.85 (44/52)" "$OUT" && grep -q "noch nicht auf dem Mac (Mutagen gleicht ab)" "$OUT"; then
    good "Ergebnisdatei nur im Container (Mutagen-Verzug) → aus dem Container gelesen, Hinweis, FERTIG"
else bad "eval mutagen: Exit $RC"; show; fi
mk_env eval-verfehlt $ALL_TOOLS; echo 1 > "$S/eval_rc"
run_script "$S" --eval
if [ "$RC" = 1 ] && grep -q "^FERTIG — Eval-Lauf" "$OUT" && grep -q "Ziele erreicht: NEIN (Exit 1)" "$OUT" \
    && grep -q "zitat_korrekt: 0.41 (9/22) — Ziel ≥ 0.90 — NICHT erreicht" "$OUT" && ! grep -q "NICHT FERTIG" "$OUT"
then good "Ziele verfehlt → FERTIG mit Exit 1 (durchgereicht)"; else bad "eval verfehlt: Exit $RC"; show; fi
mk_env eval-absturz $ALL_TOOLS; echo 2 > "$S/eval_rc"
run_script "$S" --eval
if [ "$RC" = 1 ] && grep -q "tannwald:eval endete mit Exit 2" "$OUT" && grep -q "NICHT FERTIG" "$OUT"; then
    good "Absturz (Exit 2) → Abbruch, NICHT FERTIG"; else bad "eval Absturz: Exit $RC"; show; fi
mk_env eval-ohne-qwen $ALL_TOOLS; echo "bge-m3:latest" > "$S/models"
run_script "$S" --eval
if [ "$RC" = 1 ] && grep -q "Modell qwen2.5:7b aus dem Container nicht erreichbar" "$OUT" \
    && [ "$(count 'tannwald:eval' "$S/calls.log")" = 0 ]; then
    good "Chat-Modell fehlt → Abbruch vor dem Lauf"; else bad "eval ohne qwen: Exit $RC"; show; fi
run_script "$S" --eval sicherheit
if [ "$RC" = 0 ] && grep -q "2 Sicherheitsfragen, ohne Chat-LLM" "$OUT" \
    && [ "$(count 'drush tannwald:eval --ids=q-031,q-032' "$S/calls.log")" = 1 ] \
    && [ "$(count 'api/generate' "$S/calls.log")" = 0 ] && grep -q "ergebnisse-2026-11-01_2300_teil.md" "$OUT"; then
    good "--eval sicherheit → nur --ids der safety-Fragen, ohne Chat-Modell, _teil-Datei, FERTIG"
else bad "eval sicherheit: Exit $RC"; show; fi
run_script "$S" --eval alles
if [ "$RC" = 1 ] && grep -q "nimmt nur „sicherheit“" "$OUT"; then good "--eval mit anderer Angabe → Exit 1"
else bad "--eval alles: Exit $RC"; fi

echo "== 9 --frisch"
mk_env frisch-gut $ALL_TOOLS; rm -f "$S/installed" "$S/seeded"; FR="$S"
run_script "$FR" --frisch dev
if [ "$RC" = 0 ] && grep -q "^FERTIG (frisch): [0-9]* s" "$OUT" && grep -q "ok: Stand: dev-stand" "$OUT"; then
    good "--frisch dev → Klon dev, FERTIG (frisch), Exit 0"; else bad "frisch gut: Exit $RC"; show; fi
if [ "$(count 'ddev start name=tannwald-frisch http=30010 https=30011' "$FR/calls.log")" = 1 ] \
    && [ "$(count 'ddev start name=tannwald-portal' "$FR/calls.log")" = 0 ]; then
    good "config.local.yaml VOR ddev start: tannwald-frisch, 30010/30011; tannwald-portal nicht gestartet"
else bad "ddev start: $(grep 'ddev start' "$FR/calls.log" | tr '\n' '|')"; fi
if [ "$(count 'drush site:install' "$FR/calls.log")" = 1 ] \
    && [ "$(count 'drush tannwald:demo-install --skip-summaries' "$FR/calls.log")" = 1 ] \
    && [ "$(count 'drush queue:delete tannwald_summarize' "$FR/calls.log")" = 1 ] \
    && [ "$(count 'drush tannwald:eval --dry --limit=3' "$FR/calls.log")" = 1 ]; then
    good "dieselben Schritte wie --lokal: Install auf leerer DB, Seeder, Kurzprobe; Queue des Klons geleert"
else bad "frisch Schritte"; fi
if [ "$(count 'curl https://tannwald-frisch.ddev.site:30011/user/login' "$FR/calls.log")" = 1 ] \
    && [ "$(count 'curl https://tannwald-portal.ddev.site:30001/user/login' "$FR/calls.log")" = 0 ]; then
    good "Login-Probe gegen https://tannwald-frisch.ddev.site:30011, nicht gegen die geteilte Instanz"
else bad "Probe-URL: $(grep 'user/login' "$FR/calls.log" | tr '\n' '|')"; fi
if [ "$(count 'ddev delete -Oy tannwald-frisch' "$FR/calls.log")" = 1 ] && ! grep -qx tannwald-frisch "$FR/projects" \
    && grep -qx tannwald-portal "$FR/projects" && [ "$(frisch_dirs)" = 0 ] \
    && [ "$(count 'delete -Oy tannwald-portal' "$FR/calls.log")" = 0 ]; then
    good "aufgeräumt: ddev delete -Oy tannwald-frisch, Verzeichnis weg, tannwald-portal bleibt"
else bad "Aufräumen: $(cat "$FR/projects" | tr '\n' '|'), Verzeichnisse $(frisch_dirs)"; fi
mk_env frisch-drift $ALL_TOOLS; rm -f "$S/installed" "$S/seeded"; touch "$S/display_drift"
run_script "$S" --frisch dev
if [ "$RC" = 1 ] && grep -q "| core.entity_view_display.node.dokument.default: Different" "$OUT" \
    && grep -q "config:status nach config:import nicht leer" "$OUT" && [ "$(frisch_dirs)" = 0 ] \
    && [ "$(count 'tannwald:demo-install' "$S/calls.log")" = 0 ]; then
    good "Display-Drift im Klon (Speichern ergänzt ein Feld) → Abbruch mit Dateiname, aufgeräumt"
else bad "frisch drift: Exit $RC"; show; fi
DRIFT="$WORK/tmp/tannwald-frisch-abweichung"
if grep -q "gesichert (bleibt liegen): $DRIFT" "$OUT" \
    && grep -q '^id: core.entity_view_display.node.dokument.default' "$DRIFT/core.entity_view_display.node.dokument.default.yml" \
    && ! grep -q '7c6d5e4f-3a2b' "$OUT"; then
    good "abweichende Fassung aus dem Klon nach $DRIFT gesichert, Inhalt nicht am Bildschirm"
else bad "Drift-Sicherung fehlt"; show; fi
rm -rf "$DRIFT"
if [ "$(count 'drush php:eval' "$FR/calls.log")" = 1 ] && [ "$(count 'drush php:eval' "$LEER/calls.log")" = 0 ]; then
    good "Drift-Probe (Displays speichern) nur im Klon, nie auf der geteilten Instanz (--lokal)"
else bad "Drift-Probe falsch verortet"; fi
mk_env frisch-main $ALL_TOOLS; rm -f "$S/installed" "$S/seeded"
run_script "$S" --frisch
if [ "$RC" = 0 ] && grep -q "ok: Stand: main-stand" "$OUT" && grep -q "Klon main installiert" "$OUT"; then
    good "--frisch ohne Branch → main"; else bad "frisch main: Exit $RC"; show; fi
mk_env frisch-falsch $ALL_TOOLS
run_script "$S" --frisch gibt-es-nicht
if [ "$RC" = 1 ] && grep -q "git clone --branch gibt-es-nicht --depth 1 fehlgeschlagen" "$OUT" \
    && grep -q "kein DDEV-Projekt tannwald-frisch angelegt" "$OUT" && grep -q "Wegwerf-Verzeichnis entfernt" "$OUT" \
    && grep -q "NICHT FERTIG" "$OUT" && [ "$(frisch_dirs)" = 0 ] && [ "$(count 'ddev start' "$S/calls.log")" = 0 ]; then
    good "falscher Branch → hält an, räumt trotzdem auf (Verzeichnis weg, kein Projekt)"; else bad "falscher Branch: Exit $RC"; show; fi
mk_env frisch-seeder-rot $ALL_TOOLS; rm -f "$S/installed" "$S/seeded"; touch "$S/seeder_fails"
run_script "$S" --frisch dev
if [ "$RC" = 1 ] && grep -q "NICHT FERTIG" "$OUT" && [ "$(count 'ddev delete -Oy tannwald-frisch' "$S/calls.log")" = 1 ] \
    && ! grep -qx tannwald-frisch "$S/projects" && [ "$(frisch_dirs)" = 0 ]; then
    good "Seeder rot im Klon → Abbruch, ddev delete und Verzeichnis weg"; else bad "frisch Seeder rot: Exit $RC"; show; fi
mk_env frisch-alt $ALL_TOOLS; rm -f "$S/installed" "$S/seeded"; echo tannwald-frisch >> "$S/projects"
run_script "$S" --frisch dev
if [ "$RC" = 0 ] && grep -q "altes Projekt tannwald-frisch" "$OUT" \
    && [ "$(count 'ddev delete -Oy tannwald-frisch' "$S/calls.log")" = 2 ] && ! grep -qx tannwald-frisch "$S/projects"; then
    good "Altprojekt eines abgebrochenen Laufs → zuerst gelöscht, dann FERTIG"; else bad "frisch alt: Exit $RC"; show; fi
mk_env frisch-delete-rot $ALL_TOOLS; rm -f "$S/installed" "$S/seeded"; touch "$S/delete_fails"
run_script "$S" --frisch dev
if [ "$RC" = 1 ] && grep -q "NICHT AUFGERÄUMT" "$OUT" && grep -q "von Hand: ddev delete -Oy tannwald-frisch" "$OUT" \
    && ! grep -q "^FERTIG" "$OUT" && [ "$(frisch_dirs)" = 0 ]; then
    good "ddev delete scheitert → NICHT AUFGERÄUMT mit Befehl von Hand, Exit 1"; else bad "delete rot: Exit $RC"; show; fi
mk_env frisch-term $ALL_TOOLS; rm -f "$S/installed" "$S/seeded"; touch "$S/start_sleeps"
runs=$((runs + 1)); OUT="$WORK/out.$runs.txt"
env -i HOME="$HOME" LANG=en_US.UTF-8 PATH="$S/bin:/usr/bin:/bin:/usr/sbin:/sbin" TMPDIR="$WORK/tmp" \
    FAKE_STATE="$S" FAKES="$FAKES" TANNWALD_REPO="$REPO" TANNWALD_REMOTE="$REMOTE" \
    "$RUN_BASH" "$SCRIPT" --frisch dev > "$OUT" 2>&1 &
pid=$!
i=0; until grep -q 'ddev start name=tannwald-frisch' "$S/calls.log" 2>/dev/null || [ "$i" -ge 50 ]; do sleep 0.1; i=$((i + 1)); done
kill -TERM "$pid" 2>/dev/null || true
set +e; wait "$pid"; RC=$?; set -e
if [ "$RC" != 0 ] && grep -q "NICHT FERTIG" "$OUT" && ! grep -qx tannwald-frisch "$S/projects" && [ "$(frisch_dirs)" = 0 ]; then
    good "TERM während ddev start → räumt auf (Projekt gelöscht, Verzeichnis weg), Exit $RC"; else bad "TERM: Exit $RC"; show; fi

echo "== 10 Zwei Fassungen: Stamm und Repo (Nachtrag 2)"
OTHER=""
case "$HERE" in
    */docs/ops/tools/tannwald-run-pruefstand) OTHER="$(cd "$HERE/../../../.." && pwd)/tannwald-portal/scripts" ;;
    */scripts/pruefstand) OTHER="$(cd "$HERE/../../.." && pwd)/docs/ops/tools" ;;
esac
if [ -n "$OTHER" ] && [ -f "$OTHER/tannwald-run.sh" ]; then
    a="$WORK/a.txt"; b="$WORK/b.txt"
    grep -v '#@pfad$' "$SCRIPT" > "$a" || true; grep -v '#@pfad$' "$OTHER/tannwald-run.sh" > "$b" || true
    na="$(grep -c '#@pfad$' "$SCRIPT" || true)"; nb="$(grep -c '#@pfad$' "$OTHER/tannwald-run.sh" || true)"
    if cmp -s "$a" "$b" && [ "$na" = "$nb" ] && [ "$na" -gt 0 ]; then
        good "Skript: Stamm- und Repo-Fassung gleich bis auf $na #@pfad-Zeilen"
    else bad "Skript-Fassungen weichen ab ($na/$nb #@pfad-Zeilen)"; diff "$a" "$b" | head -n 10; fi
    ps_other="$OTHER/tannwald-run-pruefstand/pruefstand.sh"; [ -f "$ps_other" ] || ps_other="$OTHER/pruefstand/pruefstand.sh"
    if cmp -s "$0" "$ps_other"; then good "Prüfstand: beide Kopien byte-gleich"; else bad "Prüfstand-Kopien weichen ab"; fi
    rel="$SCRIPT"; case "$HERE" in */docs/ops/tools/*) rel="$OTHER/tannwald-run.sh" ;; esac
    if [ "$(grep -c '/Users/' "$rel" || true)" = 0 ]; then good "Repo-Fassung ohne absoluten Pfad /Users/…"
    else bad "Repo-Fassung enthält /Users/"; fi
else
    echo "   übersprungen: keine zweite Fassung neben diesem Ordner (frischer Klon ohne Stamm)"
fi

echo "== 7 Keine Hash-Stücke, keine Geheimnisse, keine Temp-Dateien"
ALL="$WORK/alle-ausgaben.txt"; cat "$WORK"/out.*.txt > "$ALL"
# Nicht als Pipe mit grep -q prüfen (pipefail + SIGPIPE macht aus einem Treffer ein „bestanden“): erst Datei, dann grep.
if [ -s "$ALL" ] && ! grep -qE '[0-9a-fA-F]{12,}' "$ALL"; then good "kein Hash-Stück in $runs Ausgaben"
else bad "Hash-Stück in der Ausgabe"; grep -nE '[0-9a-fA-F]{12,}' "$ALL" | head -n 5; fi
if ! grep -q '"digest"' "$ALL"; then good "keine Rohausgabe von Ollama"; else bad "Rohausgabe von Ollama sichtbar"; fi
left="$(find "$WORK/tmp" -mindepth 1 | wc -l | tr -d ' ')"
if [ "$left" = 0 ]; then good "keine Temp-Datei und kein Wegwerf-Verzeichnis liegt"; else bad "$left Einträge liegen in tmp"; fi

echo; echo "Prüfstand ($RUN_BASH): $pass grün, $fail rot"
[ "$fail" = 0 ] || exit 1
echo "FERTIG — tannwald-run.sh hält Vorbedingungen ein, installiert nur Fehlendes, installiert Drupal nur einmal,"
echo "         räumt den Klon immer auf und zeigt nichts Geheimes."
