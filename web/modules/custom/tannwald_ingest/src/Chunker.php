<?php

declare(strict_types=1);

namespace Drupal\tannwald_ingest;

use Drupal\Component\Utility\Html;
use Psr\Log\LoggerInterface;

/**
 * Zerlegt Dokument-HTML (Format dokument_html) in Chunks entlang h2/h3.
 *
 * Ziel 350 Tokens (Schätzung 4 Zeichen/Token), Überlappung 60 Tokens an
 * Satzgrenzen, Tabellen atomar (Zeilen „Zelle | Zelle“, Kopfzeile zuerst),
 * Listen zeilenweise. Die Überschrift steht am Anfang jedes Chunk-Textes.
 */
class Chunker {

  /**
   * Trenner im heading_path (U+203A mit Leerzeichen).
   */
  public const PATH_SEPARATOR = ' › ';

  /**
   * Zielgrösse eines Chunks in Tokens.
   */
  public const TARGET_TOKENS = 350;

  /**
   * Überlappung zwischen Folge-Chunks eines Abschnitts in Tokens.
   */
  public const OVERLAP_TOKENS = 60;

  /**
   * Zeichen je Token (Schätzung).
   */
  public const CHARS_PER_TOKEN = 4;

  public function __construct(
    protected ?LoggerInterface $logger = NULL,
  ) {}

  /**
   * Zerlegt ein Dokument.
   *
   * @param string $html
   *   Body im Format dokument_html.
   * @param string $title
   *   Dokumenttitel (heading_path des Textes vor dem ersten h2).
   *
   * @return array
   *   Liste von {chunk_index: int, heading_path: string, text: string,
   *   token_count: int}, chunk_index ab 0 fortlaufend.
   */
  public function chunk(string $html, string $title): array {
    $title = self::clean($title);
    $chunks = [];
    foreach ($this->sections($html, $title) as $section) {
      foreach ($this->pack($section['heading'], $section['units'], $section['path']) as $text) {
        $chunks[] = [
          'chunk_index' => count($chunks),
          'heading_path' => $section['path'],
          'text' => $text,
          'token_count' => self::tokens($text),
        ];
      }
    }
    return $chunks;
  }

  /**
   * Token-Schätzung: ceil(mb_strlen / 4).
   */
  public static function tokens(string $text): int {
    return (int) ceil(mb_strlen($text, 'UTF-8') / self::CHARS_PER_TOKEN);
  }

  /**
   * Bildet Abschnitte {path, heading, units[]} aus dem HTML.
   *
   * Eine Unit ist ['text' => string, 'atomic' => bool, 'sentence' => bool]:
   * Sätze eines Absatzes, Listenzeilen, ganze Tabellen (atomic).
   */
  protected function sections(string $html, string $title): array {
    $sections = [];
    $current = ['path' => $title, 'heading' => '', 'units' => []];
    $h2 = NULL;
    if (trim($html) === '') {
      return [];
    }
    $dom = Html::load($html);
    $body = $dom->getElementsByTagName('body')->item(0);
    if ($body === NULL) {
      return [];
    }
    foreach ($this->blockNodes($body) as $node) {
      $tag = strtolower($node->nodeName);
      if ($tag === 'h2' || $tag === 'h3') {
        $heading = self::clean($node->textContent);
        if ($heading === '') {
          continue;
        }
        $sections[] = $current;
        if ($tag === 'h2') {
          $h2 = $heading;
          $path = $heading;
        }
        else {
          $path = ($h2 ?? $title) . self::PATH_SEPARATOR . $heading;
        }
        $current = ['path' => $path, 'heading' => $heading, 'units' => []];
        continue;
      }
      foreach ($this->units($node) as $unit) {
        $current['units'][] = $unit;
      }
    }
    $sections[] = $current;
    // Leere Abschnitte (nur Überschrift, kein Inhalt) überspringen.
    return array_values(array_filter($sections, static fn(array $s): bool => $s['units'] !== []));
  }

