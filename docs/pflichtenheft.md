# Technisches Pflichtenheft – myTannwald Kundenportal (Proof of Concept)

| | |
|---|---|
| **Dokumenttyp** | Pflichtenheft (technisch) – Antwort des Auftragnehmers auf das Lastenheft v1.0 |
| **Bezug** | `docs/lastenheft.yaml`, Version 1.0 vom 31.10.2026 |
| **Geltungsbereich** | 48-Stunden-Proof-of-Concept (PoC). Abschnitt 14 beschreibt den Ausbau zu Phase 1 (Produktion). |
| **Version / Status** | 1.0 / zur Umsetzung freigegeben |
| **Datum** | 31.10.2026 |
| **Fiktiv** | Kunde, Daten und Zahlen sind erfunden. Reale Produktnamen bezeichnen Systemtypen. |

> Das Lastenheft sagt, **was** der Kunde braucht. Dieses Dokument sagt, **wie** es gebaut wird: Architektur, Technologiestack, Datenmodell, Modul-Design, Qualitätssicherung, Betrieb. Jede Umsetzungsentscheidung verweist auf die Anforderungs-ID aus dem Lastenheft und auf den Nachweis (Testfall, Eval, Demo).

---

## 1. Zielsetzung und Abgrenzung

**Der PoC beweist in einem lauffähigen System:**

1. Dokumente pro Maschine mit Versionen, Sprachen und Exportklasse hinter einem Login (FA-DP-01, 03, 05, 08, 09).
2. Rechtebewusste hybride Suche und ein Assistent, der nur aus freigegebenen, berechtigten Dokumenten antwortet und zitiert (FA-SE-01, 02, 04; FA-AI-01 bis 08).
3. Einen redaktionellen Freigabe-Workflow für KI-Zusammenfassungen (FA-AI-06, FA-RED-01).
4. Messbare Qualität: automatisierte Tests für die Rechte-Logik, Evals für den Assistenten, Kosten-Tracking (FA-AI-08, FA-REP-02, NFA-QA-03).

**Simuliert** (gleiche Logik, austauschbare Quelle): SAP (JSON-Fixtures über Migrate), Azure Blob (privates Dateisystem), Salesforce (Support-Anfrage als Entity), PDF-Extraktion (Dokumente als strukturiertes HTML).

**Nicht im PoC:** SSO über Entra ID, Kunden-Admin, Offline-Pakete/PWA, dynamische Kategorien, Support-Assistent, MCP, PIM/DAM, Lead-Routing, Commerce, Lasttests, Hochverfügbarkeit, Barrierefreiheits-Audit. Vollständige Liste: Lastenheft Anhang 17, `poc_abdeckung.nicht_im_poc`.

---

## 2. Anforderungsabdeckung

| ID | Anforderung (Kurzform) | Umsetzung im PoC | Nachweis |
|---|---|---|---|
| FA-AUTH-01 | Login Kunden/Händler, Zuordnung Firma | Drupal-Konten, Nutzerfelder `field_firma`, `field_land`; Demo-Nutzer | TC-01, Functional Test |
| FA-AUTH-03 | Rollenmodell | Rollen `kunde`, `haendler`, `techniker`, `redaktion`, `compliance`, `portal_admin`; Rechtematrix in `docs/rechtematrix.md` | Kernel Test Rechtematrix |
| FA-DP-01 | Dokumente pro Seriennummer | `maschine`-Nodes mit Referenz auf Maschinentyp; Dokumente referenzieren Maschinentypen oder eine Seriennummer; View `portal_maschine` | TC-01 |
| FA-DP-03 | Versionierung, Änderungshistorie | Node-Revisionen mit Pflichtfeld `field_aenderungsvermerk`, Revisionslog-View | Demo |
| FA-DP-04 | Benachrichtigung, Lesebestätigung | Mail bei Bulletin-Freigabe über Queue; Entity `lesebestaetigung` | TC-05 (Mailpit) |
| FA-DP-05 | Exportkontrolle | Feld `field_exportklasse`, Config `tannwald_access.export_rules`, Access-Grants | TC-03 |
| FA-DP-06 | Download-Protokoll | Tabelle `tannwald_audit` mit Ereignistyp `download` | Demo, CSV-Export-View |
| FA-DP-08 | Mehrsprachige Dokumente | Content Translation, Fallback-Logik im Theme | Demo |
| FA-DP-09 | Anlagenspezifische Dokumente | `field_seriennummer` am Dokument, Grant-Realm `serial` | TC-02, Functional Test |
| FA-SE-01 | Hybride Suche | Search API DB (BM25-ähnlich) + Vektor-Cosinus + RRF | TC-06, Eval |
| FA-SE-02 | Live-Resultate, Facetten | Endpoint `/api/search/live`, Facets-Modul | Demo |
| FA-SE-04 | Berechtigungsbewusste Suche | Access-Keys als Index-Feld und Vorfilter; Entity-Access nach Treffer | TC-02, TC-03 |
| FA-AI-01 | Belegte Antworten | Zitatpflicht im Prompt, `CitationValidator` | TC-07, Eval |
| FA-AI-02 | Rechte im Assistenten | Retriever nutzt dieselbe Access-Logik wie Suche | TC-02, Functional Test |
| FA-AI-03 | Ablehnung, Eskalation | Schwellwert, `support_anfrage`-Entity | TC-08 |
| FA-AI-04 | Sicherheitshinweise | Themenklassifikator + Prompt-Regel | TC-09, Eval |
| FA-AI-05 | Sprache | Spracherkennung der Frage, Antwortsprache erzwungen | Eval (de, en, fr) |
| FA-AI-06 | Zusammenfassungen mit Freigabe | Content Moderation auf Content Type `zusammenfassung`; nur `published` indexiert | TC-10 |
| FA-AI-07 | Feedback, unbeantwortete Fragen | Tabelle `tannwald_assistant_log`, View `redaktion_unbeantwortet` | Demo |
| FA-AI-08 | Qualitätsmessung | `drush tannwald:eval`, `docs/evals/` | TC-11 |
| FA-RED-01 | Redaktions-Workflow, Vier-Augen | Workflow `redaktion`; Constraint `NotOwnBulletinApproval` | Demo, Kernel Test |
| FA-RED-02 | Metadaten-Pflege, Massenbearbeitung | Views Bulk Operations | Demo |
| FA-REP-01 | Nutzungs-Dashboard | Views auf Log- und Audit-Tabellen | Demo |
| FA-REP-02 | Kosten-Dashboard, Limit | Tabelle `tannwald_usage`, Config `monthly_token_limit`, Suche-only-Modus | TC-12 |
| NFA-SEC-02 | Rechte automatisiert getestet | Functional Test über drei Rollen, Kernel Test Grants | CI |
| NFA-SEC-04 | Prompt-Injection, Rate-Limit | Kontext-Abgrenzung, keine Tools im RAG-Pfad, Flood Control | TC-16 |
| NFA-DSG-02 | LLM ohne Training, Wechsel möglich | Ollama lokal als Standard; Provider per Config | TC-14 |
| NFA-QA-03 | Standards, Static Analysis, Tests | PHPCS Drupal, PHPStan Level 5, PHPUnit in GitHub Actions | CI |
| NFA-QA-04 | ADRs, Dokumentation | `docs/adr/`, README | Review |
| NFA-QA-05 | Kein LLM-Lock-in | AI-Modul Provider-Abstraktion | TC-14 |

