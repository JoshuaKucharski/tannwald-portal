# LinkedIn-Nachtrag „jetzt live“ (Vorschlag, de-CH; der Betreiber entscheidet Wortlaut und Zeitpunkt)

Bezug: `post.md` (PoC-Post, 01.11.2026). Zweck: Nachtrag als eigener Post oder als Kommentar unter dem ersten Post.
Bild: `docs/demo.gif` oder die sechs Screens aus `docs/screens/1x-*.webp`. Link im ersten Kommentar:
`https://mytannwald.getleaven.com` und das Repo.

## Vorschlag (145 Wörter)

myTannwald ist jetzt live. Der Proof of Concept vom Wochenende läuft seit heute auf einem gewöhnlichen Server unter
mytannwald.getleaven.com: offen, sieben Demo-Konten auf der Anmeldeseite, Passwort auf der Seite.

Was in der zweiten Phase dazukam: ein Design-System mit Abnahme je Seite (Inter, ein Raster, Farbe nur als Signal,
Kontrast und Fokusweg gemessen), 20 Bilder fiktiver Maschinen als tragendes Element, und das Chat-Modell aus einer
API in Frankfurt statt lokal. Die Embeddings bleiben in Europa, auf der CPU des Servers.

Drei Zahlen, ehrlich gelesen: 291 automatisierte Tests sichern Rechte, Suche und Ablehnung. 80 Eval-Fragen in drei
Sprachen; die erwartete Quelle steht in 88 Prozent der Antworten unter den Zitaten, streng gezählt (jedes Zitat
korrekt) sind es 48 Prozent, weil das Modell verwandte Kapitel mitzitiert. Ein Eval-Lauf kostet vier Rappen.

Der Weg vom Mac auf den Server ist eine Zeile. Klicken Sie sich durch; Rückmeldungen gern hier.

## Alternative Erstzeile

Seit heute kann man es anklicken: myTannwald, der Kundenportal-PoC mit rechtebewusster Suche und zitierendem
Assistenten, läuft live.

## Hinweise für den Betreiber

- Keine Hashtags im Vorschlag; wenn gewünscht höchstens zwei (`#Drupal #KI`).
- „vier Rappen“ = CHF 0.04 für Eval 3b zum Listenpreis (Bericht 3.2); „88 Prozent“ = 37/42 enthaltend, „48 Prozent“ =
  20/42 streng (`docs/evals/ergebnisse-2026-11-02_1713.md`); „291 Tests“ = `phpunit --list-tests` am 03.11.2026.
- „seit heute“ gilt für den 03.11.2026; bei späterer Veröffentlichung „seit dem 3. November“.
