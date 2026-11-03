# Design-Leitplanken myTannwald — v2 (gilt für alle Theme-Aufträge ab 2026-11-02, Programm „myTannwald Phase 2“)

**Änderungsvermerk (1.4, 2026-11-02 21:30):** §13 Phone-Kopf, Hover nur mit `(hover: hover)`, Inline-Link-Regel; Abnahme-Punkt 3 um den Phone-Kopf ergänzt.
**Änderungsvermerk:** v2 ersetzt v1 (2026-11-01 21:10, unten als Abschnitt „Herkunft“ erhalten). Neu: Inter als selbst gehosteter
Webfont, Typo-Skala 13/15/17/22/32/44, 4-/8-pt-Raster mit 12 Spalten, Weissraum-Stufen, Farbe nur als Signal mit gemessener
Kontrasttabelle, Bildsprache (3:2, 16:9, Platzhalter), Komponenten-Katalog mit Zuständen, Bewegungsregeln, Mikrotexte,
Barrierefreiheit, Abnahme-Tabelle je Seite (10 Punkte) und Vergleichsblatt-Vorlage. Der Massstab ist messbar; jeder Theme-Bericht
belegt ihn mit Screens, Messwerten und einem Vergleichsblatt je Seite. Umsetzung: Theme `tannwald`, `src/tailwind.css` (Tokens
und Klassen), `/styleguide` (nur Split `dev`), ADR-010 (Bericht 1.1 beim Betreiber).

## 1. Haltung — Zurückhaltung mit Präzision

Ein Kundenportal einer Schweizer Verpackungsmaschinen-Firma: präzise, ruhig, vertrauenswürdig, nüchtern. Keine SaaS-Kartenwand,
keine Verläufe, keine Dekoration. Hierarchie entsteht durch Weissraum und Schriftgrösse, nicht durch Farbe oder Rahmen. Bilder sind
tragend (echte Maschinen, 3:2 und 16:9), alles andere ist diszipliniert. Eine Sache darf auffallen: die Wortmarke mit Bildmarke und
die Antwort-Karte des Assistenten. Chanel-Regel: vor dem Abgeben eine Zutat entfernen — und im Bericht sagen, welche.

## 2. Typografie — Inter, sechs Grössen, drei Gewichte

Inter (rsms, SIL OFL 1.1) als selbst gehosteter Webfont aus `@fontsource/inter`, Schnitte 400/500/600, latin + latin-ext, `woff2`
unter `dist/fonts/`, `font-display: swap`, Lizenztext `fonts/LICENSE-Inter.txt`. Kein externer Request (gemessen: ein Host).
Wurzel 16 px (Raster), Text 15 px.

| Klasse | Grösse / Zeilenhöhe | Gewicht | Verwendung |
|---|---|---|---|
| `text-3xl` Hero | 44 px / 1.1 | 600 | nur Markenpanel der Anmeldung |
| `text-2xl` Display | 32 px / 1.2 | 600 | **ein** Seitentitel je Seite (`page-title`) |
| `text-xl` Titel | 22 px / 1.3 | 600 | Kartentitel, Abschnitte (`card-title`) |
| `text-lg` Lead | 17 px / 1.5 | 400 | Dokumenttext (`prose-doc`), Einleitungszeilen |
| `text-base` Text | 15 px / 1.5 | 400 (Label 500) | Oberfläche, Formulare, Tabellen |
| `text-sm` Meta | 13 px / 1.5 | 400 (Badge 500/600) | Datum, Version, Hilfe, Pillen, Badges (`meta`) |

