# Übersetzbare Konfiguration (Aufgabe 6.5)

Quelle der Wahrheit ist die Tabelle in `docs/i18n/config-translate.php` (Wörterbuch deutscher Quelltext → fr/en; Kopfzeile
je erzeugter Datei). Das Skript schreibt `config/sync/language/{fr,en}/<name>.yml`, fasst keine Datenbank an und ist
idempotent (`--check` prüft Vollständigkeit, aktuelle Quelltexte und aktuelle Dateien). Import: `ddev drush config:import -y`;
danach `ddev drush config:status` leer.

| Gruppe | Konfigurationsnamen | übersetzte Schlüssel |
|---|---|---|
| Felder | `field.field.node.{bulletin,dokument,maschine,zusammenfassung}.*`, `field.field.taxonomy_term.{dokumenttyp,firma,maschinentyp}.*`, `field.field.user.user.field_{firma,land}` | `label`, `description` |
| Listenwerte | `field.storage.node.field_{bulletin_art,exportklasse,vertrag}`, `field.storage.taxonomy_term.field_firma_art` | `settings.allowed_values[].label` |
| Content-Typen | `node.type.{bulletin,dokument,maschine,zusammenfassung}` | `name`, `description` |
| Vokabulare | `taxonomy.vocabulary.{dokumenttyp,firma,maschinentyp}` | `name`, `description` |
| Rollen | `user.role.{compliance,haendler,kunde,portal_admin,redaktion,techniker}` | `label` |
| Workflow | `workflows.workflow.redaktion` | `label`, Status- und Transitions-`label` |
| Views | `views.view.{admin_kosten,admin_nutzung,portal_bulletins,portal_maschinen,redaktion_bestaetigungen,redaktion_freigaben,redaktion_support_anfragen,redaktion_unbeantwortet}` | `display_options.title`, `fields.*.label`, `empty…content`, `exposed_form` (Anwenden, Sortieren nach), `arguments…exception.title`, `menu.title` |

**Bewusst nicht übersetzt:** Views-`label`, `description`, `display_title`, `admin_label` (nur im Verwaltungs-UI, Quellen- und
Abkürzungstexte); `core.date_format.*` (Namen nicht sichtbar); `system.site` (Name „myTannwald“, Slogan leer);
`block.block.tannwald_*` (ohne Titel); `system.menu.*` und Core-Konfiguration (liefert die Core-Übersetzung fr unter
`config/sync/language/fr/`, schon vorhanden). **Inhalt, nicht Konfiguration:** Namen der Terme (Dokumenttypen, Maschinentypen) und
Demo-Texte übersetzt der Seeder als Inhalts-Übersetzung.
