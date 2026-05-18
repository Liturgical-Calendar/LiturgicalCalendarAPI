<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Ops;

use LiturgicalCalendar\Api\Handlers\Ops\OpcacheResetHandler;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpcacheResetHandler::class)]
final class OpcacheResetHandlerTest extends TestCase
{
    /**
     * Determine whether opcache is loaded AND enabled in the current SAPI,
     * without calling opcache_reset() ourselves. opcache_reset() can return
     * false on its second consecutive call when the cache is empty, so we
     * must not "probe" with it — the handler under test must be the one
     * making the call.
     */
    private function opcacheIsActive(): bool
    {
        if (!function_exists('opcache_reset') || !function_exists('opcache_get_status')) {
            return false;
        }
        $status = @opcache_get_status(false);
        return is_array($status) && ( $status['opcache_enabled'] ?? false ) === true;
    }

    public function testPostReturns200WhenOpcacheIsEnabled(): void
    {
        if (!$this->opcacheIsActive()) {
            $this->markTestSkipped(
                'opcache extension is not active in this SAPI (opcache.enable_cli is off or extension missing). '
                . 'See testPostReturns500WhenOpcacheIsDisabled / testPostReturns503WhenOpcacheExtensionMissing.'
            );
        }

        $handler = new OpcacheResetHandler();
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $response = $handler->handle(new ServerRequest('POST', '/_ops/opcache-reset'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('OPcache reset', (string) $response->getBody());
    }

    public function testPostReturns500WhenOpcacheIsDisabled(): void
    {
        if (!function_exists('opcache_reset')) {
            $this->markTestSkipped('opcache extension not loaded; 503 path will run instead.');
        }
        if ($this->opcacheIsActive()) {
            $this->markTestSkipped(
                'opcache is active in this SAPI; the 500 path only fires when the extension is '
                . 'loaded-but-disabled. See testPostReturns200WhenOpcacheIsEnabled.'
            );
        }

        $handler = new OpcacheResetHandler();
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $response = $handler->handle(new ServerRequest('POST', '/_ops/opcache-reset'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('opcache_reset() returned false', (string) $response->getBody());
    }

    public function testPostReturns503WhenOpcacheExtensionMissing(): void
    {
        if (function_exists('opcache_reset')) {
            $this->markTestSkipped(
                'opcache extension is loaded; the 503 fallback only fires when the '
                . 'function does not exist at all.'
            );
        }

        $handler = new OpcacheResetHandler();
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $response = $handler->handle(new ServerRequest('POST', '/_ops/opcache-reset'));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringContainsString('OPcache extension is not loaded', (string) $response->getBody());
    }

    public function testGetWithDisallowedMethodThrowsMethodNotAllowed(): void
    {
        $handler = new OpcacheResetHandler();
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::POST,
        ]);

        $this->expectException(\LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException::class);
        $handler->handle(new ServerRequest('GET', '/_ops/opcache-reset'));
    }
}
