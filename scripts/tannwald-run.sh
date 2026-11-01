#!/usr/bin/env bash
# tannwald-run.sh — DAS eine Skript für das Programm „myTannwald PoC“. Erste Fassung 1.1 (Auftrag tannwald-1,
# „A Tannwald“), Seeder-Schritte 2.3 (B), Endfassung 6.2 (Auftrag tannwald-16, „D Tannwald“, 2026-11-01).
# Der Betreiber startet es mit EINER Zeile und tippt nichts ab. Es gibt keinen Server und keinen Deploy: die
# --live-Teile der Vorlage 09 entfallen; die Klon-Probe --frisch ersetzt den Deploy-Beweis.
#
# Aufruf (im normalen Terminal, im Repo-Ordner tannwald-portal; S=scripts):               #@pfad
#   bash $S/tannwald-run.sh --vorbereitung     # Werkzeuge prüfen, Fehlendes installieren (D3)
#   bash $S/tannwald-run.sh --lokal            # DDEV, Drupal, Seeder, Eval-Kurzprobe, Login-Link
#   bash $S/tannwald-run.sh --probe            # liest; endet mit „Bereit: ja/NEIN“
#   bash $S/tannwald-run.sh --reset            # Demo-Daten zurücksetzen (tannwald:demo-reset)
#   bash $S/tannwald-run.sh --eval             # voller Eval-Lauf (15–25 min, Ollama exklusiv)
#   bash $S/tannwald-run.sh --eval sicherheit  # nur die 30 Sicherheitsfragen (Sekunden, ohne Chat)
#   bash $S/tannwald-run.sh --frisch [branch]  # Klon von GitHub als zweites DDEV-Projekt, immer aufgeräumt
#
# --vorbereitung: misst brew, Docker-Daemon, ddev, mkcert-Zertifikat, ollama, Ollama-Dienst, beide Modelle;
#   sagt vorher, was es installieren wird; installiert nur Fehlendes mit den Teilen der D3-Zeile
#   (brew install ddev/ddev/ddev · mkcert -install · brew install ollama · brew services start ollama ·
#   ollama pull bge-m3 · ollama pull qwen2.5:7b). `mkcert -install` fragt nach dem Mac-Passwort. Läuft der
#   Docker-Daemon nicht, hält es VOR dem ersten Installieren an. Endet mit FERTIG.
# --lokal: Repo da, Branch dev oder main, ddev da, Docker läuft; ddev start; ddev composer install;
#   site:install --existing-config NUR wenn Drupal noch nicht installiert ist; dann bei jedem Lauf updatedb,
#   locale:check, locale:update (de.po der Module), config:import (zuletzt), drush cr; Aufwärm-Aufruf (Fehlen
#   ist eine Warnung); Seeder `tannwald:demo-install --skip-summaries` (idempotent; Embeddings sofort, die
#   Zusammenfassungen holt der DDEV-Cron alle 5 min nach — Entscheid 2.3, Anpassung 23: ein 20-min-Lauf
#   ist nie Vordergrund-Schritt); Eval-Kurzprobe `tannwald:eval --dry --limit=3` (Sekunden; Ergebnisdatei nur
#   in /tmp des Containers, nicht im Repo); Probe der Login-Seite; Login-Link. Endet mit FERTIG. Ein zweiter
#   Lauf installiert nichts neu. Während --lokal und --reset keinen zweiten queue-run starten.
# --reset: Vorbedingungen wie --lokal, ddev start, Drupal muss installiert sein; tannwald:demo-reset (Rollback
#   der Migrationen, Demo-Inhalte gelöscht, neu eingespielt; Audit-Log bleibt). Endet mit FERTIG.
# --probe (liest; einzige Schreibspur: die Kurzprobe legt tannwald_usage-Zeilen „embedding“ an): Werkzeuge,
#   Docker, DDEV läuft, Bootstrap, config:status leer, Ollama-Modelle aus dem Container, Login-Seite enthält
#   „myTannwald“, Demo-Daten (Dokumente > 0, Konto kunde-a), Chunks > 0, Zusammenfassungen (nur Zahl),
#   Eval-Kurzprobe (hit@5 > 0). Endet mit „Bereit: ja“ (Exit 0) oder „Bereit: NEIN — fehlt: …“ (Exit 1).
# --eval: Vorbedingungen wie --lokal, Drupal installiert, Chunks > 0, BEIDE Modelle aus dem Container
#   erreichbar (sonst Abbruch), Aufwärm-Aufruf beider Modelle, dann `tannwald:eval` über 60 Fragen mit LLM
#   (15–25 min; Ollama exklusiv — keine Chat-Läufe in anderen Terminals). Nennt Ergebnisdatei
#   (docs/evals/ergebnisse-<datum>_<zeit>.md, im Repo uncommittet), Dauer und die vier Kennzahlen mit Ziel.
#   Exit-Code von tannwald:eval: 0 = alle Ziele erreicht, 1 = gelaufen, nicht alle erreicht (beides endet
#   mit FERTIG); jeder andere ist ein Abbruch.
# --frisch [branch]: Klon-Probe des Durchgangs (Standard main; vor dem Merge `--frisch dev`).
#   `git clone --branch <branch> --depth 1` von GitHub nach $(mktemp -d)/tannwald-portal, schreibt
#   .ddev/config.local.yaml (Projekt tannwald-frisch, Ports 30010/30011) VOR ddev start, dann dieselben
#   Schritte wie --lokal in diesem Verzeichnis (leere Datenbank → site:install, Seeder, Kurzprobe, Login-Probe
#   gegen https://tannwald-frisch.ddev.site:30011); leert danach die Zusammenfassungs-Queue des Klons (kein
#   zweiter Cron gegen Ollama). Räumt IMMER auf, auch bei Fehler oder Ctrl-C (trap): `ddev delete -Oy
#   tannwald-frisch` und rm -rf des Wegwerf-Verzeichnisses. Die geteilte Instanz tannwald-portal (30000/30001)
#   bleibt unberührt. Endet mit `FERTIG (frisch): <n> s`. Repo privat: git nutzt das Token im Schlüsselbund
#   (nur HTTPS, keine Rückfrage — GIT_TERMINAL_PROMPT=0); nach dem Öffentlich-Schalten geht es ohne Token.
#
# Regeln (Betreiber): läuft ohne Rückfragen (ausser dem Passwort von mkcert -install); hält beim ersten Fehler
# an; zeigt nie ein Geheimnis und kein Stück eines Hashes — Rohausgaben von docker, ddev, brew, git, drush und
# ollama gehen in eine Temp-Datei und erscheinen nur gekürzt und maskiert; Zählungen mit `|| true`; keine
# LINES/COLUMNS, kein ${var,,}, keine assoziativen Arrays, kein mapfile; ${VAR} vor Nicht-ASCII; Zustände als
# Wort („ja“/„NEIN“); Werte nur im Werte-Block; Zeilen ≤ 110 Zeichen.
# Prüfstand: pruefstand.sh im Unterordner (gefälschte brew, docker, ddev, ollama, mkcert, curl im PATH; echtes
# git gegen ein Wegwerf-Remote). Geprüft mit /bin/bash -n (bash 3.2 des Macs) und bash 5.
# Zwei Fassungen: Stamm docs/ops/tools/tannwald-run.sh (absoluter Repo-Pfad) und Repo scripts/tannwald-run.sh
# (Pfade relativ zum Repo, Nachtrag 2 zu 6.2). Sie sind gleich bis auf die Zeilen, die auf #@pfad enden —
# der Prüfstand belegt es, sobald beide nebeneinander liegen.
set -euo pipefail

