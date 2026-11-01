<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Limit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\symfony_mailer\Attribute\MailerInfo;
use Drupal\symfony_mailer\Component\ComponentMailerBase;
use Drupal\symfony_mailer\EmailInterface;
use Drupal\user\UserInterface;

/**
 * Warnmail „Assistent: 80 % des Monatslimits erreicht“ über symfony_mailer.
 *
 * Englisch ist Quelle, Deutsch aus translations/de.po; symfony_mailer schaltet
 * beim Bauen auf die Sprache des Empfängers.
 */
#[MailerInfo(
  base_tag: 'tannwald_assistant',
  sub_defs: ['limit_warning' => new TranslatableMarkup('Assistant limit warning')],
)]
class LimitMailer extends ComponentMailerBase implements LimitMailerInterface {

  /**
   * Quelltexte (englisch).
   */
  public const SOURCE = [
    'subject' => 'Assistant: 80 % of the monthly limit reached',
    'greeting' => 'Hello @name,',
    'usage' => 'The assistant has used @usage of @limit tokens this month (@share %).',
    'effect' => 'At 100 % the assistant switches to search-only mode until the end of the month; search remains available.',
  ];

  /**
   * {@inheritdoc}
   */
  public function warn(UserInterface $recipient, int $usage, int $limit): bool {
    return $this->newEmail('limit_warning')
      ->setTo($recipient)
      ->setParam('usage', $usage)
      ->setParam('limit', $limit)
      ->send();
  }

  /**
   * {@inheritdoc}
   */
  public function build(EmailInterface $email): void {
    $langcode = $email->getLangcode();
    $usage = (int) $email->getParam('usage');
    $limit = max(1, (int) $email->getParam('limit'));
    $args = [
      '@usage' => number_format($usage, 0, '.', "'"),
      '@limit' => number_format($limit, 0, '.', "'"),
      '@share' => number_format($usage / $limit * 100, 1, '.', ''),
      '@name' => $email->getAccount()->getDisplayName(),
    ];
    $email->setSubject($this->text('subject', $langcode));
    $email->setBody([
      // TranslatableMarkup escapt die @-Platzhalter; die Quelltexte sind fest.
      'greeting' => ['#markup' => '<p>' . $this->text('greeting', $langcode, $args) . '</p>'],
      'usage' => ['#markup' => '<p>' . $this->text('usage', $langcode, $args) . '</p>'],
      'effect' => ['#markup' => '<p>' . $this->text('effect', $langcode) . '</p>'],
    ]);
  }

  /**
   * Text in der Empfängersprache.
   */
  public function text(string $key, string $langcode, array $args = []): string {
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return (string) new TranslatableMarkup(self::SOURCE[$key], $args, ['langcode' => $langcode]);
  }

}
