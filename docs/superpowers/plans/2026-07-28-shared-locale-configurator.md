# Shared LocaleConfigurator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps
> use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the process-global locale/`LANGUAGE`/ICU setup duplicated across three sites into one shared `Services\LocaleConfigurator`, standardizing region resolution on
CLDR likely subtags and the failure policy (resolve-or-throw; Latin the sole reset).

**Architecture:** A stateless `LocaleConfigurator::configure(string): ConfiguredLocale` owns `setlocale` + `LANGUAGE` + ICU default and returns the resolved runtime info.
`CalendarHandler::prepareL10N()`, `EventsHandler::setLocale()`, and `FerialEventNameGenerator::initializeGettext()` each call it, then keep their own tail (formatters,
`LitLocale` statics, text-domain binding, model `setLocale`).

**Tech Stack:** PHP 8.4, `ext-intl` (`\Locale`), gettext, PHPUnit 12, PHPStan level 10, phpcs (PSR-12).

## Global Constraints

- PHP >= 8.4; PSR-12; short array syntax; single quotes unless interpolating.
- PHPStan level 10 clean (`composer analyse`, scans `src` only); phpcs clean (`composer lint`).
- Never `--no-verify`; CaptainHook pre-commit runs lint + lint:md.
- Region resolution MUST use `jsondata/likelySubtags.json` (region subtag only — glibc rejects script-bearing names like `en_Latn_US`).
- Failure policy: a non-Latin language with no installed system locale MUST throw `ServiceUnavailableException`; it must never silently produce English. Latin (`la`/`la_VA`) MUST
  take the reset branch and never throw.
- Work happens in the existing worktree `.claude/worktrees/locale-configurator-745` (branch `feature/locale-configurator-745`, off `development`). Run all commands there.
- `LitLocale::LATIN_PRIMARY_LANGUAGE === 'la'`.

---

### Task 1: `LocaleConfigurator` service + `ConfiguredLocale` result

**Files:**

- Create: `src/Services/ConfiguredLocale.php`
- Create: `src/Services/LocaleConfigurator.php`
- Test: `phpunit_tests/Services/LocaleConfiguratorTest.php`

**Interfaces:**

- Produces:
  - `final class ConfiguredLocale` with `public readonly string $primaryLanguage`, `public readonly string $runtimeLocale`, `public readonly bool $isLatin`.
  - `LocaleConfigurator::configure(string $requestLocale): ConfiguredLocale` — applies process-global locale state and returns the result; throws `ServiceUnavailableException`
    for a non-Latin language with no installed locale.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Services/LocaleConfiguratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\LocaleConfigurator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocaleConfigurator::class)]
final class LocaleConfiguratorTest extends TestCase
{
    private string $savedApiFilePath = '';
    private string|false $savedLanguage = false;
    private string $savedIcuDefault = 'en';

    protected function setUp(): void
    {
        $this->savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        $this->savedLanguage    = getenv('LANGUAGE');
        $this->savedIcuDefault  = \Locale::getDefault();

        // JsonData::FOLDER->path() prefixes Router::$apiFilePath; point it at the repo root.
        Router::$apiFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        // Start from a clean process-global locale.
        setlocale(LC_ALL, 'C');
        putenv('LANGUAGE');
    }

    protected function tearDown(): void
    {
        Router::$apiFilePath = $this->savedApiFilePath;
        setlocale(LC_ALL, 'C');
        if ($this->savedLanguage === false) {
            putenv('LANGUAGE');
        } else {
            putenv('LANGUAGE=' . $this->savedLanguage);
        }
        \Locale::setDefault($this->savedIcuDefault);
    }

    public function testRegionlessLanguageResolvesViaLikelySubtags(): void
    {
        $result = LocaleConfigurator::configure('en');
        self::assertSame('en', $result->primaryLanguage);
        self::assertSame('en_US', $result->runtimeLocale);
        self::assertFalse($result->isLatin);
        self::assertStringStartsWith('en_US', (string) getenv('LANGUAGE'));
    }