  /**
   * Liefert die Block-Knoten in Dokumentreihenfolge (Container aufgelöst).
   *
   * @return \DOMNode[]
   *   Blöcke: h2, h3, p, table, ul, ol, Textknoten und Unbekanntes.
   */
  protected function blockNodes(\DOMNode $parent): array {
    $blocks = [];
    foreach ($parent->childNodes as $child) {
      $tag = strtolower($child->nodeName);
      if (in_array($tag, ['div', 'section', 'article', 'main', 'header', 'footer'], TRUE)) {
        array_push($blocks, ...$this->blockNodes($child));
        continue;
      }
      if ($child instanceof \DOMText && self::clean($child->textContent) === '') {
        continue;
      }
      if ($child instanceof \DOMComment) {
        continue;
      }
      $blocks[] = $child;
    }
    return $blocks;
  }

  /**
   * Zerlegt einen Block in Units.
   */
  protected function units(\DOMNode $node): array {
    $tag = strtolower($node->nodeName);
    if ($tag === 'table') {
      $text = $this->tableText($node);
      return $text === '' ? [] : [['text' => $text, 'atomic' => TRUE, 'sentence' => FALSE]];
    }
    if ($tag === 'ul' || $tag === 'ol') {
      $units = [];
      $n = 0;
      foreach ($node->childNodes as $li) {
        if (strtolower($li->nodeName) !== 'li') {
          continue;
        }
        $line = self::clean($li->textContent);
        if ($line === '') {
          continue;
        }
        $n++;
        $prefix = $tag === 'ol' ? $n . '. ' : '- ';
        foreach ($this->splitLong($prefix . $line) as $part) {
          $units[] = ['text' => $part, 'atomic' => FALSE, 'sentence' => FALSE];
        }
      }
      return $units;
    }
    $text = self::clean($node->textContent);
    if ($text === '') {
      return [];
    }
    $units = [];
    foreach ($this->sentences($text) as $sentence) {
      foreach ($this->splitLong($sentence) as $part) {
        $units[] = ['text' => $part, 'atomic' => FALSE, 'sentence' => TRUE];
      }
    }
    return $units;
  }

  /**
   * Tabelle als Text: eine Zeile je tr, Zellen mit „ | “, Kopf zuerst.
   */
  protected function tableText(\DOMNode $table): string {
    $head = [];
    $rows = [];
    $xpath = new \DOMXPath($table->ownerDocument);
    foreach ($xpath->query('.//tr', $table) as $tr) {
      $cells = [];
      $isHead = strtolower($tr->parentNode->nodeName) === 'thead';
      foreach ($tr->childNodes as $cell) {
        $cellTag = strtolower($cell->nodeName);
        if ($cellTag === 'th' || $cellTag === 'td') {
          $cells[] = self::clean($cell->textContent);
        }
      }
      if (array_filter($cells, static fn(string $c): bool => $c !== '') === []) {
        continue;
      }
      $line = implode(' | ', $cells);
      if ($isHead) {
        $head[] = $line;
      }
      else {
        $rows[] = $line;
      }
    }
    return implode("\n", array_merge($head, $rows));
  }

  /**
   * Teilt Text an Satzgrenzen.
   *
   * @return string[]
   *   Sätze ohne führende/folgende Leerzeichen.
   */
  protected function sentences(string $text): array {
    $parts = preg_split('/(?<=[.!?…])\s+(?=\S)/u', $text) ?: [$text];
    return array_values(array_filter(array_map('trim', $parts), static fn(string $p): bool => $p !== ''));
  }

