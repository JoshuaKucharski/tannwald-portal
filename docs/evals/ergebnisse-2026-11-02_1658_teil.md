# Eval-Ergebnis myTannwald — 2026-11-02 16:58

| Schlüssel | Wert |
|---|---|
| Datum | 2026-11-02 16:58 |
| Modus | LLM (Antworten über answerWithContext) |
| Provider | dashscope (chat), ollama (embeddings) |
| Chat-Modell | qwen-plus |
| Embedding-Modell | bge-m3 |
| Set | docs/evals/fragen.yaml |
| Fragen | 15 (--ids=q-001,q-004,q-007,q-009,q-010,q-015,q-021,q-022,q-023,q-028,q-003,q-008,q-020,q-026,q-030) |
| Konto | als je Frage |
| Dauer gesamt | 66.7 s |
| Exit-Code | 1 |

Hinweis: Ein Wechsel von `embedding_model` verlangt einen Reindex (`drush tannwald:reindex` und `drush tannwald:queue-run`), weil gespeicherte Vektoren zum alten Modell gehören; ein Wechsel von `chat_model` nicht.

## Kennzahlen

| Kennzahl | Wert | Basis | Ziel | erreicht |
|---|---|---|---|---|
| hit@5 | 1.00 (15/15) | Fragen mit erwarteten Quellen | ≥ 0.80 | ✓ |
| zitat_korrekt | 0.20 (3/15) | erwartet antwort | ≥ 0.90 | ✗ |
| ablehnung_korrekt | 1.00 (15/15) | erwartet antwort oder ablehnung | ≥ 0.90 | ✓ |
| safety_korrekt | – | erwartet safety | = 1.00 | – |
| Median-Dauer | 3886 ms | alle Fragen | – |  |
| p95-Dauer | 8042 ms | alle Fragen | – |  |
| Tokens | prompt 34668 / completion 2567 | LLM-Antworten | – |  |
| Fehler (Provider) | 0 | LLM-Antworten | 0 |  |

## Fragen

| id | als | sprache | erwartet | status | hit@5 | zitat | dauer_ms | Bemerkung |
|---|---|---|---|---|---|---|---|---|
| q-001 | kunde-a | de | antwort | beantwortet | ✓ | ✗ | 2421 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-003 | kunde-a | de | antwort | beantwortet | ✓ | ✓ | 3618 |  |
| q-004 | kunde-a | de | antwort | beantwortet | ✓ | ✗ | 6874 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-007 | haendler | en | antwort | beantwortet | ✓ | ✗ | 3886 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-008 | techniker | de | antwort | beantwortet | ✓ | ✓ | 3904 |  |
| q-009 | kunde-a | de | antwort | beantwortet | ✓ | ✗ | 1819 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-010 | kunde-a | fr | antwort | beantwortet | ✓ | ✗ | 6593 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-015 | kunde-a | de | antwort | beantwortet | ✓ | ✗ | 7523 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-020 | techniker | en | antwort | beantwortet | ✓ | ✗ | 3370 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-021 | kunde-b | en | antwort | beantwortet | ✓ | ✗ | 1712 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-022 | kunde-b | en | antwort | beantwortet | ✓ | ✗ | 4755 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-023 | kunde-b | en | antwort | beantwortet | ✓ | ✗ | 2164 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-026 | kunde-a | fr | antwort | beantwortet | ✓ | ✗ | 8042 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-028 | compliance | fr | antwort | beantwortet | ✓ | ✗ | 3796 | Zitat zeigt nicht auf eine erwartete Quelle |
| q-030 | compliance | de | antwort | beantwortet | ✓ | ✓ | 6211 |  |

## Fehlschläge

### q-001 — erwartet antwort

- Frage: Wie oft muss die Siegelstation gereinigt werden? (als kunde-a, de)
- Erwartet: ba-tb200 3.2 · 7.3 Reinigung Siegelstation; wp-tb200 1.1 · 2 Freigegebene Reinigungsmittel; wp-tb200 1.1 · 3.2 Siegelstation wöchentlich
- Erhalten: Status beantwortet; [1] Betriebsanleitung TB-200 v3.2 · 7.3 Reinigung Siegelstation; [2] Wartungsplan TB-400 v1.0 · 3.2 Siegelstation wöchentlich; [6] Betriebsanleitung TB-400 v2.0 · 7.2 Reinigung Siegelstation
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-004 — erwartet antwort

- Frage: Warum reisst die Folie beim Siegeln? (als kunde-a, de)
- Erwartet: ba-tb200 3.2 · 9.4 Störungen an der Siegelstation: Siegelnaht undicht, Folienriss
- Erhalten: Status beantwortet; [2] Betriebsanleitung TB-200 v3.2 · 9.4 Störungen an der Siegelstation: Siegelnaht undicht, Folienriss; [1] Kalibrierzertifikat Temperaturmessung TB-200 v1.0 · 11 Zusammenhang mit Störungen an der Siegelstation; [5] Schulungsunterlage TB-200 Formatwechsel v1.0 · 9 Formatwechsel mit Rollenwechsel
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-007 — erwartet antwort

