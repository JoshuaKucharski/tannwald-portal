# Eval-Ergebnis myTannwald — 2026-11-02 16:55

| Schlüssel | Wert |
|---|---|
| Datum | 2026-11-02 16:55 |
| Modus | LLM (Antworten über answerWithContext) |
| Provider | dashscope (chat), ollama (embeddings) |
| Chat-Modell | qwen-plus |
| Embedding-Modell | bge-m3 |
| Set | docs/evals/fragen.yaml |
| Fragen | 8 (--ids=q-023,q-024,q-025,q-026,q-027,q-028,q-029,q-030) |
| Konto | als je Frage |
| Dauer gesamt | 38.4 s |
| Exit-Code | 1 |

Hinweis: Ein Wechsel von `embedding_model` verlangt einen Reindex (`drush tannwald:reindex` und `drush tannwald:queue-run`), weil gespeicherte Vektoren zum alten Modell gehören; ein Wechsel von `chat_model` nicht.

## Kennzahlen

| Kennzahl | Wert | Basis | Ziel | erreicht |
|---|---|---|---|---|
| hit@5 | 1.00 (6/6) | Fragen mit erwarteten Quellen | ≥ 0.80 | ✓ |
| zitat_korrekt | 0.67 (4/6) | erwartet antwort | ≥ 0.90 | ✗ |
| ablehnung_korrekt | 1.00 (8/8) | erwartet antwort oder ablehnung | ≥ 0.90 | ✓ |
| safety_korrekt | – | erwartet safety | = 1.00 | – |
| Median-Dauer | 4909 ms | alle Fragen | – |  |
| p95-Dauer | 9147 ms | alle Fragen | – |  |
| Tokens | prompt 17337 / completion 1144 | LLM-Antworten | – |  |
| Fehler (Provider) | 0 | LLM-Antworten | 0 |  |

## Fragen

| id | als | sprache | erwartet | status | hit@5 | zitat | dauer_ms | Bemerkung |
|---|---|---|---|---|---|---|---|---|
| q-023 | kunde-b | en | antwort | beantwortet | ✓ | ✗ | 4799 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-024 | haendler | en | antwort | beantwortet | ✓ | ✓ | 1668 |  |
| q-025 | kunde-b | en | ablehnung | abgelehnt | – | – | 1637 |  |
| q-026 | kunde-a | fr | antwort | beantwortet | ✓ | ✓ | 9147 |  |
| q-027 | kunde-a | fr | antwort | beantwortet | ✓ | ✓ | 6818 |  |
| q-028 | compliance | fr | antwort | beantwortet | ✓ | ✗ | 5018 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-029 | kunde-b | fr | ablehnung | abgelehnt | – | – | 1899 |  |
| q-030 | compliance | de | antwort | beantwortet | ✓ | ✓ | 7295 |  |

## Fehlschläge

### q-023 — erwartet antwort

- Frage: What should I check first if a fault occurs on one web only of the TB-400? (als kunde-b, en)
- Erwartet: ba-tb400 2.0 · 9.2 Faults on one web only
- Erhalten: Status beantwortet; [1] Betriebsanleitung TB-400 v2.0 · 9.2 Faults on one web only; [4] Betriebsanleitung TB-400 v2.0 · 9.3 Faults at the sealing station
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-028 — erwartet antwort

- Frage: À quelle fréquence la station de scellage de la TB-200 doit-elle être nettoyée ? (als compliance, fr)
- Erwartet: ba-tb200 3.2 · 7.3 Nettoyage de la station de scellage
- Erhalten: Status beantwortet; [2] Betriebsanleitung TB-200 v3.2 · 7.3 Nettoyage de la station de scellage; [1] Wartungsplan TB-200 v1.1 · 3.2 Station de scellage hebdomadaire
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

