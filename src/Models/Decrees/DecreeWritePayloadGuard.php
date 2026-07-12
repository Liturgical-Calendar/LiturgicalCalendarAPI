<?php

namespace LiturgicalCalendar\Api\Models\Decrees;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;

/**
 * Enforces the per-action sidecar matrix for decree write payloads
 * (spec: docs/superpowers/specs/2026-07-11-decrees-write-paths-design.md).
 *
 * | action              | i18n                | readings                              |
 * |---------------------|---------------------|---------------------------------------|
 * | createNew           | required            | required on PUT, optional on PATCH    |
 * | makeDoctor          | required            | rejected on PUT, optional on PATCH    |
 * | setProperty (name)  | required            | rejected on PUT, optional on PATCH    |
 * | setProperty (grade) | rejected            | rejected on PUT, optional on PATCH    |
 */
final class DecreeWritePayloadGuard
{
    /**
     * @throws ValidationException when the payload violates the sidecar matrix
     */
    public static function assertSidecars(\stdClass $payload, string $baseLocale, bool $isCreate): void
    {
        $metadata = property_exists($payload, 'metadata') ? $payload->metadata : null;
        if (!$metadata instanceof \stdClass) {
            $metadata = new \stdClass();
        }
        $action   = property_exists($metadata, 'action') ? $metadata->action : null;
        $property = property_exists($metadata, 'property') ? $metadata->property : null;
        $hasI18n  = property_exists($payload, 'i18n') && $payload->i18n instanceof \stdClass;
        $hasRead  = property_exists($payload, 'readings') && $payload->readings instanceof \stdClass;

        $nameBearing = in_array($action, ['createNew', 'makeDoctor'], true)
            || ( $action === 'setProperty' && $property === 'name' );

        if ($nameBearing) {
            if (!$hasI18n || count(get_object_vars($payload->i18n)) === 0) {
                $actionStr   = is_string($action) ? $action : 'unknown';
                $propertyStr = is_string($property) ? $property : null;
                throw new ValidationException(
                    "Decrees with metadata.action `{$actionStr}`" . ( $propertyStr !== null ? " (property `{$propertyStr}`)" : '' )
                    . ' require an `i18n` object with at least one entry'
                );
            }
            if (!property_exists($payload->i18n, $baseLocale)) {
                throw new ValidationException(
                    "The `i18n` object must contain an entry for the Accept-Language base locale `{$baseLocale}`"
                );
            }
        } elseif ($hasI18n) {
            $actionStr   = is_string($action) ? $action : 'unknown';
            $propertyStr = is_string($property) ? " and property `{$property}`" : '';
            throw new ValidationException(
                "Decrees with metadata.action `{$actionStr}`{$propertyStr} do not affect the event name: the `i18n` object is not allowed"
            );
        }

        if ($isCreate) {
            if ($action === 'createNew' && !$hasRead) {
                throw new ValidationException(
                    'Decrees with metadata.action `createNew` require a `readings` object when creating: a new liturgical event must define its lectionary readings'
                );
            }
            if ($action !== 'createNew' && $hasRead) {
                $actionStr = is_string($action) ? $action : 'unknown';
                throw new ValidationException(
                    "Decrees with metadata.action `{$actionStr}` do not accept a `readings` object on creation; readings may only be corrected via PATCH"
                );
            }
        }
        // On PATCH (isCreate === false) readings are optional for every action.

        // FINDING 4: validate that i18n and readings locale keys are actually valid locales.
        // The schema regex (^[a-z]{2,3}$) catches format; here we reject codes that are not
        // recognised by LitLocale (which covers ICU locales plus 'la'/'la_VA').
        if ($hasI18n) {
            foreach (array_keys(get_object_vars($payload->i18n)) as $localeKey) {
                if (!LitLocale::isValid($localeKey)) {
                    throw new ValidationException(
                        "The `i18n` object contains an invalid locale key `{$localeKey}`"
                    );
                }
            }
        }
        if ($hasRead) {
            foreach (array_keys(get_object_vars($payload->readings)) as $localeKey) {
                if (!LitLocale::isValid($localeKey)) {
                    throw new ValidationException(
                        "The `readings` object contains an invalid locale key `{$localeKey}`"
                    );
                }
            }
        }
    }
}
