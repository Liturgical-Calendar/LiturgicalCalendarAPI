#!/usr/bin/env php
<?php

/**
 * Guard the per-locale psalm numbering convention in the rite-level Roman lectionary corpus
 * (`jsondata/sourcedata/rite/roman/lectionary/`).
 *
 * Why this is a build gate and not merely a style nit (#973): the Psalter is numbered two
 * incompatible ways — Hebrew/Masoretic and Greek/Vulgate — and each locale's own liturgical
 * books use one or the other, sometimes glossed, sometimes not. Getting a citation's number
 * wrong does NOT produce a differently-formatted citation; it names a DIFFERENT psalm. `Psalm
 * 71` (Hebrew) and `Psalmo 71` (Vulgate) point at different texts. Nothing else in CI can see
 * this: schema validation only checks that a string is a string, and #969's key-set comparison
 * agrees perfectly across six files holding six different psalms under the same key.
 *
 * The six locales' conventions (sources in `jsondata/sourcedata/rite/roman/lectionary/README.md`):
 *
 *   | locale | form               | example          |
 *   |--------|--------------------|------------------|
 *   | `en`   | bare Hebrew        | `Psalm 89`       |
 *   | `hr`   | bare Hebrew        | `Ps 89`          |
 *   | `it`   | Vulgate (Hebrew)   | `Salmo 88 (89)`  |
 *   | `fr`   | Vulgate (Hebrew)   | `Psaume 88 (89)` |
 *   | `nl`   | Hebrew (Vulgate)   | `Psalm 89 (88)`  |
 *   | `la`   | bare Vulgate       | `Psalmo 88`      |
 *
 * The gloss is omitted where the two numberings coincide (Ps 1–8, Ps 148–150) — there is
 * nothing to gloss there.
 *
 * THREE TRAPS this script exists to not fall into. All three are the same shape — the check
 * looks at LESS than it appears to, and reports the silence as a pass:
 *
 *   1. MATCHING ONLY A LOCALE'S OWN PREFIX WOULD BE BLIND IN EXACTLY THE CASE IT MOST NEEDS
 *      TO SEE. While `nl.json` still holds Latin (`Psalmo 71`), an `nl`-only matcher (looking
 *      only for `Psalm ...`) finds nothing and the file passes having been checked for
 *      nothing — a green that means "I looked at zero citations," not "every citation is
 *      correct." That is the "check that reports an untruth" family this repository has hit
 *      repeatedly (#822, #833, #834, #835). So this script recognises ALL SIX prefixes it
 *      knows of, in every locale's file, and only THEN checks whether the prefix found matches
 *      the one that locale's own convention requires — turning silence into the finding it
 *      should be: a Latin citation sitting in a Dutch file. Five of the six are valid for some
 *      locale; the sixth, `Psalmus`, is valid for NONE and is recognised precisely so that it
 *      is reported rather than skipped. The corpus really did hold four `Psalmus …` citations
 *      that this lint could not see, and its per-locale counts read 128 for corpora of 130.
 *
 *   2. THE SIX PREFIXES OVERLAP, AND PCRE ALTERNATION IS LEFTMOST-FIRST, NOT LONGEST-FIRST.
 *      `Ps` is a prefix of `Psalm`, `Psaume`, `Psalmo` and `Psalmus`; `Psalm` is a prefix of
 *      `Psalmo` and `Psalmus`. A naive `(Psalm|Salmo|Psalmo|Psaume|Ps)` reads `Psalmo 71` as
 *      `Psalm` — a Latin citation silently classified as Dutch, a green for the very file this
 *      rule exists to catch. The pinned regex below requires whitespace after the prefix
 *      (`Psalmo`'s next character is `o`, never a space, so `\s+` alone already disambiguates
 *      it from `Psalm`) AND orders the alternatives longest-first as belt and braces against
 *      someone later relaxing that to `\s*`. `runSelfTest()` below is a standing regression
 *      test for exactly this: it fails the whole run, distinctly from a data violation, if the
 *      regex is ever edited into the leftmost-first trap.
 *
 *   3. A CITATION CAN NAME MORE THAN ONE PSALM, AND ONLY THE FIRST CARRIES A PREFIX. The Easter
 *      Vigil's seventh psalm is two psalms in one citation: `Psalm 42:3,5;43:3,4`. A matcher
 *      anchored at the start of the string reads the `42` and stops, so a citation whose second
 *      half is still in the wrong numbering passes. That is not hypothetical either — it is how
 *      `Psalmo 41:3,5;43:3,4` shipped in nine leaves, where the bare `43` in a bare-Vulgate file
 *      names Hebrew 44 (*Deus, auribus nostris*) instead of Hebrew 43 (*Iudica me, Deus*). So a
 *      citation is split into its leading reference plus every `;`-joined continuation, each is
 *      checked under the same locale rule (and, for `la`, against ITS own counterpart in `en`,
 *      not against the leading one), and the number of continuations checked is reported in the
 *      summary line. Anything after a `;` that cannot be read as a psalm reference is FAILED,
 *      not skipped.
 *
 * A THIRD wrinkle, orthogonal to the prefix trap: `la` is the one locale whose citation carries
 * only ONE number, so it cannot self-validate the way a dual citation can (a dual citation's
 * own two numbers can be checked against each other via the mapping table). Whether `la`'s bare
 * number is genuinely Vulgate — as opposed to a stray Hebrew number, which is what the corpus
 * held before #973 — can only be told by comparing it against a known-Hebrew reference for the
 * SAME citation. `en` supplies that reference: `en` stays bare Hebrew throughout (this PR only
 * strips its stray duals), it covers every citation `la` has (verified against the corpus), and
 * even its still-to-be-fixed duals carry the Hebrew value in the parenthetical (`la` renumbering
 * keeps that value, per the #973 spec). So for every section, this script reads `en.json` FIRST
 * and builds a per-citation Hebrew-number map from it, then checks `la`'s bare number against
 * the Vulgate value the mapping table derives from that Hebrew number. No other locale needs
 * this: `it`/`fr`/`nl` duals carry both numbers themselves and validate against each other.
 *
 * Hebrew → Vulgate mapping, deterministic except in two verse-dependent zones:
 *
 *   | Hebrew                | Vulgate   |
 *   |-----------------------|-----------|
 *   | 1–8                   | same      |
 *   | 9–10                  | 9         |
 *   | 11–113                | −1        |
 *   | 114–115               | 113       |
 *   | 116:1-9 / 116:10-19   | 114 / 115 |
 *   | 117–146               | −1        |
 *   | 147:1-11 / 147:12-20  | 146 / 147 |
 *   | 148–150               | same      |
 *
 * Psalms 116 and 147 split by VERSE, not by a fixed rule, so a whole-psalm citation of either
 * (no verse numbers at all) is genuinely ambiguous — there is no single correct answer to
 * validate against. Those citations are SKIPPED from the pair/cross-locale check rather than
 * failed, and the count of skips is reported in the summary line so it stays visible rather
 * than silently vanishing into a lower failure count. When a citation DOES carry a verse
 * number, the split is resolved from it (verse ≤ 9 → 114, else 115; verse ≤ 11 → 146, else 147)
 * and checked normally.
 *
 * Usage:
 *   php scripts/lint-lectionary-psalms.php      (composer lint:lectionary-psalms)
 *
 * Exit codes:
 *   0  every recognised psalm citation in every locale file matches that locale's convention.
 *   1  at least one citation is wrong, OR the script's own regex/mapping self-test failed.
 *
 * STATE OF THE CORPUS: green. The lint was introduced RED — 468 violations across
 * `en`/`it`/`fr`/`la`/`nl` — and #973's data fix took it to zero. Any red from here is a
 * regression, not a backlog.
 */

