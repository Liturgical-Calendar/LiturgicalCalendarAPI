<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use LiturgicalCalendar\Api\Services\SourceData\ChangeRequestSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublishNotifier;
use LiturgicalCalendar\Tests\Support\CollectingLogger;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Drives ChangeRequestSourceDataWriter with the queue-mode collaborators a handler
 * would give it.
 *
 * ChangeRequestReview and ResourceAdminService are both final, so auto-approval is
 * steered by a queued OpenFGA response rather than by stubbing a class — the same
 * seam ResourceAdminServiceTest uses. The writer is rebuilt on demand so a test can
 * change the answer between calls.
 */
final class ChangeRequestTraitHost
{
    /** @var array<string, mixed> */
    private array $oidcUser = [];

    private bool $administers = false;

    private ?ChangeRequestSourceDataWriter $writer = null;

    private ?SourceDataPublishNotifier $publishNotifier = null;

    private string $projectRoot = '/app/';

    public function __construct(private readonly SourceDataChangeRequestRepository $repository)
    {
    }

    /**
     * Point the writer at a real directory, so `stage()`'s `base_sha` capture has actual
     * files to hash. The default `/app/` is a path that does not exist, which is exactly
     * what every other test here wants: it relativises paths without any disk I/O, and
     * every staged row's `base_sha` comes out null.
     */
    public function setProjectRoot(string $projectRoot): void
    {
        $this->projectRoot = $projectRoot;
        $this->writer      = null;
    }

    /** @param array<string, mixed> $user */
    public function setSubmitter(array $user): void
    {
        $this->oidcUser = $user;
        $this->writer   = null;
    }

    public function setAdministers(bool $administers): void
    {
        $this->administers = $administers;
        $this->writer      = null;
    }

    /**
     * Inject the notifier the writer should forward to, the same way
     * {@see ChangeRequestAdminHandlerTest}'s `handler()` injects one — so a test can substitute a
     * recording subclass instead of touching Redis.
     */
    public function setPublishNotifier(SourceDataPublishNotifier $notifier): void
    {
        $this->publishNotifier = $notifier;
        $this->writer          = null;
    }

    public function stageFile(string $absolutePath, ChangeOperation $operation, ?string $content): void
    {
        $this->writer()->stage($absolutePath, $operation, $content);
    }

    /** @return array<string, mixed> */
    public function commitStagedFiles(ChangeResource $resource, bool $deletesResource = false): array
    {
        $result       = $this->writer()->commit($resource, $deletesResource);
        $this->writer = null;

        return $result;
    }

    private function writer(): ChangeRequestSourceDataWriter
    {
        return $this->writer ??= new ChangeRequestSourceDataWriter(
            $this->repository,
            new ChangeRequestReview(new ResourceAdminService($this->fgaAnswering($this->administers), new CollectingLogger())),
            $this->oidcUser,
            $this->projectRoot,
            $this->publishNotifier
        );
    }

    private function fgaAnswering(bool $allowed): OpenFgaClient
    {
        $responses = [new GuzzleResponse(200, [], json_encode(['allowed' => $allowed]))];
        $guzzle    = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);
        $psr17     = new Psr17Factory();

        return new OpenFgaClient(
            apiUrl: 'http://openfga.test',
            storeId: 'test-store',
            modelId: 'test-model',
            httpClient: $guzzle,
            requestFactory: $psr17,
            streamFactory: $psr17,
            apiToken: 'test-token'
        );
    }
}