    public function testFrenchResolvesToInstalledRegionVariant(): void
    {
        $result = LocaleConfigurator::configure('fr');
        self::assertSame('fr', $result->primaryLanguage);
        self::assertSame('fr_FR', $result->runtimeLocale);
        self::assertFalse($result->isLatin);
    }

    public function testRegionBearingLocaleIsPreserved(): void
    {
        $result = LocaleConfigurator::configure('it_IT');
        self::assertSame('it', $result->primaryLanguage);
        self::assertSame('it_IT', $result->runtimeLocale);
    }

    public function testLatinResetsAndClearsLanguage(): void
    {
        putenv('LANGUAGE=it_IT.utf8:it_IT:it:en'); // simulate a prior translated request
        foreach (['la', 'la_VA'] as $latin) {
            $result = LocaleConfigurator::configure($latin);
            self::assertTrue($result->isLatin, "{$latin} should take the Latin reset branch");
            self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $result->primaryLanguage);
            self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $result->runtimeLocale);
            self::assertFalse(getenv('LANGUAGE'), 'Latin must clear the leaked LANGUAGE env var');
        }
    }

    public function testOverridesLeakedLanguageFromPriorRequest(): void
    {
        putenv('LANGUAGE=it_IT.utf8:it_IT:it:en');
        LocaleConfigurator::configure('fr');
        self::assertStringStartsWith('fr', (string) getenv('LANGUAGE'));
    }

    public function testThrowsWhenNoInstalledLocaleForLanguage(): void
    {
        $this->expectException(ServiceUnavailableException::class);
        LocaleConfigurator::configure('zz');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/LocaleConfiguratorTest.php`
Expected: FAIL/ERROR — `Class "LiturgicalCalendar\Api\Services\LocaleConfigurator" not found`.

- [ ] **Step 3: Create the `ConfiguredLocale` result object**

Create `src/Services/ConfiguredLocale.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * Immutable result of {@see LocaleConfigurator::configure()}: the process-global
 * locale state that was applied for a request.
 */
final class ConfiguredLocale
{
    /**
     * @param string $primaryLanguage Primary language subtag (e.g. 'it', 'en', 'la').
     * @param string $runtimeLocale   Normalized runtime locale in effect (e.g. 'it_IT',
     *                                 'en_US', or 'la' for the Latin reset branch).
     * @param bool   $isLatin         True when the Latin/reset branch was taken.
     */
    public function __construct(
        public readonly string $primaryLanguage,
        public readonly string $runtimeLocale,
        public readonly bool $isLatin
    ) {
    }
}
```

- [ ] **Step 4: Create the `LocaleConfigurator` service**

Create `src/Services/LocaleConfigurator.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Utilities;

/**
 * Deterministically applies the process-global locale state — setlocale(LC_ALL),
 * the LANGUAGE env var (which glibc gettext() reads above LC_MESSAGES), and ICU's
 * default — for a single request, in a leak-free way.
 *
 * Region resolution uses CLDR likely subtags (jsondata/likelySubtags.json) so a
 * region-less request locale (e.g. 'en', 'fr') maps to the installed system locale
 * ('en_US', 'fr_FR'). A real language whose system locale is not installed at all
 * throws — it must never silently fall through to English. Latin ('la'/'la_VA') is
 * the sole special case: it cannot be installed, so it takes the reset branch and
 * never throws (downstream code emits hardcoded Latin).
 *
 * Centralizes logic previously duplicated across CalendarHandler::prepareL10N(),
 * EventsHandler::setLocale(), and FerialEventNameGenerator::initializeGettext() (#745).
 */
final class LocaleConfigurator
{
    /** @var array<string,string>|null Cached CLDR likelySubtags map (language => "lang-Script-Region"). */
    private static ?array $likelySubtags = null;

    /**
     * Apply the process-global locale for the given request locale and return the
     * resolved runtime information.
     *
     * @param string $requestLocale The request locale (e.g. 'it_IT', 'en', 'la_VA').
     * @throws ServiceUnavailableException When a non-Latin language has no installed system locale.
     */
    public static function configure(string $requestLocale): ConfiguredLocale
    {
        $canonical       = \Locale::canonicalize($requestLocale);
        $canonical       = ( $canonical === null || $canonical === '' ) ? $requestLocale : $canonical;
        $primaryLanguage = \Locale::getPrimaryLanguage($canonical);
        $primaryLanguage = ( $primaryLanguage === null || $primaryLanguage === '' ) ? $canonical : $primaryLanguage;

        if ($primaryLanguage === LitLocale::LATIN_PRIMARY_LANGUAGE) {
            self::reset(LitLocale::LATIN_PRIMARY_LANGUAGE);
            return new ConfiguredLocale(LitLocale::LATIN_PRIMARY_LANGUAGE, LitLocale::LATIN_PRIMARY_LANGUAGE, true);
        }

        $region = \Locale::getRegion($canonical);
        if ($region === null || $region === '') {
            $region = self::likelyRegion($primaryLanguage);
        }

        $candidates = [];
        if ($region !== '') {
            $langRegion = $primaryLanguage . '_' . $region;
            $candidates = [$langRegion . '.utf8', $langRegion . '.UTF-8', $langRegion];
        }
        $candidates = array_merge($candidates, [
            $primaryLanguage . '.utf8',
            $primaryLanguage . '.UTF-8',
            $primaryLanguage,
        ]);

        $runtimeLocale = setlocale(LC_ALL, $candidates);
        if ($runtimeLocale === false) {
            throw new ServiceUnavailableException('Could not set locale to ' . $requestLocale . '.');
        }

        // Example: "it_IT.UTF-8" → "it_IT"
        $normalizedLocale = strtok($runtimeLocale, '.') ?: $runtimeLocale;
        if ($normalizedLocale === 'C' || $normalizedLocale === 'POSIX') {
            $normalizedLocale = $region !== '' ? $primaryLanguage . '_' . $region : $primaryLanguage;
        }

        // Pin gettext's catalog for THIS request, overriding any LANGUAGE a prior
        // request in this persistent worker left behind (glibc reads it above LC_MESSAGES).
        $languageEnv = implode(':', array_unique([
            $runtimeLocale,
            $normalizedLocale,
            $primaryLanguage,
            'en',
        ]));
        putenv("LANGUAGE={$languageEnv}");
        \Locale::setDefault($normalizedLocale);

        return new ConfiguredLocale($primaryLanguage, $normalizedLocale, false);
    }

    /**
     * Reset the process-global locale state so gettext output falls through to the
     * untranslated (English) msgid base, and update ICU's default. Used for Latin.
     */
    private static function reset(string $icuDefaultLocale): void
    {
        setlocale(LC_ALL, 'C');
        putenv('LANGUAGE');
        \Locale::setDefault($icuDefaultLocale);
    }

    /**
     * Resolve the likely region subtag for a region-less language via CLDR likely
     * subtags (e.g. 'en' → 'US', 'pt' → 'BR'). Returns '' when unknown.
     *
     * Only the region subtag is used: glibc rejects script-bearing locale names
     * like "en_Latn_US", so the caller builds "en_US" from language + region.
     */
    private static function likelyRegion(string $language): string
    {
        if (self::$likelySubtags === null) {
            /** @var array{supplemental:array{likelySubtags:array<string,string>}} $data */
            $data                = Utilities::jsonFileToArray(JsonData::FOLDER->path() . '/likelySubtags.json');
            self::$likelySubtags = $data['supplemental']['likelySubtags'];
        }

        $maximized = self::$likelySubtags[$language] ?? null;
        if ($maximized === null) {
            return '';
        }

        $canonical = \Locale::canonicalize($maximized);
        $region    = \Locale::getRegion(( $canonical === null || $canonical === '' ) ? $maximized : $canonical);
        return ( $region === null ) ? '' : $region;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/LocaleConfiguratorTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Static analysis + lint on the new files**

Run: `composer analyse` — Expected: `[OK] No errors`.
Run: `vendor/bin/phpcs src/Services/LocaleConfigurator.php src/Services/ConfiguredLocale.php phpunit_tests/Services/LocaleConfiguratorTest.php` — Expected: exit 0.

- [ ] **Step 7: Commit**

```bash
git add src/Services/LocaleConfigurator.php src/Services/ConfiguredLocale.php phpunit_tests/Services/LocaleConfiguratorTest.php
git commit -m "feat(locale): add shared LocaleConfigurator service (#745)"
```

---

### Task 2: Rewire `CalendarHandler::prepareL10N()`

**Files:**

- Modify: `src/Handlers/CalendarHandler.php` (add import; replace `prepareL10N()` body)
- Test: `phpunit_tests/Handlers/CalendarHandlerLocaleLeakTest.php` (existing — must stay green), golden master (existing).

**Interfaces:**

- Consumes: `LocaleConfigurator::configure(string): ConfiguredLocale` from Task 1.

- [ ] **Step 1: Add the import**

In `src/Handlers/CalendarHandler.php`, add to the `use` block (alongside the other `use LiturgicalCalendar\Api\...` imports):

```php
use LiturgicalCalendar\Api\Services\LocaleConfigurator;
```

- [ ] **Step 2: Replace the `prepareL10N()` body**

Replace the entire current `prepareL10N()` method with:

```php
    /**
     * Set up the locale for this API request.
     *
     * Delegates process-global locale/LANGUAGE/ICU setup to the shared
     * LocaleConfigurator (deterministic + leak-free, #745), then sets the
     * LitLocale statics, the LiturgicalEvent locale, the date/ordinal formatters,
     * and binds the gettext text domain.
     */
    private function prepareL10N(): void
    {
        $baseLocale = \Locale::getPrimaryLanguage($this->CalendarParams->Locale);
        if (null === $baseLocale) {
            throw new ServiceUnavailableException('“Pride was the reason for the division of tongues, humility the reason they were reunited.” - St. Augustine, The City of God, Book XVI, Chapter 4');
        }

        $configured                 = LocaleConfigurator::configure($this->CalendarParams->Locale);
        LitLocale::$PRIMARY_LANGUAGE = $configured->primaryLanguage;
        LitLocale::$RUNTIME_LOCALE   = $configured->runtimeLocale;

        LiturgicalEvent::setLocale(LitLocale::$RUNTIME_LOCALE);

        $this->createFormatters();

        if (false === is_dir(Router::$apiFilePath . 'i18n')) {
            throw new ServiceUnavailableException('The i18n folder does not exist at path: ' . Router::$apiFilePath . 'i18n' . '.');
        }

        if (false === bindtextdomain('litcal', Router::$apiFilePath . 'i18n')) {
            throw new ServiceUnavailableException('Could not bind text domain for translations to path: ' . Router::$apiFilePath . 'i18n' . '.');
        } else {
            bind_textdomain_codeset('litcal', 'UTF-8');
            $textDomain = textdomain('litcal');
        }

        if ($textDomain !== 'litcal') {
            throw new ServiceUnavailableException('Could not set text domain for translations to \'litcal\'.');
        }
    }
```

- [ ] **Step 3: Run the Calendar leak test + golden master**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarHandlerLocaleLeakTest.php phpunit_tests/Handlers/CalendarGoldenMasterTest.php`
Expected: PASS (leak test byte-identical to golden master; golden master 9/9). If the `.env.local`/`it_IT`-availability preconditions are missing the leak test may skip — that is
acceptable, but the golden master must pass.

- [ ] **Step 4: Static analysis + lint**

Run: `composer analyse` — Expected: `[OK] No errors`.
Run: `vendor/bin/phpcs src/Handlers/CalendarHandler.php` — Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
git add src/Handlers/CalendarHandler.php
git commit -m "refactor(calendar): use shared LocaleConfigurator in prepareL10N (#745)"
```

---

### Task 3: Rewire `EventsHandler::setLocale()` (delete #744 helpers)

**Files:**

- Modify: `src/Handlers/EventsHandler.php` (add import; replace `setLocale()` body; delete `applyTranslatedLocale()` and `resetToUntranslatedLocale()`)
- Test: `phpunit_tests/Handlers/EventsHandlerLocaleLeakTest.php` (existing — must stay green).

**Interfaces:**

- Consumes: `LocaleConfigurator::configure(string): ConfiguredLocale` from Task 1.

- [ ] **Step 1: Add the import**

In `src/Handlers/EventsHandler.php`, add to the `use` block:

```php
use LiturgicalCalendar\Api\Services\LocaleConfigurator;
```

- [ ] **Step 2: Replace `setLocale()` and delete the two helpers**

Replace the `setLocale()` method (its docblock + body) with:

```php
    /**
     * Set up the process-global locale for this request via the shared
     * LocaleConfigurator (deterministic + leak-free, #745), then bind the gettext
     * text domain and configure the LiturgicalEventAbstract locale.
     */
    private function setLocale(): void
    {
        LocaleConfigurator::configure($this->EventsParams->Locale);
        bindtextdomain('litcal', Router::$apiFilePath . 'i18n');
        textdomain('litcal');
        LiturgicalEventAbstract::setLocale($this->EventsParams->Locale);
    }
```

Then delete the two now-unused private methods added in #744, in their entirety: `private function applyTranslatedLocale(string $baseLocale): void { ... }` and `private function
resetToUntranslatedLocale(string $icuDefaultLocale): void { ... }`.

- [ ] **Step 3: Verify no dangling references to the deleted helpers**

Run: `grep -n "applyTranslatedLocale\|resetToUntranslatedLocale" src/Handlers/EventsHandler.php`
Expected: no output (both methods gone, no callers).

- [ ] **Step 4: Run the Events leak test + Events tests**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/EventsHandlerLocaleLeakTest.php phpunit_tests/Handlers/EventsHandlerTest.php
phpunit_tests/Handlers/EventsHandlerRiteRoutingTest.php`
Expected: PASS. (English now resolves via `en_US` instead of the #744 degrade path; output identical. The `it_IT`-availability skip guard in the leak test still applies.)

- [ ] **Step 5: Static analysis + lint**

Run: `composer analyse` — Expected: `[OK] No errors`.
Run: `vendor/bin/phpcs src/Handlers/EventsHandler.php` — Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
git add src/Handlers/EventsHandler.php
git commit -m "refactor(events): use shared LocaleConfigurator in setLocale (#745)"
```

---

### Task 4: Fold in `FerialEventNameGenerator::initializeGettext()`

**Files:**

- Modify: `src/FerialEventNameGenerator.php` (add import; replace `initializeGettext()` body)
- Test: `phpunit_tests/Handlers/TemporaleHandlerTest.php` (existing — must stay green).

**Interfaces:**

- Consumes: `LocaleConfigurator::configure(string): ConfiguredLocale` from Task 1.

- [ ] **Step 1: Add the import**

In `src/FerialEventNameGenerator.php`, add to the `use` block (it already has `use LiturgicalCalendar\Api\Router;` etc.):

```php
use LiturgicalCalendar\Api\Services\LocaleConfigurator;
```

- [ ] **Step 2: Replace the `initializeGettext()` body**

Replace the entire current `initializeGettext()` method (the Latin early-return, `$localeArray`, `$regionMap`, `setlocale`, `LANGUAGE` block, and text-domain binding) with:

```php
    /**
     * Initialize gettext for the locale.
     *
     * Delegates process-global locale/LANGUAGE/ICU setup to the shared
     * LocaleConfigurator (#745) — which resets for Latin and pins the catalog for
     * translated locales, overriding any state a prior request left in this worker —
     * then binds the gettext text domain. Latin phrase templates fall through to the
     * English msgid base (there is no installable Latin locale); Latin day names and
     * ordinals are emitted from hardcoded tables elsewhere in this class.
     */
    private function initializeGettext(): void
    {
        LocaleConfigurator::configure($this->locale);

        // Bind textdomain using Router::$apiFilePath if available, else fall back.
        $i18nPath = Router::$apiFilePath . 'i18n';
        if (!is_dir($i18nPath)) {
            $i18nPath = dirname(__DIR__) . '/i18n';
        }

        bindtextdomain('litcal', $i18nPath);
        bind_textdomain_codeset('litcal', 'UTF-8');
        textdomain('litcal');
    }
```

- [ ] **Step 3: Verify the dropped `$regionMap` heuristic is gone**

Run: `grep -n "regionMap\|strtoupper" src/FerialEventNameGenerator.php`
Expected: no output.

- [ ] **Step 4: Run the Temporale tests**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/TemporaleHandlerTest.php`
Expected: PASS (3 tests — Latin `/temporale` still returns 200 with a non-empty body; invalid-locale still raises `ValidationException`).

- [ ] **Step 5: Static analysis + lint**

Run: `composer analyse` — Expected: `[OK] No errors`.
Run: `vendor/bin/phpcs src/FerialEventNameGenerator.php` — Expected: exit 0.

- [ ] **Step 6: Commit**

```bash
git add src/FerialEventNameGenerator.php
git commit -m "refactor(ferial): use shared LocaleConfigurator in initializeGettext (#745)"
```

---

### Task 5: Full validation

**Files:** none (validation + optional PR).

- [ ] **Step 1: Full static analysis + code style**

Run: `composer analyse` — Expected: `[OK] No errors`.
Run: `composer lint` — Expected: no phpcs violations.
Run: `composer parallel-lint` — Expected: `No syntax error found`.

- [ ] **Step 2: Run the full local suite**

Run: `composer test`
Expected: 0 failures. Pre-existing environmental errors are acceptable and unrelated to this change: tests under `phpunit_tests/Routes/**` error with `Could not detect API
binding on http://localhost:8000` (need a live server) and DB-gated tests report `connection ... refused` when Postgres is absent. Confirm there are **no failures** and no new
errors outside those two categories.

- [ ] **Step 3: Confirm the three old duplications are gone**

Run: `grep -rn "putenv(\"LANGUAGE=" src/`
Expected: only `src/Services/LocaleConfigurator.php` (the three former sites — CalendarHandler, EventsHandler, FerialEventNameGenerator — no longer set `LANGUAGE` directly).

- [ ] **Step 4: Push and open the PR (optional — confirm with the user first)**

```bash
git push -u origin feature/locale-configurator-745
gh pr create --base development --head feature/locale-configurator-745 \
  --title "refactor(locale): shared LocaleConfigurator across handlers (#745)" \
  --body "Closes #745. Extracts the process-global locale/LANGUAGE/ICU setup duplicated in CalendarHandler::prepareL10N(), EventsHandler::setLocale(), and FerialEventNameGenerator::initializeGettext() into Services\\LocaleConfigurator. Region resolution standardized on CLDR likelySubtags; failure policy is resolve-or-throw with Latin the sole reset. See docs/superpowers/specs/2026-07-28-shared-locale-configurator-design.md."
```

---

## Notes for the implementer

- **Do not** modify `CalendarParams::maximizeLocale()` — it maximizes at the Params layer for the response's echoed `settings.locale`; the service resolves region independently
  and both can coexist. (Follow-up #745 notes a possible later consolidation.)
- **Do not** change the argument each handler passes to its model `setLocale()` (`CalendarHandler` → `LitLocale::$RUNTIME_LOCALE`; `EventsHandler` →
  `$this->EventsParams->Locale`). Preserving these avoids shifting Latin model-branching/output.
- Behavior change to be aware of while validating: a real language with **zero** installed system locales now throws 503 (was: Events degraded to English, Ferial silent). For the
  locales the test host ships (`en_US`, `fr_FR`, `it_IT`, …) nothing observable changes.