Weitere Grössen gibt es nicht (`text-xs`, `text-[28px]` sind entfernt; `--text-*: initial`). Zeilenhöhen als Utilities:
`leading-copy` 1.5, `leading-title` 1.3, `leading-display` 1.2, `leading-hero` 1.1, `leading-none`. Gewichte: `font-normal` 400,
`font-medium` 500 (Label, Navigation, Sekundärbutton), `font-semibold` 600 (Titel, Primärbutton, Badges). Regeln: Satzschreibung
(keine Versalien, `uppercase` = 0 gemessen), kein Titel doppelt, Zeilenlänge ≤ 80 Zeichen (`max-w-prose` 720 px), Zahlen tabellarisch
(`num`) mit `’` als Tausendertrenner, Datum `TT.MM.JJJJ`, Meta-Zeilen mit „·“ nur wo sie Information trennen.

## 3. Raster — 4-pt fein, 8-pt Basis, 12 Spalten

- Feinraster 4 px (`--spacing: 0.25rem`), Basisraster 8 px: Abstände sind Vielfache von 8, Innenabstände kleiner Elemente von 4.
- Spaltenraster 12 auf 1376 px (`--container-page`, `max-w-page`), Gutter 24 px (`gap-6`), Phone 16 px (`gap-4`); Seitenrand 32 px
  (`px-8`), Phone 16 px (`px-4`). Formulare `max-w-form` 400 px, Lesespalte `max-w-prose` 720 px.
- Bedienelemente 44 px hoch (`h-11`), Radius Buttons/Felder 6 px (`--radius-control`), Karten 8 px (`--radius-card`), Pillen 12 px.
- Breakpoints Tailwind: `md` 768 (zwei Spalten, Seitenleiste), `lg` 1024 (Login zweispaltig, Chat als Panel), Phone 390 ist der Prüfwert.

## 4. Weissraum — Abstandsstufen und wo sie gelten

| Token | px | Gilt für |
|---|---|---|
| `--space-1` | 4 | Innenabstand Pille/Badge vertikal, Abstand Icon → Text (`gap-1`) |
| `--space-2` | 8 | Chips untereinander, Badge-Reihen, Abstand Label → Feld (6 ≈ `gap-1.5`) |
| `--space-3` | 12 | Zellen-Innenabstand horizontal, Abstand Titel → Meta, Buttons in einer Reihe (`gap-3`) |
| `--space-4` | 16 | Formular-Zeilenabstand, Karten-Innenabstand Phone, Listenzeilen vertikal, Seitenrand Phone |
| `--space-6` | 24 | Karten-Innenabstand Desktop (`p-6`), Gutter, Abstand zwischen Karten (`gap-6`) |
| `--space-8` | 32 | Seitenrand Desktop, Abstand Seitenkopf → Inhalt (`mb-8`), Abstand zwischen Kapiteln in Dokumenten (`mt-10` = 40) |
| `--space-12` | 48 | Abstand zwischen Seitenabschnitten (`gap-12`), Demo-Konten-Block unter dem Formular |
| `--space-16` | 64 | Markenpanel-Innenabstand Desktop (`px-16`, `py-14`) |

Regel: Weissraum zeigt Zusammengehörigkeit — innerhalb einer Gruppe höchstens `--space-4`, zwischen Gruppen mindestens `--space-6`,
zwischen Abschnitten `--space-12`. Rahmen nur dort, wo Weissraum nicht genügt (Tabellen: Zeilenraster, keine Zellrahmen).

## 5. Farbe — nur als Signal

Zwei Graustufen für Flächen (`surface #F3F4F2` Seite, `panel #FFFFFF` Karten), Tinte `ink #1C2024`, Meta `muted #5B6470`, Trenner
`line #D6DAE0` / `line-soft #ECEEF1`, Ränder von Bedienelementen `control #848E9B` (neu, ≥ 3:1). **Ein** Akzent `accent #1F4E79`
(Hover `#15385A`, Aktiv `#102A44`) nur für Aktion und Link (Primärbutton, Textlink, aktive Navigation, gewählter Chip, Fokusring).
Status-Farben nur, wo Status Information ist: `ok`, `warn`/`warn-strong`, `danger`, `dualuse`, `private`, je mit `-bg`. Keine
Verläufe (`gradient` = 0 gemessen), **ein** Schattenlevel `--shadow-card` (0 1px 2px rgba(28,32,36,.06)); Tiefe entsteht durch
Rahmen und Fläche, nicht durch Schatten. Treffer-Hervorhebung `mark #FFF3B0`. Alle Paare gemessen (Styleguide, Bericht §3 (g)):
Text ≥ 4.5:1, UI ≥ 3:1, kein Paar darunter.