---

## 3. Systemarchitektur

### 3.1 Überblick

```mermaid
flowchart LR
  subgraph Nutzer
    K[Kunde / Händler / Techniker]
    R[Redaktion / Compliance]
  end
  subgraph Drupal["Drupal 11 (DDEV)"]
    T[Theme tannwald<br/>Twig · SDC · Tailwind · Alpine]
    A[tannwald_access<br/>Entitlement · Grants · Exportregeln · Audit]
    I[tannwald_ingest<br/>Chunker · Embeddings · Queue · Zusammenfassung]
    S[tannwald_assistant<br/>Retriever · Prompt · Antwort · Evals · Usage]
    D[tannwald_demo<br/>Fixtures · Migrate · Reset]
    W[Core: Content Moderation · Search API DB · Facets · Views · Queue]
    DB[(MariaDB<br/>Nodes · Revisionen<br/>tannwald_chunk · _usage · _audit · _assistant_log)]
  end
  O[Ollama (Host)<br/>bge-m3 · qwen2.5:7b]
  M[Mailpit]
  K --> T
  R --> T
  T --> A
  T --> S
  S --> A
  S --> DB
  I --> DB
  W --> DB
  I -- "Drupal AI: embeddings / chat" --> O
  S -- "Drupal AI: chat" --> O
  I -- "Benachrichtigung" --> M
```

### 3.2 Die vier Kernflüsse

**Ingestion (Dokument → Chunks):** Dokument gespeichert oder Zusammenfassung freigegeben → `hook_entity_update` stellt Job `tannwald_embed` in die Queue → Worker lädt Revision, prüft Moderationsstatus `published`, chunkt entlang Überschriften (`h2`/`h3`, Ziel 350 Tokens, Überlappung 60), ruft Embeddings über das AI-Modul ab, schreibt in `tannwald_chunk` mit Access-Keys → Suchindex-Item wird markiert. Unpublish oder Zurückweisung löscht die Chunks derselben Revision.

**Retrieval und Antwort (Frage → zitierte Antwort):** `POST /api/assistant` → Flood Control (10/min pro Nutzer) → Limit-Check (`tannwald_usage`) → `Retriever`: Access-Keys des Nutzers berechnen → Keyword-Treffer über Search API (Top 20) und Vektor-Treffer über Cosinus auf `tannwald_chunk` gefiltert nach Access-Keys (Top 20) → RRF-Fusion (k = 60) → Top 6 → pro Chunk `entityAccess('view')` des zugehörigen Nodes (zweite Schranke) → Score-Schwelle → `PromptBuilder` → AI-Chat → `CitationValidator` prüft, dass jedes `[n]` auf einen gelieferten Chunk zeigt → Antwort, Log, Usage.

**Zugriff (Nutzer → sichtbare Dokumente):** `EntitlementService` berechnet aus Firma, Rolle, Land und Maschinenbestand die Grant-Realms. `hook_node_grants` liefert sie bei jedem Node-Zugriff; `hook_node_access_records` schreibt pro Dokument die passenden Realms. Suche und Assistent nutzen dieselben Keys als Index-Feld, Views nutzen die Grants automatisch.

**Freigabe (Zusammenfassung → Index):** Ingestion erzeugt pro neuer Dokument-Revision einen `zusammenfassung`-Node im Status `zur_pruefung`. Redaktion bearbeitet, gibt frei oder weist zurück. Nur `published` wird indexiert und am Dokument angezeigt. Für Bulletins verhindert eine Entity-Constraint, dass die erstellende Person freigibt.