# ------------------------------------------------------------------------------------------- Werte
ROOT="$(cd "$(dirname "$0")/.." && pwd)"                 # Repo-Ordner tannwald-portal               #@pfad
REPO="${TANNWALD_REPO:-$ROOT}"                           # Prüfstand: Wegwerf-Repo                   #@pfad
BRANCHES="dev main"
PROJECT="tannwald-portal"                                # Name aus .ddev/config.yaml
SITE_URL="https://tannwald-portal.ddev.site:30001"        # Port fest: Valet-nginx hält 80/443
MAILPIT_URL="https://tannwald-portal.ddev.site:8026"
FRISCH_REMOTE="${TANNWALD_REMOTE:-https://github.com/JoshuaKucharski/tannwald-portal.git}"
FRISCH_BRANCH="main"                                     # nach dem Merge; vorher --frisch dev
FRISCH_PROJECT="tannwald-frisch"
FRISCH_HTTP_PORT="30010"
FRISCH_HTTPS_PORT="30011"
FRISCH_URL="https://tannwald-frisch.ddev.site:30011"
LOGIN_PATH="user/login"
EXPECT_WORD="myTannwald"
OLLAMA_HOST_URL="http://localhost:11434"                 # vom Mac aus
OLLAMA_CONTAINER_URL="http://host.docker.internal:11434" # aus dem Web-Container, wie Drupal es erreicht
MODELS="bge-m3 qwen2.5:7b"
WARMUP_MODEL="bge-m3"                                    # klein; das Chat-Modell (5 GB) wärmt nur --eval
CHAT_MODEL="qwen2.5:7b"
EVAL_SHORT_OUT="/tmp/tannwald-eval-kurz.md"              # im Web-Container, nicht im Repo
EVAL_METRICS="hit@5 zitat_korrekt ablehnung_korrekt safety_korrekt"
SYNC_WAIT="${TANNWALD_SYNC_WAIT:-30}"                     # s Wartezeit, bis Mutagen die Datei bringt
ADMIN_NAME="admin"
ADMIN_PASS="demo"                                        # lokaler PoC, kein Geheimnis (project-facts §7)
DEMO_ACCOUNT="kunde-a"                                   # Demo-Konto des Seeders (Passwort demo)
D3_DDEV="brew install ddev/ddev/ddev"
D3_MKCERT="mkcert -install"
D3_OLLAMA="brew install ollama"
D3_OLLAMA_START="brew services start ollama"
D3_PULL="ollama pull"
DISPLAY_RESAVE_PHP='foreach (["entity_view_display", "entity_form_display"] as $t) {
  foreach (\Drupal::entityTypeManager()->getStorage($t)->loadMultiple() as $d) { $d->save(); }
}'
MODES="--vorbereitung, --lokal, --probe, --reset, --eval [sicherheit], --frisch [branch]"
EVAL_PART=""                                             # sicherheit: nur die erwartet-safety-Fragen
SELF="bash $ROOT/scripts/tannwald-run.sh"                                               #@pfad

MODE=""
case "${1:-}" in
    --vorbereitung) MODE=vorbereitung ;;
    --lokal) MODE=lokal ;;
    --reset) MODE=reset ;;
    --probe) MODE=probe ;;
    --eval) MODE=eval ;;
    --frisch) MODE=frisch ;;
    *) echo "ABBRUCH: unbekannte Angabe „${1:-}“ — erlaubt: $MODES." >&2; exit 1 ;;
esac
if [ "$MODE" = frisch ] && [ $# -eq 2 ]; then
    FRISCH_BRANCH="$2"
    case "$FRISCH_BRANCH" in
        ""|-*|*[!A-Za-z0-9._/-]*)
            echo "ABBRUCH: Branch „${FRISCH_BRANCH}“ ist kein gültiger Name." >&2; exit 1 ;;
    esac