declare(strict_types=1);

// Refuse any entry that is not the CLI. These scripts ship to the server — they are run there per
// the RBAC runbook — and they sit under a path whose `.php` files are handed to php-fpm, so an HTTP
// request can reach them. Inlined per script rather than factored into a shared require: a guard
// that depends on resolving another path has a failure mode that a single constant comparison does
// not. See the same block in lint-missals.php, lint-jsondata.php and lint-locales.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Router;

// Initialize the file-path prefix that JsonData::path() requires.
// Router sets this during HTTP boot; CLI scripts must set it manually.
Router::$apiFilePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;

/**
 * The six psalm prefixes this lint RECOGNISES, ordered LONGEST FIRST so PCRE's leftmost-first
 * alternation cannot mistake `Psalmus`/`Psalmo`/`Psaume` for `Psalm`, or any of the five for
 * `Ps`. Do not reorder this — see the module docblock's trap #2, and `runSelfTest()` below,
 * which fails the whole run if this ever regresses.
 *
 * `Psalmus` is recognised even though NO locale's convention permits it, so that a citation
 * spelled that way is reported as a prefix/locale mismatch rather than passing unseen. This is
 * not a hypothetical spelling: it is the correct Latin nominative, the Nova Vulgata prints
 * `PSALMUS 139 (138)`, and the corpus really did hold four `Psalmus …` citations in `la.json`
 * and `nl.json` until #973 normalised them — citations this lint could not see, because
 * `Psalm\s+` does not match `Psalmus`. Leaving it out made the per-locale counts read 128 for
 * corpora of 130, which is the "check that reports an untruth" failure this repository keeps
 * hitting (#822, #833, #834, #835). Recognising it turns silence into a finding.
 */
const PSALM_CITATION_REGEX = '/^(?:Cf\.?\s*)?(Psalmus|Psalmo|Psaume|Psalm|Salmo|Ps)\s+(\d+)\s*(?:\((\d+)\))?/u';

/**
 * A CONTINUATION reference: a second psalm named inside the same citation after a `;`, with or
 * without repeating the prefix. The Easter Vigil's seventh psalm is two psalms — `Psalm
 * 42:3,5;43:3,4` (Hebrew) — and both numbers need converting, so a lint that only reads the
 * leading reference passes a citation whose second half still names the WRONG PSALM. That is
 * exactly what happened: nine leaves shipped `Psalmo 41:3,5;43:3,4`, where the bare `43` in a
 * bare-Vulgate file resolves to Hebrew 44 (*Deus, auribus nostris*), not Hebrew 43
 * (*Iudica me, Deus*).
 *
 * The prefix is optional because the corpus writes the continuation both ways (`;43:3,4` and
 * `; Psalm 43:3, 4`). The trailing lookahead, rather than a consumed character, keeps the
 * remainder (the continuation's own verse numbers) easy to slice off for the Ps 116/147
 * verse-dependent split.
 *
 * Anything after a `;` that this cannot read as a psalm reference is FAILED, not skipped — see
 * `$parseReferences()`.
 */
