# LinkedIn-Post (zwei Fassungen, je unter 1'300 Zeichen; Bild: `docs/demo.gif`)

## Deutsch

Wer darf was wissen? In 48 Stunden haben wir ein Kundenportal als Proof of Concept gebaut, in dem Suche und
KI-Assistent nur sehen, was die fragende Person sehen darf.

Fiktiver Kunde: ein Verpackungsmaschinenbauer mit 280'000 technischen Dokumenten, Exportkontrolle und Händlern in 70
Ländern. Gebaut auf Drupal 11, Sprachmodell lokal über Ollama, kein Cloud-Schlüssel.

Was der PoC beweist:
– Eine Rechte-Quelle für Seite, Suche und Assistent; der Leak-Test misst alle drei Wege.
– Hybride Suche (Volltext + Vektoren) findet „Folie reisst beim Siegeln“, obwohl kein Wort übereinstimmt.
– Der Assistent zitiert jede Aussage oder lehnt ab; Sicherheitsfragen bekommen nie eine Kurzanleitung.
– KI-Zusammenfassungen werden erst nach redaktioneller Freigabe zu Wissen.
– 259 automatisierte Tests, 60 Eval-Fragen mit Vorher/Nachher: hit@5 0.73 → 0.87, Ablehnungen 0.97 → 1.00, Sicherheitsverweise 0.60 → 0.87, Zitate 0.41 → 0.50 (Restbefund, ehrlich).

Drei Lehren: Rechte sind ODER-verknüpft, bis man die Schlüssel kombiniert. Ein geteilter Arbeitsbaum braucht Besitzer je
Welle. Erst messen, dann tunen.

Code, Architektur-Entscheide und Eval-Ergebnisse sind öffentlich — Link im ersten Kommentar.

#Drupal #RAG #KI #Kundenportal #Maschinenbau

## English

Who is allowed to know what? In 48 hours we built a proof of concept for a customer portal where search and the AI
assistant only see what the person asking is allowed to see.

Fictional client: a packaging-machine manufacturer with 280,000 technical documents, export control and dealers in 70
countries. Built on Drupal 11, language model running locally via Ollama, no cloud key.

What the PoC proves:
– One entitlement source for page, search and assistant; a leak test measures all three paths.
– Hybrid search (full text + vectors) finds "film tears during sealing" although not a single word matches.
– The assistant cites every statement or refuses; safety questions never get a shortened answer.
– AI summaries only become knowledge after editorial release.
– 259 automated tests, a 60-question eval set with before/after: hit@5 0.73 → 0.87, refusals 0.97 → 1.00, safety references 0.60 → 0.87, citations 0.41 → 0.50 (honest leftover).

Three lessons: access grants are OR-combined until you combine the keys. A shared working tree needs one owner per wave.
Measure first, then tune.

Code, architecture decisions and eval results are public — link in the first comment.

#Drupal #RAG #AI #CustomerPortal #MachineBuilding
