<?php

declare(strict_types=1);

namespace Drupal\Tests\drupal_mcp\Unit;

use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Locks down conversion of the MCP SDK's lazy SSE responses.
 */
#[CoversNothing]
final class McpPsr7ResponseConverterTest extends TestCase {

  /**
   * Tests an event stream remains lazy until Symfony sends the response.
   */
  public function testEventStreamIsConvertedToStreamedResponse(): void {
    $stream = Utils::streamFor(static function (): void {
      echo "event: message\n";
      echo "data: {\"jsonrpc\":\"2.0\",\"id\":1}\n\n";
    });
    $psr7Response = new Psr7Response(200, ['Content-Type' => 'text/event-stream'], $stream);

    $response = (new HttpFoundationFactory())->createResponse(
      $psr7Response,
      str_starts_with(strtolower($psr7Response->getHeaderLine('Content-Type')), 'text/event-stream'),
    );

    $this->assertInstanceOf(StreamedResponse::class, $response);
    $this->assertSame('text/event-stream', $response->headers->get('Content-Type'));
    ob_start();
    $response->sendContent();
    $content = (string) ob_get_clean();
    $this->assertStringContainsString('event: message', $content);
    $this->assertStringContainsString('"jsonrpc":"2.0"', $content);
  }

  /**
   * Tests ordinary JSON responses remain buffered responses.
   */
  public function testJsonResponseRemainsBuffered(): void {
    $psr7Response = new Psr7Response(200, ['Content-Type' => 'application/json'], '{"ok":true}');

    $response = (new HttpFoundationFactory())->createResponse(
      $psr7Response,
      str_starts_with(strtolower($psr7Response->getHeaderLine('Content-Type')), 'text/event-stream'),
    );

    $this->assertInstanceOf(Response::class, $response);
    $this->assertNotInstanceOf(StreamedResponse::class, $response);
    $this->assertSame('{"ok":true}', $response->getContent());
  }

}
