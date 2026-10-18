<?php

declare(strict_types=1);

namespace Drupal\agency_host\Console;

use Drupal\Core\Form\FormStateInterface;

/**
 * Refuses rendering an input without a declared, reviewable collection purpose.
 */
final class ConsoleFieldInventory {

  /**
   * Form API after-build callback, after all input element plugins have run.
   */
  public static function check(array $form, FormStateInterface $state): array {
    $inventory = json_decode(file_get_contents(__DIR__ . '/../../resources/console-fields.json'), TRUE, 8, JSON_THROW_ON_ERROR);
    self::walk($form, $inventory);
    return $form;
  }

  /**
   * Refuses a newly collected command key without an inventory purpose.
   */
  public static function command(string $command, array $input): void {
    $inventory = json_decode(file_get_contents(__DIR__ . '/../../resources/console-fields.json'), TRUE, 8, JSON_THROW_ON_ERROR);
    if (!isset($inventory['command_inputs'][$command])) {
      throw new \LogicException('Undeclared console command: ' . $command . '.');
    }
    self::inputKeys($input, $inventory['command_inputs'][$command], $inventory);
  }

  /**
   * Maps scalar input leaves to declared collection or fixed host purposes.
   */
  private static function inputKeys(array $input, array $allowed, array $inventory, string $prefix = ''): void {
    foreach ($input as $key => $value) {
      $path = $prefix . $key;
      if (is_array($value) && $value !== []) {
        self::inputKeys($value, $allowed, $inventory, $path . '.');
        continue;
      }
      $purpose = $allowed[$path] ?? NULL;
      if (!$purpose || (!isset($inventory['fields'][$purpose]) && !isset($inventory['system_inputs'][$purpose]))) {
        throw new \LogicException('Undeclared console command input: ' . $path . '.');
      }
    }
  }

  /**
   * Checks rendered controls, including hidden inputs and command buttons.
   */
  private static function walk(array $element, array $inventory): void {
    if (!empty($element['#input'])) {
      $name = $element['#name'] ?? '';
      $purpose = $element['#agency_field_purpose'] ?? NULL;
      $declared = $purpose !== NULL && isset($inventory['fields'][$purpose]);
      $control = in_array($element['#type'] ?? '', ['submit', 'button'], TRUE) && isset($inventory['controls'][$name]);
      $framework = in_array($name, $inventory['framework_exemptions'], TRUE);
      // Date's child widgets collect only the parent's declared timestamp.
      $date_child = in_array($name, ['expected_by[date]', 'expected_by[time]'], TRUE)
        && isset($inventory['fields']['next_action.expected_by']);
      if (!$declared && !$control && !$framework && !$date_child) {
        throw new \LogicException('Undeclared console input purpose: ' . $name . '.');
      }
    }
    foreach ($element as $key => $child) {
      if (is_string($key) && !str_starts_with($key, '#') && is_array($child)) {
        self::walk($child, $inventory);
      }
    }
  }

}
