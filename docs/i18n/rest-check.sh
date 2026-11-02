#!/bin/bash
# Reste-Prüfung (6.5): meldet sich als Demo-Konto per curl an, ruft die drei Wireframe-Seiten in einer Sprache ab
# und listet Wörter der jeweils anderen Sprachen im sichtbaren Text. Rein lesend (ein Login, keine Inhaltsänderung).
# Aufruf: bash docs/i18n/rest-check.sh <fr|en> <konto>   z. B.  fr kunde-a  /  en kunde-b KT500-2021-0097  (Passwort: demo; dritte Angabe = Seriennummer)
set -u
LANG_CODE="${1:-fr}"; USER_NAME="${2:-kunde-a}"; SERIAL="${3:-TB200-2021-0417}"
BASE="https://tannwald-portal.ddev.site:30001"
JAR="$(mktemp)"; trap 'rm -f "$JAR"' EXIT
curl -sk -c "$JAR" -o /dev/null "$BASE/user/login"
curl -sk -b "$JAR" -c "$JAR" -o /dev/null -d "name=$USER_NAME&pass=demo&form_id=user_login_form&op=Log+in" "$BASE/user/login"
# Maschine TB200-2021-0417 aus der Liste der Maschinen
LIST="$(curl -sk -b "$JAR" "$BASE/$LANG_CODE/meine-maschinen")"
MACH="$(printf '%s' "$LIST" | grep -o "href=\"[^\"]*\"[^>]*>$SERIAL" | head -1 | sed 's/href="\([^"]*\)".*/\1/')"
# Maschinenlinks tragen kein Sprachpräfix: Sprache der Seite erzwingen
case "$MACH" in /$LANG_CODE/*) ;; /*) MACH="/$LANG_CODE$MACH" ;; esac
[ -n "$MACH" ] || { echo "FEHLER: Maschine TB200-2021-0417 nicht gefunden (Login oder Sprache?)"; exit 1; }
case "$LANG_CODE" in
  fr) FOREIGN='\b(Download|Search|Machine type|Serial number|Hybrid|Full text only|Current versions only|Change history|Ask the assistant|Was this helpful|Suche|Suchen|Dokumente?|Maschine|Seriennummer|Treffer|Frage an|Änderungshistorie|Nur aktuelle|Gelesen|Bulletin lesen)\b' ;;
  en) FOREIGN='\b(Suche|Suchen|Dokumente?|Maschinen?|Seriennummer|Treffer|Frage an|Änderungshistorie|Nur aktuelle|Gelesen bestätigen|Bulletin lesen|Freigegeben|Zur Prüfung|Baujahr|Vertrag|Standort|Quellen)\b' ;;
esac
for PAGE in "$MACH" "/$LANG_CODE/suche?q=E-4711"; do
  case "$PAGE" in /*) URL="$BASE$PAGE" ;; *) URL="$BASE$PAGE" ;; esac
  TEXT="$(curl -sk -b "$JAR" "$URL" | sed -e 's/<script[^>]*>.*<\/script>//g' -e 's/<[^>]*>/\n/g' | grep -v '^[[:space:]]*$')"
  echo "== $URL ($(printf '%s\n' "$TEXT" | wc -l | tr -d ' ') Textzeilen)"
  printf '%s\n' "$TEXT" | grep -E "$FOREIGN" | sort | uniq -c | sort -rn | head -30
done
echo "Erwartet: unter jeder Seite keine Trefferzeilen (Eigennamen/Codes begründen)."
