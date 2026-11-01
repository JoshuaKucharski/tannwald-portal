# Eval-Ergebnis myTannwald — 2026-11-01 22:02

| Schlüssel | Wert |
|---|---|
| Datum | 2026-11-01 22:02 |
| Modus | LLM (Antworten über answerWithContext) |
| Provider | ollama |
| Chat-Modell | qwen2.5:7b |
| Embedding-Modell | bge-m3 |
| Set | docs/evals/fragen.yaml |
| Fragen | 10 (--limit=10) |
| Konto | als je Frage |
| Dauer gesamt | 289.3 s |
| Exit-Code | 1 |

Hinweis: Ein Wechsel von `embedding_model` verlangt einen Reindex (`drush tannwald:reindex` und `drush tannwald:queue-run`), weil gespeicherte Vektoren zum alten Modell gehören; ein Wechsel von `chat_model` nicht.

## Kennzahlen

| Kennzahl | Wert | Basis | Ziel | erreicht |
|---|---|---|---|---|
| hit@5 | 0.86 (6/7) | Fragen mit erwarteten Quellen | ≥ 0.80 | ✓ |
| zitat_korrekt | 0.14 (1/7) | erwartet antwort | ≥ 0.90 | ✗ |
| ablehnung_korrekt | 1.00 (10/10) | erwartet antwort oder ablehnung | ≥ 0.90 | ✓ |
| safety_korrekt | – | erwartet safety | = 1.00 | – |
| Median-Dauer | 25651 ms | alle Fragen | – |  |
| Tokens | prompt 23372 / completion 599 | LLM-Antworten | – |  |
| Fehler (Provider) | 0 | LLM-Antworten | 0 |  |

## Fragen

| id | als | sprache | erwartet | status | hit@5 | zitat | dauer_ms | Bemerkung |
|---|---|---|---|---|---|---|---|---|
| q-001 | kunde-a | de | antwort | beantwortet | ✓ | ✗ | 25684 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-002 | kunde-a | de | ablehnung | abgelehnt | – | – | 19629 |  |
| q-003 | kunde-a | de | antwort | beantwortet | ✓ | ✓ | 27051 |  |
| q-004 | kunde-a | de | antwort | beantwortet | ✗ | ✗ | 25617 | Zitat zeigt nicht auf eine erwartete Quelle; erwartete Quelle nicht unter den ersten 5 Treffern |
| q-005 | kunde-b | en | ablehnung | abgelehnt | – | – | 18734 |  |
| q-006 | haendler | en | ablehnung | abgelehnt | – | – | 15320 |  |
| q-007 | haendler | en | antwort | beantwortet | ✓ | ✗ | 19551 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-008 | techniker | de | antwort | beantwortet | ✓ | ✗ | 28681 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-009 | kunde-a | de | antwort | beantwortet | ✓ | ✗ | 53259 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-010 | kunde-a | fr | antwort | beantwortet | ✓ | ✗ | 55642 | Zitat zeigt nicht auf eine erwartete Quelle |

## Fehlschläge

### q-001 — erwartet antwort

- Frage: Wie oft muss die Siegelstation gereinigt werden? (als kunde-a, de)
- Erwartet: ba-tb200 3.2 · 7.3 Reinigung Siegelstation; wp-tb200 1.1 · 2 Freigegebene Reinigungsmittel; wp-tb200 1.1 · 3.2 Siegelstation wöchentlich
- Erhalten: Status beantwortet; [1] Wartungsplan TB-400 v1.0 · 3.2 Siegelstation wöchentlich; [2] Betriebsanleitung TB-200 v3.2 · 7.3 Reinigung Siegelstation; [5] Wartungsplan TB-200 v1.1 · 3.2 Siegelstation wöchentlich
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-004 — erwartet antwort

- Frage: Warum reisst die Folie beim Siegeln? (als kunde-a, de)
- Erwartet: ba-tb200 3.2 · 9.4 Störungen an der Siegelstation: Siegelnaht undicht, Folienriss
- Erhalten: Status beantwortet; [3] SRV-2026-19 Folienspannung nach Rollenwechsel v · Ursache und Fehlerbild; [5] Schulungsunterlage TB-200 Formatwechsel v1.0 · 9 Formatwechsel mit Rollenwechsel; [2] Betriebsanleitung TB-400 v2.0 · 9.3 Störungen an der Siegelstation
- Grund: Zitat zeigt nicht auf eine erwartete Quelle; erwartete Quelle nicht unter den ersten 5 Treffern

### q-007 — erwartet antwort

- Frage: What is the setpoint for the brake value after a roll change on the TB-200? (als haendler, en)
- Erwartet: SRV-2026-19 · Tabelle 2: Angepasste Sollwerte für den Bremswert; ba-tb200 3.2 · 5.2 Roll change
- Erhalten: Status beantwortet; [1] Betriebsanleitung TB-200 v3.2 · 5.2 Roll change; [3] Betriebsanleitung TB-200 v3.2 · 9.4 Faults at the sealing station: leaking seal seam, film tear; [5] SRV-2026-19 Folienspannung nach Rollenwechsel v · Ursache und Fehlerbild
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-008 — erwartet antwort

- Frage: Was lernen die Teilnehmenden in der Schulung TB-400 über den Rollenwechsel mit zwei Rollen? (als techniker, de)
- Erwartet: su-tb400 1.0 · 4.2 Rollenwechsel mit zwei Rollen
- Erhalten: Status beantwortet; [1] Schulungsunterlage TB-400 Bedienerschulung v1.0 · 4.2 Rollenwechsel mit zwei Rollen; [3] Schulungsunterlage TB-400 Bedienerschulung v1.0 · 9 Rollen und Zuständigkeiten
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-009 — erwartet antwort

- Frage: Bis wann war das Kalibrierzertifikat Temperaturmessung TB-200 gültig? (als kunde-a, de)
- Erwartet: zert-kalibrierung-tb200 1.0 · 7 Ablösung dieses Zertifikats
- Erhalten: Status beantwortet; [1] Kalibrierzertifikat Temperaturmessung TB-200 v1.0 · 1 Kalibrierzertifikat Temperaturmessung; [2] Kalibrierzertifikat Temperaturmessung TB-200 v1.0 · 1.1 Angaben zum Zertifikat; [3] Kalibrierzertifikat Temperaturmessung TB-200 v1.0 · 7 Ablösung dieses Zertifikats
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-010 — erwartet antwort

- Frage: Comment calibrer la cellule de détection d'étui du KT-500 ? (als kunde-a, fr)
- Erwartet: wp-kt500 2.0 · 3.2 Calibration of the carton detection cell
- Erhalten: Status beantwortet; [1] Wartungsplan KT-500 v2.0 · 3.2 Calibration of the carton detection cell; [2] Schulungsunterlage KT-500 Bedienerschulung v1.0 · 4.3 Calibration of the carton detection cell; [3] Betriebsanleitung KT-500 v1.3 · 9.2 Faults at the detection cell
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

