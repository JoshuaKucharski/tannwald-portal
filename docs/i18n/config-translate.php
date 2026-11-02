<?php

declare(strict_types=1);

/**
 * Konfigurations-Übersetzungen fr und en (Aufgabe 6.5): Feld-Labels, Content-Typen, Vokabulare, Rollen,
 * Workflow, Views, Listenwerte. Die Site-Sprache ist de; fr/en entstehen als Sprach-Overrides.
 *
 * Aufruf (im Repo tannwald-portal/):
 *   php docs/i18n/config-translate.php          schreibt config/sync/language/{fr,en}/<name>.yml (idempotent)
 *   php docs/i18n/config-translate.php --check  prüft nur: Tabelle vollständig, Quelltexte noch aktuell, Dateien aktuell (Exit 1 sonst)
 *   php docs/i18n/config-translate.php --list   Tabelle der Konfigurationsnamen (für Doku)
 *
 * Das Skript fasst keine Datenbank an. Der Import ist `ddev drush config:import -y` (lokal im Skript tannwald-run.sh);
 * danach `ddev drush config:status` leer. Dateien, die dieses Skript nicht erzeugt hat (Kopfzeile fehlt), werden nie überschrieben.
 */

const HEADER = "# Erzeugt von docs/i18n/config-translate.php (Aufgabe 6.5) — nicht von Hand ändern.\n";

