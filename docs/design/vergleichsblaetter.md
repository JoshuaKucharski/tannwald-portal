# Vergleichsblätter und Abnahme-Tabellen — Phase 2 (Stand 03.11.2026)

Massstab: [`leitplanken-v2.md`](leitplanken-v2.md) §11 (zehn Punkte je Seite) und §12 (Vergleichsblatt-Vorlage). Die
Messungen stammen aus den Theme-Berichten der Aufgaben 1.1 bis 1.4 und Z1/Z3 (Kontrast nach der WCAG-Formel aus den
Tokens, Fokusweg per Tab-Protokoll, Kopfhöhe und `scrollWidth` per DOM-Messung bei 1440 und 390 px). Die vollständigen
Vergleichsblätter je Seite mit allen Zuständen (Fokus, Fehler, Druck, Phone) liegen beim Betreiber; hier stehen die
Ergebnisse und die Paare, die im Repo liegen.

## Vorher / Nachher

| Seite | Vorher (PoC, 01.11.2026) | Nachher (Phase 2) | Was wurde entfernt |
|---|---|---|---|
| Anmelden | zweispaltig mit Bildmarke, Füllfläche | [`11-anmelden-1440.webp`](../screens/11-anmelden-1440.webp): Markenpanel mit Hallenbild, ein Titel, zwei Felder, ein Primärbutton, sieben Demo-Chips in zwei Gruppen, „?“-Fenster, GitHub-Button | Füllfläche, harter Schnitt |
| Meine Maschinen | Tabelle mit Kennzahl-Kästen | [`12-meine-maschinen-1440.webp`](../screens/12-meine-maschinen-1440.webp): Karten mit Typbild, Kopfzeile mit Bildmarke, Lupe und Sprachwechsler | Kennzahl-Kästen, Button, Navigationspunkt „Suche“ |
| Maschine | [`01-maschinen-detail-1440.png`](../screens/01-maschinen-detail-1440.png): Kartenwand | [`13-maschine-1440.webp`](../screens/13-maschine-1440.webp): Typenschild, Seriennummernbild, Dokumente als Tabelle mit Zeilenraster und Thumbs, Assistent rechts | Kartenwand |
| Dokument | Karten um Inhaltsverzeichnis und Text | [`14-dokument-1440.webp`](../screens/14-dokument-1440.webp): Typenschild mit Titelbild, Download als Primär, haftendes Inhaltsverzeichnis mit Scrollspy, Lesespalte 17 px, Zeitleiste, Druckansicht | Karten um TOC/Text |
| Bulletin | Kartenrahmen | [`15-bulletin-1440.webp`](../screens/15-bulletin-1440.webp): Bild, Datum, Hinweis zur Lesebestätigung, Kapitel mit Ankern | Kartenrahmen |
| Styleguide | — (neu) | [`16-styleguide-1440.webp`](../screens/16-styleguide-1440.webp): Typo-Skala, Raster, Farben mit Kontrasttabelle, alle Komponenten in fünf Zuständen | — |
| Suche | [`02-suche-1440.png`](../screens/02-suche-1440.png): Facetten als Kästen | Facetten-Seitenleiste (44-px-Zeilen, Zahl rechts), Modus als Reiter, Trefferkarten mit 3:2-Thumb, Filter-Sheet auf dem Phone | zweites Lösch-Kreuz, Brotkrumen |
| Assistent | Kasten unter der Dokumentliste | ein SDC in drei Modi (eingebettet, Panel 360 px neben der Suche, Sheet auf dem Phone mit Fokusfalle), alle Zustände | — |
| Redaktion | [`03-redaktion-freigabe-1440.png`](../screens/03-redaktion-freigabe-1440.png): Reiter und Karten | Navigation als Leiste, Review-Karten mit Dokumenttyp-, Maschinentyp- und Sprach-Pillen, Sheet „Zurückweisen“ | — |

## Abnahme-Tabellen (zehn Punkte, ✓ erfüllt, – nicht zutreffend)

Spalten: 1 ein Seitentitel · 2 Typo-Skala · 3 Raster, 44-px-Ziele, Kopf ≤ 120 px · 4 ein Primärbutton · 5 Farbe nur als
Signal · 6 Kontrast · 7 Fokus und Tastatur · 8 390 px ohne Querlauf, Bilder · 9 Mikrotexte de-CH · 10 Entfernt.