elif [ "$MODE" = eval ] && [ $# -eq 2 ]; then
    [ "$2" = sicherheit ] || { echo "ABBRUCH: --eval nimmt nur „sicherheit“ dazu." >&2; exit 1; }
    EVAL_PART=sicherheit
elif [ $# -ne 1 ]; then
    echo "ABBRUCH: eine Angabe (--frisch mit Branch, --eval mit „sicherheit“) — erlaubt: $MODES." >&2
    exit 1
fi

# ------------------------------------------------------------------------------------------- Ende
done_ok=0
final_rc=0
FRISCH_BASE=""                                           # gesetzt, solange ein Wegwerf-Klon existiert
cleanup_ok=1
TMP_LOG="$(mktemp "${TMPDIR:-/tmp}/tannwald-run.XXXXXX")"
TMP_MD="$(mktemp "${TMPDIR:-/tmp}/tannwald-run-md.XXXXXX")"
exec 3>&1 4>&2                                          # Ausgabe des Betreibers, für finish
# cleanup_frisch: Wegwerf-Projekt und -Verzeichnis entfernen; cleanup_ok=0, wenn etwas liegen bleibt.
cleanup_frisch() {
    [ -n "$FRISCH_BASE" ] || return 0
    echo; echo "== Aufräumen (Wegwerf-Klon)"
    if frisch_listed; then
        if ddev delete -Oy "$FRISCH_PROJECT" > "$TMP_LOG" 2>&1 && ! frisch_listed; then
            echo "   ok: ddev delete -Oy $FRISCH_PROJECT"
        else
            cleanup_ok=0
            echo "   FEHLER: $FRISCH_PROJECT noch in ddev list — von Hand: ddev delete -Oy $FRISCH_PROJECT"
            tail -n 10 "$TMP_LOG" | mask | sed 's/^/   | /'
        fi
    else
        echo "   ok: kein DDEV-Projekt $FRISCH_PROJECT angelegt"
    fi
    case "$FRISCH_BASE" in
        */tannwald-frisch.*) rm -rf "$FRISCH_BASE" 2>/dev/null || true ;;
    esac
    if [ -e "$FRISCH_BASE" ]; then
        cleanup_ok=0; echo "   FEHLER: Wegwerf-Verzeichnis liegt noch: $FRISCH_BASE"
    else
        echo "   ok: Wegwerf-Verzeichnis entfernt"
    fi
    FRISCH_BASE=""
}
finish() {
    # Signal mitten in run_quiet (Umleitung einer Funktion): sonst ginge alles in die Temp-Datei.
    exec 1>&3 2>&4
    cleanup_frisch || true
    rm -f "$TMP_LOG" "$TMP_MD"
    if [ "$cleanup_ok" != 1 ]; then echo; echo "NICHT AUFGERÄUMT — siehe FEHLER oben."; exit 1; fi
    # bash 3.2 meldet nach einem Abbruch durch set -u im Trap $?=0 — darum ausdrücklich mit Fehlerstatus.
    if [ "$done_ok" != 1 ]; then
        echo; echo "NICHT FERTIG — die letzte Meldung oben sagt, wo es steht."; exit 1
    fi
    exit "$final_rc"
}
trap finish EXIT
trap 'exit 130' INT TERM HUP

# ------------------------------------------------------------------------------------------- Helfer
step() { echo; echo "== $1"; }
STEP_N=0
STEP_TOTAL=0
stepn() { STEP_N=$((STEP_N + 1)); step "$STEP_N/$STEP_TOTAL $1"; }
ok()   { echo "   ok: $1"; }
hint() { echo "   Hinweis: $1"; }
stop() { echo "ABBRUCH: $1" >&2; exit 1; }
missing=""
need() { missing="$missing, $1"; echo "   FEHLT: $1"; }
has_cmd() { command -v "$1" >/dev/null 2>&1; }
mask() { tr '\r' '\n' | sed -E 's/[0-9a-fA-F]{12,}/<…>/g'; }

# ddv <args…>: ddev im Projektordner (ddev braucht den Ordner als Arbeitsverzeichnis).
ddv() { ( cd "$REPO" && ddev "$@" ); }

# show_tail [n]: die letzten Zeilen der Temp-Datei, gekürzt und maskiert.
show_tail() {
    echo "   letzte Zeilen (gekürzt, Hash-Stücke maskiert):"
    tail -n "${1:-25}" "$TMP_LOG" | mask | sed 's/^/   | /'
}
# run_quiet <beschreibung> <befehl…>: Rohausgabe in die Temp-Datei; bei Fehler die letzten Zeilen maskiert.
run_quiet() {
    desc="$1"; shift
    t0="$(date +%s)"
    if "$@" > "$TMP_LOG" 2>&1; then
        ok "$desc ($(( $(date +%s) - t0 )) s)"
    else
        show_tail
        stop "$desc fehlgeschlagen."
    fi
}

# model_names_json: Modellnamen aus /api/tags (JSON) je Zeile; model_names_list: aus `ollama list`.
model_names_json() { grep -oE '"name": *"[^"]+"' | sed -E 's/.*"([^"]+)"$/\1/' || true; }
model_names_list() { awk 'NR > 1 { print $1 }' || true; }
# has_model <namen> <modell>: ja, wenn <modell> oder <modell>:latest in der Liste steht.
has_model() {
    n="$(printf '%s\n' "$1" | grep -c -x -F -e "$2" -e "$2:latest" || true)"
    [ "$n" -gt 0 ]
}
container_models() {
    ddv exec curl -s -m 5 "$OLLAMA_CONTAINER_URL/api/tags" 2>/dev/null | model_names_json || true
}
docker_up() { has_cmd docker && docker info >/dev/null 2>&1; }
ollama_up() { curl -s -m 3 "$OLLAMA_HOST_URL/api/tags" >/dev/null 2>&1; }
bootstrap_state() { ddv drush status --field=bootstrap 2>/dev/null | tr -d '[:space:]' || true; }
# frisch_listed: ja, wenn ddev das Wegwerf-Projekt kennt (Schlüssel "name" im JSON von ddev list).
frisch_listed() {
    n="$(ddev list -j 2>/dev/null | grep -cE "\"name\": *\"$FRISCH_PROJECT\"" || true)"
    [ "$n" -gt 0 ]
}
# sql_count <sql>: eine Zahl aus drush sql:query (leer/Fehler → 0).
sql_count() {
    n="$(ddv drush sql:query "$1" 2>/dev/null | tr -d '[:space:]' || true)"
    case "$n" in ''|*[!0-9]*) echo 0 ;; *) echo "$n" ;; esac
}
# seeder_summary: Zählungen und Änderungen aus der Ausgabe des Seeders (Temp-Datei).
SEED_KEYS='firmen|maschinen|users|dokumente|revisionen|bulletins|chunks|zusammenfassungen_zur_pruefung'
SEED_KEYS="$SEED_KEYS|lesebestaetigungen"
seeder_summary() {
    grep -E "^ +($SEED_KEYS) " "$TMP_LOG" \
        | awk '{ printf "%s%s %s", sep, $1, $2; sep=", " } END { if (NR) print "" }' \
        | sed 's/^/   Zählung: /' || true
    grep -E '^(Changes|Änderungen):' "$TMP_LOG" | sed 's/^/   /' || true
}
# metric_of <datei> <kennzahl>: „0.73 (38/52)“ aus der Kennzahlen-Zeile (Markdown oder Drush-Tabelle).
metric_of() {
    grep -E "^[ |]*$2[ |]" "$1" | head -n 1 | grep -oE '[0-9]+\.[0-9]+ \([0-9]+/[0-9]+\)' | head -n 1 || true
}
# cell_of <zeile> <spalte>: eine Zelle einer Markdown-Tabellenzeile, ohne Rand-Leerzeichen.
cell_of() { printf '%s' "$1" | awk -F'|' -v c="$2" '{ v = $c; gsub(/^ +| +$/, "", v); print v }'; }
# eval_short: tannwald:eval --dry --limit=3 (nur Retrieval, Sekunden); setzt EVAL_SHORT („hit@5 2/2“).
# Erfolg = der Befehl lief (Exit 0 oder 1: Ziel erreicht/verfehlt) UND mindestens ein Treffer (Chunks und
# Embeddings arbeiten). Drei Fragen messen keine Qualität — das macht --eval.
EVAL_SHORT=""
eval_short() {
    EVAL_SHORT=""
    rc=0
    ddv drush tannwald:eval --dry --limit=3 --out="$EVAL_SHORT_OUT" > "$TMP_LOG" 2>&1 || rc=$?
    case "$rc" in 0|1) ;; *) EVAL_SHORT="Exit $rc"; return 1 ;; esac
    # drush meldet einen unbekannten Befehl mit Exit 1 (gemessen 22:55: Klon von origin/dev vor 4.3).
    if [ "$(grep -c 'Command "tannwald:eval" is not defined' "$TMP_LOG" || true)" -gt 0 ]; then
        EVAL_SHORT="Befehl tannwald:eval fehlt in diesem Stand (vor 4.3?)"; return 1
    fi
    frac="$(metric_of "$TMP_LOG" 'hit@5' | grep -oE '[0-9]+/[0-9]+' || true)"
    [ -n "$frac" ] || { EVAL_SHORT="keine hit@5-Zeile"; return 1; }
    EVAL_SHORT="hit@5 $frac"
    [ "${frac%%/*}" -gt 0 ]
}
# save_drift <status-json>: im Klon die aktive Fassung jedes abweichenden Objekts (config:get, YAML) in
# einen Ordner auf dem Mac sichern, der das Aufräumen überlebt (für den Master; Inhalt nicht am Bildschirm).
save_drift() {
    dir="${TMPDIR:-/tmp}/tannwald-frisch-abweichung"
    rm -rf "$dir"; mkdir -p "$dir"
    names="$(printf '%s' "$1" | grep -oE '"name":"[^"]+"' | sed -E 's/"name":"([^"]+)"/\1/' | head -n 15)"
    for name in $names; do
        case "$name" in *[!A-Za-z0-9._-]*) continue ;; esac
        ddv drush config:get "$name" --format=yaml > "$dir/$name.yml" 2>/dev/null || rm -f "$dir/$name.yml"
    done
    echo "   Aktive Fassung aus dem Klon gesichert (bleibt liegen): $dir"
}
# safety_ids: Kommaliste der Fragen mit „erwartet: safety“ aus docs/evals/fragen.yaml (awk, kein YAML-Parser).
safety_ids() {
    awk '/^- id: /{ id = $3 } /^  erwartet: safety/{ printf "%s%s", sep, id; sep = "," }' \
        "$REPO/docs/evals/fragen.yaml" 2>/dev/null || true
}
# tools_up: ddev da, Docker läuft.
tools_up() {
    has_cmd ddev || stop "ddev fehlt — zuerst: $SELF --vorbereitung"
    docker_up || stop "Der Docker-Daemon läuft nicht — Docker Desktop starten, dann erneut."
    ok "ddev und Docker-Daemon"
}
# preconditions: Repo auf dev/main, ddev da, Docker läuft (für --lokal, --reset, --eval).
preconditions() {
    [ -d "$REPO/.git" ] || stop "$REPO ist kein Repo."
    b="$(git -C "$REPO" symbolic-ref --short HEAD 2>/dev/null || true)"
    case " $BRANCHES " in
        *" $b "*) [ -n "$b" ] && ok "$REPO auf $b ($(git -C "$REPO" log -1 --format='%h %cd' \
            --date=format:'%d.%m.%Y %H:%M' 2>/dev/null || true))" ;;
        *) stop "$REPO steht auf „${b}“, erwartet dev oder main." ;;
    esac
    [ -n "$b" ] || stop "$REPO hat keinen Branch."
    tools_up
}
# require_drupal: ddev start und Bootstrap (für --reset und --eval).
require_drupal() {
    run_quiet "ddev start" ddv start
    [ "$(bootstrap_state)" = Successful ] || stop "Drupal ist nicht installiert — zuerst: $SELF --lokal"
}

