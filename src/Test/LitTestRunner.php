<?php

namespace LiturgicalCalendar\Api\Test;

use Swaggest\JsonSchema\Schema;
use LiturgicalCalendar\Api\Enum\FrameFamily;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitEventTestAssertion;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\Status;
use LiturgicalCalendar\Api\Enum\Step;
use LiturgicalCalendar\Api\Test\TestsMap;

/**
 * @phpstan-type LiturgicalEvent object{
 *      event_key:string,
 *      event_idx:int,
 *      name:string,
 *      year:int,
 *      month:int,
 *      month_short:string,
 *      month_long:string,
 *      day:int,
 *      type:string,
 *      grade:int,
 *      grade_display:string|null,
 *      grade_abbr:string|null,
 *      grade_lcl:string|null,
 *      date:string,
 *      color:string[],
 *      color_lcl:string[],
 *      common:string[],
 *      common_lcl:string,
 *      day_of_the_week_iso8601:int,
 *      day_of_the_week_long:string,
 *      day_of_the_week_short:string,
 *      liturgical_season:string,
 *      liturgical_season_lcl:string,
 *      liturgical_year:string,
 *      psalter_week:int,
 *      is_vigil_for?:bool,
 *      is_vigil_mass?:bool,
 *      has_vigil_mass?:bool,
 *      has_vesper_i?:bool,
 *      has_vesper_ii?:bool
 * }
 * @phpstan-import-type TestDataObject from TestsMap
 */
class LitTestRunner
{
    /**
     * @var bool Indicates whether the test is ready to run.
     * When the server loads a new test, it will attempt to load the test instructions JSON file and validate it against the schema.
     * If the loading and validation are successful, the readyState is set to true.
     * All subsequent calls to isReady() will return the value of readyState.
     */
    private bool $readyState = false;

    /**
     * @var object{settings:object{year:int,rite?:string,national_calendar?:string,diocesan_calendar?:string},litcal:array<LiturgicalEvent>} The data to be tested
     */
    private object $dataToTest;

    /**
     * @var \stdClass|null The message to be returned by the test
     */
    private ?\stdClass $Message = null;

    /**
     * @var string|null The name of the test
     */
    private ?string $Test = null;

    /**
     * @var string|null The cache key for this test: `{rite}/{Test}`.
     * The corpus is partitioned by rite (#787), so the same test name under two
     * rites is two different tests, and the static cache key must carry the rite
     * to avoid a silent collision between them.
     */
    private ?string $cacheKey = null;

    /**
     * @var TestsMap|null The cache for the test instructions and supported years
     * This is a static property that is shared across all instances of LitTestRunner.
     * It is used to avoid loading the same test instructions multiple times.
     * @static
     */
    private static ?TestsMap $testCache = null;