const CONTINUATION_REFERENCE_REGEX = '/^\s*(?:(Psalmus|Psalmo|Psaume|Psalm|Salmo|Ps)\s+)?(\d+)\s*(?:\((\d+)\))?(?=$|[,:\s])/u';

/** The prefix each locale's own liturgical books require, per the README's convention table. */
const REQUIRED_PREFIX = [
    'en' => 'Psalm',
    'hr' => 'Ps',
    'it' => 'Salmo',
    'fr' => 'Psaume',
    'nl' => 'Psalm',
    'la' => 'Psalmo',
];

/** Only `la` needs a cross-locale reference to validate its single bare number; see the docblock. */
const CROSS_LOCALE_CHECK_LOCALE = 'la';

/** The locale whose citations supply the Hebrew ground truth `la` is checked against. */
const GROUND_TRUTH_LOCALE = 'en';

/**
 * Match one citation alternative against every psalm prefix the corpus uses.
 *
 * @return array{prefix:string,n1:int,n2:?int,remainder:string}|null null when `$alt` is not a
 *         psalm citation at all (e.g. a reading from another book).
 */
$matchPsalmCitation = static function (string $alt): ?array {
    if (preg_match(PSALM_CITATION_REGEX, $alt, $m) !== 1) {
        return null;
    }

    return [
        'prefix'    => $m[1],
        'n1'        => (int) $m[2],
        'n2'        => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
        // Whatever is left after the matched prefix/number(s) — verse numbers, punctuation, etc.
        // Non-empty exactly when the citation carries verse-level detail rather than naming the
        // whole psalm.
        'remainder' => trim(substr($alt, strlen($m[0]))),
    ];
};

/**
 * Split one citation alternative into every psalm reference it carries: the leading citation,
 * plus each `;`-joined continuation reference.
 *
 * Every reference is checked under the same locale rule, and — for `la` — against its own
 * counterpart in `en`, which is why each carries an index: reference 0 is the leading citation,
 * reference 1 the first continuation, and so on.
 *
 * FAILS CLOSED. A `;` inside a psalm citation whose right-hand side cannot be read as a psalm
 * reference is reported, not ignored: silently skipping it is how the leading-reference-only
 * version of this lint passed nine wrong psalm numbers. (Only values that BEGIN with a psalm
 * citation reach here, so a `;`-joined pair of gospel references such as
 * `John 1:7; Luke 1:17` — which the corpus does hold — is never examined.)
 *
 * @return array{refs:array<int,array{prefix:?string,n1:int,n2:?int,remainder:string,text:string}>,error:?string}|null
 *         null when `$alt` is not a psalm citation at all.
 */
$parseReferences = static function (string $alt) use ($matchPsalmCitation): ?array {
    $leading = $matchPsalmCitation($alt);
    if ($leading === null) {
        return null;
    }

    $refs = [
        [
            'prefix'    => $leading['prefix'],
            'n1'        => $leading['n1'],
            'n2'        => $leading['n2'],
            'remainder' => $leading['remainder'],
            'text'      => trim(explode(';', $alt)[0]),
        ],
    ];

    $segments = explode(';', $leading['remainder']);
    array_shift($segments); // the leading reference's own verse numbers, already accounted for

    foreach ($segments as $segment) {
        if (preg_match(CONTINUATION_REFERENCE_REGEX, $segment, $m) !== 1) {
            return [
                'refs'  => $refs,
                'error' => 'the continuation reference after ";" ("' . trim($segment)
                    . '") could not be read as a psalm reference — a psalm citation may only be continued'
                    . ' by another psalm reference, so this cannot be checked and is failed rather than skipped',
            ];
        }
        $refs[] = [
            'prefix'    => isset($m[1]) && $m[1] !== '' ? $m[1] : null,
            'n1'        => (int) $m[2],
            'n2'        => isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null,
            'remainder' => trim(substr($segment, strlen($m[0]))),
            'text'      => trim($segment),
        ];
    }

    return ['refs' => $refs, 'error' => null];
};

/** Psalms 1–8 and 148–150: Hebrew and Vulgate numbering coincide, so there is nothing to gloss. */
$isAlignedPsalm = static function (int $hebrew): bool {
    return ( $hebrew >= 1 && $hebrew <= 8 ) || ( $hebrew >= 148 && $hebrew <= 150 );
};

/**
 * The non-verse-dependent part of the Hebrew → Vulgate mapping table.
 *
 * Returns null for the two verse-dependent zones (116, 147) — the caller must resolve those via
 * `$resolveAmbiguousVulgate` — and for anything outside the Psalter.
 */