# setup_site <art>: die Schritte von --lokal ab „DDEV starten“ in $REPO gegen $SITE_URL; <art> lokal|frisch.
setup_site() {
    stepn "DDEV starten ($PROJECT)"
    run_quiet "ddev start" ddv start

    stepn "Composer"
    run_quiet "ddev composer install" ddv composer install --no-interaction --no-progress

    stepn "Drupal"
    if [ "$(bootstrap_state)" = Successful ]; then
        ok "bereits installiert — kein Install; Demo-Daten zurücksetzen mit --reset"
    else
        run_quiet "site:install --existing-config (Konto $ADMIN_NAME)" \
            ddv drush site:install --existing-config --account-name="$ADMIN_NAME" \
            --account-pass="$ADMIN_PASS" -y
        [ "$(bootstrap_state)" = Successful ] || stop "Nach site:install kein erfolgreicher Bootstrap."
    fi
    # Update-Hooks bestehender Datenbanken (4.2) zuerst. Die de.po der eigenen Module kommen nur über
    # locale:update in die Datenbank (D gemessen) — es übersetzt dabei aber auch Labels von 15 Core-Objekten
    # (Views, navigation, workflows.editorial) und macht config:status „Different“ (Master 23:14). Darum
    # config:import ZULETZT: setzt sie auf config/sync zurück, holt neue Module und Konfiguration aus dem
    # Merge und zieht auf frischer Datenbank die Sprache en nach (Core legt sie bei --existing-config mit
    # Standardsprache de nicht an, gemessen 2026-11-01); danach ist config:status leer.
    run_quiet "updatedb" ddv drush updatedb -y
    run_quiet "locale:check" ddv drush locale:check
    run_quiet "locale:update" ddv drush locale:update
    run_quiet "config:import" ddv drush config:import -y
    run_quiet "drush cr" ddv drush cr
    if [ "$1" = frisch ]; then
        # Nur im Wegwerf-Klon (schreibt Konfiguration): alle View- und Form-Displays einmal speichern.
        # Ein Hook, der beim Speichern Komponenten ergänzt (Pseudo-Feld tannwald_summary, gemessen 23:20),
        # macht sonst erst beim ersten Speichern config:status „Different“ — die Prüfung danach deckt es auf.
        # Nicht in --probe: die geteilte Instanz bleibt unverändert (--probe liest).
        run_quiet "View- und Form-Displays gespeichert (Drift-Probe)" ddv drush php:eval "$DISPLAY_RESAVE_PHP"
    fi
    cs="$(ddv drush config:status --format=json 2>/dev/null | tr -d '[:space:]' || true)"
    case "$cs" in
        ""|"[]"|"{}") ok "config:status leer" ;;
        *) echo "   abweichend (Name: Zustand, höchstens 15):"
           printf '%s' "$cs" | grep -oE '"name":"[^"]+","state":"[^"]+"' \
               | sed -E 's/"name":"([^"]+)","state":"([^"]+)"/\1: \2/' | head -n 15 | mask \
               | sed 's/^/   | /' || true
           [ "$1" != frisch ] || save_drift "$cs"
           stop "config:status nach config:import nicht leer — diese Ausgabe an den Master." ;;
    esac

    stepn "Ollama aufwärmen (Fehlen ist eine Warnung, kein Abbruch)"
    names="$(container_models)"
    warm=0
    for m in $MODELS; do
        if has_model "$names" "$m"; then ok "Modell $m aus dem Container erreichbar"
        else hint "WARNUNG: Modell $m aus dem Container nicht erreichbar — --vorbereitung"; fi
    done
    if has_model "$names" "$WARMUP_MODEL"; then
        warm=1
        t0="$(date +%s)"
        if curl -s -m 120 "$OLLAMA_HOST_URL/api/embed" \
            -d "{\"model\":\"$WARMUP_MODEL\",\"input\":\"Aufwärmen\"}" > "$TMP_LOG" 2>&1 \
            && [ "$(grep -c '"embeddings"' "$TMP_LOG" || true)" -gt 0 ]; then
            ok "$WARMUP_MODEL aufgewärmt ($(( $(date +%s) - t0 )) s)"
        else
            hint "WARNUNG: Aufwärm-Aufruf an $WARMUP_MODEL ohne Embedding"
        fi
    fi

    stepn "Demo-Daten (Seeder tannwald:demo-install --skip-summaries, idempotent; erster Lauf etwa 1 min)"
    run_quiet "tannwald:demo-install --skip-summaries" ddv drush tannwald:demo-install --skip-summaries
    seeder_summary
    waiting="$(sql_count "SELECT COUNT(*) FROM queue WHERE name = 'tannwald_summarize'")"
    if [ "$1" = frisch ]; then
        # Der Klon lebt nur Minuten: seine Zusammenfassungen liefen sonst über den Cron des Klons gegen das
        # geteilte Ollama. Prüfgegenstand des Klons sind Install, Seeder und Embeddings.
        run_quiet "Zusammenfassungs-Queue des Klons geleert ($waiting Jobs)" \
            ddv drush queue:delete tannwald_summarize
    elif [ "$waiting" -gt 0 ]; then
        hint "$waiting Zusammenfassungen warten (tannwald_summarize); der DDEV-Cron arbeitet sie ab"
        echo "   (alle 5 min, etwa 34 s je Job über Ollama). Sofort statt über den Cron, im Repo-Ordner:"
        echo "   ddev drush tannwald:queue-run --only=summarize"
    else
        ok "keine Zusammenfassung wartet"
    fi

    stepn "Eval-Kurzprobe (tannwald:eval --dry --limit=3, nur Retrieval)"
    if [ "$warm" = 1 ]; then
        if eval_short; then
            ok "Eval-Kurzprobe: ${EVAL_SHORT} (Ergebnisdatei nur im Container, $EVAL_SHORT_OUT)"
        else
            show_tail 15
            stop "Eval-Kurzprobe ohne Treffer: ${EVAL_SHORT}."
        fi
    else
        EVAL_SHORT="übersprungen (Ollama)"
        hint "WARNUNG: Eval-Kurzprobe übersprungen — $WARMUP_MODEL aus dem Container nicht erreichbar"
    fi

    stepn "Probe der Login-Seite"
    html="$(curl -sk -m 20 "$SITE_URL/$LOGIN_PATH" || true)"
    n="$(printf '%s' "$html" | grep -c -- "$EXPECT_WORD" || true)"
    [ "$n" -gt 0 ] || stop "Probe: „${EXPECT_WORD}“ nicht in $SITE_URL/$LOGIN_PATH."
    ok "$SITE_URL/$LOGIN_PATH enthält „${EXPECT_WORD}“"
}