    /**
     * Initializes the test object with the provided Test and test data.
     * Loads test instructions from a JSON file and validates them against the LitCalTest schema.
     * Populates the test cache with the test instructions and supported years.
     * Updates the ready state based on successful initialization.
     *
     * @param string $Test The name of the test.
     * @param \stdClass&object{settings:object{year:int,rite?:string,national_calendar?:string,diocesan_calendar?:string},litcal:LiturgicalEvent[]} $testData The test data object.
     * @param Rite $rite The rite partition this test belongs to (#787).
     */
    public function __construct(string $Test, \stdClass $testData, Rite $rite)
    {
        $this->Test       = $Test;
        $this->dataToTest = $testData;
        if (self::$testCache === null) {
            self::$testCache = new TestsMap();
        }
        // The cache key carries the rite: the corpus is partitioned, so the same name
        // under two rites is two different tests (#787).
        $cacheKey       = $rite->value . '/' . $Test;
        $this->cacheKey = $cacheKey;
        if (false === self::$testCache->has($cacheKey)) {
            $testPath = self::testPathFor($Test, $rite);
            if (file_exists($testPath)) {
                $testInstructionsRaw = file_get_contents($testPath);
                if ($testInstructionsRaw) {
                    $testInstructions = json_decode($testInstructionsRaw);
                    $jsonLastError    = json_last_error();
                    if (JSON_ERROR_NONE === $jsonLastError && $testInstructions instanceof \stdClass) {
                        $schemaFile = rtrim(JsonData::SCHEMAS_FOLDER->path(), '/\\') . DIRECTORY_SEPARATOR . 'LitCalTest.json';
                        try {
                            $schema = Schema::import($schemaFile);
                            $schema->in($testInstructions);
                            /** @var TestDataObject $testInstructions */
                            self::$testCache->add($cacheKey, $testInstructions);
                            $this->readyState = true;
                        } catch (\Throwable $e) {
                            $this->setError("Cannot proceed with {$Test}, the Test instructions were incorrectly validated against schema " . $schemaFile . ': ' . $e->getMessage());
                        }
                    } else {
                        $gettype          = gettype($testInstructions);
                        $jsonErrorMessage = $jsonLastError !== JSON_ERROR_NONE ? ' (' . json_last_error_msg() . ')' : '';
                        $this->setError("Test server could not decode Test instructions JSON data for {$Test}: expected stdClass but got {$gettype}{$jsonErrorMessage}");
                    }
                }
            } else {
                // The file is absent from the requested rite's partition. Before blaming
                // the filesystem, look for the same test name under the other rites: if it
                // lives there, the file is fine and the *request* is wrong, and the operator
                // needs to hear about the rite rather than go hunting for a missing file.
                // See issue #794 — after #787 partitioned the corpus by rite, Health resolves
                // the rite from the calendar under test and passes it as the test's partition,
                // so a mismatch lands here instead of in detectRiteMismatch().
                $declaredRites = self::ritesDefiningTest($Test, $rite);
                if ([] === $declaredRites) {
                    $this->setError("Test server could not read Test instructions for {$Test}");
                } else {
                    $this->setError($this->riteMismatchMessage($declaredRites, $rite));
                }
            }
        } else {
            $this->readyState = self::$testCache->isReady($cacheKey);
        }
    }

    /**
     * The path the test instructions for `$Test` would occupy in `$rite`'s partition of the corpus.
     */
    private static function testPathFor(string $Test, Rite $rite): string
    {
        return rtrim(JsonData::testsFolderFor($rite)->path(), '/\\') . DIRECTORY_SEPARATOR . basename($Test) . '.json';
    }

    /**
     * The rites — other than `$requested` — whose partition actually defines a test named `$Test`.
     *
     * Iterates {@see \LiturgicalCalendar\Api\Enum\Rite::cases()} rather than naming the two
     * current rites, so a third rite is covered the day it is added. One `file_exists()` per
     * rite; deliberately uncached, since this only runs on the error path where the requested
     * partition has already come up empty.
     *
     * @return list<Rite>
     */
    private static function ritesDefiningTest(string $Test, Rite $requested): array
    {
        $found = [];
        foreach (Rite::cases() as $candidate) {
            if ($candidate === $requested) {
                continue;
            }
            if (file_exists(self::testPathFor($Test, $candidate))) {
                $found[] = $candidate;
            }
        }

        return $found;
    }

    /**
     * The single operator-facing sentence for "this test and this calendar are of different rites".
     *
     * Shared by the two paths that can detect the condition — the constructor, when the test
     * is absent from the requested partition but present in another, and
     * {@see \LiturgicalCalendar\Api\Test\LitTestRunner::detectRiteMismatch()}, when the response
     * echoes back a rite that disagrees with the loaded test's. One condition, one phrasing.
     *
     * A test name can legitimately be defined under more than one other rite; all of them are
     * named, since which one the operator meant is exactly what is in question.
     *
     * @param non-empty-list<Rite> $declaredRites The rite(s) the test is actually scoped to.
     * @param Rite                 $responseRite  The rite the calendar under test was computed under.
     */
    private function riteMismatchMessage(array $declaredRites, Rite $responseRite): string
    {
        $names = array_map(static fn (Rite $rite): string => $rite->value, $declaredRites);
        if (1 === count($names)) {
            $scope = "the {$names[0]} rite";
        } else {
            $last  = array_pop($names);
            $scope = 'the ' . implode(', ', $names) . " and {$last} rites";
        }

        return "{$this->Test} is scoped to {$scope}, but the calendar under test was computed under the {$responseRite->value} rite";
    }

    /**
     * Indicates whether the LitTest is ready to run.
     *
     * When the server loads a new test, it will attempt to load the test instructions JSON file and validate it against the schema.
     * If the loading and validation are successful, the readyState is set to true.
     * All subsequent calls to isReady() will return the value of readyState.
     *
     * @return bool true if the test is ready to run, false otherwise
     */
    public function isReady(): bool
    {
        return $this->readyState;
    }

