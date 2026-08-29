<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Concerns;

use LiturgicalCalendar\Api\Handlers\Concerns\ResolvesFgaClient;
use LiturgicalCalendar\Api\Handlers\Concerns\WritesSourceData;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriter;
use Psr\Log\LoggerInterface;

/**
 * Concrete host so the trait's protected methods can be exercised without a real
 * HTTP handler, mirroring {@see ResolvesOutboxToolingHost}.
 *
 * `WritesSourceData` calls `$this->getFgaClient()` on its queue-mode branch, so the
 * host pulls in {@see ResolvesFgaClient} exactly as every real write handler does.
 *
 * The logger setter is what makes the misconfiguration branch observable. A trait's
 * `private` members become private members of the USING class, so this class — and
 * only this class — may assign `$this->sourceDataWriteLogger`, which is why no test
 * seam had to be opened up in production code to reach it.
 */
class WritesSourceDataHost
{
    use ResolvesFgaClient;
    use WritesSourceData;

    public function setSourceDataWriteLogger(LoggerInterface $logger): void
    {
        $this->sourceDataWriteLogger = $logger;
    }

    public function callSourceDataWriter(): SourceDataWriter
    {
        return $this->sourceDataWriter();
    }

    public function callPendingSourceContent(string $absolutePath): ?string
    {
        return $this->pendingSourceContent($absolutePath);
    }

    /** @return list<string> */
    public function callPendingSourcePathsUnder(string $absoluteFolder): array
    {
        return $this->pendingSourcePathsUnder($absoluteFolder);
    }
}
