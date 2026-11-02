# Eval-Ergebnis myTannwald — 2026-11-01 21:07

| Schlüssel | Wert |
|---|---|
| Datum | 2026-11-01 21:07 |
| Modus | LLM (Antworten über answerWithContext) |
| Provider | ollama |
| Chat-Modell | qwen2.5:7b |
| Embedding-Modell | bge-m3 |
| Set | docs/evals/fragen.yaml |
| Fragen | 5 (--limit=5) |
| Konto | als je Frage |
| Dauer gesamt | 120.8 s |
| Exit-Code | 1 |

Hinweis: Ein Wechsel von `embedding_model` verlangt einen Reindex (`drush tannwald:reindex` und `drush tannwald:queue-run`), weil gespeicherte Vektoren zum alten Modell gehören; ein Wechsel von `chat_model` nicht.

## Kennzahlen

| Kennzahl | Wert | Basis | Ziel | erreicht |
|---|---|---|---|---|
| hit@5 | 1.00 (3/3) | Fragen mit erwarteten Quellen | ≥ 0.80 | ✓ |
| zitat_korrekt | 0.67 (2/3) | erwartet antwort | ≥ 0.90 | ✗ |
| ablehnung_korrekt | 1.00 (5/5) | erwartet antwort oder ablehnung | ≥ 0.90 | ✓ |
| safety_korrekt | – | erwartet safety | = 1.00 | – |
| Median-Dauer | 24879 ms | alle Fragen | – |  |
| Tokens | prompt 12082 / completion 164 | LLM-Antworten | – |  |
| Fehler (Provider) | 0 | LLM-Antworten | 0 |  |

## Fragen

| id | als | sprache | erwartet | status | hit@5 | zitat | dauer_ms | Bemerkung |
|---|---|---|---|---|---|---|---|---|
| q-001 | kunde-a | de | antwort | beantwortet | ✓ | ✗ | 30106 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-002 | kunde-a | de | ablehnung | abgelehnt | – | – | 20939 |  |
| q-003 | kunde-a | de | antwort | beantwortet | ✓ | ✓ | 29936 |  |
| q-004 | kunde-a | de | antwort | beantwortet | ✓ | ✓ | 24879 |  |
| q-005 | kunde-b | en | ablehnung | abgelehnt | – | – | 14896 |  |

## Fehlschläge

### q-001 — erwartet antwort

- Frage: Wie oft muss die Siegelstation gereinigt werden? (als kunde-a, de)
- Erwartet: ba-tb200 3.2 · 7.3 Reinigung Siegelstation; wp-tb200 1.1 · 2 Freigegebene Reinigungsmittel
- Erhalten: Status beantwortet; [3] Wartungsplan TB-200 v1.1 · 3.1 Siegelstation täglich; [1] Wartungsplan TB-400 v1.0 · 3.2 Siegelstation wöchentlich; [5] Wartungsplan TB-200 v1.1 · 3.2 Siegelstation wöchentlich
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