## 6. Bildsprache

- Seitenverhältnisse: **3:2** für Karten und Detailseiten (`aspect-card`, `figure--card`), **16:9** für den Hero (`aspect-hero`,
  `figure--hero`), 1:1 nur für Avatare/Initialen. Der Rahmen steht vor dem Laden (kein Springen), Bild `object-cover`.
- Bildstile (C, 2.2): `tw_hero_1600` (16:9, 1600 w), `tw_card_800` (3:2, 320 w + 800 w `srcset`), `tw_thumb_320`; WebP, ≤ 400 KB
  Quelle, Alt-Text dreisprachig aus dem Manifest. Theme-Asset nur `images/hero-login.webp` (Login hat keinen Node).
- Einbau: `tannwald_image($entity, $style, ['fallback' => $typeTerm])` liefert `<img>` mit `srcset`/`sizes`/`loading` oder den
  Platzhalter (`div.tw-image--placeholder`); eingebettet in `tannwald:figure` (Slot `image`) oder direkt als `.tw-image` mit
  festem Verhältnis.
- Platzhalter-Regel: fehlt das Bild, zeigt die Fläche `line-soft` die Bildmarke (drei Blister-Rechtecke) zentriert, `aria-hidden`
  bzw. `role="img"` mit Label „Kein Bild vorhanden“; nie ein fremdes Symbolbild, nie ein leerer weisser Kasten.
- Alt-Texte nennen, was zu sehen ist („Blisterlinie TB-200, Bedienerseite“), keine Marken, keine Floskeln; dekorative Bilder `alt=""`.
- Stil (D5): fotorealistisch, Edelstahl, GMP, neutrales Tageslicht, keine Personen, keine Texte. Bilder ohne Rahmen und Schatten.

## 7. Komponenten-Katalog mit Zuständen

Zustände: **Ruhe · Hover · Fokus · Aktiv · Deaktiviert · Fehler** (wo sinnvoll). Fokus ist immer der globale Ring (2 px Akzent,
2 px Abstand, `:focus-visible`); Hover und Aktiv ändern nur Fläche/Rand/Farbe, nie Grösse oder Position. Deaktiviert: 45 % Deckkraft,
`cursor: not-allowed`, kein Hover. Im Styleguide erzwingen `data-state="hover|active|focus"` die Zustände.

