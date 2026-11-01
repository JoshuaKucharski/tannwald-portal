<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_assistant\Functional;

use Drupal\node\NodeAccessRebuild;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\tannwald_access\Traits\AccessFixturesTrait;
use Drupal\tannwald_assistant_test\FakeEmbeddingService;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\UserInterface;

/**
 * Gemeinsamer Aufbau: C's Testwelt, echte Grants und Chunk-Store, Doubles.
 *
 * Embedding und Chat kommen aus tannwald_assistant_test (feste Vektoren,
 * Antwort aus State) — nie Ollama. Chunks schreibt der echte
 * tannwald_ingest.chunk_store mit Keys aus EntitlementService.
 */
abstract class AssistantFunctionalTestBase extends BrowserTestBase {

  use AccessFixturesTrait;

  /**
   * Achse des Frage-Embeddings „Folie reisst beim Siegeln“ (= Kapitel 9.4).
   */
  protected const AXIS_FOLIE = 0;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'taxonomy',
    'file',
    'options',
    'workflows',
    'content_moderation',
    'tannwald_assistant_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Testwelt (AccessFixturesTrait::createAccessWorld()).
   *
   * @var array<string, \Drupal\Core\Entity\EntityInterface>
   */
  protected array $w;

  /**
   * Konten kunde-a (CH, TB-200), kunde-b (BR, KT-500), haendler (BR).
   *
   * @var array<string, \Drupal\user\UserInterface>
   */
  protected array $users;

  /**
   * Term-ID Dokumenttyp „Betriebsanleitung“.
   */
  protected int $docType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createContentModel();
    $this->w = $this->createAccessWorld();
    if (Vocabulary::load('dokumenttyp') === NULL) {
      Vocabulary::create(['vid' => 'dokumenttyp', 'name' => 'Dokumenttyp'])->save();
    }
    $term = Term::create(['vid' => 'dokumenttyp', 'name' => 'Betriebsanleitung']);
    $term->save();
    $this->docType = (int) $term->id();
    $this->users = [
      'kunde-a' => $this->createUser([], 'kunde-a', FALSE, [
        'roles' => ['kunde'],
        'field_firma' => $this->w['firma_a']->id(),
        'field_land' => 'CH',
      ]),
      'kunde-b' => $this->createUser([], 'kunde-b', FALSE, [
        'roles' => ['kunde'],
        'field_firma' => $this->w['firma_b']->id(),
        'field_land' => 'BR',
      ]),
      'haendler' => $this->createUser([], 'haendler', FALSE, [
        'roles' => ['haendler'],
        'field_firma' => $this->w['firma_h']->id(),
        'field_land' => 'BR',
      ]),
    ];
    $this->container->get(NodeAccessRebuild::class)->rebuild();
    $this->container->get('state')->set('tannwald_assistant_test.vectors', ['Folie reisst' => self::AXIS_FOLIE]);
  }

  /**
   * Schreibt die Chunks eines Dokuments (ersetzt alle der Revision).
   *
   * @param \Drupal\node\NodeInterface $node
   *   Dokument.
   * @param array<int, array{0: string, 1: string, 2: int, 3?: int[]}> $chunks
   *   Je Chunk: heading_path, Text, Vektor-Achse, optional maschinentyp_tids.
   * @param string[] $extraKeys
   *   Zusätzliche Keys (simulierte Drift zwischen Chunk-Keys und Grants).
   * @param int|null $vid
   *   Revision (Standard: aktuelle).
   */
  protected function index(NodeInterface $node, array $chunks, array $extraKeys = [], ?int $vid = NULL): void {
    $keys = array_values(array_unique(array_merge($this->container->get('tannwald_access.entitlement')->getRealmsForNode($node), $extraKeys)));
    $typeTids = array_map('intval', array_column($node->get('field_maschinentyp')->getValue(), 'target_id'));
    $rows = [];
    foreach ($chunks as $i => $chunk) {
      $rows[] = [
        'chunk_index' => $i,
        'heading_path' => $chunk[0],
        'title' => $node->label(),
        'text' => $chunk[1],
        'token_count' => (int) ceil(mb_strlen($chunk[1]) / 4),
        'embedding' => FakeEmbeddingService::axis($chunk[2]),
        'access_keys' => $keys,
        'exportklasse' => $node->hasField('field_exportklasse') ? (string) $node->get('field_exportklasse')->value : 'keine',
        'dokumenttyp_tid' => $this->docType,
        'maschinentyp_tids' => $chunk[3] ?? $typeTids,
      ];
    }
    $this->container->get('tannwald_ingest.chunk_store')->replaceForRevision((int) $node->id(), $vid ?? (int) $node->getRevisionId(), $node->language()->getId(), $rows);
  }

  /**
   * Kapitel 7.3, 9.1 und 9.4 der Betriebsanleitung TB-200.
   */
  protected function indexManual(): void {
    $this->assertInstanceOf(NodeInterface::class, $this->w['d_ba']);
    $this->index($this->w['d_ba'], [
      [
        '7 Reinigung › 7.3 Reinigung Siegelstation',
        "7.3 Reinigung Siegelstation\nDie Siegelstation wird täglich trocken und wöchentlich nass gereinigt.",
        2,
      ],
      [
        '9 Störungen › 9.1 Störungstabelle',
        "9.1 Störungstabelle\nCode | Störung | Abhilfe\nE-4711 | Siegeltemperatur zu niedrig | Heizpatrone prüfen",
        3,
      ],
      [
        '9 Störungen › 9.4 Störungen an der Siegelstation',
        "9.4 Störungen an der Siegelstation\nSiegelnaht undicht, Deckfolie bricht ein: Bremswert der Abwickelstation prüfen.",
        self::AXIS_FOLIE,
      ],
    ]);
  }

  /**
   * JSON-Aufruf mit Session-Cookie und optional X-CSRF-Token.
   *
   * @return array{0: int, 1: array}
   *   HTTP-Status und dekodierter Body.
   */
  protected function api(string $method, string $path, ?array $body = NULL, bool $csrf = TRUE, array $query = []): array {
    $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json'];
    if ($csrf) {
      $headers['X-CSRF-Token'] = $this->drupalGet('session/token');
    }
    $response = $this->getHttpClient()->request($method, $this->buildUrl($path, ['query' => $query]), [
      'cookies' => $this->getSessionCookies(),
      'headers' => $headers,
      'body' => $body === NULL ? NULL : json_encode($body),
      'http_errors' => FALSE,
    ]);
    $decoded = json_decode((string) $response->getBody(), TRUE);
    return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
  }

  /**
   * Meldet ein Konto der Testwelt an.
   */
  protected function loginAs(string $name): UserInterface {
    $this->drupalLogin($this->users[$name]);
    return $this->users[$name];
  }

}