$simpleHebrewToVulgate = static function (int $hebrew): ?int {
    return match (true) {
        $hebrew >= 1 && $hebrew <= 8       => $hebrew,
        $hebrew === 9 || $hebrew === 10    => 9,
        $hebrew >= 11 && $hebrew <= 113    => $hebrew - 1,
        $hebrew === 114 || $hebrew === 115 => 113,
        $hebrew >= 117 && $hebrew <= 146   => $hebrew - 1,
        $hebrew >= 148 && $hebrew <= 150   => $hebrew,
        default                            => null,
    };
};

/** The first verse number appearing in a citation's remainder, or null when there is none. */
$firstVerseNumber = static function (string $remainder): ?int {
    if ($remainder === '' || preg_match('/(\d+)/', $remainder, $m) !== 1) {
        return null;
    }
    return (int) $m[1];
};

/**
 * Resolve Hebrew 116 or 147 to their verse-dependent Vulgate value.
 *
 * @return array{0:?int,1:bool} [vulgate number or null, whether this citation must be SKIPPED
 *         because no verse number is present to resolve the split]
 */
$resolveAmbiguousVulgate = static function (int $hebrew, string $remainder) use ($firstVerseNumber): array {
    $verse = $firstVerseNumber($remainder);
    if ($verse === null) {
        return [null, true];
    }
    return match ($hebrew) {
        116     => [$verse <= 9 ? 114 : 115, false],
        147     => [$verse <= 11 ? 146 : 147, false],
        default => [null, false],
    };
};

/**
 * The Vulgate number that corresponds to a given Hebrew number for this citation.
 *
 * @return array{0:?int,1:bool} [vulgate number or null (unmappable, or ambiguous with no verse
 *         to resolve it), whether the caller must SKIP this citation's pair/cross-locale check]
 */
$expectedVulgateFor = static function (int $hebrew, string $remainder) use ($resolveAmbiguousVulgate, $simpleHebrewToVulgate): array {
    if ($hebrew === 116 || $hebrew === 147) {
        return $resolveAmbiguousVulgate($hebrew, $remainder);
    }
    return [$simpleHebrewToVulgate($hebrew), false];
};

/**
 * Recursively collect every string leaf of a lectionary event structure, with its path.
 *
 * Fails closed on anything that is neither an array to recurse into nor a string leaf: the
 * corpus is not expected to hold booleans, numbers or nulls here, and a shape this does not
 * understand must be reported, not silently skipped — the same principle CLAUDE.md documents
 * for `readingsAreAllEmpty()` and `fileIsAllPlaceholders()`.
 *
 * @param array<int|string,mixed> $data
 * @param string[] $path
 * @param string[] $failures
 * @return array<int,array{path:string[],value:string}>
 */
$collectStringLeaves = static function (array $data, array $path, string $fileLabel, array &$failures) use (&$collectStringLeaves): array {
    $leaves = [];
    foreach ($data as $key => $value) {
        $childPath = [...$path, (string) $key];
        if (is_array($value)) {
            $leaves = [...$leaves, ...$collectStringLeaves($value, $childPath, $fileLabel, $failures)];
        } elseif (is_string($value)) {
            $leaves[] = ['path' => $childPath, 'value' => $value];
        } else {
            $failures[] = sprintf(
                '%s: %s is neither a nested reading block nor a string reading (found %s)',
                $fileLabel,
                implode('/', $childPath),
                get_debug_type($value)
            );
        }
    }
    return $leaves;
};

/**
 * Decode a JSON file, or record a failure and return null.
 *
 * @param string[] $failures
 */
$decodeJsonFile = static function (string $path, string $relativePath, array &$failures): mixed {
    $raw = file_get_contents($path);
    if ($raw === false) {
        $failures[] = "could not read {$relativePath}";
        return null;
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        $failures[] = "could not parse {$relativePath}: {$e->getMessage()}";
        return null;
    }
    if (!is_array($decoded)) {
        $failures[] = "{$relativePath} did not decode to a JSON object of event_key => readings";
        return null;
    }
    return $decoded;
};

/**
 * Build the Hebrew-number ground truth `la` is cross-checked against, from one section's
 * `en.json`. Keyed by "path/joined#altIndex#refIndex" so a `|`-alternative is matched to its
 * own counterpart rather than the first alternative found at that leaf, and a `;`-joined
 * continuation reference to ITS own counterpart rather than to the leading reference.
 *
 * @param string[] $failures
 * @return array<string,int>
 */