/** Wörterbuch: deutscher Quelltext => [fr, en]. */
$D = [
  // Felder
  'Inhalt' => ['Contenu', 'Content'],
  'Bestätigung Pflicht' => ['Confirmation obligatoire', 'Confirmation required'],
  'Art' => ['Type', 'Type'],
  'Maschinentyp' => ['Type de machine', 'Machine type'],
  'Seriennummern' => ['Numéros de série', 'Serial numbers'],
  'Änderungsvermerk' => ['Note de modification', 'Change note'],
  'Datei' => ['Fichier', 'File'],
  'Dokumenttyp' => ['Type de document', 'Document type'],
  'Exportklasse' => ['Classe d’exportation', 'Export class'],
  'Freigegeben am' => ['Approuvé le', 'Approved on'],
  'Gültig bis' => ['Valable jusqu’au', 'Valid until'],
  'Seriennummer' => ['Numéro de série', 'Serial number'],
  'Nur für anlagenspezifische Dokumentation: dann ist das Dokument ausschliesslich für die Firma dieser Maschine sichtbar.' => ['Uniquement pour la documentation spécifique à une installation : le document n’est alors visible que pour l’entreprise de cette machine.', 'Only for system-specific documentation: the document is then visible only to the company of this machine.'],
  'Version' => ['Version', 'Version'],
  'Zum Beispiel 3.2.' => ['Par exemple 3.2.', 'For example 3.2.'],
  'Baujahr' => ['Année de construction', 'Year built'],
  'Firma' => ['Entreprise', 'Company'],
  'Genau ein Maschinentyp (die Storage ist mit dokument und bulletin geteilt und darum mehrwertig).' => ['Exactement un type de machine (le stockage est partagé avec document et bulletin et donc multivalué).', 'Exactly one machine type (the storage is shared with document and bulletin and therefore multi-valued).'],
  'Standort' => ['Site', 'Location'],
  'Vertrag' => ['Contrat', 'Contract'],
  'Vertrag bis' => ['Contrat jusqu’au', 'Contract until'],
  'Dokument' => ['Document', 'Document'],
  'Dokument-Revision' => ['Révision du document', 'Document revision'],
  'Generiert am' => ['Généré le', 'Generated on'],
  'Modell' => ['Modèle', 'Model'],
  'Slug' => ['Slug', 'Slug'],
  'Maschinenlesbarer Schlüssel, z. B. betriebsanleitung.' => ['Clé lisible par machine, p. ex. betriebsanleitung.', 'Machine-readable key, e.g. betriebsanleitung.'],
  'Händler-Gebiet' => ['Territoire du revendeur', 'Dealer territory'],
  'Länderkürzel (ISO-3166 alpha-2), nur für Händler.' => ['Code pays (ISO 3166 alpha-2), uniquement pour les revendeurs.', 'Country code (ISO 3166 alpha-2), dealers only.'],
  'Land' => ['Pays', 'Country'],
  'ISO-3166 alpha-2, z. B. CH.' => ['ISO 3166 alpha-2, p. ex. CH.', 'ISO 3166 alpha-2, e.g. CH.'],
  'SAP-Nummer' => ['Numéro SAP', 'SAP number'],
  'Typ-Code' => ['Code de type', 'Type code'],
  'Eindeutiger Code des Maschinentyps ohne Bindestrich, z. B. TB200; bildet den Realm type:&lt;code&gt;.' => ['Code unique du type de machine, sans trait d’union, p. ex. TB200 ; forme le realm type:&lt;code&gt;.', 'Unique machine type code without hyphen, e.g. TB200; forms the realm type:&lt;code&gt;.'],
  'Leer für interne Rollen.' => ['Vide pour les rôles internes.', 'Empty for internal roles.'],
  // Content-Typen und Vokabulare
  'Bulletin' => ['Bulletin', 'Bulletin'],
  'Maschine' => ['Machine', 'Machine'],
  'Zusammenfassung' => ['Résumé', 'Summary'],
  'Service- oder Sicherheits-Bulletin zu Maschinentypen oder Seriennummern.' => ['Bulletin de service ou de sécurité relatif à des types de machine ou à des numéros de série.', 'Service or safety bulletin for machine types or serial numbers.'],
  'Technisches Dokument einer Maschine mit Versionen, Sprachen und Exportklasse.' => ['Document technique d’une machine avec versions, langues et classe d’exportation.', 'Technical document of a machine with versions, languages and export class.'],
  'Maschine eines Kunden (Quelle SAP über Migrate); der Titel ist die Seriennummer.' => ['Machine d’un client (source SAP via Migrate) ; le titre est le numéro de série.', 'Customer machine (SAP source via Migrate); the title is the serial number.'],
  'KI-Zusammenfassung einer Dokument-Revision; entsteht nur durch den SummaryGenerator.' => ['Résumé IA d’une révision de document ; créé uniquement par le SummaryGenerator.', 'AI summary of a document revision; created only by the SummaryGenerator.'],
  'Dokumenttypen (Betriebsanleitung, Ersatzteilkatalog …). Terme legt der Seeder an.' => ['Types de document (manuel d’utilisation, catalogue de pièces de rechange …). Les termes sont créés par le seeder.', 'Document types (operating instructions, spare parts catalogue …). The terms are created by the seeder.'],
  'Kundenfirmen und Händler (Quelle SAP über Migrate).' => ['Entreprises clientes et revendeurs (source SAP via Migrate).', 'Customer companies and dealers (SAP source via Migrate).'],
  'Maschinentypen (TB-200, TB-400, KT-500). Terme legt der Seeder an.' => ['Types de machine (TB-200, TB-400, KT-500). Les termes sont créés par le seeder.', 'Machine types (TB-200, TB-400, KT-500). The terms are created by the seeder.'],
  // Rollen
  'Compliance' => ['Conformité', 'Compliance'],
  'Händler' => ['Revendeur', 'Dealer'],
  'Kunde' => ['Client', 'Customer'],
  'Portal-Admin' => ['Administrateur du portail', 'Portal admin'],
  'Redaktion' => ['Rédaction', 'Editorial'],
  'Techniker' => ['Technicien', 'Technician'],
  // Workflow
  'Entwurf' => ['Brouillon', 'Draft'],
  'Freigegeben' => ['Approuvé', 'Approved'],
  'Zur Prüfung' => ['À vérifier', 'Under review'],
  'Zurückgewiesen' => ['Rejeté', 'Rejected'],
  'Entwurf speichern' => ['Enregistrer le brouillon', 'Save draft'],
  'Freigeben' => ['Approuver', 'Approve'],
  'Zur Prüfung senden' => ['Envoyer pour vérification', 'Submit for review'],
  'Zurückweisen' => ['Rejeter', 'Reject'],
  // Listenwerte
  'Service' => ['Service', 'Service'],
  'Sicherheit' => ['Sécurité', 'Safety'],
  'Keine' => ['Aucune', 'None'],
  'Klasse 1' => ['Classe 1', 'Class 1'],
  'Klasse 2' => ['Classe 2', 'Class 2'],
  'Basis' => ['Basique', 'Basic'],
  'Service Plus' => ['Service Plus', 'Service Plus'],
  // Views
  'Nutzung und Kosten: Kosten je Monat' => ['Utilisation et coûts : coûts par mois', 'Usage and costs: costs per month'],
  'Nutzung und Kosten: Nutzung je Tag' => ['Utilisation et coûts : utilisation par jour', 'Usage and costs: usage per day'],
  'Monat' => ['Mois', 'Month'],
  'Tokens' => ['Tokens', 'Tokens'],
  'Kosten (CHF)' => ['Coûts (CHF)', 'Costs (CHF)'],
  'Anteil am Monatslimit' => ['Part de la limite mensuelle', 'Share of monthly limit'],
  'Noch keine Kosten.' => ['Pas encore de coûts.', 'No costs yet.'],
  'Tag' => ['Jour', 'Day'],
  'Kontext' => ['Contexte', 'Context'],
  'Operation' => ['Opération', 'Operation'],
  'Aufrufe' => ['Appels', 'Calls'],
  'Prompt-Tokens' => ['Tokens (prompt)', 'Prompt tokens'],
  'Antwort-Tokens' => ['Tokens (réponse)', 'Completion tokens'],
  'Keine Nutzung in den letzten 30 Tagen.' => ['Aucune utilisation au cours des 30 derniers jours.', 'No usage in the last 30 days.'],
  'Bulletins' => ['Bulletins', 'Bulletins'],
  'Datum' => ['Date', 'Date'],
  'Keine Bulletins vorhanden.' => ['Aucun bulletin disponible.', 'No bulletins available.'],
  'Meine Maschinen' => ['Mes machines', 'My machines'],
  'Anwenden' => ['Appliquer', 'Apply'],
  'Sortieren nach' => ['Trier par', 'Sort by'],
  'Lesebestätigungen' => ['Accusés de lecture', 'Read confirmations'],
  'Nutzer' => ['Utilisateur', 'User'],
  'Bestätigt am' => ['Confirmé le', 'Confirmed on'],
  'Noch keine Bestätigungen.' => ['Aucune confirmation pour l’instant.', 'No confirmations yet.'],
  'Alle' => ['Tous', 'All'],
  'Freigabe-Queue · KI-Zusammenfassungen' => ['File d’approbation · résumés IA', 'Approval queue · AI summaries'],
  'Titel' => ['Titre', 'Title'],
  'Status' => ['Statut', 'Status'],
  'Keine Zusammenfassungen in diesem Status.' => ['Aucun résumé dans ce statut.', 'No summaries in this status.'],
  'Support-Anfragen' => ['Demandes d’assistance', 'Support requests'],
  'Zeit' => ['Heure', 'Time'],
  'Frage' => ['Question', 'Question'],
  'Keine Support-Anfragen.' => ['Aucune demande d’assistance.', 'No support requests.'],
  'Unbeantwortete Fragen' => ['Questions sans réponse', 'Unanswered questions'],
  'Anzahl' => ['Nombre', 'Count'],
  'Zuletzt' => ['Dernière fois', 'Last asked'],
  'Keine unbeantworteten Fragen in diesem Monat.' => ['Aucune question sans réponse ce mois-ci.', 'No unanswered questions this month.'],
];

