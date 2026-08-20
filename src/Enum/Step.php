<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * A step in a check, in the vocabulary `GET /validations` publishes.
 *
 * `CheckableInventory::STEPS` advertises `exists`, `parses`, `validates`; until now those words never
 * reached the wire, because the frames were classed `file-exists` / `json-valid` / `schema-valid` and
 * nothing related the two vocabularies (#819). This enum is the published vocabulary, and
 * {@see \LiturgicalCalendar\Api\Health::FRAME_CLASS_FOR_STEP} projects it onto the legacy class names.
 *
 * `COMPLETE` is not a check; it is the terminal frame that lets a client stop without counting (#821).
 */
enum Step: string
{
    case EXISTS    = 'exists';
    case PARSES    = 'parses';
    case VALIDATES = 'validates';
    case COMPLETE  = 'complete';
}
