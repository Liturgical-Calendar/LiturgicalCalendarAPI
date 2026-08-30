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
    /** @var list<array{path: string, operation: ChangeOperation, content: ?string, base_sha: ?string}> */
    private array $staged = [];

    /**
     * @param array<string, mixed>       $oidcUser        The authenticated identity, from the
     *                                                     request's `oidc_user` attribute.
     * @param ?SourceDataPublishNotifier $publishNotifier Null is a quiet no-op, not a missing
     *                                                     dependency — see {@see commit()}.
     */
    public function __construct(
        private readonly SourceDataChangeRequestRepository $repository,
        private readonly ChangeRequestReview $review,
        private readonly array $oidcUser,
        private readonly ?string $projectRoot = null,
        private readonly ?SourceDataPublishNotifier $publishNotifier = null
    ) {
    }

    /**
     * `base_sha` is captured HERE and nowhere later, because this is the only moment that knows
     * what the edit was authored against: the file as it stands in the deployed working tree,
     * which is what every read path served the editor. It is a git BLOB sha
     * ({@see GitBlobSha}), directly comparable with the sha the same path carries in a GitHub
     * tree, so a rebase check can ask "did this file move underneath the proposal?" without
     * re-deriving anything.
     *
     * Null when there is no file there — an ordinary `create`, and also a file that so far
     * exists only as this submitter's queued work. Deliberately NOT the accumulation base's own
     * content: that content is the submitter's in-flight proposal, whose sha exists nowhere
     * upstream, so hashing it would make every accumulating chain read as permanently stale.
     * {@see SourceDataChangeRequestRepository::submitBatch()} overrides this value with the
     * accumulation ancestor's when there is one, for the mirror-image reason.
     */
    public function stage(string $absolutePath, ChangeOperation $operation, ?string $content): void
    {
        $this->staged[] = [
            'path'      => $this->repoRelativePath($absolutePath),
            'operation' => $operation,
            'content'   => $content,
            'base_sha'  => GitBlobSha::ofFile($absolutePath),
        ];
    }

    public function commit(ChangeResource $resource, bool $deletesResource = false): array
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

        // The supersede in submitBatch() keys on path, so any prior submitted row of this
        // submitter's that collides with an incoming path is cleared before the INSERT
        // (and anything else those batches held is carried forward onto the new batch)
        // — see SourceDataChangeRequestRepository's class docblock.
        // A 23505 here therefore means a genuine race: another request from the same
        // submitter, for one of the same paths, committed its own INSERT between this
        // DELETE and this INSERT. idx_scr_unique_pending_path_submitter is
        // defence-in-depth for exactly that race, not the primary guard, so surface it
        // as a 409 the client can retry rather than an opaque 500.
        $metadata = ['authorizing_relation' => 'admin'];
        if ($deletesResource) {
            // Read at merge time by MergePollRunner, which is the only moment that knows the
            // deletion actually happened. Written here because this is the only moment that
            // knows it was a resource deletion at all.
            $metadata['deletes_resource'] = true;
        }

        try {
            $submission = $this->repository->submitBatch(
                $resource,
                $this->staged,
                $sub,
                $name,
                $email,
                $emailVerified,
                $metadata
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
            // The auto-approval path is the COMMON one — an admin editing a resource they
            // administer — and it never reaches ChangeRequestAdminHandler. Announcing only there
            // would leave the most frequent approval waiting for the cron backstop.
            $this->publishNotifier?->notify($batchId);
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
                // A superseded batch id stops existing, so reporting the ids is what stops a batch
                // the client was tracking vanishing from GET /auth/change-requests unexplained.
                // They name batches FOLDED INTO this one, not work discarded: the rows this
                // request restages were replaced, and every other row those batches held was
                // carried forward onto this batch id.
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
