# Evals myTannwald

Das Eval-Set `fragen.yaml` (60 Fragen: 22 Antwort, 8 Ablehnung, 30 Sicherheit; de/en/fr; sechs Demo-Konten) misst, ob
der Assistent die richtigen Kapitel findet, korrekt zitiert, ehrlich ablehnt und bei Sicherheitsthemen auf das
Sicherheitskapitel verweist (Pflichtenheft §8, Lastenheft TC-07, TC-09, TC-11, TC-14).

## Lauf wiederholen

```
cd /Users/myki/dev/helga/tannwald-portal
ddev drush tannwald:eval --dry          # nur Retrieval, alle 60 Fragen, Sekunden
ddev drush tannwald:eval --limit=10     # erste 10 Fragen mit LLM, Minuten
ddev drush tannwald:eval                # voller Lauf mit LLM, 15–25 Minuten, Ollama exklusiv
```

Weitere Optionen: `--ids=q-001,q-004`, `--as=kunde-b`, `--set=<datei>`, `--out=<datei>`. Exit-Code 0 = alle geprüften
Ziele erreicht (bei `--dry` nur `hit@5`), 1 = mindestens ein Ziel verfehlt, 2 = Set oder Quelle nicht auflösbar.
Voraussetzung: Demo-Daten (`tannwald:demo-install`), Ollama mit `bge-m3` (und `qwen2.5:7b` ohne `--dry`).

## Was die Zahlen bedeuten

| Kennzahl | Bedeutung | Ziel |
|---|---|---|
| `hit@5` | Eine erwartete Quelle (Dokument, Version, Kapitel) steht unter den ersten 5 Treffern — Fragen mit Quellen | ≥ 0.80 |
| `zitat_korrekt` | Die Antwort ist belegt und **jedes** Zitat zeigt auf eine erwartete Quelle — Antwort-Fragen | ≥ 0.90 |
| `ablehnung_korrekt` | Ablehnungs-Fragen werden abgelehnt, Antwort-Fragen nicht | ≥ 0.90 |
| `safety_korrekt` | Sicherheitsvorlage mit mindestens einem erwarteten Sicherheitskapitel/Bulletin | = 1.0 |

Kapitel werden über die Kapitelnummer (oder den Slug) verglichen, damit eine Quelle in einer anderen Sprache zählt.

## Dateien

`ergebnisse-<datum>_<zeit>.md` = voller LLM-Lauf (Betreiber), `…_dry.md` = nur Retrieval, `…_teil.md` = `--limit`/`--ids`.
Kopf (Modell, Provider, Set), Kennzahlen, Tabelle je Frage, Fehlschläge mit erwarteten und erhaltenen Quellen.

## Vorher / Nachher (6.1)

| Kennzahl | Baseline (Betreiber, 21:20, voll) | Eval 2 `--dry` (22:01) | Eval 2 voll (Betreiber, 22:44) | Sicherheitsfragen ohne LLM (23:09) |
|---|---|---|---|---|
| `hit@5` | 0.73 (38/52) | **0.87 (45/52)** | **0.87 (45/52)** | 0.87 (26/30) |
| `hit@5` Sicherheitsfragen | 0.63 (19/30) | **0.87 (26/30)** | 0.87 | 0.87 (26/30) |
| `zitat_korrekt` | 0.41 (9/22) | – | 0.50 (11/22) | – |
| `ablehnung_korrekt` | 0.97 (29/30) | – | **1.00 (30/30)** | – |
| `safety_korrekt` | 0.60 (18/30) | – | 0.63 (19/30) | **0.87 (26/30)** |
| Median-Dauer | 218 ms (Retrieval) | – | 7029 ms | – |
| Sicherheitserkennung | 24/30, 8 falsch-positiv | 30/30, 0 falsch-positiv | 30/30 | 30/30 |

`safety_korrekt` hängt nicht am LLM (feste Vorlage); der Nachher-Wert stammt darum aus
`ergebnisse-2026-11-01_2309_teil.md` (`--ids` der 30 Sicherheitsfragen, deterministisch, nach dem Vorlagen-Fix:
bis zu drei verschiedene Kapitel, Unterkapitel vor „2 Sicherheit“, kein Kapitel doppelt in zwei Sprachen). Die vier
übrigen Fehlschläge (q-037, q-042, q-050, q-051) sind Retrieval-Fehlschläge: das erwartete Kapitel steht nicht unter den
Treffern. `--limit=10`-Läufe (22:02, 22:08, 22:14): `zitat_korrekt` 0.14 / 0.29 / 0.43 je Wortlaut der Zitierregel —
`qwen2.5:7b` zitiert verwandte Kapitel mit; Regel 2 blieb beim Wortlaut aus 4.1. Wiederholen ohne LLM:
`ddev drush tannwald:eval --ids=<ids der Sicherheitsfragen>`.

Geändert zwischen Baseline und Eval 2: q-019 erwartet Version 3.2 (Entscheid: Antworten nur aus der aktuellen Version),
q-001 zusätzlich Wartungsplan TB-200 3.2, q-033 ohne SB-2026-08 (seit dem Reseed `zur_pruefung`); Fixture 9.4 ohne
„reisst“ (TC-06). Der zweite volle LLM-Lauf (Betreiber, 22:44) liegt als `ergebnisse-2026-11-01_2244.md` vor; er lief vor dem Vorlagen-Fix.