    /**
     * Run the test.
     *
     * If the test is not ready (i.e. has not been loaded and validated), it will
     * do nothing.
     *
     * Otherwise, it will retrieve the assertion for the year we are testing,
     * and check if it is within the bounds of the supported years. If it is,
     * it will run the test according to the assertion type.
     *
     * If the assertion is of type "eventNotExists", it will check if the event
     * does not exist in the calendar. If it does, it will set an error message.
     * Otherwise, it will set a success message.
     *
     * If the assertion is of type "eventExists AND hasExpectedDate", it
     * will check if the event exists in the calendar and has the expected
     * date value. If it does not, it will set an error message. Otherwise, it
     * will set a success message.
     *
     * If the assertion is of any other type, it will set an error message.
     */
    public function runTest(): void
    {
        if ($this->readyState) {
            if (null === self::$testCache) {
                $this->setError('Test cache is not initialized');
                return;
            }
            if (null === $this->Test) {
                $this->setError('Test name is not set');
                return;
            }
            if (null === $this->cacheKey) {
                $this->setError('Test cache key is not set');
                return;
            }
            $riteMismatch = $this->detectRiteMismatch(self::$testCache, $this->cacheKey);
            if (null !== $riteMismatch) {
                $this->setError($riteMismatch);
                return;
            }

            $assertion = self::$testCache->retrieveAssertionForYear($this->cacheKey, $this->dataToTest->settings->year);
            if (is_null($assertion)) {
                $this->setError("Out of bounds error: {$this->Test} only supports calendar years [ " . implode(', ', self::$testCache->getYearsSupported($this->cacheKey)) . ' ]');
                return;
            }

            $calendarType     = $this->getCalendarType();
            $calendarName     = $this->getCalendarName();
            $messageIfError   = "{$this->Test} Assertion '{$assertion->assertion}' failed for Year " . $this->dataToTest->settings->year . " in {$calendarType}{$calendarName}.";
            $eventKey         = self::$testCache->get($this->cacheKey)->event_key;
            $eventBeingTested = array_find($this->dataToTest->litcal, fn ($item) => $item->event_key === $eventKey);

            switch ($assertion->assert) {
                case LitEventTestAssertion::EVENT_NOT_EXISTS:
                    if (null === $eventBeingTested) {
                        $this->setSuccess();
                    } else {
                        $errorMessage = is_null($assertion->expected_value)
                            ? " The event {$eventKey} should not exist, instead the event has a date value of {$eventBeingTested->date}"
                            : " What is going on here? We expected the event not to exist, and in fact it doesn't. We should never get here!";
                        $this->setAssertionFailure($messageIfError . $errorMessage);
                    }
                    break;
                case LitEventTestAssertion::EVENT_EXISTS_AND_HAS_EXPECTED_DATE:
                    $firstErrorMessage = " The event {$eventKey} should exist, instead it was not found";
                    if (null !== $eventBeingTested) {
                        $actualValue        = $eventBeingTested->date;
                        $secondErrorMessage = " The event {$eventKey} was expected to have a date value of {$assertion->expected_value}, instead it had a date value of {$actualValue}";
                        if ($actualValue === $assertion->expected_value) {
                            $this->setSuccess("expected_value = {$assertion->expected_value}, actualValue = {$actualValue}");
                        } else {
                            $this->setAssertionFailure($messageIfError . $secondErrorMessage);
                        }
                    } else {
                        $this->setAssertionFailure($messageIfError . $firstErrorMessage);
                    }
                    break;
                default:
                    $this->setError('This should never happen. We can only test whether an event does not exist, OR (does exist AND has an expected date value)');
                    break;
            }
        }
    }

    /**
     * Get a string to describe the calendar type used in the test (national, diocesan).
     *
     * @return string
     */
    private function getCalendarType(): string
    {
        return property_exists($this->dataToTest->settings, 'national_calendar') ? 'the national calendar of ' : (
            property_exists($this->dataToTest->settings, 'diocesan_calendar') ? 'the diocesan calendar of ' : ''
        );
    }