| Komponente | Klassen / SDC | Ruhe | Hover | Aktiv | Deaktiviert / Fehler |
|---|---|---|---|---|---|
| Primärbutton (einmal je Ansicht) | `btn btn-primary` | Akzent, Weiss, 600 | `accent-hover` | `accent-active` | `disabled` 45 % |
| Sekundärbutton | `btn btn-secondary` | Panel, Rand `control`, Tinte, 500 | Rand `ink` | Fläche `surface` | `disabled` |
| Textbutton / Textlink | `btn-text` | Akzent, 500, 44-px-Trefffläche | Unterstrich, `accent-hover` | `accent-active` | `muted`, kein Unterstrich |
| Warn-Button („Gelesen bestätigen“) | `btn btn-warn` | `warn-strong`, Weiss | `warn` | `warn` | `disabled` |
| Icon-Button | `btn … btn-icon` | 44 × 44, `aria-label` | wie Variante | wie Variante | |
| Chip (wählbar) | `chip` (+ `aria-pressed`) | Pille, Rand `control` | Rand `ink` | Fläche `surface` | gewählt: `private-bg`, Akzent, 500 |
| Feld | `field`, `field-label`, `input`, `field-help`, `field-error` | 44 px, Rand `control`, Label 13/500 oben | Rand `ink` | Fokusring | ungültig: Rand `danger` + Fehlersatz; `disabled`: `surface`, `muted` |
| Auswahl, Textfeld | `input input--select`, `input input--textarea` | wie Feld, eigener Pfeil (SVG) | | | |
| Pille / Badge | `pill`, SDC `tannwald:badge` (`variant`, `pill`) | 13 px, 500/600, Farbwelt der Tokens | — (statisch) | — | — |
| Karte | `card` (Rahmen + Schatten), `card--plain` (ohne), `card-head`, `card-body`, `card-foot` | | — | — | |
| Seitenkopf | `page-head`, `page-title`, `page-meta`, `page-actions` | Titel Display einmal, Meta darunter, Aktionen rechts | | | |
| Tabelle | `table`, `table--compact`, `num`, `table-wrap` | Zeilenraster `line-soft`, Kopf 13/500 `muted` | Zeile `surface` | | |
| Reiter | `tab-list`, `tab` (+ `is-active`/`aria-selected`), Core `.tabs` | Unterstrich 2 px, 44 px hoch | `ink` | | |
| Toast / Hinweis | `toast-stack`, `toast toast--ok|warn|danger|neutral`, `notice notice--…` | Rand links 4 px, Schatten (Toast) | | | Fehler = `danger` |
| Sheet | `sheet-scrim`, `sheet`, `sheet-head`, `sheet-body` | Phone von unten, Desktop 440 px rechts unten, Scrim flach 40 % | | | |
| Leer-Zustand | `empty-state`, `empty-state-title`, `empty-state-text` | gestrichelter Rahmen, Aufforderung, höchstens eine Aktion | | | |
| Bildrahmen | SDC `tannwald:figure` (`ratio`, `caption`, Slot `image`), `figure--card|hero|square|bleed`, `.tw-image` | festes Verhältnis, Platzhalter mit Bildmarke | | | |
| Icon | SDC `tannwald:icon` (`name`, `size`, `label`) | 16 Namen, 1.5 px, `currentColor`, dekorativ `aria-hidden` | | | |

Icon-Set (höchstens 16, nie dekorativ): download, search, arrow, check, warning, info, close, menu, chat, document, machine, bulletin,
language, filter, sort, external. Lokale Reiter von Core nie als Pillen über einem Titel; auf Nutzerseiten ausgeblendet.

## 8. Bewegung

Nur als Antwort auf eine Handlung (Hover, Öffnen, Schliessen, Toast erscheint, Treffer klappen auf), nie beim Laden. Dauern
`--duration-fast` 120 ms (Farbe, Rand), `--duration-base` 180 ms (Sheet, Toast, Fläche), nie über 200 ms; Kurve `--ease-out-soft`.
Keine Bewegung von Grösse oder Position bei Hover. `prefers-reduced-motion: reduce` setzt alle Übergänge und Animationen auf 0 s
(gemessen: `transitionDuration` 0s auf Link, Button, Chip, Feld). Alpine: `x-transition.opacity.duration.180ms`.

## 9. Mikrotexte (de-CH, „Sie“)

- Ein Gedanke je Zeile; keine Ausrufezeichen; Satzschreibung; „ss“ statt „ß“; Wörter der Wireframes wörtlich (CLAUDE.md).
- Zahlen mit `’` (2’141 Tokens), Datum `TT.MM.JJJJ`, Uhrzeit `HH:MM`, Einheiten mit Leerzeichen (0,25 mm).
- Fehler erklären, was passiert ist, und sagen, was hilft: „Benutzername oder Passwort stimmen nicht. Bitte prüfen Sie die Eingabe.“
  Keine Entschuldigungen, keine Schuldzuweisung, keine Technik („500“, „Exception“).
- Leer-Zustände sind Aufforderungen: „Noch keine Bestätigungen. Sobald ein Nutzer bestätigt, erscheint er hier.“
- Bestätigungen kurz und faktisch: „Lesebestätigung gespeichert.“ Buttons nennen die Handlung (Verb), Links das Ziel.
- Platzhalter nie als Label; Hilfetexte unter dem Feld, ein Satz.

