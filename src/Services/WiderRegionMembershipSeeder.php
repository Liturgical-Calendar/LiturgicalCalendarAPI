<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * Derives what every national calendar source file declares (each nation's `metadata.wider_regions`, or the
 * legacy `metadata.wider_region`) and reconciles OpenFGA's `member_nation` tuples to match:
 *   wider_region:<Region>#member_nation@national_calendar:<Nation>
 *
 * Membership powers the wider_region admin TTU (`admin from member_nation`), so a
 * national admin inherits admin on their wider region (#669).
 */
final class WiderRegionMembershipSeeder
{
    /**
     * What every national calendar file declares, nation => regions, most general first.
     *
     * @return array<string, list<string>>
     */
    public function declaredRegions(string $nationsDir): array
    {
        $declared = [];
        $dirs     = glob($nationsDir . '/*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return [];
        }
        foreach ($dirs as $dir) {
            $nation = basename($dir);
            $file   = "{$dir}/{$nation}.json";
            if (!is_file($file)) {
                continue;
            }
            $raw  = file_get_contents($file);
            $data = $raw === false ? null : json_decode($raw, true);
            if (!is_array($data)) {
                throw new \RuntimeException("Unreadable or invalid national calendar file: {$file}");
            }
            $meta              = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $list              = $meta['wider_regions'] ?? null;
            $legacy            = $meta['wider_region'] ?? null;
            $declared[$nation] = is_array($list)
                ? array_values(array_filter($list, 'is_string'))
                : ( is_string($legacy) && $legacy !== '' ? [$legacy] : [] );
        }
        ksort($declared);

        return $declared;
    }

    /**
     * Reconcile every nation: those with a file to what it declares, those holding tuples but no file to none.
     *
     * @return array{writes: list<string>, deletes: list<string>}
     */
    public function reconcile(WiderRegionMembershipReconciler $reconciler, string $nationsDir, bool $apply): array
    {
        $declared = $this->declaredRegions($nationsDir);
        foreach ($reconciler->nationsWithTuples() as $nation) {
            $declared[$nation] ??= [];
        }

        $writes  = [];
        $deletes = [];
        foreach ($declared as $nation => $regions) {
            $result  = $reconciler->syncNation($nation, $regions, $apply);
            $writes  = array_merge($writes, $result['writes']);
            $deletes = array_merge($deletes, $result['deletes']);
        }

        return ['writes' => $writes, 'deletes' => $deletes];
    }
}
