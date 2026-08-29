<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\ChangeResource;

/**
 * Records a reviewable proposal instead of writing files.
 *
 * Staging is separate from committing because one API request can produce
 * several files — a calendar plus its i18n catalogues — that must be approved or
 * rejected together, so they become one batch.
 */
final class ChangeRequestSourceDataWriter implements SourceDataWriter
{
    /** @var list<array{path: string, operation: ChangeOperation, content: ?string}> */
    private array $staged = [];

    /**
     * @param array<string, mixed> $oidcUser The authenticated identity, from the
     *                                       request's `oidc_user` attribute.
     */
    public function __construct(
        private readonly SourceDataChangeRequestRepository $repository,
        private readonly ChangeRequestReview $review,
        private readonly array $oidcUser,
        private readonly ?string $projectRoot = null
    ) {
    }

    public function stage(string $absolutePath, ChangeOperation $operation, ?string $content): void
    {
        $this->staged[] = [
            'path'      => $this->repoRelativePath($absolutePath),
            'operation' => $operation,
            'content'   => $content,
        ];
    }

    public function commit(ChangeResource $resource): array
    {
        if ($this->staged === []) {
            throw new \LogicException('commit() called with no staged files');
        }

        $sub = $this->submitterSub();

        // An unverified email must never become a git commit author email: anyone
        // able to set an address in Zitadel could otherwise forge authorship of a
        // third party in a public repository.
        $emailVerified = true === ( $this->oidcUser['email_verified'] ?? false );
        $email         = $emailVerified && is_string($this->oidcUser['email'] ?? null)
            ? $this->oidcUser['email']
            : null;
        $name          = is_string($this->oidcUser['name'] ?? null) ? $this->oidcUser['name'] : null;

        $batchId = $this->repository->submitBatch(
            $resource,
            $this->staged,
            $sub,
            $name,
            $email,
            $emailVerified,
            ['authorizing_relation' => 'admin']
        );

        $paths        = array_map(static fn (array $file): string => $file['path'], $this->staged);
        $this->staged = [];

        $autoApproved = $this->review->administers($resource, $sub);
        if ($autoApproved) {
            $this->repository->approveBatch($batchId, $sub);
        }

        return [
            'disposition'    => $autoApproved ? 'approved' : 'submitted',
            'change_request' => [
                'batch_id'      => $batchId,
                'review_status' => $autoApproved ? 'approved' : 'submitted',
                'auto_approved' => $autoApproved,
                'resource'      => [
                    'type' => $resource->type,
                    'id'   => $resource->id,
                ],
                'paths'         => $paths,
            ],
        ];
    }

    /**
     * Strip the deployment root, so a path is stored the way GitHub addresses it
     * and is stable across `api/dev` and `api/vN`.
     */
    private function repoRelativePath(string $absolutePath): string
    {
        $root = $this->projectRoot ?? Router::$apiFilePath;

        return str_starts_with($absolutePath, $root)
            ? substr($absolutePath, strlen($root))
            : ltrim($absolutePath, '/');
    }

    private function submitterSub(): string
    {
        $sub = $this->oidcUser['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw new \LogicException('A change request cannot be submitted without an authenticated subject');
        }

        return $sub;
    }
}
