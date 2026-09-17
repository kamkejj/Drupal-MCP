<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use Drupal\drupal_mcp\Http\McpRequestValidator;
use Drupal\Tests\drupal_mcp\Unit\Fixtures\ConfigFactoryStub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests MCP Host and Origin validation.
 */
final class McpRequestValidatorTest extends TestCase {

  /**
   * Request validator under test.
   */
  private McpRequestValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new McpRequestValidator(new ConfigFactoryStub([
      'drupal_mcp.settings' => [
        'allowed_hosts' => ['mcp.example', 'client.example'],
        'allowed_origins' => ['https://client.example'],
      ],
    ]));
  }

  /**
   * Tests that both Host and exact Origin must satisfy the configured policy.
   */
  public function testHostAndOriginMustBothBeAllowed(): void {
    $this->assertNull($this->validator->validate(Request::create('https://mcp.example/mcp')));

    $allowed = Request::create('https://mcp.example/mcp');
    $allowed->headers->set('Origin', 'https://client.example');
    $this->assertNull($this->validator->validate($allowed));

    $invalidHost = Request::create('https://attacker.example/mcp');
    $invalidHost->headers->set('Origin', 'https://client.example');
    $this->assertSame('Forbidden: Invalid Host header.', $this->validator->validate($invalidHost));

    $wrongScheme = Request::create('https://mcp.example/mcp');
    $wrongScheme->headers->set('Origin', 'http://client.example');
    $this->assertSame('Forbidden: Invalid Origin header.', $this->validator->validate($wrongScheme));
  }

}
