<?php

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

/**
 * @phpstan-type NationalCalendarSettingsObject \stdClass&object{
 *      epiphany:string,
 *      ascension:string,
 *      corpus_christi:string,
 *      eternal_high_priest:bool,
 *      holydays_of_obligation:\stdClass&object<string,bool>
 * }
 * @phpstan-type NationalCalendarSettingsArray array{
 *      epiphany:string,
 *      ascension:string,
 *      corpus_christi:string,
 *      eternal_high_priest:bool,
 *      holydays_of_obligation:array<string,bool>
 * }
 */
final class MetadataNationalCalendarSettings extends AbstractJsonRepresentation
{
    public Epiphany $epiphany;

    public Ascension $ascension;

    public CorpusChristi $corpus_christi;

    public bool $eternal_high_priest;

    /** @var array<string,bool> */
    public array $holydays_of_obligation = [];

    /**
     * Creates a new MetadataNationalCalendarSettings object.
     *
     * The provided arguments must be valid. The object will be created with the provided values.
     *
     * @param string $epiphany When Epiphany is celebrated
     * @param string $ascension When Ascension is celebrated
     * @param string $corpus_christi When Corpus Christi is celebrated
     * @param bool $eternal_high_priest Whether the Eternal High Priest is celebrated
     * @param array<string,bool> $holydays_of_obligation An array of holydays of obligation, where the keys are the `event_key`s of the holydays and the values are booleans indicating whether they are observed as holydays of obligation.
     */
    private function __construct(
        string $epiphany,
        string $ascension,
        string $corpus_christi,
        bool $eternal_high_priest,
        array $holydays_of_obligation = []
    ) {
        $this->epiphany               = Epiphany::from($epiphany);
        $this->ascension              = Ascension::from($ascension);
        $this->corpus_christi         = CorpusChristi::from($corpus_christi);
        $this->eternal_high_priest    = $eternal_high_priest;
        $this->holydays_of_obligation = $holydays_of_obligation;
    }

    /**
     * {@inheritDoc}
     *
     * @return array{epiphany:string,ascension:string,corpus_christi:string,eternal_high_priest:bool,holydays_of_obligation:string[]}
     */
    public function jsonSerialize(): array
    {
        return [
            'epiphany'               => $this->epiphany->value,
            'ascension'              => $this->ascension->value,
            'corpus_christi'         => $this->corpus_christi->value,
            'eternal_high_priest'    => $this->eternal_high_priest,
            'holydays_of_obligation' => $this->holydays_of_obligation
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
     * - holydays_of_obligation (array): an array of holydays of obligation
     *
     * @param NationalCalendarSettingsArray $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        /** @var array<string,bool> $holydays_of_obligation */
        $holydays_of_obligation = [];
        if (array_key_exists('holydays_of_obligation', $data)) {
            if (!is_array($data['holydays_of_obligation'])) {
                throw new \ValueError('Invalid type for holydays_of_obligation: expected an array of event_key strings, got ' . gettype($data['holydays_of_obligation']) . ' instead, with value: ' . print_r($data['holydays_of_obligation'], true));
            }
            foreach ($data['holydays_of_obligation'] as $key => $value) {
                if (!is_string($key) || $key === '') {
                    throw new \ValueError('Invalid key in holydays_of_obligation: expected a non-empty string');
                }
                if (!is_bool($value)) {
                    throw new \ValueError('Invalid value for holydays_of_obligation[' . $key . ']: expected a boolean');
                }
                $holydays_of_obligation[$key] = $value;
            }
        }

        return new static(
            $data['epiphany'],
            $data['ascension'],
            $data['corpus_christi'],
            $data['eternal_high_priest'],
            $holydays_of_obligation
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
     * - holydays_of_obligation (object): an object where keys are the `event_key`s of the holy days and values are booleans indicating whether they are observed as holy days of obligation.
     *
     * @param NationalCalendarSettingsObject $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        /** @var array<string,bool> $holydays_of_obligation */
        $holydays_of_obligation = [];
        if (property_exists($data, 'holydays_of_obligation')) {
            if (!is_object($data->holydays_of_obligation)) {
                throw new \ValueError('Invalid type for holydays_of_obligation: expected an object, got ' . gettype($data->holydays_of_obligation) . ' instead, with value: ' . print_r($data->holydays_of_obligation, true));
            }
            foreach ((array) $data->holydays_of_obligation as $key => $value) {
                if (!is_string($key) || $key === '') {
                    throw new \ValueError('Invalid key in holydays_of_obligation: expected a non-empty string');
                }
                if (!is_bool($value)) {
                    throw new \ValueError('Invalid value for holydays_of_obligation[' . $key . ']: expected a boolean');
                }
                $holydays_of_obligation[$key] = $value;
            }
        }

        return new static(
            $data->epiphany,
            $data->ascension,
            $data->corpus_christi,
            $data->eternal_high_priest,
            $holydays_of_obligation
        );
    }
}