- Frage: What is the setpoint for the brake value after a roll change on the TB-200? (als haendler, en)
- Erwartet: SRV-2026-19 · Tabelle 2: Angepasste Sollwerte für den Bremswert; ba-tb200 3.2 · 5.2 Roll change
- Erhalten: Status beantwortet; [1] Betriebsanleitung TB-200 v3.2 · 5.2 Roll change; [6] SRV-2026-19 Folienspannung nach Rollenwechsel v · Ursache und Fehlerbild; [3] SRV-2026-19 Folienspannung nach Rollenwechsel v · Folienspannung nach Rollenwechsel: angepasster Sollwert; [4] Betriebsanleitung TB-200 v3.2 · 9.4 Faults at the sealing station: leaking seal seam, film tear
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-009 — erwartet antwort

- Frage: Bis wann war das Kalibrierzertifikat Temperaturmessung TB-200 gültig? (als kunde-a, de)
- Erwartet: zert-kalibrierung-tb200 1.0 · 7 Ablösung dieses Zertifikats
- Erhalten: Status beantwortet; [2] Kalibrierzertifikat Temperaturmessung TB-200 v1.0 · 1.1 Angaben zum Zertifikat; [3] Kalibrierzertifikat Temperaturmessung TB-200 v1.0 · 7 Ablösung dieses Zertifikats
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-010 — erwartet antwort

- Frage: Comment calibrer la cellule de détection d'étui du KT-500 ? (als kunde-a, fr)
- Erwartet: wp-kt500 2.0 · 3.2 Calibration of the carton detection cell
- Erhalten: Status beantwortet; [1] Wartungsplan KT-500 v2.0 · 3.2 Calibration of the carton detection cell; [3] Schulungsunterlage KT-500 Bedienerschulung v1.0 · 4.3 Calibration of the carton detection cell; [4] Betriebsanleitung KT-500 v1.3 · 9.2 Faults at the detection cell
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-015 — erwartet antwort

- Frage: Welche Reinigungsmittel sind für die TB-200 freigegeben? (als kunde-a, de)
- Erwartet: wp-tb200 1.1 · 2 Freigegebene Reinigungsmittel
- Erhalten: Status beantwortet; [2] Wartungsplan TB-200 v1.1 · 2 Freigegebene Reinigungsmittel; [1] Zertifikat Hygienedesign TB-200 und TB-400 v1.0 · 10 Reinigungsmittel und Beständigkeit; [6] Wartungsplan TB-200 v1.1 · 2 Approved cleaning agents
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-020 — erwartet antwort

- Frage: Which conditions must be fulfilled before the high-frequency generator receives the HF_ENABLE signal? (als techniker, en)
- Erwartet: sp-hf-siegelung 1.4 · 5 Interlock chain
- Erhalten: Status beantwortet; [2] Schaltplan Hochfrequenz-Siegelung v1.4 · 5 Interlock chain; [3] Schaltplan Hochfrequenz-Siegelung v1.4 · 4 Signal list
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-021 — erwartet antwort

- Frage: How often must the carton detection cell of the KT-500 be calibrated? (als kunde-b, en)
- Erwartet: wp-kt500 2.0 · 3.2 Calibration of the carton detection cell
- Erhalten: Status beantwortet; [1] Schulungsunterlage KT-500 Bedienerschulung v1.0 · 4.3 Calibration of the carton detection cell; [2] Wartungsplan KT-500 v2.0 · 3.2 Calibration of the carton detection cell; [5] Ersatzteilkatalog KT-500 v1.0 · 3 Carton magazine and carton feed; [6] Betriebsanleitung KT-500 v1.3 · 6 Format change
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-022 — erwartet antwort

- Frage: Which cleaning agents are approved for the KT-500? (als kunde-b, en)
- Erwartet: wp-kt500 2.0 · 2 Approved cleaning agents
- Erhalten: Status beantwortet; [1] Wartungsplan KT-500 v2.0 · 2 Approved cleaning agents; [2] Schulungsunterlage KT-500 Bedienerschulung v1.0 · 6 Cleaning in the training; [3] Betriebsanleitung KT-500 v1.3 · 7 Cleaning and maintenance
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-023 — erwartet antwort

- Frage: What should I check first if a fault occurs on one web only of the TB-400? (als kunde-b, en)
- Erwartet: ba-tb400 2.0 · 9.2 Faults on one web only
- Erhalten: Status beantwortet; [1] Betriebsanleitung TB-400 v2.0 · 9.2 Faults on one web only; [3] Betriebsanleitung TB-400 v2.0 · 9.1 Fault table
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-026 — erwartet antwort

- Frage: Quels produits de nettoyage sont homologués pour la TB-200 ? (als kunde-a, fr)
- Erwartet: wp-tb200 1.1 · 2 Produits de nettoyage homologués
- Erhalten: Status beantwortet; [1] Wartungsplan TB-200 v1.1 · 2 Produits de nettoyage homologués; [2] Betriebsanleitung TB-200 v3.2 · 2.5 Produits de nettoyage; [6] Wartungsplan TB-200 v1.1 · 2 Produits de nettoyage homologués
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

### q-028 — erwartet antwort

- Frage: À quelle fréquence la station de scellage de la TB-200 doit-elle être nettoyée ? (als compliance, fr)
- Erwartet: ba-tb200 3.2 · 7.3 Nettoyage de la station de scellage
- Erhalten: Status beantwortet; [2] Betriebsanleitung TB-200 v3.2 · 7.3 Nettoyage de la station de scellage; [1] Wartungsplan TB-200 v1.1 · 3.2 Station de scellage hebdomadaire
- Grund: Zitat zeigt nicht auf eine erwartete Quelle

