<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarItem;
use LiturgicalCalendar\Api\Services\CalendarMetadataProvider;

/**
 * This class encapsulates the parameters that can be passed to the Events endpoint.
 *
 * The parameters are:
 * - year: the year for which to retrieve the events
 * - locale: the language in which to retrieve the events
 * - national_calendar: the national calendar to use for the calculation
 * - diocesan_calendar: the diocesan calendar to use for the calculation
 * - eternal_high_priest: whether to include the eternal high priest in the events
 *
 * The class also provides a way to retrieve the last error message set by the class,
 * as well as to check if the parameters are valid.
 */
class EventsParams implements ParamsInterface
{
    public int $Year;
    public string $Locale;
    public string $baseLocale;
    public bool $EternalHighPriest   = false;
    public ?string $NationalCalendar = null;
    public ?string $DiocesanCalendar = null;
    public Rite $Rite                = Rite::ROMAN;

    /**
     * True when a `national_calendar=VA` filter was supplied on this request.
     * VA normalizes `$NationalCalendar` back to null (it selects the General Roman
     * Calendar, which has no national override), so this marker preserves the fact
     * that a national filter WAS requested — needed to reject an Ambrosian request
     * that (nonsensically) also asks for the VA national calendar.
     */
    private bool $vaNationalRequested = false;

    public readonly MetadataCalendars $calendarsMetadata;

    public const ALLOWED_PARAMS = [
        'eternal_high_priest',
        'locale',
        'national_calendar',
        'diocesan_calendar'
    ];

    // If we can get more data from 1582 (year of the Gregorian reform) to 1969
    //  perhaps we can lower the limit to the year of the Gregorian reform
    //  public const YEAR_LOWER_LIMIT          = 1583;
    // For now we'll just deal with the Liturgical Calendar from the Editio Typica 1970
    public const YEAR_LOWER_LIMIT = 1970;

    //The upper limit is determined by the limit of PHP in dealing with DateTime objects
    public const YEAR_UPPER_LIMIT = 9999;

    /**
     * Constructor for EventsParams
     *
     * @param array{
     *      locale?: string,
     *      national_calendar?: string,
     *      diocesan_calendar?: string,
     *      eternal_high_priest?: bool
     * } $params An associative array of parameter keys to values.
     *
     * The constructor sets a default value for the Year parameter, defaulting to current year
     * and for the Locale parameter, defaulting to latin.
     *
     * Calls the setParams method to apply the values from $params to the corresponding properties.
     */
    public function __construct($params = [])
    {
        // Build the calendars metadata index in-process from local source data
        // (single source of truth) instead of looping back through GET /calendars.
        $this->calendarsMetadata = CalendarMetadataProvider::create();

        // We need at least a default value for the current year and for the locale
        //   (which we already took from the request headers). The Latin defaults
        //   match the documented contract and guard against Locale/baseLocale
        //   being left uninitialized when setParams() receives no locale.
        $this->Year       = (int) date('Y');
        $this->Locale     = LitLocale::LATIN;
        $this->baseLocale = LitLocale::LATIN_PRIMARY_LANGUAGE;
        $this->setParams($params);
    }

