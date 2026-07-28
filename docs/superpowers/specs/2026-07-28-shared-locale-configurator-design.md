# Shared request-locale configurator

- **Issue:** [#745](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/745)
- **Date:** 2026-07-28
- **Status:** Approved (pending spec review)
- **Follows:** #742 (`CalendarHandler` leak fix, #739), #744 (`EventsHandler` leak fix, #743)

## Context and problem

Four sites independently set up the process-global locale for a request — build a
locale-candidate list, call `setlocale()`, pin the `LANGUAGE` env var (glibc
`gettext()` reads it above `LC_MESSAGES`), and update ICU's default:

Four sites do this, each differently:

- **`CalendarHandler::prepareL10N()`** — region via `\Locale::getRegion()` (Params
  already maximized through likelySubtags); **throws 503** on `setlocale()` failure;
  resets on Latin (#739).
- **`EventsHandler::setLocale()`** (via `applyTranslatedLocale()` /
  `resetToUntranslatedLocale()`) — `strtoupper(baseLocale)` region guess
  (`en`→`en_EN`, broken); **degrades to English** on failure (#744); resets on
  Latin (#743).
- **`FerialEventNameGenerator::initializeGettext()`** (used by `TemporaleHandler`) —
  hardcoded `$regionMap` (`en`→`US`, …); **silent no-op** on failure; **no** Latin
  reset (leak-prone).
- **`CalendarParams::maximizeLocale()`** — resolves region via likelySubtags at the
  *Params* layer, feeding `CalendarHandler`.

This has already drifted: three different region-resolution strategies, three
different failure policies, and only two of the three runtime sites reset the leak
vector. The logic should live in **one** shared, independently testable unit.

## Policy (decided)

1. **Resolve the region deterministically.** Canonicalize the request locale; if it
   carries no region, derive the likely region from CLDR **likely subtags**
   (`jsondata/likelySubtags.json`), taking only the **region** subtag
   (`en`→`US`; never the script-bearing `en_Latn_US`, which glibc rejects). This
   replaces both the `strtoupper` guess and the hardcoded `$regionMap`.
2. **A real language must resolve or fail — never silently become English.** Build
   candidates from the resolved language/region and `setlocale()` them. If no
   installed form of that language exists at all, **throw** (`ServiceUnavailableException`
   → 503). A request for French must not produce English.
3. **Latin is the sole special case.** `la`/`la_VA` cannot be installed on any host,
   so it takes the reset branch (`setlocale(LC_ALL,'C')`, clear `LANGUAGE`, ICU
   default `la`) and never throws; downstream helpers already emit hardcoded Latin.
4. **English is not special** — it resolves like any language: likelySubtags gives
   `en`→`US`, `en_US.utf8` is installed, `setlocale()` succeeds, and because there is
   no `i18n/en` catalog `gettext()` returns the (English) msgids. This replaces the
   #744 "degrade to msgid base" path for `EventsHandler` with a genuine resolution.
5. **Every branch overrides prior process state**, so a `LANGUAGE`/locale left by an
   earlier request in the same persistent worker cannot leak.

## Goals

- One shared unit that owns process-global locale state (`setlocale` + `LANGUAGE` +
  ICU default) and returns the resolved runtime info.
- Consistent region resolution (likelySubtags) and failure policy across
  `CalendarHandler`, `EventsHandler`, and `FerialEventNameGenerator`.
- No change to observable output for locales that are installed (the common case);
  the golden master and both existing leak tests stay green.

## Non-goals / out of scope

- **`CalendarParams::maximizeLocale()`** stays as-is (it maximizes at the *Params*
  layer for response `settings.locale`; changing it would alter the API's echoed
  locale). The new service resolves region itself so it is correct regardless of
  whether the Params layer maximized. Consolidating the two readers of
  `likelySubtags.json` is a possible later cleanup, noted below.
- **Model `setLocale()` arguments are unchanged.** Each handler keeps passing what it
  passes today (`CalendarHandler` → `runtimeLocale`; `EventsHandler` →
  `EventsParams->Locale`) to avoid shifting model-side Latin branching and output.
- No change to text-domain binding style, formatter setup, or `LitLocale` statics —
  those remain each caller's tail.

## Design

### New unit: `LocaleConfigurator` (+ `ConfiguredLocale`)

`src/Services/LocaleConfigurator.php` — `final class`, namespace
`LiturgicalCalendar\Api\Services`. Stateless except a private static cache of the
likelySubtags map. Single public entry point:

```php
public static function configure(string $requestLocale): ConfiguredLocale
```

`src/Services/ConfiguredLocale.php` — readonly value object returned to callers:

```php
final class ConfiguredLocale
{
    public function __construct(
        public readonly string $primaryLanguage, // e.g. 'it', 'en', 'la'
        public readonly string $runtimeLocale,   // normalized, e.g. 'it_IT' or 'la'
        public readonly bool   $isLatin,         // true => reset branch was taken
    ) {}
}
```

### `configure()` contract

```text
canonical        = \Locale::canonicalize(requestLocale) ?: requestLocale
primaryLanguage  = \Locale::getPrimaryLanguage(canonical) ?: canonical

if primaryLanguage === LitLocale::LATIN_PRIMARY_LANGUAGE:      // 'la'
    reset('la')                                               // C locale, unset LANGUAGE, ICU=la
    return ConfiguredLocale('la', 'la', isLatin: true)

region = \Locale::getRegion(canonical)
if region === '':
    region = likelyRegion(primaryLanguage)                    // from likelySubtags, may be ''

candidates = []
if region !== '':
    lr = primaryLanguage . '_' . region                       // e.g. 'en_US'
    candidates = [ lr.'.utf8', lr.'.UTF-8', lr ]
candidates = candidates + [ primaryLanguage.'.utf8', primaryLanguage.'.UTF-8', primaryLanguage ]

runtimeLocale = setlocale(LC_ALL, candidates)
if runtimeLocale === false:
    throw new ServiceUnavailableException("Could not set locale to {requestLocale}.")

normalized = strtok(runtimeLocale, '.') ?: runtimeLocale       // 'it_IT.UTF-8' -> 'it_IT'
if normalized in ('C','POSIX'):
    normalized = region !== '' ? primaryLanguage.'_'.region : primaryLanguage

putenv('LANGUAGE=' . implode(':', array_unique([runtimeLocale, normalized, primaryLanguage, 'en'])))
\Locale::setDefault(normalized)
return ConfiguredLocale(primaryLanguage, normalized, isLatin: false)
```

`reset($icuDefault)`: `setlocale(LC_ALL,'C'); putenv('LANGUAGE'); \Locale::setDefault($icuDefault);`

`likelyRegion($language)`: lazy-load `jsondata/likelySubtags.json`
(`Utilities::jsonFileToArray(JsonData::FOLDER->path() . '/likelySubtags.json')`,
`['supplemental']['likelySubtags']`, cached in a static — mirroring
`CalendarParams::maximizeLocale`); return
`\Locale::getRegion(\Locale::canonicalize($map[$language]) ?: $map[$language])` or `''`
when the language is absent.

Notes:

- The raw canonical string (which may carry a script subtag, e.g. `en_Latn_US`) is
  intentionally **not** offered to `setlocale()` — glibc rejects script-bearing
  names. Candidates are built from `primaryLanguage` + extracted `region` only.
- `ServiceUnavailableException` (an HTTP exception) thrown from a service matches the
  existing behavior in `CalendarHandler` and the codebase's convention; callers let
  it propagate to the router's error handling.

### Caller integration

**`CalendarHandler::prepareL10N()`** — keep its `null`-primary-language guard (the
St-Augustine 503 message) as a pre-check, then:

```php
$configured = LocaleConfigurator::configure($this->CalendarParams->Locale);
LitLocale::$PRIMARY_LANGUAGE = $configured->primaryLanguage;
LitLocale::$RUNTIME_LOCALE   = $configured->runtimeLocale;
LiturgicalEvent::setLocale(LitLocale::$RUNTIME_LOCALE);
$this->createFormatters();
// existing is_dir + error-checked bindtextdomain + bind_textdomain_codeset + textdomain checks
```

**`EventsHandler::setLocale()`** — replace the body (and delete the
`applyTranslatedLocale()` / `resetToUntranslatedLocale()` helpers added in #744):

```php
LocaleConfigurator::configure($this->EventsParams->Locale);
bindtextdomain('litcal', Router::$apiFilePath . 'i18n');
textdomain('litcal');
LiturgicalEventAbstract::setLocale($this->EventsParams->Locale);
```

**`FerialEventNameGenerator::initializeGettext()`** — replace the
`$localeArray` + `$regionMap` + `setlocale` + `LANGUAGE` block with
`LocaleConfigurator::configure($this->locale);`, keeping its existing tail: the
`is_dir` fallback path, `bindtextdomain`, `bind_textdomain_codeset`, `textdomain`.
`initializeFormatters()` is unaffected.

## Behavior changes and risks (intentional)

- **`CalendarHandler`**: region resolution now happens inside the service and no
  longer depends on the Params layer having maximized the locale — strictly more
  robust. No output change for installed locales.
- **`EventsHandler`**: English now resolves via `en_US` rather than the #744
  degrade-to-msgid path (same English output); a real language with **zero**
  installed locales now **throws** 503 instead of silently degrading to English —
  the intended stricter policy.
- **`FerialEventNameGenerator` / `TemporaleHandler`**: gains likelySubtags region
  resolution, the Latin reset, `LANGUAGE` leak-proofing, and the throw-on-unresolvable
  policy (previously silent). The throw path is not expected to fire for
  already-validated route locales.
- All three sites now reset the leak vector, closing the `FerialEventNameGenerator`
  gap where a Latin `/temporale` request after a translated request could inherit a
  stale `LANGUAGE`.

## Testing

- **New `LocaleConfiguratorTest`** (unit, no handler needed), each with locale-state
  reset in `setUp`/`tearDown`:
  - region from likelySubtags: `en`→ runtime `en_US`, `pt`→ `pt_BR` (assert
    `getenv('LANGUAGE')` and the returned `runtimeLocale`).
  - Latin: `configure('la')` / `configure('la_VA')` → `isLatin` true,
    `getenv('LANGUAGE') === false`, ICU default `la`.
  - leak override: pre-set `putenv('LANGUAGE=it_IT:it')`, `configure('fr')` →
    `LANGUAGE` starts with a French entry (not Italian).
  - throw path: `configure()` on a guaranteed-absent language (e.g. `zz`) throws
    `ServiceUnavailableException`.
- **Keep** `CalendarHandlerLocaleLeakTest` and `EventsHandlerLocaleLeakTest` green —
  they now exercise the shared path. The Events English test still passes (English
  resolves rather than degrades). The `it_IT`-availability skip guard added in #744
  is retained.
- Golden master unchanged; PHPStan level 10 + phpcs clean; `TemporaleHandlerTest`
  and the ferial-name paths stay green.

## Follow-ups (not in this change)

- Consolidate the two readers of `likelySubtags.json` (`CalendarParams::maximizeLocale`
  and `LocaleConfigurator::likelyRegion`) behind one small helper.
- Consider unifying the model `setLocale()` argument (`runtimeLocale` vs raw
  `Locale`) once its effect on Latin model-branching is characterized.