# ------------------------------------------------------------------------------------------- --vorbereitung
if [ "$MODE" = vorbereitung ]; then
    step "1/3 Werkzeuge messen (liest nur)"
    has_cmd brew || stop "Homebrew fehlt — zuerst nach https://brew.sh installieren, dann erneut."
    ok "brew"
    plan=""
    add() { plan="$plan$1
"; }
    if docker_up; then ok "Docker-Daemon läuft"; else need "Docker-Daemon"; fi
    if has_cmd ddev; then
        ok "ddev ($(ddev --version 2>/dev/null | head -n 1 | mask))"
        if has_cmd mkcert; then
            caroot="$(mkcert -CAROOT 2>/dev/null || true)"
            if [ -n "$caroot" ] && [ -f "$caroot/rootCA.pem" ]; then ok "mkcert-Zertifikat angelegt"
            else need "mkcert-Zertifikat"; add "$D3_MKCERT"; fi
        else
            stop "mkcert fehlt, obwohl ddev da ist — einmal 'brew reinstall ddev/ddev/ddev', dann erneut."
        fi
    else
        need "ddev"; add "$D3_DDEV"; add "$D3_MKCERT"
    fi
    pulls_later=0
    if has_cmd ollama; then
        ok "ollama"
        if ollama_up; then
            ok "Ollama-Dienst antwortet"
            names="$(ollama list 2>/dev/null | model_names_list)"
            for m in $MODELS; do
                if has_model "$names" "$m"; then ok "Modell $m"; else need "Modell $m"; add "$D3_PULL $m"; fi
            done
        else
            need "Ollama-Dienst"; add "$D3_OLLAMA_START"; pulls_later=1
        fi
    else
        need "ollama"; add "$D3_OLLAMA"; add "$D3_OLLAMA_START"; pulls_later=1
    fi
    # Homebrew verweigert jede Installation, wenn die Command Line Tools zu alt sind (gemessen 2026-11-01
    # 09:20: CLT 16.2 auf macOS 26.1). Nur messen, wenn brew etwas installieren soll — brew doctor dauert.
    clt_old=0
    case "$plan" in
        *"brew install"*)
            n="$(brew doctor 2>&1 | grep -c 'Command Line Tools are too outdated' || true)"
            if [ "$n" -gt 0 ]; then
                clt_old=1; need "Command Line Tools aktuell (Homebrew installiert sonst nichts)"
            else
                ok "Command Line Tools aktuell genug für Homebrew"
            fi ;;
    esac

    step "2/3 Plan"
    if [ -z "$plan" ] && [ "$pulls_later" = 0 ]; then
        ok "nichts zu installieren"
    else
        echo "   Das Skript wird jetzt ausführen (in dieser Reihenfolge):"
        printf '%s' "$plan" | sed 's/^/     → /'
        [ "$pulls_later" = 0 ] || echo "     → danach: fehlende Modelle laden ($MODELS; zusammen etwa 6 GB)"
        echo "   mkcert -install fragt nach dem Mac-Passwort."
    fi
    docker_up || stop "Der Docker-Daemon läuft nicht — Docker Desktop starten, dann dieses Skript erneut
   (es installiert nichts doppelt)."
    [ "$clt_old" = 0 ] || stop "Die Command Line Tools sind zu alt — Homebrew installiert nichts. Einmal:
     sudo rm -rf /Library/Developer/CommandLineTools && sudo xcode-select --install
   (Mac-Passwort, dann im Dialog „Installieren“; dauert einige Minuten), danach dieses Skript erneut."

    step "3/3 Installieren und nachmessen"
    old_ifs="$IFS"; IFS='
