<?php

declare(strict_types=1);

namespace Drupal\tannwald_access\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\tannwald_access\AuditLogger;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Audit-Log als Seite (Pager 50) und als CSV (max. 10'000 Zeilen).
 */
final class AuditController implements ContainerInjectionInterface {

  use AutowireTrait;
  use StringTranslationTrait;

  /**
   * Spalten wie Tabelle tannwald_audit (Pflichtenheft §5.4).
   */
  public const COLUMNS = ['id', 'uid', 'ereignis', 'entity_type', 'entity_id', 'details', 'ip_land', 'created'];

  /**
   * Höchstzahl der CSV-Zeilen.
   */
  public const CSV_LIMIT = 10000;

  public function __construct(
    private readonly Connection $database,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * Seite /compliance/audit.
   *
   * @return array<string, mixed>
   *   Render-Array.
   */
  public function page(): array {
    $total = (int) $this->database->select(AuditLogger::TABLE, 'a')->countQuery()->execute()->fetchField();
    $pager = $this->pagerManager->createPager($total, 50);
    $result = $this->database->select(AuditLogger::TABLE, 'a')
      ->fields('a', self::COLUMNS)
      ->orderBy('created', 'DESC')
      ->orderBy('id', 'DESC')
      ->range($pager->getCurrentPage() * 50, 50)
      ->execute();
    $rows = [];
    foreach ($result as $record) {
      $rows[] = [
        $record->id,
        $this->dateFormatter->format((int) $record->created, 'custom', 'd.m.Y H:i:s'),
        $record->uid,
        $record->ereignis,
        $record->entity_type,
        $record->entity_id,
        $record->ip_land,
        $record->details,
      ];
    }
    return [
      'csv' => [
        '#type' => 'link',
        '#title' => $this->t('Download CSV'),
        '#url' => Url::fromRoute('tannwald_access.audit_csv'),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('ID'),
          $this->t('Date'),
          $this->t('User'),
          $this->t('Event'),
          $this->t('Entity type'),
          $this->t('Entity ID'),
          $this->t('Country'),
          $this->t('Details'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No audit entries.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * CSV /compliance/audit.csv: Trenner ";", UTF-8 mit BOM, neueste zuerst.
   */
  public function csv(): StreamedResponse {
    $database = $this->database;
    $response = new StreamedResponse(static function () use ($database): void {
      $out = fopen('php://output', 'wb');
      if ($out === FALSE) {
        return;
      }
      fwrite($out, "\xEF\xBB\xBF");
      fputcsv($out, self::COLUMNS, ';', '"', '');
      $result = $database->select(AuditLogger::TABLE, 'a')
        ->fields('a', self::COLUMNS)
        ->orderBy('created', 'DESC')
        ->orderBy('id', 'DESC')
        ->range(0, self::CSV_LIMIT)
        ->execute();
      foreach ($result as $record) {
        $line = [];
        foreach (self::COLUMNS as $column) {
          $line[] = (string) ($record->{$column} ?? '');
        }
        fputcsv($out, $line, ';', '"', '');
      }
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="tannwald-audit.csv"');
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
