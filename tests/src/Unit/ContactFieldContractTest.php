<?php

declare(strict_types=1);

namespace Drupal\Tests\agency\Unit;

use Drupal\agency_commercial\ContactMigration\ContactFields;
use Drupal\agency_commercial\Exception\CommercialRefusal;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins contact source fields to typed email, telephone and address.
 *
 * Generic ContactFieldTypesTest. Host CrmContact entity source and form
 * widgets stay on the webcms host.
 *
 * @group agency_commercial
 */
#[Group('agency_commercial')]
final class ContactFieldContractTest extends UnitTestCase {

  /**
   * The reviewed layout accepts typed email, telephone and address fields.
   */
  public function testReviewedLayoutKeepsTypedContactFields(): void {
    $layout = $this->layout();
    ContactFields::assertLayout($layout);
    $this->assertSame('email', $layout['fields']['email']['type']);
    $this->assertSame('telephone', $layout['fields']['phone']['type']);
    $this->assertSame('address', $layout['fields']['address']['type']);
  }

  /**
   * A string stand-in for email, phone or address is unreviewed.
   */
  public function testStringStandInsForContactFieldsAreUnreviewed(): void {
    foreach (['email', 'phone', 'address'] as $field) {
      $layout = $this->layout();
      $layout['fields'][$field]['type'] = 'string';
      try {
        ContactFields::assertLayout($layout);
        $this->fail($field . ' must stay a typed contact field.');
      }
      catch (CommercialRefusal $exception) {
        $this->assertSame(
          'contact_source_layout_unreviewed',
          $exception->reason,
        );
      }
    }
  }

  /**
   * Builds one reviewed contact source layout.
   */
  private function layout(): array {
    $types = [
      'address' => 'address',
      'changed' => 'changed',
      'client_user' => 'entity_reference',
      'contact_restriction' => 'list_string',
      'created' => 'created',
      'email' => 'email',
      'email_match_key' => 'string',
      'email_merge_hint' => 'string',
      'email_original' => 'string',
      'first_touch' => 'string_long',
      'id' => 'integer',
      'label' => 'string',
      'last_touch' => 'string_long',
      'mailbox_kind' => 'list_string',
      'merge_token' => 'string',
      'merged_into' => 'entity_reference',
      'organization_name' => 'string',
      'owner' => 'entity_reference',
      'phone' => 'telephone',
      'revision_created' => 'created',
      'revision_default' => 'boolean',
      'revision_id' => 'integer',
      'revision_log_message' => 'string_long',
      'revision_user' => 'entity_reference',
      'source' => 'list_string',
      'uuid' => 'uuid',
    ];
    $fields = [];
    foreach ($types as $name => $type) {
      $fields[$name] = [
        'type' => $type,
        'cardinality' => 1,
        'revisionable' => !in_array($name, [
          'id',
          'uuid',
          'revision_id',
          'created',
        ], TRUE),
        'translatable' => FALSE,
        'properties' => $this->properties($type),
      ];
      if ($type === 'entity_reference') {
        $fields[$name]['settings'] = [
          'target_type' => $name === 'merged_into' ? 'crm_contact' : 'user',
        ];
      }
    }
    return [
      'keys' => [
        'id' => 'id',
        'uuid' => 'uuid',
        'revision' => 'revision_id',
        'langcode' => '',
        'bundle' => '',
      ],
      'revision_metadata_keys' => [
        'revision_created' => 'revision_created',
        'revision_default' => 'revision_default',
        'revision_log_message' => 'revision_log_message',
        'revision_user' => 'revision_user',
      ],
      'revisionable' => TRUE,
      'translatable' => FALSE,
      'fields' => $fields,
    ];
  }

  /**
   * Returns the stored property map for one reviewed field type.
   */
  private function properties(string $type): array {
    return match ($type) {
      'address' => array_fill_keys([
        'additional_name',
        'address_line1',
        'address_line2',
        'address_line3',
        'administrative_area',
        'country_code',
        'dependent_locality',
        'family_name',
        'given_name',
        'langcode',
        'locality',
        'organization',
        'postal_code',
        'sorting_code',
      ], 'string'),
      'entity_reference' => ['target_id' => 'integer'],
      'integer' => ['value' => 'integer'],
      'created', 'changed' => ['value' => 'timestamp'],
      'email' => ['value' => 'email'],
      'boolean' => ['value' => 'boolean'],
      default => ['value' => 'string'],
    };
  }

}
