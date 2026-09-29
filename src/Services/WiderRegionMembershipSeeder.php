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
            $declared[$nation] = self::regionsFromMetadata($data['metadata'] ?? null);
        }
        ksort($declared);

        return $declared;
    }

    /**
     * The regions a national calendar file's `metadata` declares — `wider_regions` (a list) if present, else the
     * legacy `wider_region` (a single string) as a one-element list, else none.
     *
     * The single shared definition of this mapping: {@see declaredRegions()} and
     * {@see \LiturgicalCalendar\Api\Services\SourceData\MergePollRunner::syncWiderRegionMembership()} both read a
     * national calendar's declared regions from JSON that may or may not have been through Task 6's normalisation
     * yet, and must agree on what a legacy-shaped file means — a runner that read the legacy string as "no
     * regions" would DELETE a nation's real membership the moment a pre-normalisation row merged (#1005 review).
     *
     * @return list<string>
     */
    public static function regionsFromMetadata(mixed $metadata): array
    {
        $meta   = is_array($metadata) ? $metadata : [];
        $list   = $meta['wider_regions'] ?? null;
        $legacy = $meta['wider_region'] ?? null;

        return is_array($list)
            ? array_values(array_filter($list, 'is_string'))
            : ( is_string($legacy) && $legacy !== '' ? [$legacy] : [] );
    }

    /**
     * Reconcile every nation: those with a file to what it declares, those holding tuples but no file to none.
     *
     * "No file" counts as a removal only when the nation's folder is gone too, which is what a real removal leaves (an
     * applied DELETE removes the folder, and a merged one leaves git nothing to keep it). A folder that exists without
     * its `{N}.json` is a partial tree, so a nation there is skipped and reported, never pruned.
     *
     * @return array{writes: list<string>, deletes: list<string>, skipped: list<string>}
     */
    public function reconcile(WiderRegionMembershipReconciler $reconciler, string $nationsDir, bool $apply): array
    {
        // A reconcile that finds no national calendar at all is reading a missing or half-written tree (a deploy in
        // progress, a bad mount), not a deployment without nations: every nation holding tuples would be pruned to
        // none. Refuse before contacting OpenFGA, so a scheduled run fails loudly instead of revoking access. The
        // check is shared with every other destructive job (#1015).
        $refusal = ( new SourceTreeGuard($nationsDir) )->refusalReason();
        if ($refusal !== null) {
            throw new \RuntimeException($refusal);
        }
        $declared = $this->declaredRegions($nationsDir);
        $skipped  = [];
        foreach ($reconciler->nationsWithTuples() as $nation) {
            if (array_key_exists($nation, $declared)) {
                continue;
            }
            if (is_dir("{$nationsDir}/{$nation}")) {
                $skipped[] = $nation;
                continue;
            }
            $declared[$nation] = [];
        }

        $writes  = [];
        $deletes = [];
        foreach ($declared as $nation => $regions) {
            $result  = $reconciler->syncNation($nation, $regions, $apply);
            $writes  = array_merge($writes, $result['writes']);
            $deletes = array_merge($deletes, $result['deletes']);
        }

        return ['writes' => $writes, 'deletes' => $deletes, 'skipped' => $skipped];
    }
}