/** Spezifikation: Konfigurationsname => [Pfad (Punkte) => deutscher Quelltext | ['allowed' => [[wert, de], …]]]. */
$f = fn(string $label, ?string $desc = NULL) => array_filter(['label' => $label, 'description' => $desc]);
$view = fn(string $title, array $fields, ?string $empty = NULL, array $extra = []) => array_merge(
  ['display.default.display_options.title' => $title],
  array_combine(array_map(fn($k) => "display.default.display_options.fields.$k.label", array_keys($fields)), array_values($fields)),
  $empty === NULL ? [] : ['display.default.display_options.empty.area_text_custom.content' => $empty],
  $extra
);
$S = [
  'field.field.node.bulletin.body' => $f('Inhalt'),
  'field.field.node.bulletin.field_bestaetigung_pflicht' => $f('Bestätigung Pflicht'),
  'field.field.node.bulletin.field_bulletin_art' => $f('Art'),
  'field.field.node.bulletin.field_maschinentyp' => $f('Maschinentyp'),
  'field.field.node.bulletin.field_seriennummern' => $f('Seriennummern'),
  'field.field.node.dokument.body' => $f('Inhalt'),
  'field.field.node.dokument.field_aenderungsvermerk' => $f('Änderungsvermerk'),
  'field.field.node.dokument.field_datei' => $f('Datei'),
  'field.field.node.dokument.field_dokumenttyp' => $f('Dokumenttyp'),
  'field.field.node.dokument.field_exportklasse' => $f('Exportklasse'),
  'field.field.node.dokument.field_freigegeben_am' => $f('Freigegeben am'),
  'field.field.node.dokument.field_gueltig_bis' => $f('Gültig bis'),
  'field.field.node.dokument.field_maschinentyp' => $f('Maschinentyp'),
  'field.field.node.dokument.field_seriennummer' => $f('Seriennummer', 'Nur für anlagenspezifische Dokumentation: dann ist das Dokument ausschliesslich für die Firma dieser Maschine sichtbar.'),
  'field.field.node.dokument.field_version' => $f('Version', 'Zum Beispiel 3.2.'),
  'field.field.node.maschine.field_baujahr' => $f('Baujahr'),
  'field.field.node.maschine.field_firma' => $f('Firma'),
  'field.field.node.maschine.field_maschinentyp' => $f('Maschinentyp', 'Genau ein Maschinentyp (die Storage ist mit dokument und bulletin geteilt und darum mehrwertig).'),
  'field.field.node.maschine.field_standort' => $f('Standort'),
  'field.field.node.maschine.field_vertrag' => $f('Vertrag'),
  'field.field.node.maschine.field_vertrag_bis' => $f('Vertrag bis'),
  'field.field.node.zusammenfassung.body' => $f('Inhalt'),
  'field.field.node.zusammenfassung.field_dokument' => $f('Dokument'),
  'field.field.node.zusammenfassung.field_dokument_revision' => $f('Dokument-Revision'),
  'field.field.node.zusammenfassung.field_generiert_am' => $f('Generiert am'),
  'field.field.node.zusammenfassung.field_modell' => $f('Modell'),
  'field.field.taxonomy_term.dokumenttyp.field_slug' => $f('Slug', 'Maschinenlesbarer Schlüssel, z. B. betriebsanleitung.'),
  'field.field.taxonomy_term.firma.field_firma_art' => $f('Art'),
  'field.field.taxonomy_term.firma.field_haendler_gebiet' => $f('Händler-Gebiet', 'Länderkürzel (ISO-3166 alpha-2), nur für Händler.'),
  'field.field.taxonomy_term.firma.field_land' => $f('Land', 'ISO-3166 alpha-2, z. B. CH.'),
  'field.field.taxonomy_term.firma.field_sap_nr' => $f('SAP-Nummer'),
  'field.field.taxonomy_term.maschinentyp.field_typ_code' => $f('Typ-Code', 'Eindeutiger Code des Maschinentyps ohne Bindestrich, z. B. TB200; bildet den Realm type:&lt;code&gt;.'),
  'field.field.user.user.field_firma' => $f('Firma', 'Leer für interne Rollen.'),
  'field.field.user.user.field_land' => $f('Land', 'ISO-3166 alpha-2, z. B. CH.'),
  'field.storage.node.field_bulletin_art' => ['allowed' => [['service', 'Service'], ['sicherheit', 'Sicherheit']]],
  'field.storage.node.field_exportklasse' => ['allowed' => [['keine', 'Keine'], ['klasse_1', 'Klasse 1'], ['klasse_2', 'Klasse 2']]],
  'field.storage.node.field_vertrag' => ['allowed' => [['basis', 'Basis'], ['service_plus', 'Service Plus']]],
  'field.storage.taxonomy_term.field_firma_art' => ['allowed' => [['kunde', 'Kunde'], ['haendler', 'Händler']]],
  'node.type.bulletin' => ['name' => 'Bulletin', 'description' => 'Service- oder Sicherheits-Bulletin zu Maschinentypen oder Seriennummern.'],
  'node.type.dokument' => ['name' => 'Dokument', 'description' => 'Technisches Dokument einer Maschine mit Versionen, Sprachen und Exportklasse.'],
  'node.type.maschine' => ['name' => 'Maschine', 'description' => 'Maschine eines Kunden (Quelle SAP über Migrate); der Titel ist die Seriennummer.'],
  'node.type.zusammenfassung' => ['name' => 'Zusammenfassung', 'description' => 'KI-Zusammenfassung einer Dokument-Revision; entsteht nur durch den SummaryGenerator.'],
  'taxonomy.vocabulary.dokumenttyp' => ['name' => 'Dokumenttyp', 'description' => 'Dokumenttypen (Betriebsanleitung, Ersatzteilkatalog …). Terme legt der Seeder an.'],
  'taxonomy.vocabulary.firma' => ['name' => 'Firma', 'description' => 'Kundenfirmen und Händler (Quelle SAP über Migrate).'],
  'taxonomy.vocabulary.maschinentyp' => ['name' => 'Maschinentyp', 'description' => 'Maschinentypen (TB-200, TB-400, KT-500). Terme legt der Seeder an.'],
  'user.role.compliance' => $f('Compliance'),
  'user.role.haendler' => $f('Händler'),
  'user.role.kunde' => $f('Kunde'),
  'user.role.portal_admin' => $f('Portal-Admin'),
  'user.role.redaktion' => $f('Redaktion'),
  'user.role.techniker' => $f('Techniker'),
  'workflows.workflow.redaktion' => [
    'label' => 'Redaktion',
    'type_settings.states.draft.label' => 'Entwurf',
    'type_settings.states.published.label' => 'Freigegeben',
    'type_settings.states.zur_pruefung.label' => 'Zur Prüfung',
    'type_settings.states.zurueckgewiesen.label' => 'Zurückgewiesen',
    'type_settings.transitions.entwurf_speichern.label' => 'Entwurf speichern',
    'type_settings.transitions.freigeben.label' => 'Freigeben',
    'type_settings.transitions.zur_pruefung_senden.label' => 'Zur Prüfung senden',
    'type_settings.transitions.zurueckweisen.label' => 'Zurückweisen',
  ],
  'views.view.admin_kosten' => $view('Nutzung und Kosten: Kosten je Monat', [
    'period_month' => 'Monat', 'tokens_total' => 'Tokens', 'kosten_chf' => 'Kosten (CHF)', 'limit_share' => 'Anteil am Monatslimit',
  ], 'Noch keine Kosten.'),
  'views.view.admin_nutzung' => $view('Nutzung und Kosten: Nutzung je Tag', [
    'period_day' => 'Tag', 'kontext' => 'Kontext', 'operation' => 'Operation', 'id' => 'Aufrufe',
    'prompt_tokens' => 'Prompt-Tokens', 'completion_tokens' => 'Antwort-Tokens',
  ], 'Keine Nutzung in den letzten 30 Tagen.'),
  'views.view.portal_bulletins' => $view('Bulletins', [
    'title' => 'Bulletin', 'field_bulletin_art' => 'Art', 'created' => 'Datum',
  ], 'Keine Bulletins vorhanden.'),
  'views.view.portal_maschinen' => $view('Meine Maschinen', [
    'title' => 'Seriennummer', 'field_maschinentyp' => 'Maschinentyp', 'field_standort' => 'Standort',
    'field_baujahr' => 'Baujahr', 'field_vertrag' => 'Vertrag', 'field_vertrag_bis' => 'Vertrag bis',
  ], NULL, [
    'display.default.display_options.exposed_form.options.submit_button' => 'Anwenden',
    'display.default.display_options.exposed_form.options.exposed_sorts_label' => 'Sortieren nach',
    'display.page_1.display_options.menu.title' => 'Meine Maschinen',
  ]),
  'views.view.redaktion_bestaetigungen' => $view('Lesebestätigungen', [
    'name' => 'Nutzer', 'field_firma' => 'Firma', 'title' => 'Maschine', 'bestaetigt_am' => 'Bestätigt am',
  ], 'Noch keine Bestätigungen.', [
    'display.default.display_options.exposed_form.options.submit_button' => 'Anwenden',
    'display.default.display_options.exposed_form.options.exposed_sorts_label' => 'Sortieren nach',
    'display.default.display_options.arguments.bulletin_nid.exception.title' => 'Alle',
  ]),
  'views.view.redaktion_freigaben' => $view('Freigabe-Queue · KI-Zusammenfassungen', [
    'title' => 'Titel', 'field_dokument' => 'Dokument', 'field_version' => 'Version', 'field_generiert_am' => 'Generiert am',
    'field_modell' => 'Modell', 'moderation_state' => 'Status',
  ], 'Keine Zusammenfassungen in diesem Status.'),
  'views.view.redaktion_support_anfragen' => $view('Support-Anfragen', [
    'created' => 'Zeit', 'uid' => 'Nutzer', 'maschine_nid' => 'Maschine', 'frage' => 'Frage', 'status' => 'Status',
  ], 'Keine Support-Anfragen.'),
  'views.view.redaktion_unbeantwortet' => $view('Unbeantwortete Fragen', [
    'frage_normalisiert' => 'Frage', 'id' => 'Anzahl', 'name' => 'Maschinentyp', 'last_created' => 'Zuletzt',
  ], 'Keine unbeantworteten Fragen in diesem Monat.'),
];

