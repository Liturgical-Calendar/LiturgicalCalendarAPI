# Notitiae decree survey — design

**Date:** 2026-09-16
**Status:** approved design, awaiting implementation plan

## Problem

`jsondata/sourcedata/rite/roman/decrees/decrees.json` holds fifteen decrees of the Dicastery for Divine
Worship, all of which touch the General Roman Calendar and all but one of which date from 2012 or later.
They were recorded because somebody happened to know about them. Nothing in the repository records the
decrees and concessions issued for particular calendars (national, diocesan, religious), and nothing
guarantees that the General Roman Calendar decrees are complete.

The Dicastery's review *Notitiae* is the authoritative published register of that activity. The Dicastery
has put the whole run online as PDFs — 418 files covering 1965–2022 — at
<https://www.cultodivino.va/it/rivista-notitiae.html>. Beyond the full text of the major decrees, every
issue carries a *Summarium Decretorum* section listing each approval of a particular calendar, each
confirmation of a patron, each change of grade or title, with its protocol number and date.

This project surveys that corpus and records every calendar-relevant decree in a form the project can
act on, one calendar at a time, in later work.

## Scope

**In scope.** Every decree or concession printed in *Notitiae* that changes what a liturgical calendar
contains or how a celebration in it is ranked, named, dated or observed:

- changes to the General Roman Calendar (new celebrations, grade changes, transfers, name changes,
  doctors of the Church, Martyrology-only inscriptions);
- approvals and later amendments of particular calendars for nations, wider regions, dioceses and
  religious institutes;
- patron confirmations (a confirmed patron raises the grade of that celebration in the calendar
  concerned);
- concessions for a single celebration in a particular calendar.

All levels are recorded — including dioceses and institutes the API does not model yet — because the
API intends to model all dioceses and, later, religious institutes.

