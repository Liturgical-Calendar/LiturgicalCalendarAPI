<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use GuzzleHttp\Psr7\Request;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\Auth\WsCaller;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\WsCallerResolver;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * The `caller` object on the `hello` frame — #894.
 *
 * The frame is where the permission *advertisement* lives, and the point of putting it there is that
 * a client no longer has to decide for itself who may run tests. UnitTestInterface used to, from a
 * role list of its own, and its own CLAUDE.md recorded that the resulting gate was client-side only.
 * An advertisement that came from anywhere other than the object that also enforces the rule would
 * reintroduce exactly that gap, so {@see testTheAdvertisementComesFromThePolicy()} pins the two
 * together.
 *
 * `caller` is a **sibling** of `capabilities`, not a member of it. That object answers what this
 * server can be asked for, with every entry derived from an enum so that it cannot go stale; who is
 * asking is per-connection and derived from a cookie, so it does not belong inside it.
 */
#[CoversClass(Health::class)]
final class HealthCallerFrameTest extends TestCase
{
    // onOpen() queues the connect-time /calendars metadata fetch. See the trait.
    use HealthQueueIsolationTrait;

    /** Free of the words JwtServiceFactory treats as placeholder secrets. */
    private const SECRET = 'kFj9wQz2Lm7XpR4tVbN8sHc1YdG6aE0u';

    /**
     * The `Health` cache statics `onOpen()` writes, saved so this file can put them back.
     *
     * @var array<string, mixed>
     */
    private array $cacheStateBackup = [];

    /** @var list<string> */
    private const CACHE_STATICS = ['cacheInitialized', 'cacheEnabled', 'cacheBackend', 'redis'];

    /**
     * Skip the cache-init block inside `onOpen()`, for the reason `HealthHelloFrameTest` documents at
     * length: it turns caching on process-wide, and six other Health suites are written against it
     * being off. Setting `cacheInitialized` first is the flag's own way of saying "already done".
     */
    protected function setUp(): void
    {
        foreach (self::CACHE_STATICS as $name) {
            $property                      = new \ReflectionProperty(Health::class, $name);
            $this->cacheStateBackup[$name] = $property->getValue();
        }

        ( new \ReflectionProperty(Health::class, 'cacheInitialized') )->setValue(null, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->cacheStateBackup as $name => $value) {
            ( new \ReflectionProperty(Health::class, $name) )->setValue(null, $value);
        }
        $this->cacheStateBackup = [];
    }

    /**
     * A Ratchet connection carrying an upgrade request, the way `WsServer` leaves one.
     *
     * `httpRequest` and `resourceId` are dynamic public properties Ratchet assigns and neither is
     * part of `ConnectionInterface`, so this is a stub rather than a PHPUnit mock — same convention
     * as `HealthHelloFrameTest`.
     */
    private static function createStubConnection(int $resourceId, ?Request $httpRequest = null): ConnectionInterface
    {
        return new class ($resourceId, $httpRequest) implements ConnectionInterface {
            /** @var list<string> */
            public array $sent = [];

            public function __construct(public int $resourceId, public ?Request $httpRequest)
            {
            }

            public function send($data)
            {
                $this->sent[] = (string) $data;

                return $this;
            }

            public function close()
            {
            }
        };
    }

    private function resolver(): WsCallerResolver
    {
        return new WsCallerResolver(new JwtService(self::SECRET), null, null);
    }

    /**
     * @param array<int, string> $roles
     */
    private function requestWithToken(array $roles): Request
    {
        $token = ( new JwtService(self::SECRET) )->generate('someone', ['roles' => $roles]);

        return new Request('GET', '/', ['Cookie' => 'litcal_access_token=' . $token]);
    }