$buildHebrewGroundTruth = static function (
    string $enFile,
    string $relativeEnFile,
    array &$failures
) use (
    $decodeJsonFile,
    $collectStringLeaves,
    $parseReferences
): array {
    if (!is_file($enFile)) {
        $failures[] = "{$relativeEnFile} does not exist — cannot cross-check " . CROSS_LOCALE_CHECK_LOCALE
            . "'s numbers against " . GROUND_TRUTH_LOCALE . "'s Hebrew values for this section";
        return [];
    }

    $ignored = [];
    $data    = $decodeJsonFile($enFile, $relativeEnFile, $ignored);
    if ($data === null) {
        $failures[] = "{$relativeEnFile} could not be read — cannot cross-check " . CROSS_LOCALE_CHECK_LOCALE
            . "'s numbers against " . GROUND_TRUTH_LOCALE . "'s Hebrew values for this section";
        return [];
    }

    $groundTruth = [];
    foreach ($collectStringLeaves($data, [], $relativeEnFile, $ignored) as $leaf) {
        $alts = explode('|', $leaf['value']);
        foreach ($alts as $altIndex => $altRaw) {
            $alt = trim($altRaw);
            if ($alt === '') {
                continue;
            }
            $parsed = $parseReferences($alt);
            if ($parsed === null) {
                continue;
            }
            foreach ($parsed['refs'] as $refIndex => $ref) {
                // en is bare Hebrew, so the Hebrew value is the bare number; should a dual ever
                // reappear there it is `Vulgate (Hebrew)`, so the parenthesised one wins.
                $hebrew            = $ref['n2'] ?? $ref['n1'];
                $key               = implode('/', $leaf['path']) . '#' . $altIndex . '#' . $refIndex;
                $groundTruth[$key] = $hebrew;
            }
        }
    }
    return $groundTruth;
};

/**
 * Standing regression test for the two traps the module docblock describes. Runs before any
 * corpus data is read; a failure here is an INTERNAL error in the script itself (the regex or
 * the mapping table regressed), reported and failed distinctly from a data violation.
 *
 * @return string[] human-readable descriptions of every failed assertion (empty when all pass)
 */
