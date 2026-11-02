# Rechtematrix

**Stand:** finale Fassung aus Aufgabe 2.4 (2026-11-01) auf A's Füllung aus 2.1. Grundrechte (Core-Permissions) und die
`tannwald …`-Permissions stehen in `config/sync/user.role.*.yml`; Sichtbarkeit rechnet `tannwald_access`
(`EntitlementService`, Node-Grants). Belegt durch `NodeGrantsTest`, `EntitlementRealmsTest`, `DocumentLeakTest`,
`NotOwnBulletinApprovalTest`, `ExportRulesFormTest` (Modul `web/modules/custom/tannwald_access/tests`).

Grundlage: Pflichtenheft §5.3 (Rollen), §5.5 (Realms), §6.1 (Permissions), §9 (Sicherheit); RunBook §1.1 (Realms,
Workflow). Sichtbar ist ein Node, wenn seine Keys und die Keys des Nutzers eine nicht leere Schnittmenge haben
(Abschnitt „Realms und Keys“). Keine Rolle ausser `administrator` hat `bypass node access`.

| Rolle | anzeigen | herunterladen | freigeben | Exportregeln | Audit-Log |
|---|---|---|---|---|---|
| `kunde` | veröffentlichte Dokumente und Bulletins mit einem Typ im Bestand der eigenen Firma (`type:<code>`), anlagenspezifische nur mit Seriennummer der eigenen Firma (`serial:<nid>`), Exportklasse nur bei Freigabe für Land und Firma (`+export:<klasse>`); Maschinen der eigenen Firma (`firma:<tid>`, View `/meine-maschinen`); Permission `tannwald view own company documents` | wie anzeigen (`/dokument/{nid}/download`: `node.view`), jeder Download Audit `download` | nein | nein | nein |
| `haendler` | wie `kunde`, Typen = Bestand der Kundenfirmen, deren `field_land` im `field_haendler_gebiet` der Händlerfirma liegt; **kein** `serial:` (anlagenspezifisch bleibt der besitzenden Firma); Exportregeln nach eigenem Land und Firma; Maschinen der Kundenfirmen im Gebiet; `tannwald view own company documents` | wie anzeigen | nein | nein | nein |
| `techniker` | alle veröffentlichten Dokumente, Bulletins, Maschinen (`role:intern`), Exportregeln gelten (`role:intern+export:<klasse>`); `view all revisions`; `tannwald view all documents` | wie anzeigen | nein | nein | nein |
| `redaktion` | wie `techniker`, dazu unveröffentlicht (`view any unpublished content`, `view latest version`); `tannwald view all documents` | wie anzeigen (Entwürfe nur mit Zugriff auf den Entwurf) | ja: Transitionen `zur_pruefung_senden`, `freigeben`, `zurueckweisen`, `entwurf_speichern`; eigenes Sicherheits-Bulletin nicht (Constraint `NotOwnBulletinApproval`, auch im Moderationsformular) | nein | nein |
| `compliance` | wie `redaktion` (alle, auch unveröffentlicht, Exportregeln gelten); `tannwald view all documents` | wie anzeigen | ja: `freigeben`, `zurueckweisen` (zweites Augenpaar für Sicherheits-Bulletins der Redaktion) | ja: `/compliance/exportregeln` (`tannwald manage export rules`) | ja: `/compliance/audit`, CSV `/compliance/audit.csv` (`tannwald view audit log`) |
| `portal_admin` | wie `redaktion` und `compliance` (`administer nodes`, nicht `bypass node access`; Exportregeln gelten auch hier) | wie anzeigen | ja: wie `redaktion` und `compliance`; eigenes Sicherheits-Bulletin nicht | ja | ja |
| anonym | nichts aus den Bundles `dokument`, `bulletin`, `maschine`, `zusammenfassung` (keine Keys) | nein (403, Audit `zugriff_verweigert` mit `uid 0`) | nein | nein | nein |

**Was Permissions und was Grants tun:** Die vier `tannwald …`-Permissions schalten **nicht** die Sichtbarkeit; die
rechnet `EntitlementService` aus Rolle, Firma, Land, Maschinenbestand und Exportregeln. `role:intern` hängt an den Rollen
`techniker`, `redaktion`, `compliance`, `portal_admin`, nicht an `tannwald view all documents`. Die Permissions steuern
Menüs, Listen, das Exportregel-Formular und die Audit-Seite.

## Realms und Keys

