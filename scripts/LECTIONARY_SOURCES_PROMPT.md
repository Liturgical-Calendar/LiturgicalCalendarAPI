# Monthly task: keep the lectionary sources current

This file is the authoritative spec for the scheduled task that maintains
`jsondata/sourcedata/rite/roman/lectionary_sources.json` (issue #1074). The task runs in a fresh
session each month, from a clone of `Liturgical-Calendar/LiturgicalCalendarAPI` on `development`.

## What the file holds

For each bishops' conference site that publishes the readings of the day (`usccb`, `cei`): its URL
patterns, the dates it publishes (`first_date`, `last_date`, both inclusive), the national calendars
it follows, and a `pages` map of the pages that cannot be computed from the date (a USCCB memorial's
own page, a CEI section id). `/calendar` gives an event a `readings_source_url` only within the
range, so a stale `last_date` silently drops the links of a newly published month.

## Steps

1. Branch from `development`: `chore/lectionary-sources-YYYY-MM`.
2. Run the probe as a dry run: `php scripts/probe-lectionary-sources.php`.
   - Exit 2 means a site refused a request. **Never retry past a refusal**, and never add browser
     headers to get through. The USCCB refuses most scripted requests; if it does, read its range
     in a browser instead, one page at a time, at least 3 seconds apart: open the day page for
     `last_date + 1`, `+ 2`, … and stop at the first 404.
3. Review the proposed dates. A `last_date` that moves back, or a `first_date` that moves by more
   than a few days, is a site change to describe in the PR, not to apply silently.
4. If nothing changed, stop: open no PR.
5. Otherwise run it again with `--apply` (or edit the dates found in the browser by hand), then
   `vendor/bin/phpunit phpunit_tests/Schemas/LectionarySourcesSchemaTest.php` and
   `composer lint:jsondata`.
6. Optionally, add `pages` entries for celebrations newly published on their own page or section:
   key by `event_key`; a value that differs by weekday or Sunday cycle is an object keyed `I`/`II`
   or `A`/`B`/`C`. Name, in the PR, the page each was read from.
7. Commit, push, and open one PR into `development` with the probe output as evidence.

## Why a PR and not a live probe

No instance of the API probes the sites at runtime: the USCCB refuses repeated requests, every
instance would probe on its own, and a reviewed commit keeps the links the same everywhere. The
sidecar is under `jsondata/sourcedata`, so the deploy that carries the change also invalidates the
engine cache (pinned by `CalendarHandlerCacheVersionTest::testLectionarySourcesFileIsCoveredByTheDigest`).