| Seite | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 |
|---|---|---|---|---|---|---|---|---|---|---|
| Anmelden | ✓ | ✓ | ✓ 44 px; Markenpanel-Kopf 124 px auf 390 (Phone-Hero: Entscheid Betreiber) | ✓ 1 | ✓ | ✓ 8.31:1 Weiss auf `#1F4E8C` | ✓ Tab 1–10 mit Ring, „?“ 48 × 48 | ✓ | ✓ | Füllfläche, harter Schnitt |
| Meine Maschinen | ✓ | ✓ | ✓ 117 px | ✓ 0 | ✓ | ✓ | ✓ Skip → Marke → Tabs → Lupe → DE EN FR → Avatar → Abmelden → Karten | ✓ 3 Typbilder | ✓ | Kennzahl-Kästen |
| Maschine | ✓ | ✓ | ✓ 117 px | ✓ 1 („Frage senden“) | ✓ | ✓ | ✓ Chip → Zeilen → Pillen → Download | ✓ Bild + 10 Thumbs | ✓ | Kartenwand |
| Dokument | ✓ | ✓ | ✓ 117 px | ✓ 1 (Download) | ✓ | ✓ | ✓ Download → Änderungshistorie → Pillen → TOC | ✓ Titelbild, Druck | ✓ | Karten um TOC/Text |
| Bulletin | ✓ | ✓ | ✓ 117 px | ✓ 0 | ✓ | ✓ | ✓ Brotkrumen → TOC | ✓ Bild | ✓ | Kartenrahmen |
| 403 / 404 | ✓ | ✓ | ✓ 117 px | ✓ 0 (Sekundär „Zu meinen Maschinen“) | ✓ | ✓ | ✓ | ✓ | ✓ erklärend, Weg zurück | Core-Text |
| Styleguide | ✓ | ✓ | ✓ 117 px | – (zeigt alle Zustände) | ✓ | ✓ 32 + 9 Paare | ✓ | ✓ | ✓ | – |
| Suche | ✓ | ✓ | ✓ 117 px | ✓ 1 („Suchen“) | ✓ | ✓ | ✓ Suchfeld → Löschen → Suchen → Modus → 13 Facetten; Sheets mit Fokusfalle | ✓ Thumbs 3:2 | ✓ | zweites Lösch-Kreuz, Brotkrumen |
| Bulletins | ✓ | ✓ | ✓ 117 px | ✓ 0 | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| Redaktion Freigaben | ✓ | ✓ | ✓ 117 px | – „Freigeben“ je Karte (Wireframe 03, Entscheid Betreiber) | ✓ | ✓ | ✓ Navigation 214 × 44 → Reiter → Karten | ✓ | ✓ | – |
| Redaktion Unbeantwortet, Support-Anfragen | ✓ | ✓ | ✓ 117 px | ✓ 0 | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| Nutzung, Kosten (Portal-Admin) | ✓ | ✓ | – Core-Toolbar des Admin-Kontos | ✓ 0 | – Toolbar | ✓ | ✓ | ✓ | ✓ | – |
| Compliance Audit, Exportregeln | ✓ | ✓ | ✓ 117 px | ✓ 0 bzw. 1 | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| Passwort zurücksetzen | ✓ | ✓ | ✓ Kopf 66 / 85 | ✓ 1 | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| Kopfzeile (Z3) | ✓ | ✓ Pillen 13/500 | ✓ 117 px; Lupe 44, Pillen 32 | ✓ 0 | ✓ Marke `currentColor` | ✓ 6.0 / 16.4 / 8.3 / 3.3 | ✓ | ✓ | ✓ „Suche“, „Sprache“ | Navigationspunkt „Suche“ |

## Gemessene Konstanten

| Was | Wert |
|---|---|
| Kontrast Text/Fläche (32 Token-Paare) | alle ≥ 4.5:1; Bedienelement-Ränder `#848E9B` 3.32:1 (Panel) / 3.01:1 (Fläche); Markenfarbe `#1F4E8C` auf Weiss 8.31:1, Hover `#163865` 11.75:1 |
| Fokusring | 2 px `outline`, 2 px Abstand, auf jedem Ziel sichtbar (Tab-Protokolle 1440 und 390) |
| Kopfhöhe | Desktop 101 px; Phone 117 px (Demo-Leiste einzeilig 32, Marke und Avatar 44, Tab-Zeile 40) |
| Bewegung | 120/180 ms, `prefers-reduced-motion` → 0 s (gemessen am „?“-Fenster, Sheets, Buttons) |
| Querlauf | `scrollWidth` 390 auf allen Seiten bei 390 px |
| Bilder | 3:2 mit `srcset` 320/800, Hero 16:9 1600 ohne `srcset`, erste Karte `eager`, Rest `lazy` |

Offen beim Betreiber (Teil 4 v2 der Anleitung): Phone-Hero auf der Anmeldeseite, Typo und Raster am Styleguide,
„Freigeben“ je Karte in der Freigabe-Queue.
