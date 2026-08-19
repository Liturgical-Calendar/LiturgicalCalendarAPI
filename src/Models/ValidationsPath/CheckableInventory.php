<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Router;

/**
 * The source data this API can validate, in one place.
 *
 * Previously the same knowledge lived in `Health`'s path-to-schema table, in a parallel branch
 * that matched on slugs instead of paths, and in each client's hardcoded copy of the layout —
 * with nothing detecting divergence. See #806.
 *
 * Half of it need not be written down at all: `RomanMissal` already registers every missal edition
 * and already knows which have a sanctorale file, so those items are derived. The rest have
 * dedicated `JsonData` constants and are listed explicitly.
 *
 * Paths always come from `JsonData` cases. `JsonData` is where this repo's layout is written down;
 * this class must not become a second copy of it.
 */
final class CheckableInventory
{
    /** @var list<string> Every item is checked the same three ways today. */
    private const STEPS = ['exists', 'parses', 'validates'];

    /** @var list<CheckableItem>|null */
    private static ?array $items = null;

    /** @return list<CheckableItem> */
    public static function all(): array
    {
        if (null === self::$items) {
            self::$items = array_merge(self::derivedRomanSanctorale(), self::explicitItems());
        }

        return self::$items;
    }

    public static function byId(string $id): ?CheckableItem
    {
        return array_find(self::all(), static fn (CheckableItem $i): bool => $i->id === $id);
    }

    /**
     * `Health` compares against `JsonData::*->value`, i.e. repo-relative paths, while this
     * inventory stores the absolute form the `JsonData` and `RomanMissal` accessors return.
     * Normalise here so neither caller has to know which representation it is holding.
     */
    public static function byPath(string $path): ?CheckableItem
    {
        $needle = str_starts_with($path, Router::$apiFilePath)
            ? $path
            : Router::$apiFilePath . ltrim($path, '/');
        $needle = rtrim($needle, '/');

        return array_find(
            self::all(),
            static fn (CheckableItem $i): bool => rtrim($i->path, '/') === $needle
        );
    }

    /**
     * The Roman sanctorale, derived from the missal registry rather than restated.
     *
     * `getSanctoraleFileName()` returns false for the editions that have no sanctorale file on
     * disk, which is exactly how the five that do were picked in the old hand-written table. A new
     * edition with a sanctorale file joins the inventory with no edit here.
     *
     * @return list<CheckableItem>
     */
    private static function derivedRomanSanctorale(): array
    {
        $items = [];
        foreach (RomanMissal::getMissalIds() as $missalId) {
            $file = RomanMissal::getSanctoraleFileName($missalId);
            if (false === $file) {
                continue;
            }

            $name = RomanMissal::getName($missalId);
            // 'VA' in produceMetadata() means "not nation-specific"; this inventory says so with null.
            $region = str_starts_with($missalId, 'EDITIO_TYPICA_') ? null : explode('_', $missalId)[0];

            $items[] = new CheckableItem(
                "sanctorale:roman:{$missalId}",
                'file',
                Rite::ROMAN,
                $region,
                $name,
                LitSchema::PROPRIUMDESANCTIS,
                self::STEPS,
                $file
            );

            $i18n = RomanMissal::getSanctoraleI18nFilePath($missalId);
            if (false !== $i18n) {
                $items[] = new CheckableItem(
                    "sanctorale:roman:{$missalId}:i18n",
                    'folder',
                    Rite::ROMAN,
                    $region,
                    "{$name} translations",
                    LitSchema::I18N,
                    self::STEPS,
                    rtrim($i18n, '/')
                );
            }
        }

        return $items;
    }

    /** @return list<CheckableItem> */
    private static function explicitItems(): array
    {
        return [
            new CheckableItem(
                'temporale:roman',
                'file',
                Rite::ROMAN,
                null,
                'Roman Proprium de Tempore',
                LitSchema::PROPRIUMDETEMPORE,
                self::STEPS,
                JsonData::TEMPORALE_FILE->path()
            ),
            new CheckableItem(
                'temporale:roman:i18n',
                'folder',
                Rite::ROMAN,
                null,
                'Roman Proprium de Tempore translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::TEMPORALE_I18N_FOLDER->path()
            ),
            new CheckableItem(
                'decrees:roman',
                'file',
                Rite::ROMAN,
                null,
                'Memorials from Decrees',
                LitSchema::DECREES_SRC,
                self::STEPS,
                JsonData::DECREES_FILE->path()
            ),
            new CheckableItem(
                'decrees:roman:i18n',
                'folder',
                Rite::ROMAN,
                null,
                'Memorials from Decrees translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::DECREES_I18N_FOLDER->path()
            ),
            new CheckableItem(
                'temporale:ambrosian',
                'file',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Tempore',
                LitSchema::PROPRIUMDETEMPORE,
                self::STEPS,
                JsonData::AMBROSIAN_TEMPORALE_FILE->path()
            ),
            new CheckableItem(
                'temporale:ambrosian:i18n',
                'folder',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Tempore translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::AMBROSIAN_TEMPORALE_I18N_FOLDER->path()
            ),
            new CheckableItem(
                'sanctorale:ambrosian',
                'file',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Sanctis',
                LitSchema::PROPRIUMDESANCTIS,
                self::STEPS,
                JsonData::AMBROSIAN_SANCTORALE_FILE->path()
            ),
            new CheckableItem(
                'sanctorale:ambrosian:i18n',
                'folder',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Sanctis translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::AMBROSIAN_SANCTORALE_I18N_FOLDER->path()
            )
        ];
    }
}
