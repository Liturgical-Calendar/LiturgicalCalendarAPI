<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;

/**
 * Derives wider-region membership tuples from the national calendar source files
 * (each nation's `metadata.wider_region`) and writes them to OpenFGA:
 *   wider_region:<Region>#member_nation@national_calendar:<Nation>
 *
 * Membership powers the wider_region admin TTU (`admin from member_nation`), so a
 * national admin inherits admin on their wider region (#669).
 */
final class WiderRegionMembershipSeeder
{
    /**
     * @return list<array{user: string, relation: string, object: string}>
     */
    public function computeTuples(string $nationsDir): array
    {
        $tuples = [];
        $dirs   = glob($nationsDir . '/*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return [];
        }
        foreach ($dirs as $dir) {
            $nation = basename($dir);
            $file   = "{$dir}/{$nation}.json";
            if (!is_file($file)) {
                continue;
            }
            $raw = file_get_contents($file);
            if ($raw === false) {
                throw new \RuntimeException("Unable to read national calendar file: {$file}");
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                throw new \RuntimeException("Invalid JSON in national calendar file: {$file}");
            }
            $meta   = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $region = is_string($meta['wider_region'] ?? null) ? $meta['wider_region'] : '';
            if ($region === '') {
                continue;
            }
            $tuples[] = [
                'user'     => "national_calendar:{$nation}",
                'relation' => 'member_nation',
                'object'   => "wider_region:{$region}",
            ];
        }
        return $tuples;
    }

    /**
     * @return array{planned: int, written: int}
     */
    public function seed(OpenFgaClient $client, string $nationsDir, bool $apply): array
    {
        $tuples  = $this->computeTuples($nationsDir);
        $written = 0;
        if ($apply) {
            foreach ($tuples as $t) {
                try {
                    $client->writeTuple($t['user'], $t['relation'], $t['object']);
                    ++$written;
                } catch (TupleAlreadyExistsException) {
                    // benign — already seeded
                }
            }
        }
        return ['planned' => count($tuples), 'written' => $written];
    }
}