## 10. Barrierefreiheit

Kontrast Text ≥ 4.5:1, UI (Ränder, Fokusring, Icons) ≥ 3:1 — gemessen je Token-Paar (`/styleguide`, Bericht). Fokusring 2 px Akzent
mit 2 px Abstand auf allen fokussierbaren Elementen, sichtbar auf heller und auf Akzent-Fläche. Tastaturweg: Skip-Link („Direkt zum
Inhalt“, sichtbar bei Fokus) → Navigation → Inhalt in Lesereihenfolge; Sheet und Chat mit Escape schliessbar. Trefffläche 44 px.
Echte `<button>`, `<a>`, `<input>` mit `<label>`; Icons dekorativ (`aria-hidden`) oder mit Label; Status-Badges als Text, nicht nur
Farbe. Bilder mit Alt-Text, Platzhalter mit Label. Sprache je Seite (`lang`), Sprachwechsel über die Pillen. `prefers-reduced-motion`
respektiert. 390 px ohne Querlauf und ohne abgeschnittene Texte (`scrollWidth` = 390).

## 11. Abnahme-Tabelle je Seite (10 Punkte, jeder Theme-Bericht)

| # | Punkt | Messung / Beleg |
|---|---|---|
| 1 | Ein Seitentitel (Display), keine Dopplung mit Reitern oder Brotkrumen | `h1` = 1, `.tabs` = 0 |
| 2 | Typo-Skala eingehalten: nur die sechs Grössen, Gewichte 400/500/600, keine Versalien | `uppercase` = 0; Stichprobe `font-size` |
| 3 | Raster: 8-pt-Abstände, 44-px-Bedienelemente, Seitenrand 32/16, 12 Spalten; Phone-Kopf ≤ 120 px (§13) | Elemente < 44 px = 0; `main.top` bei 390 ≤ 120 |
| 4 | Ein Primärbutton je Ansicht, Sekundär- und Textbuttons sonst | `.btn-primary` ≤ 1 (Ausnahmen begründet) |
| 5 | Farbe nur als Signal: keine Verläufe, ein Schattenlevel, Status-Farben nur mit Information | `gradient` = 0, Schattenwerte ≤ 1 |
| 6 | Kontrast: Text ≥ 4.5:1, UI ≥ 3:1 | Token-Tabelle, Stichproben je Seite |
| 7 | Fokusring sichtbar, Tastaturweg vollständig (Skip-Link, Tab-Reihenfolge, Escape) | Screen mit Tab-Fokus, Tab-Protokoll |
| 8 | 390 px ohne Querlauf, Bilder im festen Verhältnis, nichts abgeschnitten | `scrollWidth` = 390, Screens 1440/390 |
| 9 | Leer-, Fehler- und Ladezustände formuliert (Mikrotexte §9) | Texte im Bericht, Screen |
| 10 | Was wurde entfernt? (Chanel-Regel) | ein Satz im Bericht |

Der Betreiber bestätigt je Seite „abgenommen“ am Vergleichsblatt.

## 12. Vergleichsblatt-Vorlage (eine Seite je Seite)

```
# Vergleichsblatt <Seite> — <Datum> (<Aufgabe>, <Terminal>)

Vorher: <Pfad Screen 1440> · <Pfad Screen 390> (Stand <Commit/Bericht>)
Nachher: <Pfad Screen 1440> · <Pfad Screen 390> (Stand <Commit>)

| Nr | Punkt (§11) | Vorher | Nachher | Beleg |
|---|---|---|---|---|
| 1 | Ein Seitentitel | … | … | h1 = 1 |
| 2 | Typo-Skala | … | … | … |
| 3 | Raster, 44 px | … | … | … |
| 4 | Ein Primärbutton | … | … | … |
| 5 | Farbe als Signal | … | … | … |
| 6 | Kontrast | … | … | … |
| 7 | Fokus, Tastatur | … | … | … |
| 8 | 390 px, Bilder | … | … | scrollWidth = 390 |
| 9 | Leer-/Fehlerzustände | … | … | … |
| 10 | Entfernt | — | <ein Satz> | |

Was wurde entfernt? <ein Satz>
Offen / Entscheid Betreiber: <Punkte oder „keine“>
Abgenommen: ☐ Betreiber, Datum
```

