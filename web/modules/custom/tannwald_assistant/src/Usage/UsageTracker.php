<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant\Usage;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\tannwald_assistant\AnswerService;
use Drupal\tannwald_assistant\Limit\LimitService;
use Drupal\tannwald_assistant\Provider\ProviderResolver;
use Drupal\tannwald_ingest\Usage\UsageRecorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Schreibt Chat-Usage des Assistenten aus dem AI-Modul-Event (FA-REP-02).
 *
 * Ein Weg, keine Doppelzählung: nur Chat-Aufrufe mit dem Tag
 * AnswerService::CHAT_TAG werden gezählt (AnswerService schreibt seit 4.2
 * selbst nichts mehr). D's Zusammenfassungen (Tag tannwald_summary) und
 * Embeddings schreiben ihre Zeilen weiterhin selbst. Kontext aus dem
 * Request-Attribut KONTEXT_ATTRIBUTE (assistent|ingest|eval), sonst
 * assistent; 4.3 setzt eval.
 */
final class UsageTracker implements EventSubscriberInterface {

  public const KONTEXT_ATTRIBUTE = '_tannwald_usage_kontext';

  public const DEFAULT_KONTEXT = 'assistent';

  public function __construct(
    private readonly UsageRecorder $usageRecorder,
    private readonly ProviderResolver $providerResolver,
    private readonly RequestStack $requestStack,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PostGenerateResponseEvent::EVENT_NAME => 'onPostGenerate'];
  }

  /**
   * Zählt einen Chat-Aufruf des Assistenten.
   */
  public function onPostGenerate(PostGenerateResponseEvent $event): void {
    if ($event->getOperationType() !== 'chat' || !in_array(AnswerService::CHAT_TAG, $event->getTags(), TRUE)) {
      return;
    }
    $output = $event->getOutput();
    if (!$output instanceof ChatOutput) {
      return;
    }
    $tokens = AnswerService::tokens($output);
    // Kosten des antwortenden Providers (providers.<id>.kosten_pro_1k_chf).
    $costPer1k = $this->providerResolver->costPer1k($event->getProviderId());
    $this->usageRecorder->record(
      $event->getModelId(),
      'chat',
      $tokens['prompt'],
      $tokens['completion'],
      $this->kontext(),
      round(($tokens['prompt'] + $tokens['completion']) / 1000 * $costPer1k, 4),
    );
    // Das Monatslimit neu rechnen (Cache 60 s).
    $this->cacheTagsInvalidator->invalidateTags([LimitService::CACHE_TAG]);
  }

  /**
   * Kontext aus dem Request-Attribut, sonst assistent.
   */
  private function kontext(): string {
    $value = $this->requestStack->getCurrentRequest()?->attributes->get(self::KONTEXT_ATTRIBUTE);
    return in_array($value, ['assistent', 'ingest', 'eval'], TRUE) ? $value : self::DEFAULT_KONTEXT;
  }

}
