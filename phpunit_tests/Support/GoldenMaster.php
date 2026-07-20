<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

/**
 * Shared helpers for the Roman-calendar golden-master regression suite.
 * The MATRIX exercises: missal-edition year gates (2002/2008), the two
 * national editions used by existing calendars, a diocesan overlay, and
 * the LITURGICAL year type — both implicitly (the API default) and via
 * the explicit `year_type=LITURGICAL` query parameter — which drives the
 * double-year clone/merge pass. There is no CIVIL-year case in this
 * matrix.
 *
 * `CalendarParams::initParamsFromRequestPath()` reads path segments from
 * the handler's constructor argument, not from the request URI — so each
 * case carries both the `uri` (for query-string parsing, e.g. `year_type`)
 * and the explicit `pathParams` that must be passed to
 * `new CalendarHandler($pathParams)`, mirroring
 * `CalendarHandlerTest::makeHandler()`.
 */
final class GoldenMaster
{
    /**
     * @var list<array{
     *     label: string,
     *     method: string,
     *     uri: string,
     *     headers: array<string,string>,
     *     pathParams: array<int,string>
     * }>
     */
    public const MATRIX = [
        [
            'label'      => 'general-1997',
            'method'     => 'GET',
            'uri'        => '/calendar/1997',
            'headers'    => ['Accept' => 'application/json'],
            'pathParams' => ['1997'],
        ],
        [
            'label'      => 'general-2001',
            'method'     => 'GET',
            'uri'        => '/calendar/2001',
            'headers'    => ['Accept' => 'application/json'],
            'pathParams' => ['2001'],
        ],
        [
            'label'      => 'general-2005',
            'method'     => 'GET',
            'uri'        => '/calendar/2005',
            'headers'    => ['Accept' => 'application/json'],
            'pathParams' => ['2005'],
        ],
        [
            'label'      => 'general-2020',
            'method'     => 'GET',
            'uri'        => '/calendar/2020',
            'headers'    => ['Accept' => 'application/json'],
            'pathParams' => ['2020'],
        ],
        [
            'label'      => 'general-2024',
            'method'     => 'GET',
            'uri'        => '/calendar/2024',
            'headers'    => ['Accept' => 'application/json'],
            'pathParams' => ['2024'],
        ],
        [
            'label'      => 'general-2025-litur',
            'method'     => 'GET',
            'uri'        => '/calendar/2025?year_type=LITURGICAL',
            'headers'    => ['Accept' => 'application/json'],
            'pathParams' => ['2025'],
        ],
        [
            'label'      => 'nation-US-2023',
            'method'     => 'GET',
            'uri'        => '/calendar/nation/US/2023',
            'headers'    => ['Accept' => 'application/json'],
            'pathParams' => ['nation', 'US', '2023'],
        ],
        [
            'label'      => 'nation-IT-2023',
            'method'     => 'GET',
            'uri'        => '/calendar/nation/IT/2023',
            'headers'    => ['Accept' => 'application/json'],
            'pathParams' => ['nation', 'IT', '2023'],
        ],
        [
            'label'      => 'diocese-romamo-2023',
            'method'     => 'GET',
            'uri'        => '/calendar/diocese/romamo_it/2023',
            'headers'    => ['Accept' => 'application/json'],
            'pathParams' => ['diocese', 'romamo_it', '2023'],
        ],
    ];

    /**
     * Strip fields that vary between otherwise-identical runs (timestamps,
     * the running API version, the echoed request headers, each event's
     * process-lifetime `event_idx` — a static counter in
     * {@see \LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent} that
     * increments across every calendar computed in the same PHP process, so
     * its value depends on test execution order, not on calendar content —
     * and each event's `readings`) so only deterministic calendar content
     * remains.
     *
     * `readings` is excluded because it derives from
     * `LiturgicalEventCollection::$lectionary`, a STATIC property that
     * accumulates across handler invocations within one PHP process: a
     * weekday event's `readings` come out empty when this generator runs in
     * isolation but populated when it runs after another test that already
     * loaded the lectionary. That makes `readings` order-dependent, not
     * calendar-dependent. The temporale/rite refactor this gate protects
     * never touches reading-lookup logic, so dropping `readings` from the
     * comparison keeps the gate deterministic without weakening its actual
     * purpose. The gate still covers `event_key`, `date`, `grade`, `color`,
     * `liturgical_season`, `psalter_week`, the precedence buckets in
     * `metadata`, and `messages`.
     *
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    public static function normalize(array $decoded): array
    {
        unset(
            $decoded['metadata']['date_time'],
            $decoded['metadata']['request_headers'],
            $decoded['metadata']['version'],
            $decoded['metadata']['timestamp']
        );
        unset($decoded['settings']['timestamp']);

        if (isset($decoded['litcal']) && is_array($decoded['litcal'])) {
            foreach ($decoded['litcal'] as &$event) {
                if (is_array($event)) {
                    unset($event['event_idx'], $event['readings']);
                }
            }
            unset($event);
        }

        return $decoded;
    }

    public static function fixturePath(string $label): string
    {
        return __DIR__ . '/../fixtures/golden-master/' . $label . '.json';
    }
}
