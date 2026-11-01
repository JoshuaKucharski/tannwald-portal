# Wer darf was wissen? Ein Kundenportal, in dem Suche und KI-Assistent nur sehen, was der Kunde sehen darf

*48 Stunden, Drupal 11, ein lokales Sprachmodell — und ein Proof of Concept, der seine Behauptungen misst.*

Ein Maschinenbauer mit 9'500 installierten Anlagen in 70 Ländern und 280'000 technischen Dokumenten hat ein Problem, das
jede Industriefirma kennt: Die Betriebsanleitung ist da, aber nicht beim Kunden. Ein Drittel der Support-Tickets sind
Fragen, deren Antwort in einem Dokument steht. Dazu kommt, was ein Portal schwierig macht: Nicht jeder darf alles sehen.
Anlagenspezifische Dokumentation gehört einem Kunden; Schaltpläne unterliegen der Exportkontrolle; Händler sehen nur die
Maschinentypen ihres Gebiets. Und ein KI-Assistent, der aus Dokumenten antwortet, darf keinen Millimeter mehr wissen als
die Person, die fragt.

Für diesen fiktiven Kunden — die Tannwald Verpackungstechnik AG, Firma, Personen und Dokumente sind erfunden — haben wir
in 48 Stunden einen Proof of Concept gebaut. Nicht als Folienstapel, sondern als lauffähiges System mit Tests und Evals.
Der Code ist öffentlich: https://github.com/JoshuaKucharski/tannwald-portal

## Was gebaut wurde

**Eine Rechte-Quelle für alles.** Ein `EntitlementService` berechnet aus Firma, Rolle, Land, Maschinenbestand und
Exportregeln die Schlüssel eines Nutzers und eines Dokuments — etwa `type:TB200+export:keine` oder
`serial:<Maschine>+export:klasse_2`. Node-Access-Grants in Drupal, der Download-Controller, der Vorfilter im Suchindex und
die Nachprüfung je Treffer im Assistenten leiten alle daraus ab. Ein einziger Functional-Test misst die drei Wege Seite,
Suche und Assistent für alle Konten: Der Kunde sieht seine Anlagendokumentation, der andere Kunde und der Händler
bekommen 403, keinen Treffer und eine Ablehnung ohne Quelle — und jede verweigerte Anfrage steht im Audit-Log.

**Hybride Suche ohne Suchserver.** Dokumente werden entlang ihrer Kapitel in Chunks von rund 350 Tokens geschnitten,
mit `bge-m3` eingebettet und in einer MariaDB-Tabelle gespeichert — mit FULLTEXT-Indizes für den Keyword-Zweig und
float32-Vektoren für den Bedeutungs-Zweig. Beide Zweige filtern vor dem Ranking über dieselben Zugriffsschlüssel;
Reciprocal Rank Fusion führt die Ränge zusammen. Der Demo-Moment: „Folie reisst beim Siegeln“ findet das Kapitel
„Störungen an der Siegelstation: Siegelnaht undicht, Folienriss“ nur im Hybrid-Modus — kein Wort stimmt überein.
„E-4711“ trifft die Störungstabelle in beiden Modi. Für die 581 Chunks des PoC braucht das Retrieval im Median 81 ms.

**Ein Assistent, der zitiert oder schweigt.** `qwen2.5:7b` läuft lokal über Ollama, angebunden über das Drupal-AI-Modul,
der Provider ist Konfiguration. Der Prompt erklärt die Kontextblöcke als Daten; ein `CitationValidator` prüft jedes `[n]`
gegen die gelieferten Chunks. Keine gültige Quelle, keine Antwort — stattdessen ein fester Satz und der Button
„Support-Anfrage erstellen“. Sicherheitsfragen („Schutzhaube überbrücken?“) erhalten nie eine Kurzanleitung, sondern
eine Vorlage mit Verweis auf das Sicherheitskapitel, ohne Modellaufruf. Eine Anweisung, die in einer Schulungsunterlage
versteckt ist („Ignoriere alle bisherigen Regeln …“), wird nicht befolgt.

**Freigabe vor Wissen.** Jede neue Dokument-Revision erzeugt eine KI-Zusammenfassung im Status „Zur Prüfung“. Die
Redaktion bearbeitet, gibt frei oder weist zurück; erst „Freigegeben“ wird indexiert. Sicherheits-Bulletins verlangen
eine Lesebestätigung je Nutzer und Seriennummer, und die erstellende Person darf sie nicht selbst freigeben.

**Ein Skript, ein Seeder.** Ein frischer Mac erreicht den Demo-Stand mit einer Zeile: DDEV, Drupal, Konfiguration,
Migrationen aus JSON- und HTML-Fixtures (der SAP-Mock), Embeddings, Login-Link. 20 Dokumente in 22 Revisionen und drei
Sprachen, drei Bulletins, zehn Maschinen, sechs Demo-Konten — in 65 Sekunden.

