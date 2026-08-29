<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Locale;

use LiturgicalCalendar\Api\Enum\JsonDataConstants;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\SupportedLocales;

/**
 * Answers "does this locale have everything an official locale must have?".
 *
 * Promotion to officially supported is not a label — it changes behaviour.
 * `ReadingsMap::getReadings()` throws for an official locale and degrades quietly
 * for any other, so promoting a locale whose data is incomplete converts silent
 * gaps into 500s. This is the pre-flight that must pass first (#904).
 *
 * Three callers, one implementation:
 *
 * - the admin interface, gating the promote action
 * - CI, asserting every already-official locale is still complete
 * - `Health`, reporting drift on a running deployment
 *
 * Read-only: it inspects files and never writes.
 */
final class LocaleReadinessChecker
{
    /**
     * The ten General Roman lectionary corpora. A locale missing any one of them
     * cannot serve a complete calendar.
     *
     * @var list<string>
     */
    private const LECTIONARY_CORPORA = [
        JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FOLDER,
        JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_B_FOLDER,
        JsonDataConstants::LECTIONARY_SUNDAYS_SOLEMNITIES_C_FOLDER,
        JsonDataConstants::LECTIONARY_WEEKDAYS_ORDINARY_I_FOLDER,
        JsonDataConstants::LECTIONARY_WEEKDAYS_ORDINARY_II_FOLDER,
        JsonDataConstants::LECTIONARY_WEEKDAYS_ADVENT_FOLDER,
        JsonDataConstants::LECTIONARY_WEEKDAYS_CHRISTMAS_FOLDER,
        JsonDataConstants::LECTIONARY_WEEKDAYS_LENT_FOLDER,
        JsonDataConstants::LECTIONARY_WEEKDAYS_EASTER_FOLDER,
        JsonDataConstants::LECTIONARY_SAINTS_FOLDER,
    ];

    /**
     * Missals belonging to the universal calendar. The national editions
     * (`propriumdesanctis_IT_1983`, `propriumdesanctis_US_2011`) are deliberately
     * excluded: they are only ever read for their own nation's calendar, so
     * requiring every official locale to translate them would be wrong.
     *
     * @var list<string>
     */
    private const UNIVERSAL_MISSALS = [
        'propriumdetempore',
        'propriumdesanctis_1970',
        'propriumdesanctis_2002',
        'propriumdesanctis_2008',
    ];

    private string $root;

    public function __construct(?string $root = null)
    {
        // Router::$apiFilePath is a typed static that is only initialised while a
        // request is routed; this service also runs from CI and the CLI.
        $this->root = $root
            ?? ( isset(Router::$apiFilePath) && Router::$apiFilePath !== ''
                ? Router::$apiFilePath
                : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR );
    }

    /**
     * Run every probe against $locale.
     */
    public function check(string $locale): LocaleReadinessReport
    {
        return new LocaleReadinessReport(
            $locale,
            SupportedLocales::isOfficial($locale),
            [
                $this->checkGettextCatalogue($locale),
                $this->checkLectionaryCorpora($locale),
                $this->checkDecreeSource(),
                $this->checkDecreeReadings($locale, $this->createdEventKeys()),
                $this->checkDecreeNames($locale, $this->namedEventKeys()),
                $this->checkUniversalMissals($locale),
                $this->checkDecreeReadingsPopulated($locale, $this->createdEventKeys()),
            ]
        );
    }

    /**
     * Every locale for which any resource exists, official or not.
     *
     * The union of gettext catalogues, decrees i18n files and lectionary corpora,
     * so the admin interface can offer a promotion candidate list without an
     * operator having to know what is on disk. Sorted for stable presentation.
     *
     * @return list<string>
     */
    public function knownLocales(): array
    {
        $locales = SupportedLocales::official();

        $catalogues = glob($this->root . 'i18n/*', GLOB_ONLYDIR) ?: [];
        foreach ($catalogues as $dir) {
            $locales[] = basename($dir);
        }

        $globs = [
            $this->root . JsonDataConstants::DECREES_FOLDER . '/i18n/*.json',
            $this->root . JsonDataConstants::LECTIONARY_DECREES_FOLDER . '/*.json',
        ];
        foreach (self::LECTIONARY_CORPORA as $corpus) {
            $globs[] = $this->root . $corpus . '/*.json';
        }

        // Deliberately NOT the missals: the national editions carry region-specific
        // translations (`propriumdesanctis_US_2011/i18n/en_US.json`,
        // `propriumdesanctis_IT_1983/i18n/it_IT.json`), and surfacing en_US and it_IT
        // here would offer them as candidates for General Roman promotion, which they
        // are not. Universal-missal coverage is still probed per locale by
        // checkUniversalMissals(); it just does not define the candidate set.
        foreach ($globs as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $locales[] = basename($file, '.json');
            }
        }

        $locales = array_values(array_unique($locales));
        sort($locales);