Format (Vertrag 2.4): jeder Key `<basis>+export:<klasse>` mit `basis` ∈ `type:<typ_code>` · `serial:<nid>` ·
`role:intern` und `klasse` ∈ `keine` · `klasse_1` · `klasse_2`; Maschinen-Keys ohne Export-Teil: `firma:<tid>`,
`role:intern`. Drupal-Grants und `access_keys` sind ODER-verknüpft — darum trägt jeder Key beide Bedingungen.

| Seite | Keys |
|---|---|
| `dokument` | je `field_maschinentyp` `type:<code>+export:<k>`; ist `field_seriennummer` gesetzt, **stattdessen** `serial:<nid>+export:<k>` (FA-DP-09); immer `role:intern+export:<k>`; `k` = `field_exportklasse` (leer → `keine`) |
| `bulletin` | wie Dokument mit `k = keine`, dazu `serial:<nid>+export:keine` je `field_seriennummern` |
| `zusammenfassung` | die Keys des Dokuments aus `field_dokument` (ohne Dokument keine) |
| `maschine` | `firma:<tid>` (`field_firma`), `role:intern` |
| Nutzer `kunde` | je Maschine der eigenen Firma `serial:<nid>` und `type:<code>`; `firma:<eigene tid>` |
| Nutzer `haendler` | je Maschine der Kundenfirmen im Gebiet `type:<code>`; `firma:<tid>` je solcher Firma |
| Nutzer intern | `role:intern` (Basis) und `role:intern` (Maschinen) |
| Export (Nutzer) | je Basis immer `+export:keine`; `+export:klasse_1`/`klasse_2`, wenn `field_land` in `export_rules.<k>.laender` **und** `field_sap_nr` der Firma nicht in `export_rules.<k>.gesperrte_firmen`; ohne Land keine Klasse |

| Key | Realm | gid |
|---|---|---|
| `type:<code>+export:<k>` | `tannwald_type_<k>` | Term-ID des `maschinentyp` mit diesem `field_typ_code` (kleinste) |
| `serial:<nid>+export:<k>` | `tannwald_serial_<k>` | Maschinen-NID |
| `role:intern+export:<k>` | `tannwald_intern_<k>` | `1` |
| `firma:<tid>` | `tannwald_firma` | Term-ID der Firma |
| `role:intern` | `tannwald_intern` | `1` |

Records nur für veröffentlichte Übersetzungen (`grant_view = 1`, `grant_update = grant_delete = 0`); geschützte Bundles
ohne Key erhalten einen ungeschriebenen Sperr-Record `tannwald_closed`, damit Core keinen Standard-Grant `all` setzt.
Nach Imports und Modulinstallation: `\Drupal::service(\Drupal\node\NodeAccessRebuild::class)->rebuild()`.

## Konfigurierte Permissions

| Rolle | Core-Permissions (2.1) | `tannwald …` (2.4) |
|---|---|---|
| `kunde`, `haendler` | `access content` | `tannwald view own company documents` |
| `techniker` | `access content`, `view all revisions` | `tannwald view all documents` |
| `redaktion` | `access content`, `access content overview`, `view any unpublished content`, `view latest version`, `view all revisions`, `revert all revisions`, `use text format dokument_html`, `create/edit any/delete own dokument content`, `create/edit any/delete own bulletin content`, `edit any/delete own zusammenfassung content`, `use redaktion transition zur_pruefung_senden`, `… freigeben`, `… zurueckweisen`, `… entwurf_speichern`, `create/update/delete content translations`, `translate dokument node`, `translate bulletin node`, `translate zusammenfassung node` | `tannwald view all documents` |
| `compliance` | `access content`, `access content overview`, `view any unpublished content`, `view latest version`, `view all revisions`, `use redaktion transition freigeben`, `use redaktion transition zurueckweisen`, `use text format dokument_html` | `tannwald view all documents`, `tannwald manage export rules`, `tannwald view audit log` |
| `portal_admin` | alle von `redaktion` und `compliance`, dazu `create zusammenfassung content`, `administer users`, `administer taxonomy`, `administer nodes`, `access administration pages`, `view the administration theme`, `access navigation` | `tannwald view all documents`, `tannwald manage export rules`, `tannwald view audit log` |

**Hinweis (A, 2.1):** `field_firma` und `field_land` am Nutzer stehen nicht im Konto-Formular (sonst wählte ein Kunde
seine Firma selbst und erbte deren Rechte); die Werte kommen aus SAP (Seeder). Wer sie in der Oberfläche pflegbar
macht, braucht einen Feldzugriff nur für `administer users` (`hook_entity_field_access`).