  /**
   * Teilt einen überlangen Satz an Wortgrenzen auf Zielgrösse.
   *
   * @return string[]
   *   Teile mit höchstens TARGET_TOKENS * CHARS_PER_TOKEN Zeichen (ausser
   *   einzelne Wörter, die länger sind).
   */
  protected function splitLong(string $text): array {
    $max = self::TARGET_TOKENS * self::CHARS_PER_TOKEN;
    if (mb_strlen($text, 'UTF-8') <= $max) {
      return [$text];
    }
    $parts = [];
    $buffer = '';
    foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
      $candidate = $buffer === '' ? $word : $buffer . ' ' . $word;
      if ($buffer !== '' && mb_strlen($candidate, 'UTF-8') > $max) {
        $parts[] = $buffer;
        $buffer = $word;
      }
      else {
        $buffer = $candidate;
      }
    }
    if ($buffer !== '') {
      $parts[] = $buffer;
    }
    return $parts;
  }

  /**
   * Packt die Units eines Abschnitts zu Chunk-Texten.
   *
   * @return string[]
   *   Chunk-Texte; jeder beginnt mit der Überschrift (falls vorhanden).
   */
  protected function pack(string $heading, array $units, string $path): array {
    $maxChars = self::TARGET_TOKENS * self::CHARS_PER_TOKEN;
    $overlapChars = self::OVERLAP_TOKENS * self::CHARS_PER_TOKEN;
    $texts = [];
    // Units des laufenden Chunks; $fresh zählt die neuen (nicht Überlappung).
    $buffer = [];
    $fresh = 0;

    foreach ($units as $unit) {
      $candidate = $this->join($heading, array_merge($buffer, [$unit]));
      if ($buffer === [] || mb_strlen($candidate, 'UTF-8') <= $maxChars) {
        $buffer[] = $unit;
        $fresh++;
        continue;
      }
      // Laufender Chunk ist voll (enthält immer mindestens eine neue Unit).
      $texts[] = $this->join($heading, $buffer);
      $overlap = $unit['atomic'] ? [] : $this->overlap($buffer, $overlapChars);
      if ($overlap !== [] && mb_strlen($this->join($heading, array_merge($overlap, [$unit])), 'UTF-8') > $maxChars) {
        $overlap = [];
      }
      $buffer = array_merge($overlap, [$unit]);
      $fresh = 1;
    }
    if ($fresh > 0) {
      $texts[] = $this->join($heading, $buffer);
    }

    foreach ($units as $unit) {
      if ($unit['atomic'] && self::tokens($unit['text']) > self::TARGET_TOKENS) {
        $this->logger?->warning('Table in section %path has @tokens tokens (more than @max); kept as one chunk.', [
          '%path' => $path,
          '@tokens' => self::tokens($unit['text']),
          '@max' => self::TARGET_TOKENS,
        ]);
      }
    }
    return $texts;
  }

  /**
   * Überlappung: letzte Units (keine Tabellen) bis höchstens $maxChars.
   *
   * Bevorzugt ganze Sätze; passt nicht einmal der letzte Satz, wird sein Ende
   * an Wortgrenzen genommen.
   */
  protected function overlap(array $buffer, int $maxChars): array {
    $picked = [];
    $length = 0;
    for ($i = count($buffer) - 1; $i >= 0; $i--) {
      $unit = $buffer[$i];
      if ($unit['atomic']) {
        break;
      }
      $len = mb_strlen($unit['text'], 'UTF-8') + ($picked === [] ? 0 : 1);
      if ($length + $len > $maxChars) {
        break;
      }
      array_unshift($picked, $unit);
      $length += $len;
    }
    if ($picked === [] && $buffer !== [] && !end($buffer)['atomic']) {
      // Wortweise vom Ende des letzten Satzes.
      $words = preg_split('/\s+/u', end($buffer)['text']) ?: [];
      $tail = '';
      while ($words !== []) {
        $word = array_pop($words);
        $candidate = $tail === '' ? $word : $word . ' ' . $tail;
        if (mb_strlen($candidate, 'UTF-8') > $maxChars) {
          break;
        }
        $tail = $candidate;
      }
      if ($tail !== '') {
        $picked[] = ['text' => $tail, 'atomic' => FALSE, 'sentence' => TRUE];
      }
    }
    return $picked;
  }

  /**
   * Fügt Überschrift und Units zu einem Text.
   *
   * Sätze mit Leerzeichen, sonst Zeilenumbruch.
   */
  protected function join(string $heading, array $units): string {
    $text = $heading;
    $previousSentence = FALSE;
    foreach ($units as $unit) {
      if ($text === '') {
        $text = $unit['text'];
      }
      elseif ($unit['sentence'] && $previousSentence) {
        $text .= ' ' . $unit['text'];
      }
      else {
        $text .= "\n" . $unit['text'];
      }
      $previousSentence = $unit['sentence'];
    }
    return $text;
  }

  /**
   * NFC und verdichteter Whitespace (Zeilenumbrüche werden Leerzeichen).
   */
  public static function clean(string $text): string {
    if (class_exists(\Normalizer::class)) {
      $text = (string) \Normalizer::normalize($text, \Normalizer::FORM_C);
    }
    $text = str_replace("\u{00A0}", ' ', $text);
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

}
