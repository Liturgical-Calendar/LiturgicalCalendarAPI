<?php

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

final class MetadataDiocesanCalendarSettings extends AbstractJsonRepresentation implements \IteratorAggregate
{
    public readonly ?Epiphany $epiphany;

    public readonly ?Ascension $ascension;

    public readonly ?CorpusChristi $corpus_christi;

    public function __construct(
        ?Epiphany $epiphany            = null,
        ?Ascension $ascension          = null,
        ?CorpusChristi $corpus_christi = null
    ) {
        $this->epiphany       = $epiphany;
        $this->ascension      = $ascension;
        $this->corpus_christi = $corpus_christi;
    }

    /**
     * @inheritDoc
     * @return array{epiphany?:string,ascension?:string,corpus_christi?:string}
     */
    public function jsonSerialize(): array
    {
        $retArr = [];
        if ($this->epiphany !== null) {
            $retArr['epiphany'] = $this->epiphany->value;
        }
        if ($this->ascension !== null) {
            $retArr['ascension'] = $this->ascension->value;
        }
        if ($this->corpus_christi !== null) {
            $retArr['corpus_christi'] = $this->corpus_christi->value;
        }
        return $retArr;
    }

    /**
     * Creates an instance of DiocesanCalendarSettings from an associative array.
     *
     * @param array{epiphany?:string,ascension?:string,corpus_christi?:string} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            Epiphany::tryFrom($data['epiphany']) ?? null,
            Ascension::tryFrom($data['ascension']) ?? null,
            CorpusChristi::tryFrom($data['corpus_christi']) ?? null
        );
    }

    /**
     * Creates an instance of DiocesanCalendarSettings from a stdClass object.
     *
     * The object may have any of the following properties:
     * - epiphany (string|null): when Epiphany is celebrated
     * - ascension (string|null): when Ascension is celebrated
     * - corpus_christi (string|null): when Corpus Christi is celebrated
     *
     * @param \stdClass $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            Epiphany::tryFrom($data->epiphany) ?? null,
            Ascension::tryFrom($data->ascension) ?? null,
            CorpusChristi::tryFrom($data->corpus_christi) ?? null
        );
    }

    /**
     * Returns an iterator for the diocesan calendar settings.
     *
     * This method can be used to iterate over the settings for the diocesan calendar.
     * The iterator will yield the following key-value pairs:
     * - epiphany (Epiphany|null): when Epiphany is celebrated
     * - ascension (Ascension|null): when Ascension is celebrated
     * - corpus_christi (CorpusChristi|null): when Corpus Christi is celebrated
     *
     * @return \Traversable<string, Epiphany|Ascension|CorpusChristi|null> An iterator for the diocesan calendar settings.
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this);
    }
}