        return $locales;
    }

    /**
     * Run every probe against every currently official locale.
     *
     * @return list<LocaleReadinessReport>
     */
    public function checkOfficialLocales(): array
    {
        return array_map(
            fn (string $locale): LocaleReadinessReport => $this->check($locale),
            SupportedLocales::official()
        );
    }

    private function checkGettextCatalogue(string $locale): LocaleReadinessCheck
    {
        // `en` is the source language: strings are authored in it, so it has no
        // catalogue of its own and never will.
        if ($locale === 'en') {
            return new LocaleReadinessCheck(
                'gettext_catalogue',
                true,
                'not required — en is the untranslated source language'
            );
        }

        $dir     = $this->root . JsonDataConstants::FOLDER . '/../i18n/' . $locale;
        $missing = is_dir($dir) ? [] : ['i18n/' . $locale];

        return LocaleReadinessCheck::of(
            'gettext_catalogue',
            $missing,
            'catalogue present',
            static fn (int $n): string => 'no gettext catalogue'
        );
    }

    private function checkLectionaryCorpora(string $locale): LocaleReadinessCheck
    {
        $missing = [];
        foreach (self::LECTIONARY_CORPORA as $corpus) {
            $relative = $corpus . '/' . $locale . '.json';
            if (!is_file($this->root . $relative)) {
                $missing[] = $relative;
            }
        }

        return LocaleReadinessCheck::of(
            'lectionary_corpora',
            $missing,
            sprintf('all %d corpora present', count(self::LECTIONARY_CORPORA)),
            static fn (int $n): string => sprintf('missing %d of %d lectionary corpora', $n, count(self::LECTIONARY_CORPORA))
        );
    }

    /**
     * Every decreed event must have a readings entry in this locale's decrees
     * lectionary — present, though its values may legitimately be empty.
     *
     * This is the exact gap that took down the Croatian calendar: the key being
     * absent is what threw, not the readings being blank.
     *
     * @param list<string> $decreedEventKeys
     */
    private function checkDecreeReadings(string $locale, array $decreedEventKeys): LocaleReadinessCheck
    {
        $relative = JsonDataConstants::LECTIONARY_DECREES_FOLDER . '/' . $locale . '.json';
        $present  = $this->jsonKeys($this->root . $relative);

        if ($present === null) {
            return LocaleReadinessCheck::of('decree_readings', [$relative], '', static fn (int $n): string => 'decrees lectionary file absent');
        }

        $missing = array_values(array_diff($decreedEventKeys, $present));

        return LocaleReadinessCheck::of(
            'decree_readings',
            $missing,
            sprintf('all %d newly created events have a readings entry', count($decreedEventKeys)),
            static fn (int $n): string => LocaleReadinessCheck::plural($n, 'newly created event has', 'newly created events have') . ' no readings entry'
        );
    }

    /**
     * Every decreed event must have a NON-EMPTY name in this locale.
     *
     * Unlike readings, a blank name is a real gap: it renders as an empty string
     * in the calendar. Croatian currently fails exactly here.
     *
     * @param list<string> $decreedEventKeys
     */
    private function checkDecreeNames(string $locale, array $decreedEventKeys): LocaleReadinessCheck
    {
        $relative = JsonDataConstants::DECREES_FOLDER . '/i18n/' . $locale . '.json';
        $path     = $this->root . $relative;

        if (!is_file($path)) {
            return LocaleReadinessCheck::of('decree_names', [$relative], '', static fn (int $n): string => 'decrees i18n file absent');
        }

        /** @var array<string, mixed> $names */
        $names   = $this->decodeArray($path);
        $missing = [];
        foreach ($decreedEventKeys as $key) {
            $value = $names[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                $missing[] = $key;
            }
        }

        return LocaleReadinessCheck::of(
            'decree_names',
            $missing,
            sprintf('all %d decreed events are named', count($decreedEventKeys)),
            static fn (int $n): string => LocaleReadinessCheck::plural($n, 'decreed event has', 'decreed events have') . ' no name'
        );
    }

    /**
     * How many decreed events have a readings entry that is present but BLANK.
     *
     * Advisory, not gating — and the distinction is load-bearing. These events do
     * not appear in `sanctorum` at all, so the decrees lectionary is their only
     * source of readings: a blank entry means the calendar serves none. By that
     * standard four of the five currently official locales are incomplete (fr, it
     * and nl have 10 of 11 blank, la has 9), and gating on it would fail them all
     * at once.
     *
     * Reported so the gap is visible in the admin interface and can be closed
     * deliberately. Promote this to gating (drop the `true`) once the content
     * exists, or the build goes red for locales the API already advertises.
     *
     * @param list<string> $createdEventKeys
     */
    private function checkDecreeReadingsPopulated(string $locale, array $createdEventKeys): LocaleReadinessCheck
    {
        $path = $this->root . JsonDataConstants::LECTIONARY_DECREES_FOLDER . '/' . $locale . '.json';
        if (!is_file($path)) {
            return LocaleReadinessCheck::of(
                'decree_readings_populated',
                [],
                'not applicable — no decrees lectionary for this locale',
                static fn (int $n): string => '',
                true
            );
        }

        /** @var array<string, mixed> $readings */
        $readings = $this->decodeArray($path);

        $blank = [];
        foreach ($createdEventKeys as $key) {
            $entry = $readings[$key] ?? null;
            if (!is_array($entry)) {
                continue; // absence is the gating check's business, not this one
            }

            $hasText = false;
            foreach ($entry as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $hasText = true;
                    break;
                }
            }

            if (false === $hasText) {
                $blank[] = $key;
            }
        }

        return LocaleReadinessCheck::of(
            'decree_readings_populated',
            $blank,
            sprintf('all %d newly created events have readings text', count($createdEventKeys)),
            static fn (int $n): string => LocaleReadinessCheck::plural($n, 'newly created event has', 'newly created events have')
                . ' an empty readings entry',
            true
        );
    }

    private function checkUniversalMissals(string $locale): LocaleReadinessCheck
    {
        $missing = [];
        foreach (self::UNIVERSAL_MISSALS as $missal) {
            $relative = JsonDataConstants::MISSALS_FOLDER . '/' . $missal . '/i18n/' . $locale . '.json';
            if (!is_file($this->root . $relative)) {
                $missing[] = $relative;
            }
        }

        return LocaleReadinessCheck::of(
            'universal_missals',
            $missing,
            sprintf('all %d universal missals translated', count(self::UNIVERSAL_MISSALS)),
            static fn (int $n): string => LocaleReadinessCheck::plural($n, 'universal missal is', 'universal missals are') . ' untranslated'
        );
    }

    /**
     * Whether the decree corpus itself can be read.
     *
     * Without this, a missing or unparseable `decrees.json` yields an empty event
     * list, and every downstream probe then passes vacuously — "all 0 newly created
     * events have a readings entry" — reporting the locale READY. A checker whose
     * entire purpose is to stop silent false passes must not contain one.
     *
     * Gating, and deliberately locale-independent: if the corpus is unreadable then
     * nothing about any locale has actually been verified.
     */
    private function checkDecreeSource(): LocaleReadinessCheck
    {
        $relative = JsonDataConstants::DECREES_FILE;

        if (!is_file($this->root . $relative)) {
            return LocaleReadinessCheck::of(
                'decree_source',
                [$relative],
                '',
                static fn (int $n): string => 'the decrees corpus is missing, so no decreed event could be checked'
            );
        }

        if (null === $this->decrees()) {
            return LocaleReadinessCheck::of(
                'decree_source',
                [$relative],
                '',
                static fn (int $n): string => 'the decrees corpus could not be parsed, so no decreed event could be checked'
            );
        }

        return LocaleReadinessCheck::of(
            'decree_source',
            [],
            'the decrees corpus is readable',
            static fn (int $n): string => ''
        );
    }

    /**
     * Event keys introduced by a decree, i.e. those with `action: createNew`.
     *
     * Only these need an entry in the decrees lectionary. A decree that merely
     * modifies an existing celebration — `setProperty` raising a memorial to a
     * feast, `makeDoctor` adding a title — leaves the event in the sanctorale,
     * where its readings already live. Requiring readings for those would flag
     * every official locale as incomplete for data that is correct.
     *
     * @return list<string>
     */
    private function createdEventKeys(): array
    {
        $keys = [];
        foreach ($this->decrees() ?? [] as $decree) {
            $metadata = $decree['metadata'] ?? null;
            if (!is_array($metadata) || ( $metadata['action'] ?? null ) !== 'createNew') {
                continue;
            }

            $event = $decree['liturgical_event'] ?? null;
            if (is_array($event) && is_string($event['event_key'] ?? null) && $event['event_key'] !== '') {
                $keys[] = $event['event_key'];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Event keys that the decrees i18n corpus names, taken as the union across
     * every locale file.
     *
     * Self-defining on purpose: which decrees supply a name is not derivable from
     * the action alone — `makeDoctor` adds a title and so needs one, while
     * `setProperty` raising a grade does not. Taking the union asserts the
     * invariant that actually matters: whatever any locale names, every official
     * locale must name too.
     *
     * @return list<string>
     */
    private function namedEventKeys(): array
    {
        $dir = $this->root . JsonDataConstants::DECREES_FOLDER . '/i18n';
        if (!is_dir($dir)) {
            return [];
        }

        $keys = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            foreach (array_keys($this->decodeArray($file)) as $key) {
                $keys[] = (string) $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The decree corpus, or null when it is absent or cannot be parsed.
     *
     * Nullable on purpose: "there are no decrees" and "the decrees cannot be read"
     * are different facts, and collapsing them is what let an unreadable corpus
     * report as ready. {@see checkDecreeSource()} turns the null into a failure.
     *
     * @return list<array<string, mixed>>|null
     */
    private function decrees(): ?array
    {
        $path = $this->root . JsonDataConstants::DECREES_FILE;
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (false === $raw) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    /**
     * Top-level keys of a JSON object file, or null when the file is unreadable.
     *
     * @return list<string>|null
     */
    private function jsonKeys(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        return array_map('strval', array_keys($this->decodeArray($path)));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeArray(string $path): array
    {
        $raw = file_get_contents($path);
        if (false === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
