<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Services\ZitadelService;

/**
 * Who submitted a change request, when the token did not say.
 *
 * A change request records its submitter's name and email to show reviewers and to
 * author the commit it publishes as. Both were read from the OIDC claims of the
 * request, but a Zitadel access token carries no profile claims unless the deployment
 * is configured to add them — so every batch was stored with only a `sub`, reviewers
 * saw "unknown user", and publishing fell back to the publisher's own identity.
 *
 * This fills the gap from the user directory, by `sub`: at submission for new batches,
 * and for batches already stored without it. It never overrides a claim the token did
 * carry, and never fails a request: an unreachable directory leaves the identity as it
 * was.
 */
final class SubmitterIdentity
{
    /** @var \Closure(string, float): (array{name: ?string, email: ?string, email_verified: bool}|null) */
    private \Closure $lookup;

    /** @var array<string, array{name: ?string, email: ?string, email_verified: bool}|null> */
    private array $cache = [];

    /**
     * Wall-clock seconds one request may spend on lookups, across every submitter.
     *
     * Each lookup has its own timeout (ZitadelService::PROFILE_TIMEOUT), which bounds one
     * call but not a page of them: ten unnamed submitters against a directory that is down
     * would still be ten timeouts. So each lookup is given at most what is left of the
     * budget, and once it is spent the rest go unnamed, as they were before this service
     * existed.
     */
    public const LOOKUP_BUDGET_SECONDS = 5.0;

    private ?float $lookupStartedAt = null;

    /** @var \Closure(): float */
    private \Closure $clock;

    /**
     * @param (callable(string, float): (array{name: ?string, email: ?string, email_verified: bool}|null))|null $lookup
     *        called with the `sub` and the seconds it may take; defaults to the Zitadel
     *        directory when it is configured
     * @param (callable(): float)|null $clock seconds, for the lookup budget; injectable for tests
     */
    public function __construct(?callable $lookup = null, ?callable $clock = null)
    {
        $this->clock  = $clock !== null ? \Closure::fromCallable($clock) : static fn (): float => microtime(true);
        $this->lookup = $lookup !== null
            ? \Closure::fromCallable($lookup)
            : static function (string $sub, float $timeout): ?array {
                if (!ZitadelService::isConfigured()) {
                    return null;
                }
                return ZitadelService::fromEnv()->getUserProfile($sub, $timeout);
            };
    }

    /**
     * The submitter's OIDC claims, with `name`, `email` and `email_verified` filled from
     * the directory when the token lacked both a name and an email.
     *
     * @param array<string, mixed> $oidcUser
     * @return array<string, mixed>
     */
    public function complete(array $oidcUser): array
    {
        $sub = $oidcUser['sub'] ?? null;
        if (!is_string($sub) || $sub === '' || self::hasIdentity($oidcUser['name'] ?? null, $oidcUser['email'] ?? null)) {
            return $oidcUser;
        }
        $profile = $this->profileOf($sub);
        if ($profile === null) {
            return $oidcUser;
        }
        $oidcUser['name']           = $profile['name'];
        $oidcUser['email']          = $profile['email'];
        $oidcUser['email_verified'] = $profile['email_verified'];
        return $oidcUser;
    }

    /**
     * Batch summaries (or rows) with `submitted_by_name` and `submitted_by_email` filled
     * for the ones stored without either, looked up once per submitter.
     *
     * @param array<int, array<string, mixed>> $batches
     * @return array<int, array<string, mixed>>
     */
    public function fillSummaries(array $batches): array
    {
        foreach ($batches as &$batch) {
            $batch = $this->fillSummary($batch);
        }
        unset($batch);
        return $batches;
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    public function fillSummary(array $batch): array
    {
        $sub = $batch['submitted_by_sub'] ?? null;
        if (!is_string($sub) || $sub === '' || self::hasIdentity($batch['submitted_by_name'] ?? null, $batch['submitted_by_email'] ?? null)) {
            return $batch;
        }
        $profile = $this->profileOf($sub);
        if ($profile !== null) {
            $batch['submitted_by_name']  = $profile['name'];
            $batch['submitted_by_email'] = $profile['email'];
        }
        return $batch;
    }

    /**
     * The directory's profile for `$sub`, or null when it cannot be had.
     *
     * @return array{name: ?string, email: ?string, email_verified: bool}|null
     */
    public function profileOf(string $sub): ?array
    {
        if (array_key_exists($sub, $this->cache)) {
            return $this->cache[$sub];
        }

        $now                     = ( $this->clock )();
        $this->lookupStartedAt ??= $now;
        $remaining               = self::LOOKUP_BUDGET_SECONDS - ( $now - $this->lookupStartedAt );
        if ($remaining <= 0.0) {
            return null;
        }

        try {
            $this->cache[$sub] = ( $this->lookup )($sub, min(ZitadelService::PROFILE_TIMEOUT, $remaining));
        } catch (\Throwable) {
            $this->cache[$sub] = null;
        }
        return $this->cache[$sub];
    }

    private static function hasIdentity(mixed $name, mixed $email): bool
    {
        return ( is_string($name) && $name !== '' ) || ( is_string($email) && $email !== '' );
    }
}