---

## 4. Technologiestack

| Schicht | PoC (48 h) | Version | Produktion Phase 1 | Begründung (ADR) |
|---|---|---|---|---|
| CMS | Drupal | 11.x, aktuelle Minor | Drupal 11 | ADR-001 |
| Sprache | PHP | 8.4 | PHP 8.4 | Drupal 11 Minimum 8.3 |
| Datenbank | MariaDB | 11.4 LTS | Azure Database for MySQL Flexible Server | Beste Contrib-Kompatibilität |
| Cache | Drupal Cache in DB | – | Redis 7 (`redis`) | PoC-Last klein |
| Queue | Core Queue API, `drush queue:run` per Cron | – | `advancedqueue` | Sichtbarkeit und Retry erst produktiv nötig |
| Volltextsuche | `search_api` + `search_api_db` + `facets` | 1.x | `search_api_opensearch`, OpenSearch 2.19+ | ADR-003 |
| Vektorsuche | Tabelle `tannwald_chunk`, Cosinus in PHP | – | OpenSearch k-NN, eigener Retriever bleibt | ADR-003 |
| LLM-Abstraktion | `drupal/ai` | 1.x stabil | `drupal/ai` | ADR-004 |
| LLM-Provider | `ai_provider_ollama` | 1.x | `ai_provider_azure` (Switzerland North), Fallback vLLM | ADR-004, E-05 |
| Embedding-Modell | `bge-m3` (1024 Dim., mehrsprachig) | via Ollama | `text-embedding-3-large` oder `bge-m3` selbst betrieben | Mehrsprachigkeit zh/ja/ko |
| Chat-Modell | `qwen2.5:7b` (Alternative `llama3.1:8b`) | via Ollama | Modell gemäss Azure-Verfügbarkeit in CH | – |
| Workflow | Core `content_moderation`, `workflows` | – | gleich | ADR-005 |
| Mehrsprachigkeit | Core `language`, `content_translation`, `config_translation` | – | gleich + `tmgmt` | – |
| Dateien | Private Files (`private://`) | – | Azure Blob + SAS-URLs über CDN | ADR-006 |
| Import | `migrate`, `migrate_plus`, `migrate_tools`, JSON-Fixtures | 6.x | gleiche Migrationen gegen SAP OData | ADR-007 |
| Mail | `symfony_mailer`, Mailpit (DDEV) | – | SMTP-Relay des Kunden | – |
| 2FA | nicht im PoC | – | `tfa` | – |
| Theme | Starterkit-Theme, Twig, SDC, Tailwind 4, Alpine.js 3, Vite 6 | – | gleich | ADR-008 |
| Demo-Hilfen | `masquerade`, `queue_ui` | – | nur auf Nicht-Produktion | – |
| Lokale Umgebung | DDEV (php 8.4, mariadb 11.4, Mailpit), Ollama auf dem Host | – | Terraform + Ansible auf Azure | – |
| CI | GitHub Actions: PHPCS, PHPStan, PHPUnit Unit+Kernel | – | + Functional, Deploy via OIDC | – |
| Static Analysis | `phpstan/phpstan` + `mglaman/phpstan-drupal` Level 5, `drupal/coder` | – | Level 6+ | NFA-QA-03 |
| Tests | PHPUnit Unit, Kernel, Functional (BrowserTestBase) | – | + FunctionalJavascript, Lasttest | – |
| Code-Qualität | `drupal/core-dev`, `rector` optional | – | Renovate | – |
| Hosting (optional) | Hetzner VM, Docker Compose, Caddy, Ansible | – | Azure Switzerland North | – |

---

## 5. Datenmodell (PoC)

### 5.1 Content Types (Nodes, übersetzbar, revisionsfähig)

| Content Type | Felder (Maschinenname: Typ) | Besonderheiten |
|---|---|---|
| `dokument` | `title`, `body` (HTML mit `h2`/`h3`), `field_dokumenttyp` (Taxonomie), `field_maschinentyp` (Taxonomie, mehrwertig), `field_seriennummer` (Entity-Ref `maschine`, optional), `field_version` (String, z. B. `3.2`), `field_aenderungsvermerk` (Text, Pflicht bei neuer Revision), `field_exportklasse` (List: `keine`, `klasse_1`, `klasse_2`), `field_freigegeben_am` (Date), `field_datei` (File, privat), `field_gueltig_bis` (Date, optional) | Workflow `redaktion`; nur `published` sichtbar und indexiert |
| `bulletin` | `title`, `body`, `field_bulletin_art` (List: `service`, `sicherheit`), `field_maschinentyp`, `field_seriennummern` (Entity-Ref, mehrwertig, optional), `field_bestaetigung_pflicht` (Boolean) | Vier-Augen-Constraint bei `sicherheit` |
| `zusammenfassung` | `title`, `body`, `field_dokument` (Entity-Ref `dokument`), `field_dokument_revision` (Integer), `field_modell` (String), `field_generiert_am` (Date) | Workflow `redaktion`; nie manuell erstellt |
| `maschine` | `title` (Seriennummer), `field_maschinentyp`, `field_firma` (Taxonomie `firma`), `field_standort` (String), `field_baujahr` (Integer), `field_vertrag` (List: `basis`, `service_plus`), `field_vertrag_bis` (Date) | Quelle: Migrate aus `fixtures/sap/maschinen.json` |

### 5.2 Taxonomien

