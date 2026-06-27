<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * Pure mapper: a `deleter` tuple → the equivalent `admin` tuple (#668 folds
 * delete into admin). Non-`deleter` tuples map to null and are skipped.
 */
final class DeleterTupleMapper
{
    /**
     * @param array{user: string, relation: string, object: string} $tuple
     * @return array{user: string, relation: string, object: string}|null
     */
    public function mapTuple(array $tuple): ?array
    {
        if ($tuple['relation'] !== 'deleter') {
            return null;
        }
        return ['user' => $tuple['user'], 'relation' => 'admin', 'object' => $tuple['object']];
    }
}