'
    set -f
    for c in $plan; do
        IFS="$old_ifs"
        echo "   → $c"
        run_quiet "$c" eval "$c"
    done
    IFS="$old_ifs"; set +f
    if [ "$pulls_later" = 1 ]; then
        i=0
        until ollama_up; do
            i=$((i + 1))
            [ "$i" -le 30 ] || stop "Ollama-Dienst antwortet nach 30 s nicht ($OLLAMA_HOST_URL)."
            sleep 1
        done
        names="$(ollama list 2>/dev/null | model_names_list)"
        for m in $MODELS; do
            if has_model "$names" "$m"; then ok "Modell $m schon da"
            else echo "   → $D3_PULL $m (dauert Minuten)"; run_quiet "$D3_PULL $m" ollama pull "$m"; fi
        done
    fi
    has_cmd ddev || stop "ddev fehlt auch nach der Installation."
    has_cmd ollama || stop "ollama fehlt auch nach der Installation."
    ollama_up || stop "Ollama-Dienst antwortet nicht ($OLLAMA_HOST_URL)."
    names="$(ollama list 2>/dev/null | model_names_list)"
    for m in $MODELS; do has_model "$names" "$m" || stop "Modell $m fehlt auch nach dem Laden."; done
    ok "ddev, mkcert, ollama und beide Modelle ($MODELS) vorhanden"
    done_ok=1
    echo; echo "FERTIG — Werkzeuge bereit. Als Nächstes: $SELF --lokal"
    exit 0
fi

# ------------------------------------------------------------------------------------------- --reset
if [ "$MODE" = reset ]; then
    step "1/3 Vorbedingungen"
    preconditions
    step "2/3 DDEV"
    require_drupal
    step "3/3 Demo-Daten zurücksetzen (tannwald:demo-reset; mit Zusammenfassungen etwa 20 Minuten)"
    run_quiet "tannwald:demo-reset" ddv drush tannwald:demo-reset
    seeder_summary
    done_ok=1
    echo; echo "FERTIG — Demo-Daten zurückgesetzt und neu eingespielt."
    echo "Erwartet: Zählung firmen 7, maschinen 10, users 6, dokumente 20, revisionen 22, bulletins 3,"
    echo "          lesebestaetigungen 1; Login $DEMO_ACCOUNT / demo zeigt unter $SITE_URL/meine-maschinen"
    echo "          die Maschine TB200-2021-0417."
    exit 0
fi

# ------------------------------------------------------------------------------------------- --lokal
if [ "$MODE" = lokal ]; then
    STEP_TOTAL=9
    stepn "Vorbedingungen"
    preconditions
    setup_site lokal
    stepn "Login-Link"
    link="$(ddv drush user:login --name="$ADMIN_NAME" --no-browser 2>/dev/null \
        | grep -E '^https?://' | tail -n 1 || true)"
    [ -n "$link" ] || stop "drush user:login lieferte keinen Link."

    done_ok=1
    echo; echo "FERTIG — lokal eingerichtet. Login-Link (einmalig, für $ADMIN_NAME):"
    echo "   $link"
    echo "Erwartet: $SITE_URL zeigt „${EXPECT_WORD}“; Passwort-Login $ADMIN_NAME / $ADMIN_PASS;"
    echo "          Mails unter $MAILPIT_URL."
    echo "Demo: Login $DEMO_ACCOUNT / demo, $SITE_URL/meine-maschinen zeigt TB200-2021-0417;"
    echo "      Zurücksetzen mit --reset. Eval-Kurzprobe: ${EVAL_SHORT}."
    exit 0
fi

