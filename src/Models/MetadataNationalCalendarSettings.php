<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\Epiphany;

class MetadataNationalCalendarSettings implements \JsonSerializable
{
    public readonly Epiphany $epiphany;
    public readonly Ascension $ascension;
    public readonly CorpusChristi $corpus_christi;
    public readonly bool $eternal_high_priest;

    public function __construct(
        string $epiphany,
        string $ascension,
        string $corpus_christi,
        bool $eternal_high_priest
    ) {
        $this->epiphany            = Epiphany::from($epiphany);
        $this->ascension           = Ascension::from($ascension);
        $this->corpus_christi      = CorpusChristi::from($corpus_christi);
        $this->eternal_high_priest = $eternal_high_priest;
    }

    /**
     * @inheritDoc
     *
     * @return array{
     *      epiphany: string,
     *      ascension: string,
     *      corpus_christi: string,
     *      eternal_high_priest: bool
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'epiphany'            => $this->epiphany->value,
            'ascension'           => $this->ascension->value,
            'corpus_christi'      => $this->corpus_christi->value,
            'eternal_high_priest' => $this->eternal_high_priest
        ];
    }

    /**
     * Creates an instance of NationalCalendarSettings from an associative array.
     *
     * The array should have the following keys:
     * - epiphany (string): when Epiphany is celebrated
     * - ascension (string): when Ascension is celebrated
     * - corpus_christi (string): when Corpus Christi is celebrated
     * - eternal_high_priest (bool): whether the Eternal High Priest is celebrated
     *
     * @param array{epiphany:string,ascension:string,corpus_christi:string,eternal_high_priest:bool} $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['epiphany'],
            $data['ascension'],
            $data['corpus_christi'],
            $data['eternal_high_priest']
        );
    }

    /**
     * Creates an instance of NationalCalendarSettings from a stdClass object.
     *
     * The object should have the following properties:
     * - epiphany (string): when Epiphany is celebrated
     * - ascension (string): when Ascension is celebrated
     * - corpus_christi (string): when Corpus Christi is celebrated
     * - eternal_high_priest (bool): whether the Eternal High Priest is celebrated
     *
     * @param \stdClass $data
     * @return self
     */
    public static function fromObject(\stdClass $data): self
    {
        return new self(
            $data->epiphany,
            $data->ascension,
            $data->corpus_christi,
            $data->eternal_high_priest
        );
    }
}