- `maschinentyp`: TB-200, TB-400, KT-500 (Feld `field_typ_code`)
- `dokumenttyp`: Betriebsanleitung, Ersatzteilkatalog, Schaltplan, Wartungsplan, Zertifikat, Schulungsunterlage, Anlagenspezifische Dokumentation
- `firma`: Kundenfirmen und Händler (Felder `field_sap_nr`, `field_land` ISO-3166, `field_firma_art`: `kunde`/`haendler`, `field_haendler_gebiet` Länderliste). Quelle: Migrate aus `fixtures/sap/firmen.json`

### 5.3 Nutzerfelder

`field_firma` (Taxonomie `firma`), `field_land` (ISO-3166), `field_sprache` (Core `preferred_langcode`). Rollen: `kunde`, `haendler`, `techniker`, `redaktion`, `compliance`, `portal_admin`.

### 5.4 Custom Entities und Tabellen

| Name | Art | Felder | Zweck |
|---|---|---|---|
| `lesebestaetigung` | Content Entity | `uid`, `bulletin_nid`, `maschine_nid`, `bestaetigt_am`, `ip_land` | FA-DP-04, unveränderbar |
| `support_anfrage` | Content Entity | `uid`, `maschine_nid`, `frage`, `verlauf` (JSON), `status`, `created` | FA-AI-03, ersetzt Salesforce im PoC |
| `tannwald_chunk` | DB-Tabelle | `id`, `nid`, `vid`, `langcode`, `chunk_index`, `heading_path`, `text`, `token_count`, `embedding` (BLOB, float32 LE), `access_keys` (Text, Leerzeichen-getrennt), `exportklasse`, `created` | Vektorindex |
| `tannwald_assistant_log` | DB-Tabelle | `id`, `session_hash`, `uid_hash`, `langcode`, `frage`, `antwort`, `quellen` (JSON), `status` (`beantwortet`/`abgelehnt`/`limit`), `bewertung`, `dauer_ms`, `created` | FA-AI-07, pseudonymisiert |
| `tannwald_usage` | DB-Tabelle | `id`, `modell`, `operation` (`chat`/`embedding`), `prompt_tokens`, `completion_tokens`, `kosten_chf`, `kontext` (`assistent`/`ingest`/`eval`), `created` | FA-REP-02 |
| `tannwald_audit` | DB-Tabelle | `id`, `uid`, `ereignis` (`download`, `zugriff_verweigert`, `freigabe`, `exportregel_geaendert`, `lesebestaetigung`), `entity_type`, `entity_id`, `details` (JSON), `ip_land`, `created` | NFA-SEC-03, nur Insert |

### 5.5 Access-Keys (Realms)

Jedes Dokument erhält beim Speichern und beim Indexieren die Menge seiner Realms; jeder Nutzer erhält bei der Anfrage die Menge seiner Realms. Ein Dokument ist sichtbar, wenn **alle** Bedingungen erfüllt sind:

| Realm | Dokument trägt | Nutzer besitzt | Bedingung |
|---|---|---|---|
| `type:<maschinentyp>` | pro referenziertem Maschinentyp | pro Maschinentyp im Bestand der Firma (Händler: pro Typ im Gebietsvertrag) | mindestens ein gemeinsamer Typ **oder** Serial-Match |
| `serial:<nid>` | wenn `field_seriennummer` gesetzt | pro Maschine der eigenen Firma | bei anlagenspezifischen Dokumenten zwingend (FA-DP-09) |
| `export:<klasse>` | gemäss `field_exportklasse` | gemäss Land des Nutzers und `export_rules` | fehlt der Key beim Nutzer, ist das Dokument unsichtbar (FA-DP-05) |
| `role:intern` | – | Techniker, Redaktion, Compliance | interne Rollen sehen alle Typen, Exportregeln gelten trotzdem |

Umsetzung über `hook_node_grants` (Nutzer) und `hook_node_access_records` (Dokument) mit `grant_view`; für die Suche als Index-Feld `access_keys`. Der Assistent filtert vor dem Retrieval über `access_keys` und prüft nach dem Retrieval jeden Treffer mit `$node->access('view', $account)`.

---

## 6. Modul-Design

### 6.1 `tannwald_access`

| Element | Beschreibung |
|---|---|
| `EntitlementService` | `getRealmsForAccount(AccountInterface): string[]`, `getRealmsForNode(NodeInterface): string[]`, cachebar per Nutzer mit Cache-Tags `user:<uid>`, `taxonomy_term_list:firma`, `node_list:maschine` |
| `ExportRuleService` | liest `tannwald_access.settings` (`export_rules`: Klasse → erlaubte Länder, gesperrte Firmen); Admin-Formular unter `/compliance/exportregeln` |
| `AuditLogger` | `log(string $ereignis, EntityInterface $entity, array $details)`, schreibt `tannwald_audit`, hängt sich an Download-Controller, Access-Denied-Events und Workflow-Transitions |
| Hooks | `hook_node_grants`, `hook_node_access_records`, `hook_node_access_records_alter` (Bulletins), `hook_file_download` (privater Download nur mit Node-Zugriff, protokolliert) |
| Search API Processor | `AccessKeysProcessor` schreibt `access_keys` als Index-Feld; Query-Alter ergänzt den Filter |
| Permissions | `tannwald view own company documents`, `tannwald view all documents`, `tannwald manage export rules`, `tannwald view audit log` |
| Constraint | `NotOwnBulletinApproval` (Entity-Validation auf `bulletin` bei Transition zu `published`) |
| Tests | Kernel: Realm-Berechnung für 6 Rollen × 3 Länder; Functional: `DocumentLeakTest` (Kunde B, Händler, anonym gegen Dokument von Kunde A: 403, kein Suchtreffer, keine Assistent-Antwort) |

