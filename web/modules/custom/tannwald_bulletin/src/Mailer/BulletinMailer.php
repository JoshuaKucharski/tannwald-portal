<?php

declare(strict_types=1);

namespace Drupal\tannwald_bulletin\Mailer;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\symfony_mailer\Attribute\MailerInfo;
use Drupal\symfony_mailer\Component\ComponentMailerBase;
use Drupal\symfony_mailer\EmailInterface;
use Drupal\symfony_mailer\MailerLookupInterface;
use Drupal\symfony_mailer\MailerPlusInterface;
use Drupal\tannwald_bulletin\BulletinLabel;
use Drupal\user\UserInterface;

/**
 * Benachrichtigung zu Bulletins mit Bestätigungspflicht.
 *
 * Sprache: symfony_mailer schaltet beim Bauen auf preferred_langcode des
 * Empfängers um. Englisch ist Quelle, Deutsch kommt aus translations/de.po,
 * Französisch steht vorerst als Konstante hier (6.5 verfeinert).
 */
#[MailerInfo(
  base_tag: 'tannwald_bulletin',
  sub_defs: ['notify' => new TranslatableMarkup('Bulletin notification')],
)]
class BulletinMailer extends ComponentMailerBase implements BulletinMailerInterface {

  /**
   * Quelltexte (englisch).
   */
  public const SOURCE = [
    'subject' => 'Safety bulletin @id: @title — read confirmation required',
    'greeting' => 'Hello @name,',
    'intro' => 'Tannwald has released a safety bulletin for the following machines:',
    'read' => 'Read bulletin',
    'confirm' => 'Please confirm in the portal that you have read it ("Confirm as read").',
  ];

  /**
   * Französische Fassung bis 6.5.
   */
  public const FRENCH = [
    'subject' => 'Bulletin de sécurité @id : @title — confirmation de lecture requise',
    'greeting' => 'Bonjour @name,',
    'intro' => 'Tannwald a publié un bulletin de sécurité pour les machines suivantes :',
    'read' => 'Lire le bulletin',
    'confirm' => 'Veuillez confirmer la lecture dans le portail (« Confirmer la lecture »).',
  ];

  public function __construct(
    MailerPlusInterface $mailer,
    MailerLookupInterface $mailerLookup,
    string $baseTag,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LanguageManagerInterface $languageManager,
  ) {
    parent::__construct($mailer, $mailerLookup, $baseTag);
  }

  /**
   * {@inheritdoc}
   */
  public function notify(NodeInterface $bulletin, UserInterface $recipient, array $machines): bool {
    return $this->newEmail('notify')
      ->setTo($recipient)
      ->setParam('bulletin', $bulletin)
      ->setParam('maschinen', $machines)
      ->send();
  }

  /**
   * {@inheritdoc}
   */
  public function build(EmailInterface $email): void {
    $bulletin = $email->getParam('bulletin');
    if (!$bulletin instanceof NodeInterface) {
      return;
    }
    $langcode = $email->getLangcode();
    $label = BulletinLabel::of($bulletin);
    $name = $email->getAccount()->getDisplayName();

    $items = [];
    foreach ((array) $email->getParam('maschinen') as $machine) {
      if ($machine instanceof NodeInterface) {
        $items[] = [
          '#type' => 'link',
          '#title' => $machine->label(),
          '#url' => Url::fromUri($this->absolute($machine, $langcode)),
        ];
      }
    }
    $bulletinUrl = $this->absolute($bulletin, $langcode);

    $email->setSubject($this->text('subject', $langcode, ['@id' => $label['id'], '@title' => $label['titel']]));
    $email->setBody([
      'greeting' => ['#markup' => '<p>' . Html::escape($this->text('greeting', $langcode, ['@name' => $name])) . '</p>'],
      'intro' => ['#markup' => '<p>' . Html::escape($this->text('intro', $langcode)) . '</p>'],
      'maschinen' => ['#theme' => 'item_list', '#items' => $items],
      'read' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        'link' => [
          '#type' => 'link',
          '#title' => $this->text('read', $langcode),
          '#url' => Url::fromUri($bulletinUrl),
        ],
      ],
      'confirm' => ['#markup' => '<p>' . Html::escape($this->text('confirm', $langcode)) . '</p>'],
    ]);
  }

  /**
   * Text in der Empfängersprache.
   *
   * @param string $key
   *   Schlüssel aus self::SOURCE.
   * @param string $langcode
   *   Sprache.
   * @param array<string, string> $args
   *   Platzhalter.
   */
  public function text(string $key, string $langcode, array $args = []): string {
    if ($langcode === 'fr') {
      return strtr(self::FRENCH[$key], $args);
    }
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return (string) new TranslatableMarkup(self::SOURCE[$key], $args, ['langcode' => $langcode]);
  }

  /**
   * Absolute URL eines Nodes in der Empfängersprache.
   *
   * Basis aus tannwald_bulletin.settings:base_url (Cron läuft ohne
   * Request-Host, gemessen: "http://default/"); leer → Host des Requests.
   */
  public function absolute(NodeInterface $node, string $langcode): string {
    $options = ['language' => $this->languageManager->getLanguage($langcode) ?? $this->languageManager->getDefaultLanguage()];
    $base = rtrim((string) $this->configFactory->get('tannwald_bulletin.settings')->get('base_url'), '/');
    if ($base === '') {
      return $node->toUrl('canonical', $options + ['absolute' => TRUE])->toString();
    }
    $path = $node->toUrl('canonical', $options)->toString();
    return $base . '/' . ltrim($path, '/');
  }

}
