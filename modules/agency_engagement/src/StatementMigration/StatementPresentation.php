<?php

declare(strict_types=1);

namespace Drupal\agency_engagement\StatementMigration;

/**
 * Checks the reviewed frozen presentation layouts without rendering them.
 *
 * @internal
 */
final class StatementPresentation {

  private const LABELS = [
    'invoiceTitle', 'statementTitle', 'documentTitle', 'supersedes', 'notice',
    'work', 'date', 'seconds', 'amount', 'total', 'used', 'remaining',
    'noBudget', 'open', 'downloads', 'email',
  ];

  /**
   * Retains the versioned stored labels, formats and optional prose unchanged.
   */
  public static function qualify(mixed $value, array $groups, bool $noBudget): array {
    $version = is_array($value) ? ($value['version'] ?? NULL) : NULL;
    StatementPreflight::require(in_array($version, [1, 2], TRUE), 'presentation version');
    $keys = ['version', 'langcode', 'labels', 'created', 'groups', 'used', 'remaining'];
    if ($version === 2) {
      $keys[] = 'prose';
    }
    $value = StatementPreflight::shape($value, $keys, 'presentation');
    StatementPreflight::shape($value['labels'], self::LABELS, 'presentation labels');
    StatementPreflight::require(self::language($value['langcode']) && self::text($value['created'])
      && self::text($value['used']) && ($noBudget ? $value['remaining'] === NULL : self::text($value['remaining'])),
      'presentation');
    foreach ($value['labels'] as $label) {
      StatementPreflight::require(self::text($label), 'presentation labels');
    }
    $display = StatementPreflight::rows($value['groups'], 'presentation groups');
    $prose = $version === 2 ? StatementPreflight::rows($value['prose'], 'presentation prose') : [];
    StatementPreflight::require(count($display) === count($groups) && ($version === 1 || count($prose) === count($groups)), 'presentation groups');
    foreach ($groups as $groupIndex => $group) {
      StatementPreflight::shape($group, ['engagement', 'title', 'lines'], 'groups');
      $lines = StatementPreflight::rows($group['lines'], 'lines');
      $formatted = StatementPreflight::rows($display[$groupIndex], 'presentation lines');
      StatementPreflight::require(count($formatted) === count($lines), 'presentation lines');
      foreach ($formatted as $line) {
        StatementPreflight::shape($line, ['date', 'seconds'], 'presentation lines');
        StatementPreflight::require(self::text($line['date']) && self::text($line['seconds']), 'presentation lines');
      }
      if ($version === 2) {
        $authored = $prose[$groupIndex];
        StatementPreflight::shape($authored, ['title', 'lines'], 'presentation prose');
        self::prose($authored['title'], $group['title'], $value['langcode']);
        $authoredLines = StatementPreflight::rows($authored['lines'], 'presentation prose');
        StatementPreflight::require(count($authoredLines) === count($lines), 'presentation prose');
        foreach ($authoredLines as $lineIndex => $line) {
          StatementPreflight::shape($line, ['public_summary'], 'presentation prose');
          self::prose($line['public_summary'], $lines[$lineIndex]['public_summary'] ?? NULL, $value['langcode']);
        }
      }
    }
    return $value;
  }

  /**
   * Checks provenance and the original fallback without resolving translations.
   */
  private static function prose(mixed $value, mixed $original, string $requestedLanguage): void {
    StatementPreflight::shape($value, [
      'text', 'langcode', 'source_langcode', 'source_revision', 'source_hash', 'translation_revision',
    ], 'presentation prose');
    StatementPreflight::require(self::text($value['text']) && self::language($value['langcode'])
      && self::language($value['source_langcode'])
      && is_int($value['source_revision']) && $value['source_revision'] > 0
      && is_string($value['source_hash']) && preg_match('/^[a-f0-9]{64}$/D', $value['source_hash']) === 1
      && is_int($value['translation_revision']) && $value['translation_revision'] >= 0
      && ($value['translation_revision'] === 0
        ? ($value['text'] === $original && $value['langcode'] === $value['source_langcode'])
        : $value['langcode'] === $requestedLanguage), 'presentation prose');
  }

  /**
   * Accepts stored languages without consulting today's configured locales.
   *
   * @phpstan-assert-if-true string $value
   */
  private static function language(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-z]{2,8}(?:-[a-z0-9]{1,8})*$/iD', $value) === 1;
  }

  /**
   * Checks that stored presentation is displayable without recomputing it.
   */
  private static function text(mixed $value): bool {
    return is_string($value) && $value !== '';
  }

}
