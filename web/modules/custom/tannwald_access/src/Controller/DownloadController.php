<?php

declare(strict_types=1);

namespace Drupal\tannwald_access\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\tannwald_access\AuditLogger;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Auslieferung von field_datei nur über /dokument/{node}/download.
 */
final class DownloadController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly AuditLogger $audit,
  ) {}

  /**
   * Zusatzbedingung der Route: nur Bundle dokument.
   */
  public static function access(NodeInterface $node): AccessResultInterface {
    return AccessResult::allowedIf($node->bundle() === 'dokument')->addCacheableDependency($node);
  }

  /**
   * Liefert die Datei; die Sicht prüft die Route (_entity_access node.view).
   */
  public function download(NodeInterface $node): BinaryFileResponse {
    $file = $node->hasField('field_datei') ? $node->get('field_datei')->entity : NULL;
    if (!$file instanceof FileInterface || !is_file($file->getFileUri())) {
      throw new NotFoundHttpException();
    }
    $version = $node->hasField('field_version') ? $node->get('field_version')->value : NULL;
    $this->audit->log('download', $node, [
      'fid' => (int) $file->id(),
      'version' => $version,
    ]);
    $response = new BinaryFileResponse($file->getFileUri(), 200, [
      'Content-Type' => $file->getMimeType() ?: 'application/octet-stream',
      'Cache-Control' => 'private, no-store',
      'X-Content-Type-Options' => 'nosniff',
    ], FALSE);
    $filename = $file->getFilename();
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'download';
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename, $fallback);
    return $response;
  }

}