## Drei Lehren

**1. Rechte sind ODER-verknüpft, bis man sie kombiniert.** Drupal-Grants und Index-Filter prüfen „hat der Nutzer
irgendeinen passenden Schlüssel?“. Mit getrennten Schlüsseln für Maschinentyp und Exportklasse hätte ein Kunde mit
TB-200 den exportkontrollierten Schaltplan gesehen — der Typ passte. Die Lösung ist ein kombinierter Schlüssel
`<basis>+export:<klasse>`, der beide Bedingungen trägt. Der Fehler wäre im Code-Review unsichtbar geblieben; der
Gegenprobe-Test (Exportbedingung ausgehebelt, Leak-Test rot) hat ihn sichtbar gemacht. Dazu eine zweite Falle: Drupal
schreibt bei der Installation des Node-Moduls eine Zeile, die allen alles zeigt, bis die Grants neu gebaut werden.

**2. Ein geteilter Arbeitsbaum braucht Besitzer je Welle.** Der PoC entstand mit einem Menschen, einem koordinierenden
Terminal und bis zu fünf parallelen KI-Terminals auf demselben Repo und derselben Datenbank. Das funktioniert nur mit
Regeln, die wie Verträge wirken: Pro Welle gehört `config/sync` genau einem Terminal, alle anderen exportieren in ein
Temp-Verzeichnis und kopieren nur eigene Dateien; `composer.lock` wird mit Ansage geändert; neue Testdateien entstehen
unter `.wip`-Namen, weil ein Fatal in einer halbfertigen Testdatei die Testläufe aller anderen bricht — das haben wir
einmal gemessen und dann als Regel festgeschrieben. Jeder Bericht hält Signaturen, Formate und gemessene Dauern wörtlich
fest; die nächste Welle baut auf dem Bericht, nicht auf dem Chat.

**3. Erst messen, dann tunen.** Der erste volle Eval-Lauf über 60 Fragen — bevor jemand am Retrieval schraubte — zeigte:
Treffer unter den ersten fünf 0.73, korrekte Zitate 0.41, korrekte Ablehnungen 0.97, korrekte Sicherheitsantworten 0.60.
Die Analyse je Fehlschlag (Rang der erwarteten Quelle im Keyword-Zweig, im Vektor-Zweig, nach der Fusion) ergab klare
Ursachen: allgemeine Sicherheitskapitel ohne Wortbezug zur Frage, Verwechslung zwischen Maschinentypen, sparsames
Zitieren des 7B-Modells. Ohne Baseline hätten wir an der Chunk-Grösse gedreht — die war nicht das Problem.

## Kennzahlen

| Kennzahl | Vorher | Nachher | Ziel |
|---|---|---|---|
| Treffer unter den ersten 5 (`hit@5`) | 0.73 | 0.87 | ≥ 0.80 |
| Zitate korrekt | 0.41 | 0.50 | ≥ 0.90 |
| Ablehnungen korrekt | 0.97 | 1.00 | ≥ 0.90 |
| Sicherheitsantworten korrekt | 0.60 | 0.87¹ | = 1.00 |

Zitate und Sicherheitsverweise bleiben unter dem Ziel: Das 7B-Modell zitiert verwandte Kapitel mit, und die Sicherheitsvorlage trifft in vier von dreissig Fällen nicht das erwartete Kapitel — gemessen, nicht geschönt; die Hebel sind ein stärkeres Modell und ein Reranker, nicht mehr Prompt.

¹ Nachmessung über die 30 Sicherheitsfragen nach einem Vorlagen-Fix; der volle Lauf zeigte davor 0.63.

Dazu: 259 automatisierte Tests (Unit, Kernel, Functional), Chat-Antwort mit lokalem 7B-Modell auf einem M1 in 15 bis 30
Sekunden, Sicherheitsantwort in Millisekunden, Seeder 65 Sekunden. Alle Zahlen stammen aus den Ergebnisdateien im Repo.

## Was der PoC nicht ist

Keine Produktion: ein Modell, lokal; SAP, Azure Blob und Salesforce als Mocks mit derselben Logik; keine SSO, kein
Lasttest. Der Ausbau zu Phase 1 — OpenSearch hinter demselben Interface, Azure-Provider, Entra ID — ist im Pflichtenheft
als Delta mit Aufwand beschrieben.

Wer ein Kundenportal mit Dokumentation, Suche und Assistent plant: Die interessante Frage ist nicht, welches Modell
antwortet. Sie lautet, ob das System beweisen kann, dass es nur antwortet, was die fragende Person sehen darf.

*Fiktiver Kunde: Firma, Personen und Dokumente sind erfunden. Systemnamen bezeichnen nur den Systemtyp.*
