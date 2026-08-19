<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;

/**
 * One thing the API can be asked to validate: a source-data file, or a folder of i18n files.
 *
 * `$path` is the server-side location a check resolves against, and is deliberately absent from
 * the serialized form. Keeping filesystem paths off the wire is the whole point of `/validations`
 * (#806 step A): clients that hardcoded them had to be edited in lockstep with every layout
 * change, which is #737/UnitTestInterface#38, #795 and #800. The omission lives in this class
 * rather than in the handler so that a second caller cannot forget it.
 *
 * `$region` is `null` when the item applies to its whole rite, and an ISO nation code when it
 * applies only to that nation's calendar. This differs on purpose from
 * {@see \LiturgicalCalendar\Api\Enum\RomanMissal::produceMetadata()}, which reports `'VA'` for the
 * editiones typicae: `'VA'` is a nation code that only reads as "universal" because the General
 * Roman Calendar happens to be served under nation VA, and it would be false on Ambrosian items.
 */
final readonly class CheckableItem implements \JsonSerializable
{
    /**
     * @param 'file'|'folder' $kind
     * @param list<string> $steps
     */
    public function __construct(
        public string $id,
        public string $kind,
        public Rite $rite,
        public ?string $region,
        public string $label,
        public LitSchema $schema,
        public array $steps,
        public string $path
    ) {
    }

    /**
     * @return array{id:string,kind:string,rite:string,region:string|null,label:string,schema:string,steps:list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'id'     => $this->id,
            'kind'   => $this->kind,
            'rite'   => $this->rite->value,
            'region' => $this->region,
            'label'  => $this->label,
            // LitSchema values are '/Foo.json'; the wire carries the bare filename.
            'schema' => ltrim($this->schema->value, '/'),
            'steps'  => $this->steps
        ];
    }
}
