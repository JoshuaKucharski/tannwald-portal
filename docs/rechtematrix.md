# Rechtematrix

**Stand:** erste Füllung aus Aufgabe 2.1 (2026-11-01). Die Grundrechte (Core-Permissions) sind konfiguriert und in
`config/sync/user.role.*.yml` exportiert; was mit **(2.4)** markiert ist, ist der **Zielwert**, den C in 2.4 über
`tannwald_access` umsetzt (Realms, Grants, `tannwald …`-Permissions, Download-Controller, Audit). C schreibt die finale
Fassung und darf diese Zeilen ändern.

Grundlage: Pflichtenheft §5.3 (Rollen), §5.5 (Realms), §6.1 (Permissions), §9 (Sicherheit); RunBook §1.1 (Realms,
Workflow). Sichtbar ist ein Dokument, wenn (gemeinsamer `type:` **oder** `serial:`-Treffer **oder** `role:intern`)
**und** (kein `export:`-Key **oder** derselbe Key beim Nutzer); anlagenspezifische Dokumente verlangen den
`serial:`-Treffer. Keine Rolle ausser `administrator` hat `bypass node access`.

| Rolle | anzeigen | herunterladen | freigeben | Exportregeln | Audit-Log |
|---|---|---|---|---|---|
| `kunde` | freigegebene Dokumente und Bulletins der Maschinentypen im Bestand der eigenen Firma, anlagenspezifische nur mit Seriennummer der eigenen Firma, Exportregeln gelten **(2.4)**; Maschinen der eigenen Firma unter `/meine-maschinen` **(2.4, Grants `firma:<tid>`)** | wie anzeigen, jeder Download im Audit **(2.4)** | nein | nein | nein |
| `haendler` | wie `kunde`, Typen = Bestand der Kundenfirmen im eigenen Gebiet (`field_haendler_gebiet`), Exportregeln nach eigenem Land **(2.4)** | wie anzeigen **(2.4)** | nein | nein | nein |
| `techniker` | alle Maschinentypen (`role:intern`), nur freigegebene Fassungen, alle Revisionen (`view all revisions`), Exportregeln gelten **(2.4)** | wie anzeigen **(2.4)** | nein | nein | nein |
| `redaktion` | alle (`role:intern`), auch unveröffentlicht (`view any unpublished content`, `view latest version`), Exportregeln gelten **(2.4)** | wie anzeigen **(2.4)** | ja: alle vier Transitionen auf `dokument`, `bulletin`, `zusammenfassung`; eigenes Sicherheits-Bulletin nicht (Constraint `NotOwnBulletinApproval`, **2.4**); legt `dokument` und `bulletin` an, `zusammenfassung` nicht | nein | nein |
| `compliance` | alle (`role:intern`), auch unveröffentlicht, Exportregeln gelten **(2.4)** | wie anzeigen **(2.4)** | ja: `freigeben`, `zurueckweisen` (zweites Augenpaar, z. B. Sicherheits-Bulletins der Redaktion) | ja: `/compliance/exportregeln` (`tannwald manage export rules`) **(2.4)** | ja: `tannwald view audit log`, CSV `/compliance/audit.csv` **(2.4)** |
| `portal_admin` | alles von `redaktion` und `compliance` (`administer nodes`, nicht `bypass node access`) **(2.4)** | wie anzeigen **(2.4)** | ja: wie `redaktion` und `compliance`; legt als einzige Rolle `zusammenfassung` über die Oberfläche an | ja **(2.4)** | ja **(2.4)** |

## Konfigurierte Core-Permissions (2.1)

| Rolle | Permissions |
|---|---|
| `kunde`, `haendler` | `access content` |
| `techniker` | `access content`, `view all revisions` |
| `redaktion` | `access content`, `access content overview`, `view any unpublished content`, `view latest version`, `view all revisions`, `revert all revisions`, `use text format dokument_html`, `create/edit any/delete own dokument content`, `create/edit any/delete own bulletin content`, `edit any/delete own zusammenfassung content`, `use redaktion transition zur_pruefung_senden`, `… freigeben`, `… zurueckweisen`, `… entwurf_speichern`, `create/update/delete content translations`, `translate dokument node`, `translate bulletin node`, `translate zusammenfassung node` |
| `compliance` | `access content`, `access content overview`, `view any unpublished content`, `view latest version`, `view all revisions`, `use redaktion transition freigeben`, `use redaktion transition zurueckweisen`, `use text format dokument_html` |
| `portal_admin` | alle von `redaktion` und `compliance`, dazu `create zusammenfassung content`, `administer users`, `administer taxonomy`, `administer nodes`, `access administration pages`, `view the administration theme`, `access navigation` |

**Hinweis für 2.4:** `field_firma` und `field_land` am Nutzer stehen nicht im Konto-Formular (sonst wählte ein Kunde
seine Firma selbst und erbte deren Rechte); die Werte kommen aus SAP (Seeder). Wer sie in der Oberfläche pflegbar
macht, braucht einen Feldzugriff nur für `administer users` (`hook_entity_field_access`).
