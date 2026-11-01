<?php

declare(strict_types=1);

namespace Drupal\Tests\tannwald_bulletin\Kernel;

use Drupal\node\NodeInterface;
use Drupal\tannwald_bulletin\RecipientResolver;
use PHPUnit\Framework\Attributes\Group;

/**
 * Wer Mail bekommt, wann ein Job entsteht, was der Worker versendet.
 */
#[Group('tannwald_bulletin')]
final class BulletinRecipientsTest extends BulletinKernelTestBase {

  /**
   * Dienst.
   */
  private function resolver(): RecipientResolver {
    return $this->container->get('tannwald_bulletin.recipients');
  }

  /**
   * Empfänger als [Name => [Seriennummern]].
   *
   * @return array<string, string[]>
   *   Ergebnis in lesbarer Form.
   */
  private function recipientsOf(NodeInterface $bulletin): array {
    $result = [];
    foreach ($this->resolver()->forBulletin($bulletin) as $recipient) {
      $result[$recipient['account']->getAccountName()] = array_map(
        static fn(NodeInterface $machine): string => (string) $machine->label(),
        $recipient['maschinen'],
      );
    }
    return $result;
  }

  /**
   * Bulletin anlegen (Standard: Sicherheit mit Bestätigungspflicht).
   *
   * @param string $title
   *   Titel "<Kennung> <Titel>".
   * @param array<string, mixed> $values
   *   Feldwerte.
   * @param bool $published
   *   Veröffentlicht.
   */
  private function bulletin(string $title, array $values, bool $published = TRUE): NodeInterface {
    $defaults = ['field_bulletin_art' => 'sicherheit', 'field_bestaetigung_pflicht' => TRUE];
    return $this->createAccessNode('bulletin', $title, $values + $defaults, $published);
  }

  /**
   * Zahl der Jobs in tannwald_notify.
   */
  private function jobs(): int {
    return $this->container->get('queue')->get('tannwald_notify')->numberOfItems();
  }

  /**
   * Typ, Seriennummer, ohne Bestand, Händler im Gebiet, intern.
   */
  public function testRecipients(): void {
    $w = $this->w;
    // Typ TB-200: A (M1), C (M3); Händler über C; B und interne nicht.
    $this->assertSame([
      'kunde-a' => ['TB200-2021-0417'],
      'kunde-c' => ['TB200-2023-0099'],
      'haendler' => ['TB200-2023-0099'],
    ], $this->recipientsOf($this->bulletin('SB-2026-91 Typ', ['field_maschinentyp' => [$w['tb200']]])));

    // Nur Seriennummer M2: die besitzende Firma B; Händler nie über serial:.
    $this->assertSame([
      'kunde-b' => ['KT500-2022-0001'],
    ], $this->recipientsOf($this->bulletin('SB-2026-92 Serie', ['field_seriennummern' => [$w['m2']]])));

    // Typ KT-500 plus Seriennummer M1.
    $this->assertSame([
      'kunde-a' => ['TB200-2021-0417'],
      'kunde-b' => ['KT500-2022-0001'],
      'haendler' => ['KT500-2022-0001'],
    ], $this->recipientsOf($this->bulletin('SB-2026-93 Gemischt', [
      'field_maschinentyp' => [$w['kt500']],
      'field_seriennummern' => [$w['m1']],
    ])));

    // Typ ohne Bestand (TB-400): niemand.
    $this->assertSame([], $this->recipientsOf($this->bulletin('SB-2026-94 Ohne', ['field_maschinentyp' => [$w['tb400']]])));

    // Gesperrtes Konto bekommt nichts.
    $this->user('kunde-c')->block()->save();
    $this->assertArrayNotHasKey('kunde-c', $this->recipientsOf($this->bulletin('SB-2026-95 Gesperrt', ['field_maschinentyp' => [$w['tb200']]])));
  }

  /**
   * Job nur beim Übergang nach published mit Pflicht; idempotent.
   */
  public function testJobs(): void {
    $this->assertSame(1, $this->jobs(), 'Welt: SB-2026-07 (Sicherheit, ohne Pflichtfeld) erzeugt einen Job.');
    $this->container->get('queue')->get('tannwald_notify')->deleteQueue();
    $this->assertSame(0, $this->jobs());
    $this->bulletin('SRV-2026-96 Service', [
      'field_maschinentyp' => [$this->w['tb200']],
      'field_bulletin_art' => 'service',
      'field_bestaetigung_pflicht' => FALSE,
    ]);
    $this->assertSame(0, $this->jobs(), 'Service-Bulletin ohne Pflicht.');
    $safety = $this->bulletin('SB-2026-99 Sicherheit ohne Pflicht', [
      'field_maschinentyp' => [$this->w['tb200']],
      'field_bestaetigung_pflicht' => FALSE,
    ]);
    $this->assertSame(1, $this->jobs(), 'Sicherheits-Bulletin ohne Pflicht (TC-05).');

    $published = $this->bulletin('SB-2026-97 Sofort', ['field_maschinentyp' => [$this->w['tb200']]]);
    $this->assertSame(2, $this->jobs(), 'Insert mit published.');
    $published->setTitle('SB-2026-97 Sofort, korrigiert')->save();
    $this->assertSame(2, $this->jobs(), 'Zweites Speichern ohne Übergang.');

    $draft = $this->bulletin('SB-2026-98 Entwurf', ['field_maschinentyp' => [$this->w['tb200']]], FALSE);
    $this->assertSame(2, $this->jobs(), 'Entwurf.');
    $draft->setPublished()->save();
    $this->assertSame(3, $this->jobs(), 'Update nach published.');
    $item = $this->container->get('queue')->get('tannwald_notify')->claimItem();
    $this->assertIsObject($item);
    $this->assertSame(['bulletin_nid' => (int) $safety->id()], $item->data);
  }

  /**
   * Worker: je Empfänger eine Mail mit Seriennummer und absoluten Links.
   */
  public function testWorkerSendsMails(): void {
    $this->config('tannwald_bulletin.settings')->set('base_url', 'https://portal.example')->save();
    $bulletin = $this->bulletin('SB-2026-07 Schutzhaube Siegelstation', ['field_maschinentyp' => [$this->w['tb200']]]);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('tannwald_notify');
    $worker->processItem(['bulletin_nid' => (int) $bulletin->id()]);

    /** @var \Symfony\Component\Mime\Email[] $mails */
    $mails = $this->container->get('keyvalue')->get('symfony_mailer_test')->get('emails', []);
    $this->assertCount(3, $mails);
    $byAddress = [];
    foreach ($mails as $mail) {
      $byAddress[$mail->getTo()[0]->getAddress()] = $mail;
    }
    $this->assertSame(['kunde-a@example.com', 'kunde-c@example.com', 'haendler@example.com'], array_keys($byAddress));
    $mail = $byAddress['kunde-a@example.com'];
    $this->assertSame('Safety bulletin SB-2026-07: Schutzhaube Siegelstation — read confirmation required', $mail->getSubject());
    $html = (string) $mail->getHtmlBody();
    $this->assertStringContainsString('TB200-2021-0417', $html);
    $this->assertStringContainsString('https://portal.example/node/' . $bulletin->id(), $html);
    $this->assertStringContainsString('https://portal.example/node/' . $this->w['m1']->id(), $html);
    $this->assertStringNotContainsString('TB200-2023-0099', $html, 'Nur eigene Maschinen.');
  }

}