### 6.2 `tannwald_ingest`

| Element | Beschreibung |
|---|---|
| `Chunker` | HTML → Abschnitte entlang `h2`/`h3`; `heading_path` wie `7 Wartung › 7.3 Reinigung`; Zielgrösse 350 Tokens (Schätzung 4 Zeichen/Token), Überlappung 60, Tabellen bleiben in einem Chunk; Unit-Test mit 5 Dokumentformen |
| `EmbeddingService` | Wrapper um `ai.provider` → `embeddings()`; Batch 16; schreibt `tannwald_usage` |
| `ChunkStore` | Interface `ChunkStoreInterface` (`replaceForRevision`, `deleteForNode`, `similar(vector, accessKeys, limit)`); Implementation `DatabaseChunkStore`. Der OpenSearch-Store ist als Interface vorbereitet, nicht implementiert (ADR-003) |
| `QueueWorker` `tannwald_embed` | Payload `{nid, vid, langcode, action: index|delete}`; idempotent; Fehler → 3 Wiederholungen, dann Watchdog |
| `SummaryGenerator` | erzeugt pro neuer Dokument-Revision einen `zusammenfassung`-Node (max. 120 Wörter, Sprache des Dokuments) im Status `zur_pruefung`; Prompt verlangt Nennung sicherheitsrelevanter Kapitel |
| Hooks | `hook_entity_insert/update/delete` für `dokument`, `bulletin`, `zusammenfassung` → Queue; `hook_entity_update` erkennt Moderationsstatus-Wechsel |
| Drush | `tannwald:reindex [--nid]`, `tannwald:queue-run` |

### 6.3 `tannwald_assistant`

| Element | Beschreibung |
|---|---|
| `Retriever` | `retrieve(string $frage, AccountInterface, ?int $maschineNid): RetrievalResult` – Keyword (Search API, Top 20) + Vektor (`ChunkStore::similar`, Top 20) → RRF (k = 60) → Top 6 → Entity-Access-Check → Schwelle `min_score` (konfigurierbar, Standard 0.015 RRF) |
| `PromptBuilder` | System-Prompt (de/en/fr), Kontextblöcke `<<<DOKUMENT n>>> … <<<ENDE n>>>` mit Metadaten, Regeln: nur aus Kontext antworten, jede Aussage mit `[n]`, bei Sicherheitsthemen nur Verweis, Antwortsprache = Fragesprache |
| `SafetyTopicDetector` | Schlagwortliste (Lockout, Schutzhaube, Hochspannung, Reinigungsmittel, Überbrücken …) in drei Sprachen plus Prüfung über Chunk-Metadaten (`heading_path` enthält «Sicherheit»); Treffer setzt Prompt-Modus `safety` |
| `AnswerService` | orchestriert; `CitationValidator` entfernt oder verwirft Zitate ohne Chunk; fehlt nach Validierung jede Quelle → Ablehnung |
| `LanguageDetector` | Heuristik über Stoppwörter de/en/fr; Fallback Nutzer-Sprache |
| `UsageTracker` | lauscht auf AI-Modul-Events (`PostGenerateResponseEvent`), schreibt Tokens aus der Provider-Antwort (Ollama: `prompt_eval_count`, `eval_count`); Kosten aus Config (CHF pro 1k Tokens, für Ollama 0) |
| `LimitService` | Monatslimit `monthly_token_limit`; 80 % → Warnmail an `portal_admin`; 100 % → Modus `search_only` (Antwort 503 mit Hinweis, Suche bleibt) |
| Controller | `POST /api/assistant` (JSON: `frage`, `maschine_nid?`, `session`), CSRF-Token, `_user_is_logged_in`, Flood Control 10/min; `POST /api/assistant/feedback`; `POST /api/assistant/support` → `support_anfrage`; `GET /api/search/live?q=` (Top 5, JSON) |
| Config-Formular | `/admin/config/tannwald/assistent`: Modell, `top_k`, `min_score`, Limit, Kostensätze, Safety-Liste |
| Drush | `tannwald:eval [--set=docs/evals/fragen.yaml] [--as=kunde-a]` → Tabelle und `docs/evals/ergebnisse-<datum>.md` |
| Views | `redaktion_unbeantwortet`, `admin_nutzung`, `admin_kosten` |
| Tests | Unit: `RrfFusionTest`, `CitationValidatorTest`, `SafetyTopicDetectorTest`, `LanguageDetectorTest`; Kernel: `RetrieverAccessFilterTest` mit gemocktem Provider; Functional: `AssistantEndpointTest` (403 anonym, Limit-Modus, Support-Anfrage) |

### 6.4 `tannwald_demo`

`drush tannwald:demo-install` legt Rollen, Nutzer (`kunde-a`, `kunde-b`, `haendler`, `techniker`, `redaktion`, `compliance`, Passwort `demo`), Taxonomien, Exportregeln an, führt die Migrationen `tannwald_firmen`, `tannwald_maschinen`, `tannwald_dokumente` aus (JSON unter `fixtures/`), setzt Demo-Bulletins und startet die Queue. `drush tannwald:demo-reset` setzt auf den Ausgangszustand zurück. Die Dokumente (ca. 30, DE/EN, 2× FR) werden einmalig per LLM generiert und als Fixtures eingecheckt; sie enthalten Kapitelstruktur, Fehlercode-Tabellen, Sicherheitskapitel und ein eingebettetes Prompt-Injection-Beispiel (TC-16).