Vorher-Screens stammen aus dem letzten Bericht der Seite (nicht neu erzeugen); Nachher-Screens entstehen mit `shot.mjs` (1440 × 900,
390 × 900, volle Höhe) nach `drush cr`. Das Vergleichsblatt liegt im Screens-Ordner des Berichts als `vergleichsblatt-<seite>.md`.
Erstes Vergleichsblatt: Anmelden (siehe `vergleichsblaetter.md` in diesem Ordner).

## 13. Phone-Kopf (≤ 120 px, Aufgabe 1.4)

Auf 390 px darf der Kopf (Demo-Leiste + Kopfzeile) höchstens 120 px einnehmen, damit das tragende Element der Seite im ersten Viewport
steht. Regel (Klassen in `src/pages/shell.css`, Template `page.html.twig`):

| Zeile | Höhe | Inhalt Phone (< 768 px) | Desktop (≥ 768 px) |
|---|---|---|---|
| Demo-Leiste `demo-bar` | 32 px | einzeilig „Demo · kunde-a · Pharmalog AG, CH“, abgeschnitten mit Ellipse; Rolle und Wechsel-Links ausgeblendet | eine Zeile mit Rolle und Wechsel-Links (36 px) |
| Kopfzeile Zeile 1 | 44 px | Wortmarke links, Avatar-Button (44 × 44, Initialen) rechts | Wortmarke · Navigation inline · Name · Avatar · Abmelden (64 px) |
| Kopfzeile Zeile 2 `site-nav` | 40 px | Unterstrich-Tabs in einer Zeile, horizontal scrollbar ohne Scrollbalken | — |
| Summe | 116 px + 1 px Rahmen | gemessen 117 px (`main` beginnt bei 117) | 101 px |

Das Avatar-Menü (`site-menu`, Alpine `open`, `aria-expanded`, `aria-controls`, Escape und Klick ausserhalb schliessen) trägt Name, Firma,
Rolle, die Demo-Wechsel-Links, „Zurück zu <Konto>“ und „Abmelden“ (44-px-Zeilen); auf dem Desktop bleiben Name und „Abmelden“ zusätzlich
inline sichtbar. Reiter der Navigation sind 13 px auf dem Phone (`text-sm`), 15 px auf dem Desktop. Kein Hamburger-Menü: drei bis vier Ziele
passen in eine Tab-Zeile; ein fünftes Ziel scrollt. Messung je Seite: `main.getBoundingClientRect().top` bei 390 px ≤ 120.

**Hover am Phone:** Hover-Regeln liegen in `@media (hover: hover)` (Custom-Variant `hover` und die Zeilen-/Karten-Hover in eigenem CSS);
unter Touch-Emulation (`hover: none`) bleibt kein Zustand nach dem Tippen hängen. `data-state="hover"` (Styleguide) bleibt ohne Medienabfrage.

**Inline-Links in Text und Tabellen** (Dokumenttitel, Sprach-Pillen, Brotkrumen) sind keine Bedienelemente nach §3: Mindestgrösse 24 × 24 px
(WCAG 2.5.8), Fokusring wie überall; Buttons, Felder, Chips, Avatar, Abmelden bleiben 44 px.

## 14. Offen (Entscheid Betreiber)

- D1 Vorbild-Produkt für Ton und Dichte: unbeantwortet; bis dahin gilt diese Datei.
- Typo und Raster am Styleguide bestätigen (Anleitung Teil 4, eine Entscheidung).
- Befund 21 (Freigeben je Karte) bleibt wie Wireframe 03; Befund 20 (Sprach-Pillen „Nicht verfügbar“ bei 1.58:1, inaktiv) bleibt
  als inaktiver Zustand ausgenommen.

