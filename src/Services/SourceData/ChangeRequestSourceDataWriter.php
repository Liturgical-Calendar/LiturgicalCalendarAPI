<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PDOException;

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

        // The supersede DELETE in submitBatch() keys on path, so any prior submitted
        // batch of this submitter's that collides with an incoming path is cleared
        // before the INSERT — see SourceDataChangeRequestRepository's class docblock.
        // A 23505 here therefore means a genuine race: another request from the same
        // submitter, for one of the same paths, committed its own INSERT between this
        // DELETE and this INSERT. idx_scr_unique_pending_path_submitter is
        // defence-in-depth for exactly that race, not the primary guard, so surface it
        // as a 409 the client can retry rather than an opaque 500.
        try {
            $submission = $this->repository->submitBatch(
                $resource,
                $this->staged,
                $sub,
                $name,
                $email,
                $emailVerified,
                ['authorizing_relation' => 'admin']
            );
        } catch (PDOException $e) {
            if ('23505' === $e->getCode()) {
                throw new ConflictException(
                    'Another submission for one of these files is already pending. Reload and try again.',
                    $e
                );
            }
            throw $e;
        }

        $batchId      = $submission['batch_id'];
        $paths        = array_map(static fn (array $file): string => $file['path'], $this->staged);
        $this->staged = [];

        $autoApproved = $this->review->administers($resource, $sub);
        if ($autoApproved) {
            $this->repository->approveBatch($batchId, $sub);
        }

        return [
            'disposition'    => $autoApproved ? 'approved' : 'submitted',
            'change_request' => [
                'batch_id'             => $batchId,
                'review_status'        => $autoApproved ? 'approved' : 'submitted',
                'auto_approved'        => $autoApproved,
                'resource'             => [
                    'type' => $resource->type,
                    'id'   => $resource->id,
                ],
                'paths'                => $paths,
                // Supersession deletes WHOLE batches, so this submission may have replaced a
                // still-submitted batch that also held files this request never mentioned. Reporting
                // the ids is what stops that being invisible: the client can look each one up
                // (they are gone from GET /auth/change-requests) and see what it swept up.
                'superseded_batch_ids' => $submission['superseded_batch_ids'],
            ],
        ];
    }

    /**
     * The submitter's own not-yet-published content for this path — submitted OR approved,
     * because phase 1 publishes neither — so a handler rebuilding an aggregate file
     * accumulates onto its previous proposal instead of discarding it.
     */
    public function unpublishedContent(string $absolutePath): ?string
    {
        return $this->repository->findUnpublishedContent(
            $this->repoRelativePath($absolutePath),
            $this->submitterSub()
        );
    }

    /**
     * @return list<string> Absolute paths, ascending.
     */
    public function unpublishedPathsUnder(string $absoluteFolder): array
    {
        $prefix = $this->repoRelativePath(rtrim($absoluteFolder, '/')) . '/';

        return array_map(
            fn (string $path): string => $this->absolutePathFor($path),
            $this->repository->findUnpublishedPathsUnder($prefix, $this->submitterSub())
        );
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

    /**
     * The inverse of {@see repoRelativePath()} for paths that came out of the database.
     *
     * Only exact for paths that were relativised against this same root, which every
     * stored path was — `stage()` is the only writer of the `path` column.
     */
    private function absolutePathFor(string $repoRelativePath): string
    {
        return ( $this->projectRoot ?? Router::$apiFilePath ) . $repoRelativePath;
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