    /**
     * Returns the name of the calendar used in the test,
     * which will be a diocesan calendar, a national calendar, or 'the Universal Roman Calendar'.
     * @return string
     */
    private function getCalendarName(): string
    {
        if (property_exists($this->dataToTest->settings, 'diocesan_calendar')) {
            return $this->dataToTest->settings->diocesan_calendar;
        }

        if (property_exists($this->dataToTest->settings, 'national_calendar')) {
            return $this->dataToTest->settings->national_calendar;
        }

        return match ($this->responseRite()) {
            Rite::AMBROSIAN => 'the Ambrosian Calendar',
            default         => 'the General Roman Calendar'
        };
    }

    /**
     * The rite the calendar under test was computed under, as echoed back in
     * `settings.rite` (issue #760), or null when the response predates that
     * field or carries an unknown value.
     */
    private function responseRite(): ?Rite
    {
        if (
            false === property_exists($this->dataToTest->settings, 'rite')
            || false === is_string($this->dataToTest->settings->rite)
        ) {
            return null;
        }

        return Rite::tryFrom($this->dataToTest->settings->rite);
    }

    /**
     * Guard against a test being run against a calendar of the wrong rite.
     *
     * Without it, an Ambrosian test pointed at the General Roman Calendar fails
     * every single assertion — the event key simply does not exist there — and
     * reads as 32 broken assertions rather than one misrouted run. That is the
     * failure mode that motivated issue #767.
     *
     * The cache and cache key are passed in already narrowed by runTest(), which
     * has just checked both — re-checking here would add a branch no caller can
     * reach. The human-readable test name for the error message is read from
     * `$this->Test` rather than the (rite-prefixed) cache key.
     *
     * Returns the error text on mismatch, or null when the rites agree or the
     * response does not state one.
     */
    private function detectRiteMismatch(TestsMap $testCache, string $cacheKey): ?string
    {
        $responseRite = $this->responseRite();
        if (null === $responseRite) {
            return null;
        }

        $declaredRite = $testCache->get($cacheKey)->rite;
        if ($declaredRite === $responseRite) {
            return null;
        }

        return $this->riteMismatchMessage([$declaredRite], $responseRite);
    }

    /**
     * The calendar identifier a frame's `target` names, as opposed to the prose
     * {@see \LiturgicalCalendar\Api\Test\LitTestRunner::getCalendarName()} produces for humans.
     *
     * The same three cases, answered with ids: a diocese, a nation, or — when the response names
     * neither — the rite, which is what a rite-level calendar is identified by (`Health` passes the
     * rite's value as the calendar id for a `ritecalendar`, so the two emitters agree). A response
     * predating `settings.rite` (#760) falls back to the default rite, which is the same assumption
     * `getCalendarName()` makes when it says "the General Roman Calendar".
     */
    private function targetCalendarId(): string
    {
        if (property_exists($this->dataToTest->settings, 'diocesan_calendar')) {
            return $this->dataToTest->settings->diocesan_calendar;
        }

        if (property_exists($this->dataToTest->settings, 'national_calendar')) {
            return $this->dataToTest->settings->national_calendar;
        }

        return ( $this->responseRite() ?? Rite::default() )->value;
    }