$runSelfTest = static function () use ($matchPsalmCitation, $parseReferences, $simpleHebrewToVulgate, $expectedVulgateFor): array {
    $failures = [];

    $assertPrefix = static function (string $alt, string $expectedPrefix, int $expectedN1) use ($matchPsalmCitation, &$failures): void {
        $m = $matchPsalmCitation($alt);
        if ($m === null) {
            $failures[] = "matchPsalmCitation('{$alt}') did not match at all";
            return;
        }
        if ($m['prefix'] !== $expectedPrefix) {
            $failures[] = "matchPsalmCitation('{$alt}') resolved prefix '{$m['prefix']}', expected '{$expectedPrefix}'"
                . ' — the leftmost-first alternation trap has regressed';
        }
        if ($m['n1'] !== $expectedN1) {
            $failures[] = "matchPsalmCitation('{$alt}') resolved number {$m['n1']}, expected {$expectedN1}";
        }
    };

    // Trap #2: each of the five overlapping prefixes must resolve to itself, not a shorter one.
    $assertPrefix('Psalmus 71', 'Psalmus', 71);
    $assertPrefix('Psalmo 71', 'Psalmo', 71);
    $assertPrefix('Psalm 71', 'Psalm', 71);
    $assertPrefix('Psaume 71', 'Psaume', 71);
    $assertPrefix('Salmo 71', 'Salmo', 71);
    $assertPrefix('Ps 71', 'Ps', 71);
    // The `Cf.` prefix, and a dual citation, must not disturb prefix resolution.
    $assertPrefix('Cf. Psalmo 71', 'Psalmo', 71);
    $assertPrefix('Salmo 88 (89)', 'Salmo', 88);

    // Trap #1, the `Psalmus` half: `Psalmus` must be RECOGNISED (so it can be reported as a
    // prefix no locale allows) and must never be swallowed by the shorter `Psalm`. Dropping it
    // from PSALM_CITATION_REGEX makes matchPsalmCitation() return null here, which this asserts.
    // It is not an exotic spelling: it is the Latin nominative and the form the Nova Vulgata
    // prints, and the corpus held four of them invisibly until #973.
    $psalmusMatch = $matchPsalmCitation('Psalmus 109, 1b-e.2.3');
    if ($psalmusMatch === null) {
        $failures[] = "matchPsalmCitation('Psalmus 109, 1b-e.2.3') did not match — `Psalmus` has been dropped from"
            . ' PSALM_CITATION_REGEX, so citations spelled that way are invisible to this lint rather than reported';
    } elseif ($psalmusMatch['prefix'] !== 'Psalmus') {
        $failures[] = "matchPsalmCitation('Psalmus 109, 1b-e.2.3') resolved prefix '{$psalmusMatch['prefix']}',"
            . " expected 'Psalmus' — the leftmost-first alternation trap has regressed";
    }
    if (array_key_exists('Psalmus', REQUIRED_PREFIX)) {
        $failures[] = "'Psalmus' is listed in REQUIRED_PREFIX — it is recognised so it can be REPORTED,"
            . ' and must never be a valid prefix for any locale';
    }

    // Trap #3: a citation naming a second psalm after a `;` must yield TWO references, the second
    // carrying its own number (and its own optional prefix). If continuation parsing is removed,
    // only the leading reference comes back and these assertions fail.
    $assertReferences = static function (string $alt, array $expected) use ($parseReferences, &$failures): void {
        $parsed = $parseReferences($alt);
        if ($parsed === null) {
            $failures[] = "parseReferences('{$alt}') did not recognise a psalm citation at all";
            return;
        }
        if ($parsed['error'] !== null) {
            $failures[] = "parseReferences('{$alt}') reported an error it should not have: {$parsed['error']}";
            return;
        }
        $actual = array_map(
            static fn (array $ref): array => [$ref['prefix'], $ref['n1'], $ref['n2']],
            $parsed['refs']
        );
        if ($actual !== $expected) {
            $failures[] = "parseReferences('{$alt}') resolved " . json_encode($actual) . ', expected '
                . json_encode($expected) . ' — a `;`-joined continuation reference is being missed, so the'
                . ' second psalm it names would go unchecked';
        }
    };
    // The Easter Vigil's seventh psalm, in each of the two shapes the corpus writes it.
    $assertReferences('Psalm 42:3,5;43:3,4', [['Psalm', 42, null], [null, 43, null]]);
    $assertReferences('Psalm 42:2, 3; Psalm 43:3, 4', [['Psalm', 42, null], ['Psalm', 43, null]]);
    $assertReferences('Salmo 41 (42), 3.5;42 (43), 3.4', [['Salmo', 41, 42], [null, 42, 43]]);
    $assertReferences('Psalmo 41:3,5;42:3,4', [['Psalmo', 41, null], [null, 42, null]]);
    // A citation with no `;` must still yield exactly one reference.
    $assertReferences('Psalmo 88, 4-5', [['Psalmo', 88, null]]);

    // Fail closed: something after a `;` that is not a psalm reference must be REPORTED, never
    // silently dropped. (Only values that begin with a psalm citation ever reach parseReferences,
    // so `John 1:7; Luke 1:17` — which the corpus does hold — is never examined.)
    $unclassifiable = $parseReferences('Psalm 42:3,5; Luke 1:17');
    if ($unclassifiable === null || $unclassifiable['error'] === null) {
        $failures[] = "parseReferences('Psalm 42:3,5; Luke 1:17') did not report an unreadable continuation —"
            . ' continuation parsing must fail closed, not skip what it cannot classify';
    }

    // Non-psalm readings must not match at all.
    if ($matchPsalmCitation('Isaiah 49:1-6') !== null) {
        $failures[] = "matchPsalmCitation('Isaiah 49:1-6') matched a non-psalm citation";
    }

    // Mapping table spot checks, one per row.
    $mappingCases = [
        [5, 5],
        [9, 9],
        [10, 9],
        [12, 11],
        [113, 112],
        [115, 113],
        [120, 119],
        [146, 145],
        [148, 148],
        [150, 150],
    ];
    foreach ($mappingCases as [$hebrew, $expectedVulgate]) {
        $actual = $simpleHebrewToVulgate($hebrew);
        if ($actual !== $expectedVulgate) {
            $failures[] = "simpleHebrewToVulgate({$hebrew}) = " . var_export($actual, true) . ", expected {$expectedVulgate}";
        }
    }

    // The two verse-dependent zones: resolved when a verse is present, skipped when it is not.
    [$vulgate, $skip] = $expectedVulgateFor(116, ':5-6');
    if ($skip || $vulgate !== 114) {
        $failures[] = 'expectedVulgateFor(116, verse 5) should resolve to 114 without skipping';
    }
    [$vulgate, $skip] = $expectedVulgateFor(116, ':12-13');
    if ($skip || $vulgate !== 115) {
        $failures[] = 'expectedVulgateFor(116, verse 12) should resolve to 115 without skipping';
    }
    [, $skip] = $expectedVulgateFor(116, '');
    if (!$skip) {
        $failures[] = 'expectedVulgateFor(116, no verse) should be skipped, since the split is verse-dependent';
    }
    [$vulgate, $skip] = $expectedVulgateFor(147, ':5');
    if ($skip || $vulgate !== 146) {
        $failures[] = 'expectedVulgateFor(147, verse 5) should resolve to 146 without skipping';
    }
    [$vulgate, $skip] = $expectedVulgateFor(147, ':15-16');
    if ($skip || $vulgate !== 147) {
        $failures[] = 'expectedVulgateFor(147, verse 15) should resolve to 147 without skipping';
    }
    [, $skip] = $expectedVulgateFor(147, '');
    if (!$skip) {
        $failures[] = 'expectedVulgateFor(147, no verse) should be skipped, since the split is verse-dependent';
    }

    return $failures;
};