    /**
     * Set the parameters for the Events class using the provided associative array of values.
     *
     * The array keys should be one of the following:
     * - locale: the language in which to retrieve the events
     * - national_calendar: the national calendar to use for the calculation
     * - diocesan_calendar: the diocesan calendar to use for the calculation
     * - eternal_high_priest: whether to include the eternal high priest in the events
     *
     * All parameters are optional, and default values will be used if they are not provided.
     * @param array{
     *      locale?: string,
     *      national_calendar?: string,
     *      diocesan_calendar?: string,
     *      eternal_high_priest?: bool
     * } $params An associative array of parameter keys to values.
     */
    public function setParams(array $params = []): void
    {
        if (count($params) === 0) {
            // If no parameters are provided, we can just return
            return;
        }

        // national_calendar=VA selects the General Roman Calendar and forces
        // locale=la_VA + eternal_high_priest=false. Defer those writes until
        // after the loop so they're not overwritten by sibling iterations
        // regardless of the order the caller passed the keys in
        // (foreach iterates a snapshot, so mutating $params mid-loop is a no-op).
        $forceVaInvariants = false;

        foreach ($params as $key => $value) {
            if (in_array($key, self::ALLOWED_PARAMS)) {
                // The string-typed params must be validated as strings up front so
                // a non-string payload (e.g. ?locale[]=en in a POST body) is a 400
                // ValidationException rather than a 500 TypeError from trim() /
                // \Locale::canonicalize() / isValid*() / strtoupper().
                if (in_array($key, ['locale', 'national_calendar', 'diocesan_calendar'], true)) {
                    $value = $this->validateStringValue($key, $value);
                }
                switch ($key) {
                    case 'locale':
                        // Reject empty/whitespace-only locales explicitly. Otherwise
                        // \Locale::canonicalize('') resolves to the ambient ICU default
                        // (\Locale::getDefault()), which a prior request in the same
                        // worker may have mutated via \Locale::setDefault() — making an
                        // empty locale param non-deterministically "valid".
                        if (trim($value) === '') {
                            throw new ValidationException('Invalid empty value for param `locale`');
                        }
                        $locale = \Locale::canonicalize($value);
                        if (null === $locale) {
                            throw new ValidationException('Invalid locale string: ' . $value);
                        }

                        if (false === LitLocale::isValid($locale)) {
                            $description = "Invalid value `$locale` for param `locale`, valid values are: la, la_VA, "
                                . implode(', ', LitLocale::$AllAvailableLocales);
                            throw new ValidationException($description);
                        }
                        $this->Locale = $locale;
                        $baseLocale   = \Locale::getPrimaryLanguage($this->Locale);
                        if (null === $baseLocale) {
                            $description = '“The evil spirit had bound his tongue, and together with his tongue had fettered his soul.” — St. John Chrysostom, Homily 32 on Matthew';
                            throw new ValidationException($description);
                        }
                        $this->baseLocale = $baseLocale;
                        break;
                    case 'national_calendar':
                        if (false === $this->isValidNationalCalendar($value)) {
                            $description = "Unknown value `$value` for nation parameter, supported national calendars are: ["
                                . implode(',', $this->calendarsMetadata->national_calendars_keys) . ']';
                            throw new ValidationException($description);
                        }
                        if ($value === 'VA') {
                            $forceVaInvariants         = true;
                            $this->vaNationalRequested = true;
                        } else {
                            $this->NationalCalendar = strtoupper($value);
                        }
                        break;
                    case 'diocesan_calendar':
                        if (false === $this->isValidDiocesanCalendar($value)) {
                            $description = "unknown value `$value` for diocese parameter, supported diocesan calendars are: ["
                                . implode(',', $this->calendarsMetadata->diocesan_calendars_keys) . ']';
                            throw new ValidationException($description);
                        }
                        $this->DiocesanCalendar = $value;
                        break;
                    case 'eternal_high_priest':
                        $filteredBoolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if (null === $filteredBoolValue) {
                            $description = "Invalid value `$value` for eternal_high_priest parameter, must be boolean";
                            throw new ValidationException($description);
                        }
                        $this->EternalHighPriest = $filteredBoolValue;
                        break;
                }
            }
        }

        if ($forceVaInvariants) {
            $this->Locale            = LitLocale::LATIN;
            $this->baseLocale        = LitLocale::LATIN_PRIMARY_LANGUAGE;
            $this->EternalHighPriest = false;
            // Clear any prior non-VA national override so a second setParams()
            // call that switches to VA fully resets the request shape.
            $this->NationalCalendar = null;
        }
    }

