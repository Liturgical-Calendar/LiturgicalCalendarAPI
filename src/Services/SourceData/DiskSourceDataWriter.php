<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Services\ChangeResource;

/**
 * Writes source data to disk — the behaviour every deployment had before change
 * requests existed, and the behaviour a self-hosted instance without Postgres
 * and OpenFGA keeps.
 *
 * The logic here is lifted from RegionalDataHandler, DecreesHandler and
 * TestsHandler rather than rewritten: same `file_put_contents()`, same
 * `unlink()`, same ServiceUnavailableException on failure.
 *
 * Staging is deferred rather than immediate so that both implementations share
 * one contract, and so a request that fails validation half way through has not
 * already half-written the calendar.
 */
final class DiskSourceDataWriter implements SourceDataWriter
{
    /** @var list<array{path: string, operation: ChangeOperation, content: ?string}> */
    private array $staged = [];

    public function stage(string $absolutePath, ChangeOperation $operation, ?string $content): void
    {
        $this->staged[] = [
            'path'      => $absolutePath,
            'operation' => $operation,
            'content'   => $content,
        ];
    }

    public function commit(ChangeResource $resource): array
    {
        $staged       = $this->staged;
        $this->staged = [];

        foreach ($staged as $file) {
            if ($file['operation'] === ChangeOperation::DELETE) {
                $this->removeFile($file['path']);
                continue;
            }

            $this->writeFile($file['path'], (string) $file['content']);
        }

        return ['disposition' => 'applied'];
    }

    private function writeFile(string $path, string $content): void
    {
        if (false === file_put_contents($path, $content)) {
            throw new ServiceUnavailableException('Failed to write to file ' . $path);
        }
    }

    /**
     * A missing file is not an error: the previous handler code reached `unlink()`
     * only after its own existence checks, and re-deleting is idempotent from the
     * caller's point of view.
     */
    private function removeFile(string $path): void
    {
        if (false === file_exists($path)) {
            return;
        }

        if (false === unlink($path)) {
            throw new ServiceUnavailableException('Failed to delete file ' . $path);
        }
    }
}