$root = dirname(__DIR__, 2);
$mode = $argv[1] ?? '';
$yaml = function (array $tree, int $ind = 0) use (&$yaml): string {
  $out = '';
  $pad = str_repeat('  ', $ind);
  if (array_is_list($tree)) {
    foreach ($tree as $item) {
      $first = TRUE;
      foreach ($item as $k => $v) {
        $out .= $pad . ($first ? '- ' : '  ') . "$k: " . "'" . str_replace("'", "''", $v) . "'\n";
        $first = FALSE;
      }
    }
    return $out;
  }
  foreach ($tree as $k => $v) {
    $out .= is_array($v)
      ? "$pad$k:\n" . $yaml($v, $ind + 1)
      : "$pad$k: '" . str_replace("'", "''", (string) $v) . "'\n";
  }
  return $out;
};

$errors = [];
$files = [];
foreach ($S as $name => $spec) {
  $src = @file_get_contents("$root/config/sync/$name.yml");
  if ($src === FALSE) {
    $errors[] = "Quelle fehlt: config/sync/$name.yml";
    continue;
  }
  foreach (['fr' => 0, 'en' => 1] as $lang => $i) {
    $tree = [];
    $set = function (string $path, $value) use (&$tree) {
      $ref = &$tree;
      foreach (explode('.', $path) as $part) {
        $ref = &$ref[$part];
      }
      $ref = $value;
    };
    foreach ($spec as $path => $de) {
      if ($path === 'allowed') {
        $list = [];
        foreach ($de as [$value, $label]) {
          if (!isset($D[$label])) {
            $errors[] = "$name: Wörterbuch ohne '$label'";
            continue;
          }
          $list[] = ['value' => $value, 'label' => $D[$label][$i]];
        }
        $set('settings.allowed_values', $list);
        continue;
      }
      if (!isset($D[$de])) {
        $errors[] = "$name.$path: Wörterbuch ohne '$de'";
        continue;
      }
      if ($lang === 'fr' && !str_contains($de, "'") && !str_contains($de, '&') && !str_contains($src, $de)) {
        $errors[] = "$name.$path: Quelltext '$de' steht nicht (mehr) in config/sync/$name.yml";
      }
      $set($path, $D[$de][$i]);
    }
    $files["config/sync/language/$lang/$name.yml"] = HEADER . $yaml($tree);
  }
}

if ($mode === '--list') {
  foreach (array_keys($S) as $name) {
    echo "$name\n";
  }
  exit(0);
}
$changed = 0;
foreach ($files as $rel => $content) {
  $path = "$root/$rel";
  $cur = is_file($path) ? file_get_contents($path) : NULL;
  if ($cur !== NULL && !str_starts_with($cur, HEADER)) {
    $errors[] = "$rel existiert und stammt nicht von diesem Skript — nicht überschrieben";
    continue;
  }
  if ($cur === $content) {
    continue;
  }
  $changed++;
  if ($mode === '--check') {
    $errors[] = "$rel ist nicht aktuell";
    continue;
  }
  is_dir(dirname($path)) || mkdir(dirname($path), 0775, TRUE);
  file_put_contents($path, $content);
}
foreach ($errors as $e) {
  fwrite(STDERR, "FEHLER: $e\n");
}
printf("%d Konfigurationen × 2 Sprachen = %d Dateien, %d %s.\n", count($S), count($files), $changed, $mode === '--check' ? 'nicht aktuell' : 'geschrieben');
exit($errors ? 1 : 0);