### 6.5 Theme `tannwald`

Starterkit-Theme; Vite baut `tailwind.css` und `app.js` (Alpine). SDC-Komponenten: `doc-row`, `bulletin-banner`, `lang-pills`, `badge`, `chat` (Alpine-Store, ruft `/api/assistant`), `facet-group`, `search-live` (Alpine, `/api/search/live`, Debounce 200 ms), `review-card`. Templates für `node--dokument`, `node--maschine`, Views `portal_maschinen`, `suche`, `redaktion_freigaben`. Breakpoints: Mobile-first; der Chat wird auf Phone zum Bottom-Sheet. Screens: Wireframes `myTannwald Wireframes` (Design-Canvas).

---

## 7. Schnittstellen (PoC)

| Schnittstelle | Richtung | Protokoll | Umsetzung |
|---|---|---|---|
| Ollama | Drupal → Host | HTTP `http://host.docker.internal:11434` | `ai_provider_ollama`, Modelle `bge-m3`, `qwen2.5:7b` |
| SAP-Mock | Datei → Drupal | JSON-Dateien `fixtures/sap/*.json` | Migrate `url`-Source mit JSON-Parser; produktiv: dieselben Migrationen mit OData-URL und `high_water_property` |
| Salesforce-Mock | intern | – | Entity `support_anfrage`; produktiv: REST-Client im selben Service hinter Interface `TicketSinkInterface` |
| Mail | Drupal → Mailpit | SMTP (DDEV) | `symfony_mailer`, Queue-Versand |
| Portal-API | Browser → Drupal | JSON, Session-Auth, CSRF | `/api/assistant`, `/api/assistant/feedback`, `/api/assistant/support`, `/api/search/live` |

---

## 8. Retrieval- und Antwortdesign (Context Engineering)

| Parameter | Wert PoC | Messung |
|---|---|---|
| Chunking | Überschriften-basiert, 350 Tokens, Überlappung 60 | Eval vorher/nachher gegen 800-Token-Fenster |
| Metadaten je Chunk | Dokumenttitel, Version, Sprache, `heading_path`, Maschinentypen, Exportklasse, Access-Keys | – |
| Keyword-Suche | Search API DB, Felder `title^3`, `heading_path^2`, `text` | – |
| Vektor-Suche | Cosinus über `bge-m3`-Embeddings, Vorfilter `access_keys`, Sprache nicht gefiltert | Eval mehrsprachig |
| Fusion | RRF mit k = 60, Top 6 in den Prompt | `RrfFusionTest` |
| Schwelle | `min_score` 0.015 (RRF), darunter Ablehnung | Eval: Ablehnungs-Korrektheit |
| Prompt | System-Prompt mit Regeln, Kontext in abgegrenzten Blöcken, Frage am Ende; Temperatur 0.1; max. 400 Tokens Antwort | – |
| Zitate | `[n]` → Chunk n; Validierung; Anzeige mit Dokument, Version, `heading_path`, Link `#kapitel-<slug>` | `CitationValidatorTest` |
| Sicherheit | Modus `safety`: nur Verweis auf Kapitel, Formulierung vorgegeben | TC-09 |
| Prompt-Injection | Kontext als Daten deklariert, Anweisungen im Kontext ignorieren, keine Tool-Aufrufe im RAG-Pfad, Ausgabe wird auf Links und HTML gefiltert | TC-16 |
| Sprache | Antwortsprache erzwungen; Quelle in anderer Sprache wird markiert | Eval de/en/fr |

**Eval-Set** (`docs/evals/fragen.yaml`, 30 Fragen): je Frage `frage`, `als` (Nutzer), `sprache`, `erwartete_quellen` (Dokument + Kapitel), `erwartet` (`antwort` / `ablehnung` / `safety`). Kennzahlen: `hit@5` (erwartete Quelle unter Top 5 Chunks), `zitat_korrekt` (alle Zitate zeigen auf erwartete Quellen), `ablehnung_korrekt`, `safety_korrekt`, Median-Dauer, Tokens. PoC-Ziel: `hit@5` ≥ 0.80, `zitat_korrekt` ≥ 0.90, `ablehnung_korrekt` ≥ 0.90, `safety_korrekt` = 1.0.

---

## 9. Sicherheit

- **Zugriff:** jede Dokumentansicht, jeder Download, jeder Suchtreffer und jeder Chunk im Prompt läuft über Node-Access-Grants; der Assistent prüft zusätzlich Entity-Access pro Treffer (zwei Schranken).
- **Downloads:** `private://`, Auslieferung nur über `hook_file_download` mit Node-Zugriff; jeder Download und jede Verweigerung im Audit-Log.
- **API:** nur eingeloggte Nutzer, CSRF-Token, Flood Control 10 Anfragen/Minute, Eingabelänge max. 1'000 Zeichen, Ausgabe-Sanitizing.
- **Secrets:** keine im Repo; Ollama braucht keinen Key; optionale API-Keys über `key`-Modul und Umgebungsvariablen.
- **Datenschutz:** Assistent-Log pseudonymisiert (`uid_hash` mit Site-Salt), Löschjob nach 12 Monaten als Drush-Command vorbereitet.
- **Static Analysis:** PHPStan Level 5 mit `phpstan-drupal`; PHPCS `Drupal` und `DrupalPractice`; beides blockierend in CI.