    /**
     * Sets the message details based on the provided type and optional text. Called by {@see \LiturgicalCalendar\Api\Test\LitTestRunner::setError()} and {@see \LiturgicalCalendar\Api\Test\LitTestRunner::setSuccess()}.
     *
     * The `$attachJsonData` flag is set only by
     * {@see \LiturgicalCalendar\Api\Test\LitTestRunner::setAssertionFailure()},
     * which is the only error path that needs the diagnostic calendar
     * payload attached to the reply. Setup-time and configuration errors
     * leave it off so reply frames stay small.
     *
     * This is the frame for the **common case** — a test that actually ran and passed or failed —
     * where `Health::sendTestResult()` covers only the two ways a run can fail before the assertion is
     * reached. It says what it is about structurally, exactly as those do: `target` is the test, the
     * calendar and the year, `step` is `validates` (a test run is one named outcome, not a three-step
     * pipeline) and `status` is the outcome. `type`, `classes`, `test`, `text` and `jsonData` are the
     * legacy half and keep their own order — `classes` before `text`, unlike every frame `Health`
     * emits — because clients still match on them and this is additive.
     *
     * `.{test}.year-{year}.test-valid` **addresses the validity box**; it does not claim an outcome, and
     * the client colours that box by `status`. That is why the class is the same here for a pass and a
     * fail, and why it is correct: a class encoding the outcome would force a client to know the result
     * in order to build the selector that finds the card to put the result in.
     *
     * The selector is composed by {@see \LiturgicalCalendar\Api\Enum\FrameFamily::frameClasses()},
     * which is the only thing in the repository that composes one. This method used to hold a second
     * copy of the grammar, which is why "one place builds a selector" was true inside `Health` and not
     * across the repository.
     *
     * @param string      $type           The type of the message ('success' or 'error').
     * @param string|null $text           The optional text to include in the message.
     * @param bool        $attachJsonData When true, attaches `$this->dataToTest` to `jsonData`.
     */
    private function setMessage(string $type, ?string $text = null, bool $attachJsonData = false): void
    {
        $status = 'success' === $type ? Status::PASS : Status::FAIL;
        $year   = $this->dataToTest->settings->year;

        $this->Message          = new \stdClass();
        $this->Message->type    = $type;
        $this->Message->classes = FrameFamily::TEST_RUN->frameClasses($this->Test ?? '', Step::VALIDATES, "year-$year");
        $this->Message->test    = $this->Test;
        if (Status::PASS === $status) {
            if (is_null($text)) {
                $this->Message->text = "$this->Test passed for the Calendar {$this->getCalendarName()} for the year {$year}";
            } else {
                $this->Message->text = "$this->Test passed for the Calendar {$this->getCalendarName()} for the year {$year}: " . $text;
            }
        } else {
            $this->Message->text = $text;
            if ($attachJsonData) {
                $this->Message->jsonData = $this->dataToTest;
            }
        }

        // Structured fields follow the whole legacy block, `jsonData` included, so a v1 frame's keys are
        // in the order they have always been in and only these three are new.
        $this->Message->target = (object) [
            'id'       => $this->Test ?? '',
            'calendar' => $this->targetCalendarId(),
            'year'     => $year
        ];
        $this->Message->step   = Step::VALIDATES->value;
        $this->Message->status = $status->value;
    }

    /**
     * Sets the message to be an error message with the provided text. Called in
     * {@see \LiturgicalCalendar\Api\Test\LitTestRunner::__construct()}
     * and in {@see \LiturgicalCalendar\Api\Test\LitTestRunner::runTest()}
     * and in {@see \LiturgicalCalendar\Api\Test\LitTestRunner::getMessage()}.
     *
     * @param string $text The text of the error message.
     */
    private function setError(string $text): void
    {
        $this->setMessage('error', $text);
    }

    /**
     * Sets the message to be an assertion-failure error and attaches the
     * full calendar payload to the message under `jsonData`. The frontend
     * UnitTestInterface uses this attachment to show the actual server
     * response when the asserted event isn't where it should be (or is
     * present when it shouldn't be).
     *
     * Used only from the three assertion-mismatch branches in
     * {@see \LiturgicalCalendar\Api\Test\LitTestRunner::runTest()}. Setup-
     * and configuration-time errors (test-instructions missing, schema
     * mismatch, out-of-bounds year, internal-state mismatch) call
     * {@see \LiturgicalCalendar\Api\Test\LitTestRunner::setError()}
     * instead — those replies would have carried ~450 KB of irrelevant
     * calendar JSON on every error message.
     *
     * @param string $text The text of the assertion-failure error message.
     */
    private function setAssertionFailure(string $text): void
    {
        $this->setMessage('error', $text, true);
    }

    /**
     * Sets the message to be a success message with the provided text. Called in {@see \LiturgicalCalendar\Api\Test\LitTestRunner::runTest()}.
     *
     * @param string|null $text The optional text to include in the message.
     */
    private function setSuccess(?string $text = null): void
    {
        $this->setMessage('success', $text);
    }

    /**
     * Gets the message for the test result.
     * If the test has not been run yet, sets the message to an error message
     * and returns it.
     *
     * @return \stdClass The message object.
     */
    public function getMessage(): \stdClass
    {
        if (is_null($this->Message)) {
            $this->setError('An unknown error occurred while trying to run the test');
        }
        $message = $this->Message;
        if (null === $message) {
            throw new \RuntimeException('An unknown error occurred while trying to run the test: an error message should have been set but apparently it was not?');
        }
        return $message;
    }
}