---

## Herkunft — v1 (2026-11-01 21:10, unverändert)

### Design-Leitplanken myTannwald (gilt für alle Theme-Aufträge ab 2026-11-01 21:10)

**Anlass:** Der Betreiber hat die Login-Seite (Stand 5.1) gesehen: „die UX und das UI-Design müssen weltklasse sein, das sieht
nicht schön aus.“ Die Wireframes sind Wireframe-Stufe (README: eine Akzentfarbe, Inter); das fertige Portal muss darüber
hinaus wie ein geschliffenes Produkt wirken. Diese Datei ist der Massstab; jeder Theme-Bericht belegt die Punkte mit Screens.

### v1 §1. Haltung

Ein Industriekunden-Portal einer Schweizer Verpackungsmaschinen-Firma: präzise, ruhig, vertrauenswürdig, nüchtern — nicht
„SaaS-Kartenbaukasten“. Eine Sache darf auffallen (die Wortmarke mit Bildmarke und die Antwort-Karte des Assistenten), alles
andere ist diszipliniert. Chanel-Regel: vor dem Abgeben eine Zutat entfernen.

### v1 §2. Typografie (Inter / system-ui, kein Webfont — Lizenz)

| Rolle | Grösse / Zeilenhöhe / Gewicht | Verwendung |
|---|---|---|
| Display | 28 px / 1.2 / 600 | Seitentitel (einmal je Seite) |
| Titel | 20 px / 1.3 / 600 | Kartentitel, Abschnitte |
| Text | 16 px / 1.5 / 400 | Fliesstext, Formulare |
| Meta | 14 px / 1.45 / 400, Farbe `--color-muted` | Datum, Version, Hinweise |
| Klein | 12 px / 1.4 / 500 | Pillen, Badges |

Regeln: Satzschreibung (keine Versalien-Labels), kein Titel doppelt (Reiter „Anmelden“ **und** H1 „Anmelden“ ist ein Fehler),
Zeilenlänge ≤ 80 Zeichen, Zahlen mit `’` als Tausendertrenner, Daten `TT.MM.JJJJ`. Meta-Zeilen mit „·“ sind erlaubt, weil die
Wireframes sie vorgeben — aber nur dort, wo sie Information trennen.

### v1 §3. Raster und Abstände

8-pt-Raster: 4 / 8 / 12 / 16 / 24 / 32 / 48. Karten-Innenabstand 24 (Phone 16), Formular-Zeilenabstand 16, Abstand Label →
Feld 6, Feld → Hilfetext 6. Eingabefelder und Buttons **44 px hoch**, Radius 6; Karten Radius 8, Rahmen 1 px `--color-line`,
**ein** Schattenlevel (`0 1px 2px rgba(28,32,36,.06)`), keine Verläufe. Formulare max. 400 px breit. Seitenrand 32 (Phone 16).

### v1 §4. Farbe

Tokens aus Bericht 5.1 §3 (a) bleiben: Akzent `#1F4E79` (Hover `#15385A`), Tinte `#1C2024`, Fläche `#F3F4F2`, Panel `#FFFFFF`,
Linie `#D6DAE0`, Status ok/warn/danger/dualuse/private. Eine Akzentfarbe für Primär-Aktionen und Links — sonst nichts Blaues.
Kontrast ≥ 4.5:1, Fokusring 2 px Akzent mit 2 px Abstand (`outline-offset`), sichtbar bei Tastatur.

### v1 §5. Komponenten-Regeln

- Buttons: `btn-primary` (Akzent, weiss), `btn-secondary` (weiss, Rahmen), `btn-text` (nur Text, Akzent) — Primär **einmal** je
  Ansicht; Breite nach Inhalt (volle Breite nur auf Phone und im Login-Formular).