# ------------------------------------------------------------------------------------------- --eval
if [ "$MODE" = eval ]; then
    STEP_TOTAL=4
    stepn "Vorbedingungen"
    preconditions
    stepn "DDEV und Drupal"
    require_drupal
    chunks="$(sql_count "SELECT COUNT(*) FROM tannwald_chunk")"
    [ "$chunks" -gt 0 ] || stop "Keine Chunks in tannwald_chunk — zuerst: $SELF --lokal"
    ok "Drupal installiert, $chunks Chunks"

    # Teillauf sicherheit: die Sicherheitsvorlage braucht kein Chat-Modell (Bericht 6.1 h), nur Embeddings.
    need_models="$MODELS"; [ -z "$EVAL_PART" ] || need_models="$WARMUP_MODEL"
    stepn "Ollama ($need_models) und Aufwärm-Aufrufe"
    names="$(container_models)"
    for m in $need_models; do
        has_model "$names" "$m" || stop "Modell $m aus dem Container nicht erreichbar (--vorbereitung)."
        ok "Modell $m aus dem Container erreichbar"
    done
    t0="$(date +%s)"
    curl -s -m 120 "$OLLAMA_HOST_URL/api/embed" -d "{\"model\":\"$WARMUP_MODEL\",\"input\":\"Aufwärmen\"}" \
        > "$TMP_LOG" 2>&1 && [ "$(grep -c '"embeddings"' "$TMP_LOG" || true)" -gt 0 ] \
        || stop "Aufwärm-Aufruf an $WARMUP_MODEL ohne Embedding."
    if [ -z "$EVAL_PART" ]; then
        body="{\"model\":\"$CHAT_MODEL\",\"prompt\":\"OK\",\"stream\":false,\"options\":{\"num_predict\":1}}"
        curl -s -m 300 "$OLLAMA_HOST_URL/api/generate" -d "$body" \
            > "$TMP_LOG" 2>&1 && [ "$(grep -c '"response"' "$TMP_LOG" || true)" -gt 0 ] \
            || stop "Aufwärm-Aufruf an $CHAT_MODEL ohne Antwort."
    fi
    ok "aufgewärmt: $need_models ($(( $(date +%s) - t0 )) s)"

    set -- tannwald:eval
    if [ -n "$EVAL_PART" ]; then
        ids="$(safety_ids)"
        n_ids="$(printf '%s' "$ids" | tr ',' '\n' | grep -c . || true)"
        [ "$n_ids" -gt 0 ] || stop "Keine Fragen mit „erwartet: safety“ in $REPO/docs/evals/fragen.yaml."
        set -- tannwald:eval --ids="$ids"
        stepn "Eval-Teillauf tannwald:eval --ids=… ($n_ids Sicherheitsfragen, ohne Chat-LLM)"
        hint "dauert Sekunden; Ergebnisdatei mit Endung _teil.md."
    else
        stepn "Eval-Lauf tannwald:eval (60 Fragen mit LLM)"
        hint "dauert 15–25 min; Ollama exklusiv — keine Chat- oder Eval-Läufe in anderen Terminals."
        echo "   Start $(date +%H:%M); das Fenster bleibt bis zum Ende still."
    fi
    t0="$(date +%s)"
    rc=0
    ddv drush "$@" > "$TMP_LOG" 2>&1 || rc=$?
    secs=$(( $(date +%s) - t0 ))
    case "$rc" in
        0|1) ;;
        *) show_tail; stop "tannwald:eval endete mit Exit $rc (kein Ergebnis) nach $secs s." ;;
    esac
    file="$(grep -E '^ *Ergebnisdatei: ' "$TMP_LOG" | tail -n 1 | sed -E 's/^ *Ergebnisdatei: *//' \
        | tr -d '\r' || true)"
    # Aus dem Container lesen: Mutagen bringt die Datei erst Sekunden später auf den Mac (gemessen 23:39).
    case "$file" in
        docs/evals/ergebnisse-*.md) ;;
        *) show_tail; stop "Ergebnisdatei unerwartet („${file}“)." ;;
    esac
    ddv exec cat "$file" > "$TMP_MD" 2>/dev/null && [ -s "$TMP_MD" ] \
        || { show_tail; stop "Ergebnisdatei nicht lesbar („${file}“)."; }
    ok "Ergebnisdatei $file ($secs s)"
    i=0; while [ ! -f "$REPO/$file" ] && [ "$i" -lt "$SYNC_WAIT" ]; do sleep 1; i=$((i + 1)); done
    synced="auf dem Mac"; [ -f "$REPO/$file" ] || synced="noch nicht auf dem Mac (Mutagen gleicht ab)"
    line=""
    for m in $EVAL_METRICS; do
        row="$(grep -E "^\| $m \|" "$TMP_MD" | head -n 1 || true)"
        val="$(cell_of "$row" 3)"; goal="$(cell_of "$row" 5)"; mark="$(cell_of "$row" 6)"
        case "$mark" in "✓") word="erreicht" ;; "✗") word="NICHT erreicht" ;; *) word="nicht geprüft" ;; esac
        echo "   $m: ${val:-–} — Ziel ${goal:-–} — ${word}"
        line="$line · $m ${val%% *}"
    done
    if [ "$rc" = 0 ]; then goals="ja"; else goals="NEIN (Exit 1)"; fi
    done_ok=1
    final_rc="$rc"
    echo; echo "FERTIG — Eval-Lauf in $secs s ($((secs / 60)) min). Ziele erreicht: ${goals}."
    echo "Kennzahlen:${line# ·}"
    echo "Ergebnisdatei: $REPO/$file (${synced}; uncommittet — committet der Master)."
    exit "$rc"
fi

# ------------------------------------------------------------------------------------------- --frisch
if [ "$MODE" = frisch ]; then
    STEP_TOTAL=9
    T_START="$(date +%s)"
    stepn "Vorbedingungen (Klon $FRISCH_BRANCH von GitHub, Projekt $FRISCH_PROJECT)"
    has_cmd git || stop "git fehlt."
    tools_up
    if frisch_listed; then
        hint "ein altes Projekt $FRISCH_PROJECT liegt noch (abgebrochener Lauf) — wird zuerst gelöscht"
        run_quiet "ddev delete -Oy $FRISCH_PROJECT (alt)" ddev delete -Oy "$FRISCH_PROJECT"
    fi
    ok "Ziel $FRISCH_URL; die geteilte Instanz $PROJECT ($SITE_URL) bleibt unberührt"

    stepn "Klon in ein Wegwerf-Verzeichnis und .ddev/config.local.yaml"
    FRISCH_BASE="$(mktemp -d "${TMPDIR:-/tmp}/tannwald-frisch.XXXXXX")"
    REPO="$FRISCH_BASE/tannwald-portal"
    run_quiet "git clone --branch $FRISCH_BRANCH --depth 1" env GIT_TERMINAL_PROMPT=0 \
        git clone --quiet --branch "$FRISCH_BRANCH" --depth 1 "$FRISCH_REMOTE" "$REPO"
    [ -f "$REPO/.ddev/config.yaml" ] || stop "Der Klon hat keine .ddev/config.yaml."
    ok "Stand: $(git -C "$REPO" log -1 --format='%s (%cd)' --date=format:'%d.%m.%Y %H:%M' 2>/dev/null | mask)"
    # Eigener Name und eigene Ports VOR ddev start: derselbe Name wie die Arbeitskopie kollidiert, und ohne
    # feste Ports vergibt der Router wechselnde (Valet hält 80/443). Nicht versioniert, lebt nur im Klon.
    {
        echo "# tannwald-run.sh --frisch: Wegwerf-Klon mit eigenem Projektnamen und eigenen Ports."
        echo "name: $FRISCH_PROJECT"
        echo "router_http_port: \"$FRISCH_HTTP_PORT\""
        echo "router_https_port: \"$FRISCH_HTTPS_PORT\""
    } > "$REPO/.ddev/config.local.yaml"
    ok ".ddev/config.local.yaml: name $FRISCH_PROJECT, Ports $FRISCH_HTTP_PORT/$FRISCH_HTTPS_PORT"
    PROJECT="$FRISCH_PROJECT"
    SITE_URL="$FRISCH_URL"

    setup_site frisch
    secs=$(( $(date +%s) - T_START ))
    cleanup_frisch
    [ "$cleanup_ok" = 1 ] || exit 1
    done_ok=1
    echo; echo "FERTIG (frisch): $secs s — Klon $FRISCH_BRANCH installiert und geseedet, Login-Seite und"
    echo "                 Eval-Kurzprobe (${EVAL_SHORT}) auf $FRISCH_URL; aufgeräumt."
    exit 0
