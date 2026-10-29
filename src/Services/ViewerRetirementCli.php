<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Services\Exception\ViewerRetirementAbortedException;

/**
 * What `scripts/retire-viewer-tuples.php` does once its sweep is built, kept in `src/` so PHPStan and the tests cover
 * it (API #1049). Prints the plan, then, with --apply, carries it out and reports what was not.
 *
 * Exit codes: 0 dry run, or apply with no failures; 1 the plan could not be built or the apply stopped early;
 * 2 apply finished, but a tuple delete or request change failed outright or a planned role revocation was not
 * carried out.
 *
 * @phpstan-import-type ApplyResult from ViewerRetirementSweep
 * @phpstan-import-type RoleRevocation from ViewerRetirementPlan
 */
final class ViewerRetirementCli
{
    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private readonly ViewerRetirementSweep $sweep,
        private $out,
        private $err,
    ) {
    }

    public function run(bool $apply): int
    {
        $this->line($apply ? 'Mode: APPLY' : 'Mode: DRY RUN (pass --apply to apply changes)');
        $this->line();

        try {
            $plan = $this->sweep->plan();
        } catch (\Throwable $e) {
            $this->error('Error: the plan could not be built, so nothing was changed: ' . $e->getMessage());
            return 1;
        }

        $this->printPlan($plan);

        if ($plan->isEmpty()) {
            $this->line('Nothing to do.');
            return 0;
        }

        if (!$apply) {
            $this->line('Dry run: nothing was changed. Review the lists above, then re-run with --apply.');
            return 0;
        }

        try {
            $result = $this->sweep->apply($plan);
        } catch (ViewerRetirementAbortedException $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->printResult($e->partial);
            return 1;
        } catch (\Throwable $e) {
            // apply() wraps what it throws; this is only a guard.
            $this->error('Error: the sweep stopped early: ' . $e->getMessage());
            $this->printUnrealised(array_map(
                static fn (array $r): array => $r + ['outcome' => 'failed', 'reason' => 'not carried out: the sweep stopped early'],
                $plan->roleRevocations
            ));
            return 1;
        }

        $this->printResult($result);

        return ViewerRetirementSweep::exitCode($result);
    }

    private function printPlan(ViewerRetirementPlan $plan): void
    {
        $this->line(sprintf('Viewer tuples held by people (%d) — delete:', count($plan->viewerTuples)));
        foreach ($plan->viewerTuples as $tuple) {
            $this->line("  - {$tuple['user']} viewer {$tuple['object']}");
        }
        $this->line();

        $this->line(sprintf('Zitadel roles read for each viewer holder (%d):', count($plan->rolesRead)));
        foreach ($plan->rolesRead as $user => $roles) {
            $this->line(sprintf('  - user %s: %d role(s)%s', $user, count($roles), $roles === [] ? '' : ' — ' . implode(', ', $roles)));
        }
        $this->line();

        $this->line(sprintf('Pending access requests naming viewer (%d):', count($plan->pendingRequests)));
        foreach ($plan->pendingRequests as $request) {
            $action = strtoupper($request['action']);
            $this->line("  - [{$action}] request {$request['id']} (user {$request['user']})");
            $this->line('      drop: ' . self::describe($request['removed']));
            $this->line('      keep: ' . self::describe($request['keep']));
        }
        $this->line();

        $this->line(sprintf('Roles held only through viewer (%d) — revoke:', count($plan->roleRevocations)));
        foreach ($plan->roleRevocations as $revocation) {
            $this->line("  - user {$revocation['user']}: {$revocation['role']}");
        }
        $this->line();

        $this->line(sprintf(
            'Viewer writes for people still queued in the outbox (%d) — NOT touched; each recreates a viewer tuple once delivered:',
            count($plan->queuedViewerWrites)
        ));
        foreach ($plan->queuedViewerWrites as $write) {
            $this->line("  - outbox row {$write['id']} [{$write['status']}]: {$write['user']} viewer {$write['object']}");
        }
        $this->line();
    }

    /**
     * @param ApplyResult $result
     */
    private function printResult(array $result): void
    {
        $this->line('Summary:');
        foreach ($result['counts'] as $name => $count) {
            $this->line(sprintf('  %-20s: %d', $name, $count));
        }
        $this->line();

        $this->printUnrealised($result['unrealised_revocations']);
    }

    /**
     * @param list<array{user: string, role: string, outcome: string, reason: string}> $unrealised
     */
    private function printUnrealised(array $unrealised): void
    {
        $this->line(sprintf('Planned role revocations NOT carried out (%d):', count($unrealised)));
        foreach ($unrealised as $entry) {
            $this->line(sprintf(
                '  - [%s] user %s: %s — %s',
                strtoupper($entry['outcome']),
                $entry['user'],
                $entry['role'],
                $entry['reason']
            ));
        }
        if ($unrealised !== []) {
            $this->line('  These need a manual look: a later run cannot find a person whose viewer tuples are gone.');
        }
        $this->line();
    }

    /**
     * @param list<mixed> $permissions
     */
    private static function describe(array $permissions): string
    {
        if ($permissions === []) {
            return '(none)';
        }
        $parts = [];
        foreach ($permissions as $p) {
            if (
                is_array($p)
                && is_string($p['object_type'] ?? null)
                && is_string($p['object_id'] ?? null)
                && is_string($p['relation'] ?? null)
            ) {
                $parts[] = "{$p['relation']} {$p['object_type']}:{$p['object_id']}";
            } else {
                // Kept as stored, never erased.
                $parts[] = '(malformed, kept as is) ' . json_encode($p);
            }
        }
        return implode(', ', $parts);
    }

    private function line(string $text = ''): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }

    private function error(string $text): void
    {
        fwrite($this->err, $text . PHP_EOL);
    }
}