    /**
     * Assert that a string-typed request parameter value really is a string.
     *
     * Rejects non-string input (e.g. an array from `?locale[]=en` in a POST body)
     * with a ValidationException (400) so it never reaches string-only operations
     * — trim(), \Locale::canonicalize(), isValid*(), strtoupper() — as a
     * TypeError (500). Empty/whitespace handling and metadata validation remain
     * the responsibility of each param's own branch (which produce clearer,
     * param-specific messages), so this guard deliberately does not reject or
     * mutate the string contents.
     *
     * @param string $key   The parameter name (for the error message).
     * @param mixed  $value The raw request value.
     * @return string The validated string value.
     */
    private function validateStringValue(string $key, mixed $value): string
    {
        if (!is_string($value)) {
            throw new ValidationException("Expected value of type String for parameter `{$key}`, instead found type " . gettype($value));
        }

        return $value;
    }

    private function isValidNationalCalendar(string $calendar): bool
    {
        return in_array($calendar, $this->calendarsMetadata->national_calendars_keys);
    }

    private function isValidDiocesanCalendar(string $calendar): bool
    {
        return in_array($calendar, $this->calendarsMetadata->diocesan_calendars_keys);
    }

    /**
     * Sets the liturgical rite for which the events catalog should be built.
     *
     * @param Rite $rite the liturgical rite (ROMAN or AMBROSIAN)
     */
    public function setRite(Rite $rite): void
    {
        $this->Rite = $rite;
    }

    /**
     * Cross-field validation of the rite against the requested calendar. The Ambrosian
     * rite has no national layer — a national calendar request combined with the
     * Ambrosian rite is rejected. Diocese/rite mismatches (e.g. requesting a Roman
     * diocese under the Ambrosian rite, or an Ambrosian diocese under the Roman rite)
     * are rejected regardless of which rite was requested, rite-scoped against the
     * diocese's declared `rite` metadata rather than a hardcoded whitelist — mirroring
     * {@see \LiturgicalCalendar\Api\Params\CalendarParams::validateDiocesanCalendarParam()}.
     * A diocese whose declared rite matches the requested rite (Roman-under-Roman, or
     * Ambrosian-under-Ambrosian, e.g. `/events/ambrosian/diocese/milano_it`) is allowed.
     * A `locale` the rite has no liturgical books for is likewise rejected (issue #761).
     * Mirrors {@see \LiturgicalCalendar\Api\Params\CalendarParams::validateRiteCompatibility()},
     * minus the year-floor check (the events catalog is year-agnostic). Must be called
     * after the rite, locale, and any `national_calendar`/`diocesan_calendar` parameters
     * have been set.
     *
     * @throws ValidationException
     */
    public function validateRiteCompatibility(): void
    {
        if ($this->DiocesanCalendar !== null) {
            $dioceseItem = array_find(
                $this->calendarsMetadata->diocesan_calendars,
                fn (MetadataDiocesanCalendarItem $item): bool => $item->calendar_id === $this->DiocesanCalendar
            );

            if (null !== $dioceseItem && $dioceseItem->rite !== $this->Rite) {
                throw new ValidationException(
                    "Diocesan calendar `{$this->DiocesanCalendar}` belongs to the {$dioceseItem->rite->value} rite, not the requested {$this->Rite->value} rite."
                );
            }
        }

        if ($this->Rite === Rite::ROMAN) {
            return;
        }

        if ($this->NationalCalendar !== null || $this->vaNationalRequested) {
            throw new ValidationException(
                'The Ambrosian rite has no national calendars; request the comune ambrosiano events catalog (`/events/ambrosian`) or one of its dioceses.'
            );
        }

        // Rite-scoped locale check: the loop in setParams() can only validate against the
        // API-wide LitLocale set, since it runs before the rite is known. As on the
        // calendar endpoint, only an explicit `locale` parameter is rejected here — an
        // Accept-Language header is negotiated against the same set by
        // EventsHandler::handle() before it becomes a parameter, so it degrades to Latin
        // instead of failing the request.
        if (false === CalendarMetadataProvider::riteSupportsLocale($this->Rite, $this->Locale)) {
            throw new ValidationException(sprintf(
                'Invalid value `%s` for param `locale`: the `%s` rite has liturgical books only in: %s.',
                $this->Locale,
                $this->Rite->value,
                implode(', ', CalendarMetadataProvider::localesForRite($this->Rite))
            ));
        }
    }
}