**Recorded time range.** Entries from 1969 onward, when the *Calendarium Romanum* of the reformed
liturgy was promulgated (the API's minimum year is 1970). From 1965–1968 only `general_calendar` items
are recorded, since they document the reform itself. Particular-calendar concessions from 1965–1968 are
**not** recorded: the 1969–70 reform required every particular calendar to be revised and resubmitted,
so those concessions were superseded wholesale. The cache and text extraction cover all 418 PDFs
regardless, because they are cheap and other projects (the martyrology database) want the full run.

**Out of scope** — each is a separate follow-up issue, not part of this work:

- decrees issued after 2022, which are published on the Dicastery website rather than in *Notitiae*;
- cross-checks against *Acta Apostolicae Sedis* and the *Enchiridion Documentorum Instaurationis
  Liturgicae*;
- the Ambrosian rite;
- actually applying any recorded decree to a calendar (that happens in later, per-calendar PRs that
  update the register's `api` block).

## Deliverables

1. A persistent, shared corpus cache with tooling (its own small git repository).
2. `docs/decrees/notitiae-register.json` in this repository, validated by a schema and a PHPUnit test.
3. `scripts/notitiae/` in this repository: the locator that builds the reading worklist, and the
   generator that renders the two tracking issues from the register.
4. Two GitHub epics on this repository, generated from the register.

## 1. Corpus cache

Location: `~/development/sources/notitiae/`. It sits in the grandparent of this repository so that
both the LiturgicalCalendar workspace and `~/development/SaintsDB` can reach it, and `sources/` leaves
room for a future AAS or Martyrologium corpus beside it. It is its own git repository: tools, manifest
and README tracked; PDFs and extracted text ignored.

```text
sources/notitiae/
  README.md              provenance, layout, how to refresh
  .gitignore             pdf/ txt/ txt-layout/
  manifest.json          one record per PDF (see below)
  tools/fetch.py         idempotent download; updates the manifest
  tools/extract.py       pdftotext for every PDF lacking a text file
  pdf/<year>/Notitiae-116-1976.pdf
  txt/<year>/Notitiae-116-1976.txt            pdftotext, reading-order mode
  txt-layout/<year>/Notitiae-116-1976.txt     pdftotext -layout, keeps Summarium columns aligned
```

`manifest.json` record:

```json
{
  "file": "Notitiae-116-1976.pdf",
  "url": "https://www.cultodivino.va/content/dam/cultodivino/rivista-notitiae/1970/notitiae-12-(1976)/Notitiae-116-1976.pdf",
  "volume": 12,
  "year": 1976,
  "issues": [116],
  "sha256": "…",
  "bytes": 5163815,
  "pages": 48,
  "fetched_at": "2026-09-16T15:00:00+02:00"
}
```

`fetch.py` re-reads the index page each run, so a newly published volume is picked up by simply
running it again. It downloads sequentially with retries, skips a file whose size and hash already
match, and never deletes. `extract.py` runs `pdftotext` twice per PDF (default and `-layout`) and is
likewise idempotent. Both are plain Python 3 with no third-party dependencies beyond the poppler CLI.

## 2. Locator → worklist

`scripts/notitiae/locate.py` reads the cache and writes `worklist.json`: for each PDF, the pages a
reader must open, each tagged with the reason it was selected. Two locators contribute, and a page is
included if either selects it.

**Index-driven.** The last issue of every volume carries an `INDEX VOLUMINIS` that lists the *Decreta*
and the subsections of the *Summarium Decretorum* (*Calendaria particularia*, *Patroni confirmatio*,
*Calendarium Romanum*, …) with printed page numbers. The cumulative index for 1965–1975
(`Notitiae-113-1976-indice-1965-1975.pdf`) covers the first decade in the same way. The locator parses
those page references and maps printed pages to PDF pages using the running-head page numbers found in
the text layer of each issue.

**Keyword sweep.** A safety net for decrees printed outside the indexed sections. Patterns are
case-insensitive and multilingual, since Latin, Italian, French, English and Spanish all occur:
`Prot\.? ?N`, `[CK]alendari`, `Patron`, `Calendarium Romanum Generale`, `Martyrolog`, and any of
`inscrib|conced|confirm|approb|elev` within a few lines of `sollemnita|festum|memoria`.

**Validation gate.** Every decree already in `decrees.json` whose date falls inside the corpus
(2012–2022) must be selected by the worklist. If one is missed, the locator has a hole and the reading
phase does not start until it is closed. This is the only correctness check available before the
reading is done, so it is a hard gate, not a warning.

## 3. Reading and transcription

The worklist is read **visually** — PDF pages rendered as images — not from the text layer. The OCR is
good enough to find sections but interleaves columns in the tabular *Summarium* entries, so protocol
numbers and dates drift between lines; transcribing from it would mis-assign them silently.

Work is parallelised by volume: one subagent per volume (58 volumes), about six at a time. Each writes
its own fragment `docs/decrees/register/<year>.json` on the working branch. Every dispatch carries the
same rules:

- cite the PDF page **and** the printed page for every entry;
- never invent a protocol number or date — write `null` and set `needs_review: true` when illegible;
- record a short verbatim excerpt in the original language beside the English summary;
- transcribe every calendar-relevant line on a selected page, including patron confirmations and
  religious-institute items, and do not skip a page because it "looks like only patrons";
- 1965–1968 pages: record `general_calendar` items only.

Post-pass, done by the coordinating session, not delegated:

- merge fragments into `notitiae-register.json`, sorted by date then id;
- deduplicate — a decree often appears in full **and** as a *Summarium* line under the same protocol
  number; keep one entry citing both locations;
- validate against the schema;
- audit by re-reading roughly 5% of the selected pages per decade and comparing with what was
  transcribed; a discrepancy rate above a handful of entries per decade sends that decade back for a
  second pass.

## 4. Register format

`docs/decrees/notitiae-register.json`, an array of entries, with
`docs/decrees/notitiae-register.schema.json` (JSON Schema draft-07, the dialect `swaggest/json-schema` validates). It lives under `docs/`
rather than `jsondata/schemas/` because the API does not serve it. A PHPUnit test validates the file
against the schema; no composer script is added.

| Field          | Content                                                                                                                                                                                                                                                                                                               |
|----------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `id`           | `N<year>-<protocol>` with the protocol normalised to `[A-Z0-9-]` (`N1976-CD-1131-76`); `N<year>-p<pdf page>-<n>` when there is no protocol number                                                                                                                                                                     |
| `source`       | `{ volume, year, issue, pdf, pdf_pages: [from, to], printed_pages: [from, to], url }`; `url` is the cache manifest URL, and a second `source` may be listed under `also_in` for a Summarium duplicate                                                                                                                 |
| `protocol`     | as printed, or `null`                                                                                                                                                                                                                                                                                                 |
| `date`         | ISO date as printed, or `null`                                                                                                                                                                                                                                                                                        |
| `kind`         | `general_calendar` · `particular_calendar_approval` · `particular_calendar_change` · `new_celebration` · `grade_change` · `transfer` · `name_or_title_change` · `patron_confirmation` · `martyrology` · `other`                                                                                                       |
| `target`       | `{ level: general \| wider_region \| national \| diocesan \| religious \| other, nation: ISO 3166-1 alpha-2 \| null, wider_region?: Americas \| Asia \| Europe \| Africa \| Oceania, diocese: Latin name as printed \| null, diocese_id: id of an implemented diocesan calendar \| null, institute: string \| null }` |
| `celebration`  | `{ event_key: string \| null, name_latin: string, month: int \| null, day: int \| null, grade: int \| null }` where the decree names one; otherwise `null`                                                                                                                                                            |
| `summary_en`   | one sentence                                                                                                                                                                                                                                                                                                          |
| `excerpt`      | verbatim snippet in the original language, at most a few lines                                                                                                                                                                                                                                                        |
| `api`          | `{ calendar_implemented: bool, status: recorded \| applied \| not_applicable \| rejected, applied_in: string \| null }`                                                                                                                                                                                               |
| `needs_review` | bool                                                                                                                                                                                                                                                                                                                  |

`calendar_implemented` is derived, not typed by hand: true for `general`; for `wider_region`, `national` and `diocesan` when
`wider_region`, `nation` or `diocese_id` respectively names a calendar present under `jsondata/sourcedata/rite/roman/calendars/`;
never for `religious` or `other`. `diocese_id` is filled by the transcriber only from the table of implemented dioceses in the
transcription guide, so an unimplemented diocese is never matched by name. The generator in §5 recomputes it, so adding a
calendar later moves its entries from one epic to the other without touching the register by hand.

`status` starts as `recorded`. `applied` is set by the PR that lands the decree in a calendar, with
`applied_in` naming the PR or the `decree_id` it produced. `not_applicable` is for entries that are
recorded for completeness but cannot affect any calendar the API will model (for example a patron of a
religious province). `rejected` records a deliberate decision not to apply, with the reason in
`summary_en`.

## 5. Tracking issues

`scripts/notitiae/issues.py` renders the register into two epic bodies. They are generated so they can
be regenerated whenever the register changes; nobody edits their checklists by hand.

- **Notitiae survey — decrees for implemented calendars.** Sections in order: General Roman Calendar,
  wider regions, each nation, each diocese. One checkbox per entry: `id`, date, protocol, summary, and
  a deep link `<url>#page=<pdf page>`. A box is checked exactly when `api.status` is `applied`.
- **Notitiae survey — decrees for calendars not yet implemented.** Grouped nation → diocese → institute.
  This is also the backlog for adding new national and diocesan calendars.

The generator prints the two bodies; opening and updating the issues is done with `gh`. Application PRs
edit the register, and the epic is re-rendered afterwards.

## 6. Sequencing

0. Cache repository: README, manifest, `fetch.py`, `extract.py`; download and extract all 418 PDFs.
   No PR in this repository.
1. Locator, worklist, and the validation gate. Committed under `scripts/notitiae/`.
2. Parallel transcription by volume, then merge, dedupe, schema validation and audit. One PR: register,
   schema, PHPUnit test, `scripts/notitiae/`.
3. Generate the two epics and open them.

Expected reading load: 1,500–3,000 page renders in step 2.

## Non-goals restated

No calendar is modified by this work. No decree is added to `decrees.json`. No API endpoint changes.
