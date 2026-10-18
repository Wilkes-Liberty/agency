<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_host\Unit;

use Drupal\agency_host\Identity\IdentityDirectoryUnavailable;
use Drupal\agency_host\Identity\KeycloakDirectory;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exercises readback failures without contacting an identity provider.
 *
 * @group agency_host
 */
#[CoversClass(KeycloakDirectory::class)]
#[Group('agency_host')]
final class KeycloakDirectoryTest extends UnitTestCase {

  /**
   * Ambiguity and malformed candidates never select a principal or read one.
   */
  public function testLookupRequiresOneVerifiedCandidateAndFreshRead(): void {
    $person = [
      'id' => 'person',
      'enabled' => TRUE,
      'emailVerified' => TRUE,
      'email' => 'person@example.test',
      'firstName' => 'Synthetic',
    ];
    foreach ([
    [],
    [$person, $person],
    [array_replace($person, ['enabled' => FALSE])],
    [
      array_replace($person, [
        'emailVerified' => FALSE,
      ]),
    ],
    [
      array_replace($person, [
        'email' => 'other@example.test',
      ]),
    ],
    [
      array_replace($person, [
        'serviceAccountClientId' => 'service',
      ]),
    ],
    [
    [
      'id' => [],
    ],
    ],
    ] as $rows) {
      $this->assertNull($this->directory(new Response(200, [], json_encode($rows)))->findEmail('person@example.test'));
    }
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], '{"access_token":"fixture"}'),
      new Response(200, [], json_encode([$person])),
      new Response(200, [], '{"access_token":"fixture"}'),
      new Response(200, [], json_encode($person)),
    ]));
    $stack->push(Middleware::history($history));
    $directory = new KeycloakDirectory(new Client(['handler' => $stack]), $this->settings());
    $selected = $directory->findEmail('person@example.test');
    $this->assertSame('person', $selected['subject']);
    $this->assertSame('Synthetic', $selected['name']);
    parse_str($history[1]['request']->getUri()->getQuery(), $query);
    $this->assertSame(['email' => 'person@example.test', 'exact' => 'true', 'max' => '2'], $query);
    $this->assertCount(4, $history);
    $this->assertSame('/admin/realms/example/users/person', $history[3]['request']->getUri()->getPath());
  }

  /**
   * The selected subject must match the authoritative response exactly.
   */
  public function testWrongSubjectAndOutagesNeverBecomeAbsence(): void {
    foreach ([
      new Response(200, [], '{"id":"other","enabled":true}'),
      new Response(503, [], 'unavailable'),
      new Response(200, [], '{"id":"person","enabled":"true"}'),
    ] as $response) {
      $directory = $this->directory($response);
      try {
        $directory->read('person');
        $this->fail('Invalid authoritative response was accepted.');
      }
      catch (IdentityDirectoryUnavailable $error) {
        $this->assertSame('identity_unavailable', $error->getMessage());
      }
    }
  }

  /**
   * Only a successful missing-user lookup produces coordinator not-found.
   */
  public function testMissingAndDisabledAreDistinct(): void {
    $this->assertNull($this->directory(new Response(404))->read('person'));
    $facts = $this->directory(new Response(200, [], '{"id":"person","enabled":false,"email":"person@example.test"}'))->read('person');
    $this->assertFalse($facts['active']);
  }

  /**
   * Subject paths are explicit; no email search or identity write is issued.
   */
  public function testReadbackIsBoundedAndReadOnly(): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], '{"access_token":"synthetic-admin-token"}'),
      new Response(200, [], '{"id":"person","enabled":true,"email":"person@example.test","attributes":{"private":"omit"}}'),
    ]));
    $stack->push(Middleware::history($history));
    $directory = new KeycloakDirectory(new Client(['handler' => $stack]), $this->settings());
    $facts = $directory->read('person');
    $this->assertSame(['subject', 'active', 'email', 'email_verified', 'service', 'name'], array_keys($facts));
    $this->assertCount(2, $history);
    $this->assertSame('GET', $history[1]['request']->getMethod());
    $this->assertSame('/admin/realms/example/users/person', $history[1]['request']->getUri()->getPath());
    $this->assertSame('', $history[1]['request']->getUri()->getQuery());
    $this->assertFalse($history[0]['options']['allow_redirects']);
    $this->assertFalse($history[1]['options']['allow_redirects']);
    $this->assertLessThanOrEqual(5, $history[1]['options']['timeout']);
  }

  /**
   * Oversized and malformed records never escape as absence or remote detail.
   */
  public function testUntrustedResponseBounds(): void {
    foreach ([str_repeat('x', 65537), '{', '"private detail"'] as $body) {
      try {
        $this->directory(new Response(200, [], $body))->read('person');
        $this->fail('Malformed or oversized response accepted.');
      }
      catch (IdentityDirectoryUnavailable $error) {
        $this->assertSame('identity_unavailable', $error->getMessage());
        $this->assertNull($error->getPrevious());
      }
    }
  }

  /**
   * The configured readback realm must represent the trusted issuer.
   */
  public function testDifferentRealmIsRejectedBeforeNetwork(): void {
    $config = $this->settings()->get('agency_host_identity');
    $config['realm'] = 'other';
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
      new Response(200, [], '{"access_token":"fixture"}'),
      new Response(200, [], '{"id":"person","enabled":true}'),
    ]));
    $stack->push(Middleware::history($history));
    $directory = new KeycloakDirectory(new Client(['handler' => $stack]), new Settings([
      'agency_host_identity' => $config,
    ]));
    try {
      $directory->read('person');
      $this->fail('A different realm was accepted.');
    }
    catch (IdentityDirectoryUnavailable) {
      $this->assertSame([], $history);
    }
  }

  /**
   * Creates one readback with a synthetic service-token response.
   */
  private function directory(Response $response): KeycloakDirectory {
    $handler = HandlerStack::create(new MockHandler([
      new Response(200, [], '{"access_token":"synthetic-admin-token"}'),
      $response,
    ]));
    return new KeycloakDirectory(new Client(['handler' => $handler]), $this->settings());
  }

  /**
   * Returns synthetic runtime settings, never a real credential.
   */
  private function settings(): Settings {
    return new Settings([
      'agency_host_identity' => [
        'issuer' => 'https://identity.example.test/realms/example',
        'token_url' => 'https://identity.example.test/realms/example/protocol/openid-connect/token',
        'admin_base_url' => 'https://identity.example.test',
        'realm' => 'example',
        'client_id' => 'synthetic-readback',
        'client_secret' => 'fixture-only',
      ],
    ]);
  }

}
