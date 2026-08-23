<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * A step in a check, in the vocabulary `GET /validations` publishes.
 *
 * `CheckableInventory::STEPS` advertises `exists`, `parses`, `validates`, and `CheckableInventory::STEPS_WITH_COVERAGE`
 * adds `covers` for the items that declare an expected locale set; until this enum those words never
 * reached the wire, because the frames were classed `file-exists` / `json-valid` / `schema-valid` and
 * nothing related the two vocabularies (#819). This enum is the published vocabulary, and
 * {@see FrameFamily::frameClasses()} projects it onto the legacy class names.
 *
 * `COMPLETE` is not a check; it is the terminal frame that lets a client stop without counting (#821).
 */
enum Step: string
{
    case EXISTS    = 'exists';
    case PARSES    = 'parses';
    case VALIDATES = 'validates';

    /**
     * Whether a folder holds a file for every locale its owner declares.
     *
     * A different question from the other three, which all ask whether what is present is well-formed;
     * this one asks whether anything is missing. Advertised only by items that carry an expected locale
     * set, which is every folder whose expectation has an authority other than the folder itself — so
     * not a wider region's or a missal's own `i18n` folder, where the declared locales are scanned from
     * that very folder and the comparison would be a tautology.
     */
    case COVERS   = 'covers';
    case COMPLETE = 'complete';
}
