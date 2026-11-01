# tannwald-portal — Regeln für KI-gestütztes Arbeiten (Kurzfassung)

Volle Fassung und Gründe: `/Users/myki/dev/helga/CLAUDE.md` (Stamm) und `/Users/myki/dev/helga/docs/project-facts.md`
(Stack, Befehle, Datenwelten, Fallen). Vor der ersten Änderung beide lesen. Diese Datei gilt nur zusammen mit ihnen.

## Die fünf Regeln

1. **Nie nach `main`, nie deployen, kein eigener Branch** — Arbeit auf `dev`, Push nur `dev`; `dev → main` merged
   ausschliesslich der Betreiber.
2. **`git add` nie pauschal** — Commit nur mit Pfaden (`git commit -m "…" -- <pfade>`), keine Co-Authored-By-Zeile;
   vorher `git status --short` ansehen, fremde Pfade nie nennen.
3. **Keine volle Suite** — gezielt `ddev exec vendor/bin/phpunit --filter=<Name>` mit einer Gegenprobe (Rückbau aus
   einer Kopie, nie `git checkout`); die volle Suite läuft einmal beim Betreiber.
4. **Bezeichner englisch, Kommentare deutsch — mit D5-Klausel:** Bezeichner, die das Pflichtenheft vorgibt
   (`field_exportklasse`, Rollen `kunde`/`haendler`, `tannwald_chunk`, `tannwald:demo-install`, `zur_pruefung` …),
   bleiben wörtlich wie dort; alles Ungenannte heisst englisch. Oberfläche de-CH („ss“, „Sie“), Quellstrings
   englisch, deutsche Übersetzung je Modul in `translations/de.po`.
5. **Belegen durch Ausführen** — `ddev drush …`, `curl -sk https://tannwald-portal.ddev.site/…`; vor jeder Messung
   `ddev drush cr`, nach Grant-Änderungen `ddev drush php:eval 'node_access_rebuild();'`.

## Gemessene Befehle (Aufgabe 1.1, 2026-11-01, Apple M1, DDEV 1.25.5 mit Mutagen)

Alle Befehle im Repo-Ordner `/Users/myki/dev/helga/tannwald-portal` (`ddev` braucht ihn als Arbeitsverzeichnis).

| Zweck | Befehl | Dauer |
|---|---|---|
| Start | `ddev start` | 20 s (erster Start mit Image-Download: 553 s) |
| Einrichten / Probe | `bash /Users/myki/dev/helga/docs/ops/tools/tannwald-run.sh --lokal` · `… --probe` | 68 s auf leerer Datenbank, 26 s danach · 7 s |
| Abhängigkeiten | `ddev composer install` | 3 s |
| Neuaufbau (nur auf Ansage) | `ddev drush site:install --existing-config -y && ddev drush config:import -y` | 22 s + 6 s |
| Test gezielt | `ddev exec vendor/bin/phpunit --filter=<Name>` | 0.9 s (`--filter=ConfigSyncGuardTest`; ein leerer Filter endet mit Exit 0, „No tests executed!“) |
| Testsuite | `ddev exec vendor/bin/phpunit --testsuite=unit\|kernel\|functional` — volle Suite nur der Betreiber | — |
| PHPCS | `ddev exec vendor/bin/phpcs` (Ruleset `phpcs.xml.dist`) · gezielt `ddev exec vendor/bin/phpcs <dateien>` | 1.0 s |
| Formatter | `ddev exec vendor/bin/phpcbf <eigene neue dateien>` | — |
| PHPStan | `ddev exec vendor/bin/phpstan analyse` (Level 5, `phpstan.neon`) | 3.4 s |
| Syntax | PHP `php -l <datei>` (Host) · YAML `ddev drush php:eval "\Symfony\Component\Yaml\Yaml::parseFile('/var/www/html/<datei>');"` | — |
| Konfiguration | `ddev drush config:status` (muss leer sein) · Export nur der Besitzer: `ddev drush config:export -y` | 2 s · 3 s |
| Caches | `ddev drush cr` | 5 s |
| Login ohne Passwort | `ddev drush user:login --name=admin --no-browser` (lokal admin / `demo`) | — |
| Ingestion (3.1) | `ddev drush tannwald:reindex [--nid=]` (Jobs für alle veröffentlichten Revisionen) · `ddev drush tannwald:queue-run` (Queue `tannwald_embed` bis leer, echtes Ollama) · `ddev drush tannwald:chunk-stats` | 28 Chunks: 2 Batches, 5.9 s warm; erster Embedding-Aufruf kalt 2.5 s |

**Adressen:** Site `https://tannwald-portal.ddev.site:30001` (Port fest in `.ddev/config.yaml`, weil Laravel Valets
nginx auf dem Mac 80/443 hält) · Mailpit `https://tannwald-portal.ddev.site:8026` · Ollama aus dem Container
`http://host.docker.internal:11434`. **Testpfade:** `tests/src/Unit` und
`web/modules/custom/<modul>/tests/src/{Unit,Kernel,Functional}` (Suites `unit`, `kernel`, `functional` in
`phpunit.xml`). **Splits:** `dev` (masquerade, queue_ui) aktiv über `IS_DDEV_PROJECT`, `ci` über `CI`
(`web/sites/default/settings.php`).

## Heisse Dateien

- **`config/sync/**`** — je Welle **ein** Besitzer (RunBook-Arbeitskopie E). Nicht-Besitzer exportieren nie nach
  `config/sync`, sondern `ddev drush config:export --destination=/tmp/<buchstabe>-cfg -y` und kopieren **nur eigene**
  Dateien nach `config/sync/`; `core.extension.yml` nach `git pull --rebase` frisch lesen und nur den eigenen
  Modul-Eintrag ergänzen. Modul-eigene Konfiguration gehört nach `config/install` bzw. `config/optional` des Moduls.
  Nach jedem Commit ist `ddev drush config:status` leer.
- **`composer.json` und `composer.lock`** — immer im selben Commit. Neues Contrib-Modul nur nach Meldung an den
  Master: `git pull --rebase`, `ddev composer require …`, beide Dateien sofort committen. Nach jedem Pull, der
  `composer.lock` ändert: `ddev composer install`.
- **Eine DDEV-Datenbank für alle Terminals** — `site:install`, `sql:drop`, `config:import`, `tannwald:demo-reset`,
  `ddev restart` nur auf Ansage des Masters.
- **Jeder Zwischenstand muss laden** — `php -l <datei>` vor dem Ablegen jeder PHP-Datei; eine neue Testdatei sofort
  einmal gezielt laufen lassen (ein Fatal in einer Testdatei bricht die `--filter`-Läufe aller Terminals).
- **Wächter `IdentifierLanguageTest`** (Regel 4, D5): prüft Bezeichner in `web/modules/custom` und `web/themes/custom`
  auf deutsche Wortstämme; Erlaubnisliste als Konstante `ALLOWED` in
  `web/modules/custom/tannwald_access/tests/src/Unit/IdentifierLanguageTest.php` — wer einen neuen Pflichtenheft-Namen
  einführt, trägt ihn dort im selben Commit nach (`ddev exec vendor/bin/phpunit --filter=IdentifierLanguageTest`).

## Geheimnisse

`web/sites/default/settings.local.php` und `.ddev/.env` weder anlegen noch lesen noch zählen; im PoC gibt es keine
Geheimnisse (Ollama ist schlüssellos, DDEV-Datenbank `db/db`, Demo-Passwort `demo`).
