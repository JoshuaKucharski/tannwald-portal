# myTannwald Kundenportal – Wireframes (Block 5)

Drei Screens des 48-Stunden-Proof-of-Concept für den fiktiven Kunden Tannwald Verpackungstechnik AG.
Bezug: `docs/lastenheft.yaml` (v1.0) und `docs/pflichtenheft.md`, Abschnitt 6.5 (Theme).

## Inhalt

| Datei | Screen | Rolle |
|---|---|---|
| `index.html` | Übersicht mit Links zu den drei Screens | – |
| `01-maschinen-detail.html` | Maschinen-Detail TB200-2021-0417 mit Dokumentliste, Sicherheits-Bulletin, Dual-Use-Badge und Assistent | kunde-a |
| `02-suche.html` | Suche mit Schalter Hybrid / Nur Volltext, Live-Resultaten, Facetten, Treffer «über Bedeutung» | kunde-a |
| `03-redaktion-freigabe.html` | Freigabe-Queue für KI-Zusammenfassungen, Vier-Augen-Hinweis, unbeantwortete Fragen | redaktion |
| `png/*.png` | Screenshots der drei Screens, 1440 px breit | – |
| `source/` | Quelldateien des Design-Canvas (`canvas.json`, `*.dc.html`) zum Re-Import | – |

Die HTML-Dateien sind eigenständig: Doppelklick öffnet sie im Browser, die Navigation verlinkt die Screens untereinander.
Einzige externe Abhängigkeit ist die Schrift Inter von Google Fonts; ohne Netz fällt sie auf die Systemschrift zurück.

## Demo-Momente pro Screen

**01 Maschinen-Detail**
- Sicherheits-Bulletin bestätigen; die Bestätigung wird pro Nutzer und Seriennummer protokolliert (FA-DP-04).
- Dual-Use-Badge am Schaltplan: für Händler ohne Freigabe unsichtbar (FA-DP-05).
- Anlagendokumentation nur für Pharmalog AG: der Leak-Test (FA-DP-09, TC-02).
- Assistent antwortet mit Zitaten, Klick springt ins Kapitel (FA-AI-01); Ablehnung mit Support-Anfrage (FA-AI-03).

**02 Suche**
- «Folie reisst beim Siegeln» findet Kapitel 9.4 nur im Hybrid-Modus, weil kein Wort übereinstimmt (FA-SE-01, TC-06).
- «E-4711» trifft in beiden Modi die Störungstabelle.
- Facetten und Trefferzahl zeigen nur Dokumente, die der Nutzer sehen darf (FA-SE-04).

**03 Redaktion**
- Freigeben: Zusammenfassung erscheint am Dokument, Indexierung läuft über die Queue (FA-AI-06, TC-10).
- Zurückweisen: bleibt in Suche und Assistent unsichtbar.
- Sicherheits-Bulletin: Vier-Augen-Prinzip, die Erstellerin darf nicht freigeben (FA-RED-01).
- Unbeantwortete Fragen als Hinweis auf Dokumentationslücken (FA-AI-07).

## Gestaltung

Wireframe-Stufe mit einer Akzentfarbe (#1F4E79), Schrift Inter, Grundfläche #F3F4F2.
Für die Umsetzung im Theme `tannwald` (Twig, SDC, Tailwind 4, Alpine.js) sind die Komponenten im Pflichtenheft benannt:
`doc-row`, `bulletin-banner`, `lang-pills`, `badge`, `chat`, `facet-group`, `search-live`, `review-card`.
Alle interaktiven Elemente sind echte `<button>`, `<a>`, `<input>` mit Labels; Kontraste erfüllen 4.5:1.

Fiktiver Kunde. Firma, Personen und Daten sind erfunden.