---

## 10. Qualitätssicherung und CI

| Stufe | Inhalt | Läuft wo |
|---|---|---|
| Lint | PHPCS (Drupal, DrupalPractice), PHPStan Level 5 | CI, Pre-Commit |
| Unit | Chunker, RRF, CitationValidator, SafetyTopicDetector, LanguageDetector, ExportRuleService | CI |
| Kernel | EntitlementService, Node-Grants, Queue-Worker, Workflow-Constraint, Retriever-Access-Filter (Provider gemockt) | CI (MariaDB-Service) |
| Functional | `DocumentLeakTest`, `AssistantEndpointTest`, `WorkflowVisibilityTest` | lokal (`ddev exec phpunit --group functional`), CI optional |
| Evals | `drush tannwald:eval` gegen Ollama | lokal; Ergebnis als Markdown im Repo |
| Config | `drush config:status` muss leer sein (Export-Disziplin) | CI |

GitHub-Actions-Workflow `ci.yml`: `composer install` → PHPCS → PHPStan → PHPUnit Unit → PHPUnit Kernel (MariaDB-Service-Container) → Config-Status. Laufzeit-Ziel unter 8 Minuten.

---

## 11. Betrieb (PoC)

**Lokal:** `ddev config --php-version=8.4 --database=mariadb:11.4 --docroot=web`; Ollama auf dem Host mit `ollama pull bge-m3 qwen2.5:7b`; `.ddev/config.yaml` setzt `OLLAMA_HOST=http://host.docker.internal:11434`; Mailpit ist in DDEV enthalten. Install: `ddev start && ddev composer install && ddev drush site:install --existing-config && ddev drush tannwald:demo-install`. Cron alle 5 Minuten über DDEV (`ddev drush cron`) für Queue und Mails.

**Konfigurationsmanagement:** `config/sync` im Repo; `config_split` für `dev` (`masquerade`, `queue_ui`, Devel) und `ci`. Regel: nach jeder Konfigurationsänderung `drush config:export`; CI prüft.

**Optional Hetzner:** `docker-compose.prod.yml` (php-fpm, nginx, mariadb, ollama CPU), Caddy für TLS, Ansible-Playbook `deploy/playbook.yml`, GitHub-Actions-Deploy über SSH; täglicher Demo-Reset per Cron.

---

## 12. Arbeitspakete, Aufwand und Abnahme

| AP | Inhalt | Std. | Abnahme |
|---|---|---|---|
| 0 | Repo, DDEV, Drupal 11, Module, CI-Skelett, `CLAUDE.md`, ADR-Vorlagen | 3 | CI grün (leer) |
| 1 | Content-Modell, Taxonomien, Rollen, Nutzerfelder, Workflow, Migrationen, Config-Export | 5 | TC-01 manuell |
| 2 | `tannwald_access`: Entitlement, Grants, Exportregeln, Audit, Tests | 5 | Kernel + Functional grün (TC-02, TC-03) |
| 3 | `tannwald_ingest`: Chunker, Embeddings, ChunkStore, Queue, SummaryGenerator | 6 | 30 Dokumente indexiert, TC-10 |
| 4 | `tannwald_assistant`: Retriever, Prompt, Antwort, Endpoints, Usage, Limit, Evals | 6 | TC-07, TC-08, TC-09, TC-12, TC-16; Eval-Tabelle |
| 5 | Theme, Portal-Seiten, Suche mit Facetten, Chat, Views | 5 | Demo im Browser gemäss Wireframes |
| 6 | Demo-Inhalte, Bug-Bash, Chunking-Tuning, zweiter Eval-Lauf | 3 | Vorher/Nachher-Tabelle |
| 7 | README (Case Study), ADRs, Screenshots, Video | 5 | Repo öffentlich |
| 8 | LinkedIn-Artikel und Post | 4 | veröffentlicht |
| – | Puffer | 6 | – |
| | **Summe** | **48** | |

Streichreihenfolge bei Zeitnot: Facetten → Kosten-View → Live-Suche → Bulletin-Mail. Nie gestrichen: Leak-Test, Zitate, Workflow, Evals.

---

## 13. Technische Risiken und Annahmen

| Risiko | Massnahme |
|---|---|
| AI-Modul-API ändert sich zwischen Minor-Versionen | Version in `composer.lock` fixieren; Wrapper-Services (`EmbeddingService`, `AnswerService`) kapseln den Aufruf |
| Ollama auf CPU zu langsam für Demo-Video | Antworten auf 400 Tokens begrenzen; Chat-Modell 7B; optional API-Provider per Config |
| Node-Access-Grants und Suche driften auseinander | Eine Quelle (`EntitlementService`) für beide; Functional Test prüft beide Pfade |
| Cosinus in PHP wird bei vielen Chunks langsam | PoC-Grenze 10'000 Chunks dokumentiert (ADR-003); Interface für OpenSearch vorbereitet |
| Chunking zerschneidet Tabellen | Tabellen als atomare Chunks; Unit-Test |
| Generierte Demo-Dokumente wirken unrealistisch | Vorlage mit Kapitelraster, Fehlercode-Tabelle, Sicherheitskapitel; redaktionelle Durchsicht |

Annahmen: Entwicklungsrechner mit 16 GB RAM; keine externen Accounts ausser GitHub; Lizenz des Repos GPL-2.0-or-later (Drupal-kompatibel).

---

## 14. Produktionsausbau (Delta PoC → Phase 1)