$selfTestFailures = $runSelfTest();
if ($selfTestFailures !== []) {
    fwrite(STDERR, "lint:lectionary-psalms INTERNAL ERROR — the script's own self-test failed:\n");
    foreach ($selfTestFailures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    fwrite(STDERR, "\nThis is a defect in lint-lectionary-psalms.php itself, not in the corpus. Fix the script.\n");
    exit(1);
}

/** Render a path relative to the project root, so failure lines stay readable. */
$relativePath = static function (string $path): string {
    return str_starts_with($path, Router::$apiFilePath)
        ? substr($path, strlen(Router::$apiFilePath))
        : $path;
};

/** @var string[] $failures human-readable descriptions of every citation that fails its locale's convention */
$failures = [];

/** @var array<string,int> $citationCounts recognised-psalm-citation count per locale */
$citationCounts = [];

/** Count of citations skipped from the pair/cross-locale check because Ps 116/147 has no verse to resolve its split. */
$skipCount = 0;

/** Count of `la` citations that could not be verified because `en` had no matching citation at the same leaf. */
$unverifiableCount = 0;

/**
 * Count of `;`-joined continuation references checked in addition to their leading citation.
 * Reported in the summary line for the same reason the per-locale counts are: a zero here would
 * mean the continuation matcher had stopped seeing the Easter Vigil's two-psalm citations, which
 * is precisely the silence that let nine wrong psalm numbers through before it existed.
 */
$continuationCount = 0;

$lectionaryFolder = JsonData::LECTIONARY_FOLDER->path();
if (!is_dir($lectionaryFolder)) {
    fwrite(STDERR, 'lint:lectionary-psalms FAILED — the lectionary folder does not exist: ' . $relativePath($lectionaryFolder) . "\n");
    exit(1);
}

$sectionEntries = scandir($lectionaryFolder);
if ($sectionEntries === false) {
    fwrite(STDERR, 'lint:lectionary-psalms FAILED — could not list ' . $relativePath($lectionaryFolder) . "\n");
    exit(1);
}

$sectionCount = 0;

foreach ($sectionEntries as $sectionEntry) {
    if ($sectionEntry === '.' || $sectionEntry === '..') {
        continue;
    }
    $sectionPath = $lectionaryFolder . DIRECTORY_SEPARATOR . $sectionEntry;
    if (!is_dir($sectionPath)) {
        continue;
    }
    ++$sectionCount;

    $enFile            = $sectionPath . DIRECTORY_SEPARATOR . GROUND_TRUTH_LOCALE . '.json';
    $hebrewGroundTruth = $buildHebrewGroundTruth($enFile, $relativePath($enFile), $failures);

    $localeFiles = glob($sectionPath . DIRECTORY_SEPARATOR . '*.json') ?: [];
    sort($localeFiles);

    foreach ($localeFiles as $localeFile) {
        $locale       = basename($localeFile, '.json');
        $relativeFile = $relativePath($localeFile);

        if (!array_key_exists($locale, REQUIRED_PREFIX)) {
            $failures[] = "{$relativeFile}: '{$locale}' is not one of the locales this lint knows a psalm convention for ("
                . implode(', ', array_keys(REQUIRED_PREFIX)) . ') — add its convention to the lint and the README before adding the file';
            continue;
        }
        $requiredPrefix = REQUIRED_PREFIX[$locale];

        $data = $decodeJsonFile($localeFile, $relativeFile, $failures);
        if ($data === null) {
            continue;
        }

        $leaves = $collectStringLeaves($data, [], $relativeFile, $failures);
        foreach ($leaves as $leaf) {
            $alts     = explode('|', $leaf['value']);
            $altCount = count($alts);
            foreach ($alts as $altIndex => $altRaw) {
                $alt = trim($altRaw);
                if ($alt === '') {
                    continue;
                }
                $parsed = $parseReferences($alt);
                if ($parsed === null) {
                    // Not a psalm citation — some other reading (Isaiah, Acts, ...). Nothing to check.
                    continue;
                }

                $citationCounts[$locale] = ( $citationCounts[$locale] ?? 0 ) + 1;

                $where = implode('/', $leaf['path']) . ( $altCount > 1 ? ' [alt ' . ( $altIndex + 1 ) . ']' : '' );
                $label = "{$relativeFile}: {$where}: \"{$alt}\"";

                if ($parsed['error'] !== null) {
                    $failures[] = "{$label} — {$parsed['error']}";
                    continue;
                }

                // Reference 0 is the leading citation; 1+ are its `;`-joined continuations. Every
                // one is held to the SAME locale rule: a continuation left in the wrong numbering
                // names a different psalm just as surely as a leading reference does.
                foreach ($parsed['refs'] as $refIndex => $ref) {
                    $refLabel = $refIndex === 0
                        ? $label
                        : "{$label} — continuation reference \"{$ref['text']}\"";
                    if ($refIndex > 0) {
                        ++$continuationCount;
                    }

                    // A continuation may omit the prefix (`;43:3,4`); the leading reference never
                    // can, since the citation regex requires one. When a continuation DOES carry a
                    // prefix it must be this locale's, exactly as the leading reference's must.
                    if ($ref['prefix'] !== null && $ref['prefix'] !== $requiredPrefix) {
                        $failures[] = "{$refLabel} — uses prefix '{$ref['prefix']}', but {$locale} requires '{$requiredPrefix}'";
                        // A wrong prefix likely means a wrong numbering system entirely; further checks
                        // against this locale's own rules would not be meaningful.
                        continue;
                    }

                    $n1 = $ref['n1'];
                    $n2 = $ref['n2'];

                    // The Psalter has 150 psalms in both numberings, so anything outside 1-150 is
                    // not a psalm at all. Checked here, before the per-locale branches, rather than
                    // inside them: `it`/`fr`/`nl`/`la` would be caught downstream by the mapping
                    // table returning no equivalent, but `en` and `hr` take a bare number and
                    // `continue` without consulting it, so an out-of-range citation would pass
                    // there unexamined — a green verdict over a reference the lint never judged.
                    foreach ([$n1, $n2] as $number) {
                        if ($number !== null && ( $number < 1 || $number > 150 )) {
                            $failures[] = "{$refLabel} — psalm number {$number} is outside the Psalter's range of 1-150";
                            continue 2;
                        }
                    }

                    if ($locale === 'en' || $locale === 'hr') {
                        if ($n2 !== null) {
                            $failures[] = "{$refLabel} — carries a parenthetical gloss, but {$locale} must be bare Hebrew with no gloss";
                        }
                        continue;
                    }

                    if ($locale === CROSS_LOCALE_CHECK_LOCALE) { // 'la'
                        if ($n2 !== null) {
                            $failures[] = "{$refLabel} — carries a parenthetical gloss, but la must be bare Vulgate with no gloss";
                            continue;
                        }
                        $groundTruthKey = implode('/', $leaf['path']) . '#' . $altIndex . '#' . $refIndex;
                        if (!array_key_exists($groundTruthKey, $hebrewGroundTruth)) {
                            $failures[] = "{$refLabel} — cannot verify: no matching " . GROUND_TRUTH_LOCALE
                                . ' citation at the same reading to derive the Hebrew number from';
                            ++$unverifiableCount;
                            continue;
                        }
                        $hebrew                   = $hebrewGroundTruth[$groundTruthKey];
                        [$expectedVulgate, $skip] = $expectedVulgateFor($hebrew, $ref['remainder']);
                        if ($skip) {
                            ++$skipCount;
                            continue;
                        }
                        if ($expectedVulgate === null || $n1 !== $expectedVulgate) {
                            $failures[] = "{$refLabel} — bare number {$n1} is not the Vulgate equivalent of Hebrew {$hebrew}"
                                . ( $expectedVulgate !== null ? " (expected {$expectedVulgate})" : ' (unmappable Hebrew number)' );
                        }
                        continue;
                    }

                    // it, fr, nl: dual-citation locales. it/fr are Vulgate(Hebrew); nl is Hebrew(Vulgate).
                    // The parenthetical (n2 from the regex) is always the SECOND number written,
                    // regardless of which numbering system it represents for this locale — so "is
                    // the gloss missing" is always "$n2 === null", never role-dependent.
                    $aligned = $isAlignedPsalm($n1); // leading number; aligned range is the same in both systems

                    if ($aligned) {
                        if ($n2 !== null) {
                            $failures[] = "{$refLabel} — glosses psalm {$n1}, which is in the aligned range (1-8, 148-150) where"
                                . ' Hebrew and Vulgate coincide — there is nothing to gloss';
                        }
                        continue;
                    }

                    if ($n2 === null) {
                        $failures[] = "{$refLabel} — is missing the parenthetical gloss {$locale} requires for a psalm outside the aligned range (1-8, 148-150)";
                        continue;
                    }

                    $vulgateWritten = $locale === 'nl' ? $n2 : $n1;
                    $hebrewWritten  = $locale === 'nl' ? $n1 : $n2;

                    [$expectedVulgate, $skip] = $expectedVulgateFor($hebrewWritten, $ref['remainder']);
                    if ($skip) {
                        ++$skipCount;
                        continue;
                    }
                    if ($expectedVulgate === null || $vulgateWritten !== $expectedVulgate) {
                        $failures[] = "{$refLabel} — numbers {$n1} ({$n2}) are not a valid Hebrew/Vulgate pair per the mapping table"
                            . ( $expectedVulgate !== null ? " (Hebrew {$hebrewWritten} maps to Vulgate {$expectedVulgate})" : '' );
                    }
                }
            }
        }
    }
}

$totalCitations = array_sum($citationCounts);
$countsSummary  = implode(', ', array_map(
    static fn (string $locale): string => "{$locale}=" . ( $citationCounts[$locale] ?? 0 ),
    array_keys(REQUIRED_PREFIX)
));

printf(
    'lint:lectionary-psalms — checked %d recognised psalm citation(s) (plus %d ";"-joined continuation reference(s))'
        . ' across %d section(s): %s (%d skipped: Ps 116/147 whole-psalm citations with no verse to resolve their'
        . " Hebrew/Vulgate split%s).\n",
    $totalCitations,
    $continuationCount,
    $sectionCount,
    $countsSummary,
    $skipCount,
    $unverifiableCount > 0 ? "; {$unverifiableCount} la citation(s) could not be verified for want of a matching en citation" : ''
);

if ($failures === []) {
    printf("lint:lectionary-psalms OK — every recognised citation matches its locale's psalm numbering convention.\n");
    exit(0);
}

fwrite(STDERR, 'lint:lectionary-psalms FAILED — ' . count($failures) . " citation(s) break their locale's psalm numbering convention:\n");
foreach ($failures as $failure) {
    fwrite(STDERR, "  - {$failure}\n");
}
fwrite(
    STDERR,
    "\nEach locale's own liturgical books number the Psalter differently (Hebrew/Masoretic vs Greek/Vulgate); a\n"
    . "citation in the wrong numbering names a DIFFERENT PSALM, not a differently-formatted one. See\n"
    . "jsondata/sourcedata/rite/roman/lectionary/README.md for the convention table, sources, and the\n"
    . "Hebrew -> Vulgate mapping table. Convert the citation to the numbering (and gloss, where required)\n"
    . "the file's own locale calls for; do not just reformat it.\n"
);
exit(1);
