<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use InvalidArgumentException;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use RuntimeException;

/**
 * One approved change-request batch, flattened and type-narrowed from
 * {@see \LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository::getBatch()}'s
 * `array<string, mixed>` rows into what {@see SourceDataPublisher} needs to build a commit.
 *
 * Every row in a batch shares one resource and one submitter — they are written and
 * transitioned together, exactly as
 * {@see \LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository::hydrateBatch()}
 * already assumes when it collapses a batch with `MIN()` — so those fields are read once, off
 * the first row, rather than re-validated as agreeing across every row.
 */
final readonly class PublishablePayload
{
    /**
     * @param list<array{path: string, operation: ChangeOperation, content: ?string}> $files
     *        Ordered exactly as `getBatch()` returned them (by path). A `delete` row's
     *        `content` is null; every other operation's content is guaranteed non-null by
     *        {@see fromBatchRows()}.
     */
    private function __construct(
        public string $resourceType,
        public string $resourceId,
        public ?string $submittedByName,
        public ?string $submittedByEmail,
        public bool $submittedByEmailVerified,
        public array $files
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows One batch's rows, as returned by
     *                                                `SourceDataChangeRequestRepository::getBatch()`.
     * @throws InvalidArgumentException If `$rows` is empty — there is nothing to publish.
     * @throws RuntimeException         If a row is missing a field this class relies on, or has
     *                                  the wrong type for it — a schema/query mismatch, not a
     *                                  reachable data state.
     */
    public static function fromBatchRows(array $rows): self
    {
        if ($rows === []) {
            throw new InvalidArgumentException('Cannot build a PublishablePayload from an empty batch');
        }

        $first = $rows[0];

        $files = array_values(array_map(
            static function (array $row): array {
                $operation = ChangeOperation::from(self::requireString($row, 'operation'));
                $content   = self::optionalString($row, 'content');

                // No aggregate file is ever staged for deletion (they are rewritten in
                // place, never removed) — see the repository's findUnpublishedContent()
                // docblock — so a non-delete row with no content is a data-integrity
                // violation, not a state this class should paper over.
                if ($operation !== ChangeOperation::DELETE && $content === null) {
                    throw new RuntimeException(sprintf(
                        'Change request row for path "%s" has operation "%s" but no content',
                        self::requireString($row, 'path'),
                        $operation->value
                    ));
                }

                return [
                    'path'      => self::requireString($row, 'path'),
                    'operation' => $operation,
                    'content'   => $content,
                ];
            },
            $rows
        ));

        return new self(
            self::requireString($first, 'resource_type'),
            self::requireString($first, 'resource_id'),
            self::optionalString($first, 'submitted_by_name'),
            self::optionalString($first, 'submitted_by_email'),
            self::requireBool($first, 'submitted_by_email_verified'),
            $files
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function requireString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(
                'Expected change request column "%s" to be a non-empty string, got %s',
                $key,
                get_debug_type($value)
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function optionalString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected change request column "%s" to be a string or null, got %s',
                $key,
                get_debug_type($value)
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function requireBool(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (!is_bool($value)) {
            throw new RuntimeException(sprintf(
                'Expected change request column "%s" to be a bool, got %s',
                $key,
                get_debug_type($value)
            ));
        }

        return $value;
    }
}