- Felder: Label oben (14 px, 500), Platzhalter nie als Label, Hilfetext darunter, Fehler inline unter dem Feld in `danger` mit
  Satz, der sagt, was zu tun ist („Benutzername oder Passwort stimmen nicht. Bitte prüfen Sie die Eingabe.“).
- Lokale Reiter (Core „Local tasks“) **nie** als Pillen über einem Titel: auf Nutzerseiten ausblenden, „Passwort vergessen?“
  wird ein Textlink unter dem Button.
- Pillen/Badges: 12 px, Radius 12, farbige Fläche + Tinte aus den Tokens; Status-Badges nur wo Status Information ist.
- Leere Zustände sind Aufforderungen („Noch keine Bestätigungen. Sobald ein Nutzer bestätigt, erscheint er hier.“); Fehler
  erklären, was passiert ist und was hilft; keine Entschuldigungen.
- Bewegung nur als Antwort auf eine Handlung (Sheet öffnet, Toast erscheint, Treffer-Liste klappt auf), 150–200 ms,
  `prefers-reduced-motion` respektiert; keine Einblende-Effekte beim Laden.
- Icons: nur inline-SVG, eine Linienstärke (1.5 px), höchstens ein Dutzend, nie dekorativ.

### v1 §6. Login-Seite (konkreter Soll-Zustand, F — sofort)

Desktop ≥ 1024 px zweispaltig: links ein Markenpanel (Fläche `--color-accent`, weisse Wortmarke „myTannwald“ mit einfacher
Bildmarke (geometrisch, inline-SVG: drei gestapelte Blister-Rechtecke oder ein stilisierter Tannenzweig — **eine** Idee,
ruhig), ein Satz „Kundenportal der Tannwald Verpackungstechnik AG“, darunter drei kurze Zeilen, was das Portal kann:
„Dokumente zu Ihren Maschinen“, „Sicherheits-Bulletins mit Lesebestätigung“, „Assistent, der nur aus freigegebenen
Dokumenten antwortet“); rechts das Formular auf weissem Grund: Titel „Anmelden“ (Display, einmal), Felder Benutzername und
Passwort (44 px, Autofokus im ersten Feld, Passwort mit „anzeigen“-Schalter als Textbutton), Primärbutton „Anmelden“
(volle Formularbreite), darunter Textlink „Passwort vergessen?“; lokale Reiter ausgeblendet; Fehlerzustand inline.
Phone: eine Spalte, Markenpanel als schmaler Kopf (Wortmarke + ein Satz). **Demo-Konten** (nur Split `dev`): statt der
Textzeile sechs **Chips** („kunde-a · Pharmalog AG“ …), Klick füllt Benutzername und Passwort `demo` aus (Alpine, kein
Auto-Login); Hinweiszeile „Demo-Zugänge, Passwort demo“. Screens 1440 und 390, `scrollWidth` = 390.

### v1 §7. Abnahme-Kriterien (jeder Theme-Bericht, als Tabelle mit ✓/✗ und Screen)

1. Ein Seitentitel je Seite, keine Dopplung mit Reitern/Brotkrumen. 2. 8-pt-Abstände, 44-px-Felder/Buttons, ein Primärbutton je
Ansicht. 3. Fokusring sichtbar (Screen mit Tab-Fokus). 4. Kontrast ≥ 4.5:1 (Messung, z. B. drei Stichproben). 5. 390 px ohne
Querlauf und ohne abgeschnittene Texte. 6. Leer-/Fehlerzustände formuliert. 7. Keine Verläufe, ein Schattenlevel, keine
Versalien-Labels. 8. Selbstkritik im Bericht: „Was habe ich entfernt?“ (eine Zeile).

### v1 §8. Betreiber-Frage (offen)

Gibt es ein Vorbild-Produkt für Ton und Dichte (z. B. ein Portal oder eine App, die dem Betreiber gefällt)? Bis zur Antwort gilt
diese Datei.
