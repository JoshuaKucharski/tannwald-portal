<?php

declare(strict_types=1);

namespace Drupal\tannwald_assistant;

use Drupal\Component\Utility\Xss;

/**
 * Filtert die Modellausgabe zu sicherem Antwort-HTML (Pflichtenheft §8, TC-16).
 *
 * Erlaubt sind p, ul, ol, li, strong, em, code ohne Attribute; Links (HTML,
 * Markdown, nackte URLs) werden entfernt, ihr Text bleibt; [n] bleibt Text.
 * Antwortet das Modell in Markdown, werden Absätze, Listen und **fett** in
 * die erlaubten Tags übersetzt.
 */
final class OutputFilter {

  public const ALLOWED_TAGS = ['p', 'ul', 'ol', 'li', 'strong', 'em', 'code'];

  /**
   * Filtert eine Modellantwort.
   */
  public function filter(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    // Markdown-Links [Text](url) → Text; [n](url) → [n].
    $text = preg_replace_callback('/\[([^\]\n]+)\]\(([^)\n]*)\)/u', static function (array $m): string {
      return ctype_digit($m[1]) ? '[' . $m[1] . ']' : $m[1];
    }, $text) ?? $text;
    // Nackte URLs und Mail-Adressen entfernen.
    $text = preg_replace('~\b(?:(?:https?|ftp|file|javascript|data)://|www\.)[^\s<>"]+~iu', '', $text) ?? $text;
    $text = preg_replace('~\b(?:javascript|data|vbscript):[^\s<>"]*~iu', '', $text) ?? $text;
    $text = preg_replace('/\b[\w.+\-]+@[\w\-]+\.[\w.\-]+\b/u', '', $text) ?? $text;

    if (!preg_match('/<(p|ul|ol|li)\b/i', $text)) {
      $text = self::markdownToHtml($text);
    }

    $text = Xss::filter($text, self::ALLOWED_TAGS);
    // Attribute an erlaubten Tags entfernen.
    $text = preg_replace('/<(' . implode('|', self::ALLOWED_TAGS) . ')\b[^>]*>/i', '<$1>', $text) ?? $text;
    // Leere Absätze und doppelte Leerzeichen aufräumen.
    $text = preg_replace('/<p>\s*<\/p>/i', '', $text) ?? $text;
    $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
    $text = preg_replace('/ +([.,;:!?])/u', '$1', $text) ?? $text;
    $text = trim($text);
    if ($text !== '' && !preg_match('/^<(p|ul|ol)>/i', $text)) {
      $text = '<p>' . $text . '</p>';
    }
    return $text;
  }

  /**
   * Übersetzt einfaches Markdown in erlaubtes HTML.
   *
   * Kein Escape hier: Xss::filter() danach entschärft lose < und & und
   * entfernt nicht erlaubte Tags.
   */
  private static function markdownToHtml(string $text): string {
    $segments = [];
    foreach (explode("\n", $text) as $line) {
      $line = trim($line);
      if ($line === '') {
        // Leerzeile beendet Absatz und Liste.
        $segments[] = ['type' => 'break', 'items' => []];
        continue;
      }
      if (preg_match('/^[-*•]\s+(.+)$/u', $line, $m)) {
        [$type, $content] = ['ul', $m[1]];
      }
      elseif (preg_match('/^\d{1,2}[.)]\s+(.+)$/u', $line, $m)) {
        [$type, $content] = ['ol', $m[1]];
      }
      else {
        [$type, $content] = ['p', $line];
      }
      $last = array_key_last($segments);
      if ($last !== NULL && $segments[$last]['type'] === $type) {
        $segments[$last]['items'][] = $content;
      }
      else {
        $segments[] = ['type' => $type, 'items' => [$content]];
      }
    }

    $html = '';
    foreach ($segments as $segment) {
      if ($segment['type'] === 'p') {
        $html .= '<p>' . implode(' ', array_map([self::class, 'inline'], $segment['items'])) . '</p>';
      }
      elseif ($segment['type'] !== 'break') {
        $items = array_map(static fn (string $item): string => '<li>' . self::inline($item) . '</li>', $segment['items']);
        $html .= '<' . $segment['type'] . '>' . implode('', $items) . '</' . $segment['type'] . '>';
      }
    }
    return $html;
  }

  /**
   * Inline-Markdown: **fett**, __fett__, `code`, Rauten am Anfang weg.
   */
  private static function inline(string $text): string {
    $text = preg_replace('/^#{1,6}\s*/u', '', $text) ?? $text;
    $text = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/__(.+?)__/u', '<strong>$1</strong>', $text) ?? $text;
    return preg_replace('/`([^`]+)`/u', '<code>$1</code>', $text) ?? $text;
  }

}
