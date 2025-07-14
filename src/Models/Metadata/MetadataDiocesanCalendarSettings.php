<?php

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

class MetadataDiocesanCalendarSettings extends AbstractJsonRepresentation
{
    public ?Epiphany $epiphany;

    public ?Ascension $ascension;

    public ?CorpusChristi $corpus_christi;

    public ?bool $eternal_high_priest;

    public function __construct(
        ?string $epiphany = null,
        ?string $ascension = null,
        ?string $corpus_christi = null,
        ?bool $eternal_high_priest = null
    ) {
        $this->epiphany            = Epiphany::from($epiphany);
        $this->ascension           = Ascension::from($ascension);
        $this->corpus_christi      = CorpusChristi::from($corpus_christi);
        $this->eternal_high_priest = $eternal_high_priest;
    }

    /**
     * @inheritDoc
     * @return array{
     *      epiphany?: string,
     *      ascension?: string,
     *      corpus_christi?: string,
     *      eternal_high_priest?: bool
     * }
     */
    public function jsonSerialize(): array
    {
        $retArr = [];
        if ($this->epiphany) {
            $retArr['epiphany'] = $this->epiphany->value;
        }
        if ($this->ascension) {
            $retArr['ascension'] = $this->ascension->value;
        }
        if ($this->corpus_christi) {
            $retArr['corpus_christi'] = $this->corpus_christi->value;
        }
        if ($this->eternal_high_priest !== null) {
            $retArr['eternal_high_priest'] = $this->eternal_high_priest;
        }
        return $retArr;
    }

    /**
     * Creates an instance of DiocesanCalendarSettings from an associative array.
     *
     * @param array{
     *      epiphany?: string,
     *      ascension?: string,
     *      corpus_christi?: string,
     *      eternal_high_priest?: bool
     * } $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['epiphany'] ?? null,
            $data['ascension'] ?? null,
            $data['corpus_christi'] ?? null,
            $data['eternal_high_priest'] ?? null
        );
    }

    /**
     * Creates an instance of DiocesanCalendarSettings from a stdClass object.
     *
     * The object may have any of the following properties:
     * - epiphany (string|null): when Epiphany is celebrated
     * - ascension (string|null): when Ascension is celebrated
     * - corpus_christi (string|null): when Corpus Christi is celebrated
     * - eternal_high_priest (bool|null): whether the Eternal High Priest is celebrated
     *
     * @param \stdClass $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            $data->epiphany ?? null,
            $data->ascension ?? null,
            $data->corpus_christi ?? null,
            $data->eternal_high_priest ?? null
        );
    }
}