    /**
     * Open a connection and return the `hello` frame it was sent.
     */
    private function helloFrameFor(ConnectionInterface $conn): \stdClass
    {
        $health = $this->newHealth($this->resolver());

        ob_start();
        $health->onOpen($conn);
        ob_end_clean();

        /** @var object{sent: list<string>} $conn */
        self::assertNotEmpty($conn->sent, 'a connecting client was sent nothing at all');

        $frame = json_decode($conn->sent[0]);
        self::assertInstanceOf(\stdClass::class, $frame, 'the first frame is not a JSON object');

        return $frame;
    }

    public function testAConnectionWithNoCookieIsAdvertisedAsAnonymousAndUnpermitted(): void
    {
        $frame = $this->helloFrameFor(self::createStubConnection(1));

        $this->assertObjectHasProperty('caller', $frame);
        $this->assertFalse($frame->caller->authenticated);
        $this->assertFalse($frame->caller->permissions->runTests);
    }

    public function testATestEditorCookieIsAdvertisedAsPermitted(): void
    {
        $conn  = self::createStubConnection(2, $this->requestWithToken(['test_editor']));
        $frame = $this->helloFrameFor($conn);

        $this->assertTrue($frame->caller->authenticated);
        $this->assertTrue($frame->caller->permissions->runTests);
    }

    public function testAnAdminCookieIsAdvertisedAsPermitted(): void
    {
        $conn  = self::createStubConnection(3, $this->requestWithToken(['admin']));
        $frame = $this->helloFrameFor($conn);

        $this->assertTrue($frame->caller->permissions->runTests);
    }

    public function testARoleThatCannotRunIsAuthenticatedButUnpermitted(): void
    {
        $conn  = self::createStubConnection(4, $this->requestWithToken(['developer']));
        $frame = $this->helloFrameFor($conn);

        $this->assertTrue($frame->caller->authenticated, 'the credential was valid');
        $this->assertFalse($frame->caller->permissions->runTests, 'but the role may not run');
    }

    /**
     * The advertisement must be the policy's answer, not a second opinion. A stub that inverts the
     * verdict has to move the frame with it.
     */
    public function testTheAdvertisementComesFromThePolicy(): void
    {
        $policy = new class extends \LiturgicalCalendar\Api\Services\TestRunPolicy {
            public function mayRun(
                WsCaller $caller,
                ?\LiturgicalCalendar\Api\Models\Auth\TestTarget $target = null
            ): bool {
                return true;
            }
        };

        $health = $this->newHealth($this->resolver(), $policy);
        $conn   = self::createStubConnection(5);

        ob_start();
        $health->onOpen($conn);
        ob_end_clean();

        /** @var object{sent: list<string>} $conn */
        $frame = json_decode($conn->sent[0]);
        self::assertInstanceOf(\stdClass::class, $frame);

        $this->assertFalse($frame->caller->authenticated, 'no cookie was sent');
        $this->assertTrue($frame->caller->permissions->runTests, 'yet the policy said yes');
    }

    public function testCapabilitiesAreUnchangedAndDoNotCarryTheCaller(): void
    {
        $frame = $this->helloFrameFor(self::createStubConnection(6));

        $this->assertObjectHasProperty('capabilities', $frame);
        $this->assertObjectNotHasProperty('caller', $frame->capabilities);
        $this->assertSame(1, $frame->protocol, 'the contract version is unchanged by #894');
    }

    /**
     * Per-connection state has to be forgotten with the connection, like `runTokens` beside it.
     */
    public function testTheCallerIsForgottenWhenTheConnectionCloses(): void
    {
        $health = $this->newHealth($this->resolver());
        $conn   = self::createStubConnection(7, $this->requestWithToken(['admin']));

        ob_start();
        $health->onOpen($conn);
        $callers = ( new \ReflectionProperty(Health::class, 'callers') )->getValue($health);
        $this->assertArrayHasKey(7, $callers);

        $health->onClose($conn);
        ob_end_clean();

        $callers = ( new \ReflectionProperty(Health::class, 'callers') )->getValue($health);
        $this->assertArrayNotHasKey(7, $callers);
    }
}
