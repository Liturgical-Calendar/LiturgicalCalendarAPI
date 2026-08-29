<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use LiturgicalCalendar\Tests\Support\CollectingLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeRequestReview::class)]
final class ChangeRequestReviewTest extends TestCase
{
    /** @param array<int, GuzzleResponse> $responses */
    private function reviewWith(array $responses): ChangeRequestReview
    {
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);
        $psr17  = new Psr17Factory();
        $client = new OpenFgaClient(
            apiUrl: 'http://openfga.test',
            storeId: 'test-store',
            modelId: 'test-model',
            httpClient: $guzzle,
            requestFactory: $psr17,
            streamFactory: $psr17,
            apiToken: 'test-token'
        );

        return new ChangeRequestReview(new ResourceAdminService($client, new CollectingLogger()));
    }

    private static function allowed(bool $allowed): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode(['allowed' => $allowed]));
    }

    public function testAdministersIsTrueWhenOpenFgaAllowsTheAdminRelation(): void
    {
        $review = $this->reviewWith([self::allowed(true)]);

        self::assertTrue($review->administers(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'), 'admin-1'));
    }

    public function testAdministersIsFalseWhenOpenFgaDenies(): void
    {
        $review = $this->reviewWith([self::allowed(false)]);

        self::assertFalse($review->administers(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'), 'editor-1'));
    }

    public function testAdministersFailsClosedWhenOpenFgaIsUnreachable(): void
    {
        $review = $this->reviewWith([new GuzzleResponse(500, [], 'boom')]);

        self::assertFalse($review->administers(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'), 'admin-1'));
    }

    public function testFilterForAdminKeepsOnlyAdministeredBatches(): void
    {
        $review = $this->reviewWith([self::allowed(true), self::allowed(false)]);

        $batches = [
            ['batch_id' => 'b1', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'roman/US', 'relation' => 'admin']]],
            ['batch_id' => 'b2', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'roman/IT', 'relation' => 'admin']]],
        ];

        $kept = $review->filterForAdmin($batches, 'admin-1');

        self::assertCount(1, $kept);
        self::assertSame('b1', $kept[0]['batch_id']);
    }
}
