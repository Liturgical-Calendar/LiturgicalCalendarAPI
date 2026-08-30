<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use InvalidArgumentException;
use LiturgicalCalendar\Api\Services\GitHub\GitHubApiException;
use RuntimeException;

/**
 * The single seam {@see PublishRunner} depends on.
 *
 * {@see SourceDataPublisher} is deliberately `final` (its own docblock explains why: the
 * author/committer split it implements is the entire point of the design, and that is not
 * something a subclass should be able to weaken). Its own test suite already exercises the
 * git-wire protocol end to end against a mocked Guzzle transport
 * ({@see \LiturgicalCalendar\Tests\Services\SourceData\SourceDataPublisherTest}). `PublishRunner`
 * only needs to exercise orchestration — claim, publish, record, release-and-stop-on-failure —
 * so it depends on this narrow interface instead of the concrete class, letting its own tests
 * use a lightweight fake rather than re-deriving that Guzzle wiring for no additional coverage.
 */
interface SourceDataPublisherInterface
{
    /**
     * Publish one approved batch and record the result on every row.
     *
     * @throws InvalidArgumentException If the batch does not exist (empty row set).
     * @throws RuntimeException         If the configured base branch does not exist on GitHub.
     * @throws GitHubApiException       If any GitHub call fails — in particular, a non-fast-forward
     *         `updateRef()` on a branch another publish landed on concurrently, which the caller
     *         is expected to retry.
     */
    public function publish(string $batchId): PublishResult;
}
