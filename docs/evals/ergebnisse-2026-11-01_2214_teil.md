# Eval-Ergebnis myTannwald — 2026-11-01 22:14

| Schlüssel | Wert |
|---|---|
| Datum | 2026-11-01 22:14 |
| Modus | LLM (Antworten über answerWithContext) |
| Provider | ollama |
| Chat-Modell | qwen2.5:7b |
| Embedding-Modell | bge-m3 |
| Set | docs/evals/fragen.yaml |
| Fragen | 10 (--limit=10) |
| Konto | als je Frage |
| Dauer gesamt | 373.7 s |
| Exit-Code | 1 |

Hinweis: Ein Wechsel von `embedding_model` verlangt einen Reindex (`drush tannwald:reindex` und `drush tannwald:queue-run`), weil gespeicherte Vektoren zum alten Modell gehören; ein Wechsel von `chat_model` nicht.

## Kennzahlen

| Kennzahl | Wert | Basis | Ziel | erreicht |
|---|---|---|---|---|
| hit@5 | 0.86 (6/7) | Fragen mit erwarteten Quellen | ≥ 0.80 | ✓ |
| zitat_korrekt | 0.43 (3/7) | erwartet antwort | ≥ 0.90 | ✗ |
| ablehnung_korrekt | 1.00 (10/10) | erwartet antwort oder ablehnung | ≥ 0.90 | ✓ |
| safety_korrekt | – | erwartet safety | = 1.00 | – |
| Median-Dauer | 33570 ms | alle Fragen | – |  |
| Tokens | prompt 22984 / completion 634 | LLM-Antworten | – |  |
| Fehler (Provider) | 0 | LLM-Antworten | 0 |  |

## Fragen

| id | als | sprache | erwartet | status | hit@5 | zitat | dauer_ms | Bemerkung |
|---|---|---|---|---|---|---|---|---|
| q-001 | kunde-a | de | antwort | beantwortet | ✓ | ✗ | 27816 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-002 | kunde-a | de | ablehnung | abgelehnt | – | – | 21516 |  |
| q-003 | kunde-a | de | antwort | beantwortet | ✓ | ✓ | 64996 |  |
| q-004 | kunde-a | de | antwort | beantwortet | ✗ | ✗ | 55466 | Zitat zeigt nicht auf eine erwartete Quelle; erwartete Quelle nicht unter den ersten 5 Treffern |
| q-005 | kunde-b | en | ablehnung | abgelehnt | – | – | 60414 |  |
| q-006 | haendler | en | ablehnung | abgelehnt | – | – | 17813 |  |
| q-007 | haendler | en | antwort | beantwortet | ✓ | ✗ | 22332 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-008 | techniker | de | antwort | beantwortet | ✓ | ✓ | 39344 |  |
| q-009 | kunde-a | de | antwort | beantwortet | ✓ | ✓ | 24628 |  |
| q-010 | kunde-a | fr | antwort | beantwortet | ✓ | ✗ | 39324 | Zitat zeigt nicht auf eine erwartete Quelle |

## Fehlschläge

### q-001 — erwartet antwort

- Frage: Wie oft muss die Siegelstation gereinigt werden? (als kunde-a, de)
- Erwartet: ba-tb200 3.2 · 7.3 Reinigung Siegelstation; wp-tb200 1.1 · 2 Freigegebene Reinigungsmittel; wp-tb200 1.1 · 3.2 Siegelstation wöchentlich
- Erhalten: Status beantwortet; [1] Wartungsplan TB-400 v1.0 · 3.2 Siegelstation wöchentlich; [2] Betriebsanleitung TB-200 v3.2 · 7.3 Reinigung Siegelstation; [5] Wartungsplan TB-200 v1.1 · 3.2 Siegelstation wöchentlich
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-004 — erwartet antwort

- Frage: Warum reisst die Folie beim Siegeln? (als kunde-a, de)
- Erwartet: ba-tb200 3.2 · 9.4 Störungen an der Siegelstation: Siegelnaht undicht, Folienriss
- Erhalten: Status beantwortet; [5] Schulungsunterlage TB-200 Formatwechsel v1.0 · 9 Formatwechsel mit Rollenwechsel; [2] Betriebsanleitung TB-400 v2.0 · 9.3 Störungen an der Siegelstation
- Grund: Zitat zeigt nicht auf eine erwartete Quelle; erwartete Quelle nicht unter den ersten 5 Treffern

### q-007 — erwartet antwort

- Frage: What is the setpoint for the brake value after a roll change on the TB-200? (als haendler, en)
- Erwartet: SRV-2026-19 · Tabelle 2: Angepasste Sollwerte für den Bremswert; ba-tb200 3.2 · 5.2 Roll change
- Erhalten: Status beantwortet; [1] Betriebsanleitung TB-200 v3.2 · 5.2 Roll change; [2] SRV-2026-19 Folienspannung nach Rollenwechsel v · Folienspannung nach Rollenwechsel: angepasster Sollwert; [6] Betriebsanleitung TB-200 v3.2 · 5.2 Rollenwechsel
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-010 — erwartet antwort

- Frage: Comment calibrer la cellule de détection d'étui du KT-500 ? (als kunde-a, fr)
- Erwartet: wp-kt500 2.0 · 3.2 Calibration of the carton detection cell
- Erhalten: Status beantwortet; [1] Wartungsplan KT-500 v2.0 · 3.2 Calibration of the carton detection cell; [2] Schulungsunterlage KT-500 Bedienerschulung v1.0 · 4.3 Calibration of the carton detection cell; [3] Betriebsanleitung KT-500 v1.3 · 9.2 Faults at the detection cell
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