fi

# ------------------------------------------------------------------------------------------- --probe (liest)
step "Probe 1/7 Werkzeuge"
for t in docker ddev ollama mkcert; do
    if has_cmd "$t"; then ok "$t"; else need "$t"; fi
done

step "Probe 2/7 Docker, Repo, DDEV"
if docker_up; then ok "Docker-Daemon läuft"; else need "Docker-Daemon"; fi
ddev_running=0
if [ -d "$REPO/.git" ]; then
    b="$(git -C "$REPO" symbolic-ref --short HEAD 2>/dev/null || true)"
    case " $BRANCHES " in
        *" $b "*) [ -n "$b" ] && ok "Repo auf $b" || need "Branch dev/main" ;;
        *) need "Branch dev/main (steht auf „${b}“)" ;;
    esac
    if has_cmd ddev && docker_up; then
        n="$(ddv describe -j 2>/dev/null | grep -cE '"status": *"running"' || true)"
        if [ "$n" -gt 0 ]; then ddev_running=1; ok "DDEV-Projekt läuft"
        else need "DDEV-Projekt läuft (ddev start)"; fi
    fi
else
    need "Repo $REPO"
fi

step "Probe 3/7 Drupal"
drupal_ok=0
if [ "$ddev_running" = 1 ]; then
    if [ "$(bootstrap_state)" = Successful ]; then drupal_ok=1; ok "Bootstrap Successful"
    else need "Drupal installiert (Bootstrap)"; fi
    cs="$(ddv drush config:status --format=json 2>/dev/null | tr -d '[:space:]' || true)"
    case "$cs" in
        ""|"[]"|"{}") ok "config:status leer" ;;
        *) need "config:status leer (Konfiguration weicht von config/sync ab)" ;;
    esac
else
    hint "übersprungen — DDEV läuft nicht"
fi

step "Probe 4/7 Ollama (aus dem Container)"
embed_ok=0
if [ "$ddev_running" = 1 ]; then
    names="$(container_models)"
    for m in $MODELS; do
        if has_model "$names" "$m"; then ok "Modell $m"; else need "Modell $m"; fi
    done
    if has_model "$names" "$WARMUP_MODEL"; then embed_ok=1; fi
else
    hint "übersprungen — DDEV läuft nicht"; need "Ollama aus dem Container (DDEV läuft nicht)"
fi

step "Probe 5/7 Login-Seite"
html="$(curl -sk -m 20 "$SITE_URL/$LOGIN_PATH" || true)"
n="$(printf '%s' "$html" | grep -c -- "$EXPECT_WORD" || true)"
if [ "$n" -gt 0 ]; then ok "$SITE_URL/$LOGIN_PATH enthält „${EXPECT_WORD}“"
else need "„${EXPECT_WORD}“ auf $SITE_URL/$LOGIN_PATH"; fi

step "Probe 6/7 Demo-Daten, Chunks, Zusammenfassungen (Seeder)"
chunks=0
if [ "$drupal_ok" = 1 ]; then
    docs="$(sql_count "SELECT COUNT(*) FROM node WHERE type = 'dokument'")"
    acct="$(sql_count "SELECT COUNT(*) FROM users_field_data WHERE name = '$DEMO_ACCOUNT'")"
    chunks="$(sql_count "SELECT COUNT(*) FROM tannwald_chunk")"
    sums="$(sql_count "SELECT COUNT(*) FROM node WHERE type = 'zusammenfassung'")"
    waiting="$(sql_count "SELECT COUNT(*) FROM queue WHERE name = 'tannwald_summarize'")"
    acct_word="${DEMO_ACCOUNT} FEHLT"; [ "$acct" -eq 0 ] || acct_word="${DEMO_ACCOUNT} vorhanden"
    echo "   Demo-Daten: $docs Dokumente, ${acct_word}, $chunks Chunks, $sums Zusammenfassungen (+$waiting)"
    if [ "$docs" -gt 0 ] && [ "$acct" -gt 0 ]; then ok "Demo-Daten"
    else need "Demo-Daten (tannwald-run.sh --lokal spielt sie ein)"; fi
    if [ "$chunks" -gt 0 ]; then ok "Chunks > 0"
    else need "Chunks in tannwald_chunk (Queue tannwald_embed; --lokal)"; fi
    [ "$sums" -gt 0 ] || [ "$waiting" -gt 0 ] || hint "keine Zusammenfassung, keine wartende (nur Hinweis)"
else
    hint "übersprungen — Drupal nicht erreichbar"
fi

step "Probe 7/7 Eval-Kurzprobe (tannwald:eval --dry --limit=3)"
if [ "$drupal_ok" = 1 ] && [ "$embed_ok" = 1 ] && [ "$chunks" -gt 0 ]; then
    if eval_short; then ok "Eval-Kurzprobe: ${EVAL_SHORT}"
    else need "Eval-Kurzprobe mit Treffern (${EVAL_SHORT})"; fi
else
    hint "übersprungen — Drupal, $WARMUP_MODEL oder Chunks fehlen"
    need "Eval-Kurzprobe (Voraussetzungen fehlen)"
fi

done_ok=1
if [ -n "$missing" ]; then
    echo; echo "Bereit: NEIN — fehlt:${missing#,}"
    final_rc=1
    exit 1
fi
echo; echo "Bereit: ja"
exit 0