| Baustein | PoC | Phase 1 | Aufwand grob |
|---|---|---|---|
| Vektorsuche | DB-Tabelle, PHP-Cosinus | OpenSearch k-NN, `OpenSearchChunkStore`, Hybrid-Query nativ | 5–8 PT |
| Volltextsuche | Search API DB | `search_api_opensearch`, Synonyme, Sprachanalyse pro Sprache | 3–5 PT |
| Dateien | `private://` | Azure Blob, SAS-URLs, CDN, Event-Grid-Sync, Konflikterkennung | 8–12 PT |
| SAP | JSON-Fixtures | OData-Quelle, Delta per High-Water-Mark, Fehlerreport | 5–8 PT |
| Tickets | `support_anfrage` | Salesforce-REST hinter `TicketSinkInterface`, Status-Rückfluss | 3–5 PT |
| Identität | Drupal-Konten | `openid_connect` gegen Entra ID, `tfa` für Kunden | 4–6 PT |
| LLM | Ollama | `ai_provider_azure` Switzerland North, vLLM als Alternative; Reranker | 3–5 PT + Evals |
| Queue | Core Queue | `advancedqueue` mit UI und Retry-Strategien | 1–2 PT |
| Cache | DB | Redis, Cache-Tags für Assistent-Block, CDN-Regeln | 2–3 PT |
| Mehrsprachigkeit | DE/EN/FR | 12 Sprachen, `tmgmt`, Fallback-Matrix | 5–8 PT |
| Offline/PWA | – | `pwa`, Zip-Pakete per Queue, Ablauf 30 Tage | 6–10 PT |
| MCP | – | `mcp`-Modul, Tool-Plugin `tannwald_search` | 3–5 PT |
| Betrieb | DDEV/Hetzner | Terraform, Ansible, Azure Monitor, Sentry, Backups, Pentest-Begleitung | 10–15 PT |
| Qualität | Level 5, Unit+Kernel | Level 6+, Functional in CI, Lasttest 500 Nutzer, Barrierefreiheit | 8–12 PT |

Dimensionierung aus dem Mengengerüst: 2.5 Mio. Chunks à 1024 Dimensionen float32 ≈ 10 GB Vektoren → OpenSearch-Cluster mit 3 Knoten, 32 GB RAM; 12'000 Suchanfragen und 2'500 Assistent-Fragen pro Tag sind für diese Dimensionierung unkritisch; LLM-Budget ca. 450 Mio. Tokens/Monat, Limit und Caching gleicher Fragen (Semantic Cache, Phase 2) vorgesehen.

---

## 15. Architektur-Entscheide (Kurzfassung, Details in `docs/adr/`)

| ADR | Entscheid | Kern der Begründung |
|---|---|---|
| ADR-001 | Drupal 11 statt Laravel | Workflows, Übersetzungen, Rechte, Revisionen sind Core; Agentur-Ökosystem; Laravel-Eigenbau derselben Funktionen kostet Wochen |
| ADR-002 | Node-Access-Grants plus eigener `EntitlementService` statt `group`-Modul | Rechte sind berechnet (Firma × Maschinentyp × Vertrag × Exportklasse), nicht Mitgliedschaften; Grants wirken automatisch in Views und Search API |
| ADR-003 | Vektoren im PoC in der Datenbank, produktiv OpenSearch | Unter 10'000 Chunks reicht PHP-Cosinus; ein `ChunkStoreInterface` hält den Wechsel offen; OpenSearch vereint Volltext und Vektor und steht beim Kunden auf der Liste |
| ADR-004 | `drupal/ai` als Provider-Abstraktion, Ollama lokal | Kein Lock-in, Wechsel per Konfiguration (NFA-QA-05), Datenschutz-Variante gratis |
| ADR-005 | Zusammenfassungen als eigener Content Type im Core-Workflow | Human-in-the-loop ohne Zusatzmodul; Revisionen und Audit inklusive |
| ADR-006 | Dateien privat, Auslieferung nur über geprüften Controller | Kein direkter Dateipfad; Audit pro Download; produktiv SAS-URLs |
| ADR-007 | Migrate API für Stammdaten | Idempotent, Delta, Rollback; Fixture und SAP teilen die Konfiguration |
| ADR-008 | Twig/SDC mit Tailwind und Alpine statt SPA | Kein SEO-Bedarf hinter Login; eine Codebasis; progressive Enhancement |

---

## 16. Repository-Struktur

```
tannwald-portal/
  README.md                      Case Study
  CLAUDE.md                      Projektkonventionen für KI-gestütztes Arbeiten
  composer.json / composer.lock
  .ddev/config.yaml
  .github/workflows/ci.yml
  config/sync/                   exportierte Drupal-Konfiguration
  docs/
    lastenheft.yaml
    pflichtenheft.md             dieses Dokument
    rechtematrix.md
    adr/ADR-001 … ADR-008.md
    evals/fragen.yaml, ergebnisse-*.md
    demo.gif
  fixtures/
    sap/firmen.json, maschinen.json
    dokumente/*.html, bulletins/*.html
  web/modules/custom/
    tannwald_access/
    tannwald_ingest/
    tannwald_assistant/
    tannwald_demo/
  web/themes/custom/tannwald/
  deploy/                        optional: docker-compose.prod.yml, playbook.yml
```

---

*Ende des Pflichtenhefts. Änderungen werden über Pull Requests mit Verweis auf die betroffenen Anforderungs-IDs eingebracht.*
