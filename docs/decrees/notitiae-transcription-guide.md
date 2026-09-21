# Notitiae transcription guide

Rules for anyone — human or subagent — turning selected *Notitiae* pages into entries of
`docs/decrees/notitiae-register.json`. The register's shape is `notitiae-register.schema.json`; this guide is about judgement.

## What to read

Your assignment is one volume: the list of `{file, pdf_page, printed_page, reasons}` from `scripts/notitiae/worklist.json` for that
year. Render the pages (PDF page numbers, not printed ones) and read them; do not transcribe from the `txt/` OCR, whose column
order is unreliable in the *Summarium Decretorum*. When a selected page is mid-entry, also read the page before and after.

## What counts as an entry

One entry per decree or concession that changes what a calendar contains or how a celebration is ranked, named, dated or
observed. Record:

- General Roman Calendar changes: new celebrations, grade changes, transfers, name changes, Doctors of the Church, Martyrology
  inscriptions (`kind: martyrology`).
- Approval of a particular calendar (`particular_calendar_approval`) and later amendments (`particular_calendar_change`) for any
  nation, region, diocese or religious institute.
- Patron confirmations (`patron_confirmation`) at any level: a confirmed patron changes the grade of that celebration.
- Single-celebration concessions in a particular calendar (`new_celebration`, `grade_change`, `transfer`, `name_or_title_change`).

Do **not** record: basilica titles, coronations of images, approvals of liturgical texts or translations, indults about the
Mass, ordinations, or anything that does not touch a calendar. When unsure, record it with `kind: other` and `needs_review: true`
rather than dropping it.

Years 1965–1968: record only `general_calendar` and `wider_region` items. Everything else on those pages is skipped.

## Fields

- `id`: `N<year of the decree>-<protocol with non-alphanumerics replaced by ->`, e.g. `Prot. CD 1131/76` → `N1976-CD-1131-76`;
  `Prot. 551/00/L` → `N2001-551-00-L`. Drop the bare `N.`/`n.` token of `Prot. N. 257/16` (→ `N2016-257-16`) but keep every other
  letter prefix or suffix (`CD`, `L`). When one protocol covers two **distinct** acts, suffix the second `-2`, the third `-3`
  (`N2002-444-02-L`, `N2002-444-02-L-2`); one protocol that covers two celebrations in one act is one entry. No protocol:
  `N<year>-<source.issue>-p<pdf_page>-<n>` with `n` counting entries on that page from 1 (`N2008-521-522-p51-1`,
  `N2016-593-NS-001-p38-1`) — protocol-less ids are unique per file, not per year, so the issue token is what keeps 2008.json and
  2009.json apart. `merge.py` rewrites the older spellings (`N2008-p51-1`, `N1969-N-833-69`) to this form.
- `source.issue`: the issue number(s) from the file name (`"116"`, `"306-307"`, `"593-NS-001"`).
- `source.pdf_pages` / `printed_pages`: first and last page of the entry, `[n, n]` when it is one page.
- `protocol` and `date`: **as printed**. Illegible → `null` and `needs_review: true`. Never reconstruct.
- `target.nation`: ISO 3166-1 alpha-2. Common Latin forms: Hollandia → `NL`, Italia → `IT`, Foederatae Civitates Americae
  Septentrionalis / Statuum Foederatorum → `US`, Canada → `CA`, Croatia → `HR`, Anglia et Cambria → `GB`, Scotia → `GB`,
  Hibernia → `IE`, Lusitania → `PT`, Hispania → `ES`, Gallia → `FR`, Germania → `DE`, Helvetia → `CH`, Polonia → `PL`,
  Hungaria → `HU`, Cecoslovachia → `CZ` (with `needs_review`), Iugoslavia → `HR`/`SI`/… only when the diocese makes it
  unambiguous, otherwise `null` with `needs_review`. A diocese implies its nation.
- `target.diocese`: the Latin name exactly as printed (`Buscoducensis`; `Boscoducensis` where the page prints that spelling).
  `target.diocese_id`: only when the diocese is one the API implements — see the table below; otherwise `null`.
- `target.institute`: the institute's name as printed, for `level: religious`.
- `celebration`: fill when the decree names a single celebration; `event_key` only when you are sure of the key used in
  `jsondata/sourcedata/rite/roman/missals/` or the calendar files, else `null`. `grade` uses the API scale
  (0 weekday · 1 commemoration · 2 optional memorial · 3 memorial · 4 feast · 5 feast of the Lord · 6 solemnity · 7 higher solemnity).
- `summary_en`: one sentence, what changed for whom.
- `excerpt`: verbatim, original language, one to three lines. Keep OCR-style artefacts out: type what the page shows.
- `api`: always `{ "calendar_implemented": false, "status": "recorded", "applied_in": null }` — `merge.py` recomputes the flag.

## Rulings from the 1965–2022 survey

Decisions taken while transcribing the 58 volumes. They bind any later pass over the same pages.

- `wider_region` acts are recorded at every year, including 1965–1968 (the spec's §Scope says so too).
- A ruling on a single year's occurrence in the General Calendar (two celebrations coinciding this year, a one-off transfer)
  is `general_calendar` with `needs_review: true`, and `summary_en` names the year.
- Particular Easter-date concessions from 1969 are `kind: other`. Changes to holy days of precept are `kind: other`,
  `level: national`, `needs_review: true`.
- Abstract rubrical instructions (*Tres abhinc annos* and the like) are **not** recorded. General norms that fix calendar
  ranks — *Normae circa Patronos* (1973), the criteria for inserting saints in the General Calendar (2006), precedence
  notifications — **are** recorded as `kind: other`, `level: general`.
- Items for a parish, a church, a shrine, an association or a category of persons are `level: other`, `needs_review: true`.
  Sub-national regional calendars (Lombardy, Sicily, Wales, an ecclesiastical province) are `level: other` with `nation` filled
  and `needs_review: false`.
- A conference covering two nations gets `nation: null` and `needs_review: true`. Military ordinariates are `level: diocesan`,
  `needs_review: true`. Personal prelatures (Opus Dei) and the Order of Malta are `level: other` with `institute` filled.
- A bare *Calendaria particularia* line is `particular_calendar_approval`, or `particular_calendar_change` when the verb is one of
  amendment; `needs_review: false`.
- "liturgicae celebrationes conceduntur in honorem Beati N." — a standing concession to a named calendar — is `new_celebration`.
  Only one-year Mass-of-thanksgiving permissions after a beatification or canonisation are excluded.
- Promulgation of a book with calendar content (the *Martyrologium Romanum*) is `martyrology`, `level: general`. Variations in a
  Missal edition that have no separate decree are `general_calendar`, `needs_review: true`, `date` the edition date or null.
- `celebration.grade` is null unless the page prints a rank; `month`/`day` are null unless printed; `printed_pages` come from
  the page itself, never from the index.
- One protocol covering two celebrations is **one** entry with `celebration: null`; one protocol covering two distinct acts is two
  entries, the second suffixed `-2`; one act printed twice (full text and *Summarium*) is one entry with `also_in`.
- Diocese names are transcribed as printed (case and language). A misprint is transcribed as printed, with the corrected
  reading given in `summary_en`.
- Fixed-date votive Masses are `kind: other`, `needs_review: true`. Text approvals, church dedications and titles, basilica
  titles, coronations, indulgences and *Ordo cantus* decrees are never recorded.
- Shrine and sanctuary votive-Mass concessions listed under *Concessiones circa Calendaria*: `kind: other`, `needs_review: true`.

## Implemented diocesan calendars

| `diocese_id` | Latin name(s) in Notitiae                   |
|--------------|---------------------------------------------|
| `boston_us`  | Bostoniensis                                |
| `charlo_ca`  | Carolinapolitana                            |
| `romamo_it`  | Romana                                      |
| `agrige_it`  | Agrigentina                                 |
| `socaap_it`  | Sorana-Cassinensis-Aquinatensis-Pontiscurvi |
| `rotter_nl`  | Roterodamensis                              |
| `utrech_nl`  | Ultraiectensis                              |
| `grolee_nl`  | Groningensis-Leovardiensis                  |
| `bredad_nl`  | Bredana                                     |
| `haaams_nl`  | Harlemensis-Amstelodamensis                 |
| `sherto_nl`  | Buscoducensis                               |
| `roermo_nl`  | Ruremundensis                               |

(These are the 12 dioceses the API implements a calendar for. Every Latin name above was verified against the diocese's own
entry on catholic-hierarchy.org on 2026-09-16. A transcriber must not guess an id that is not in the table.)

Ambrosian-rite dioceses (Milan, *Mediolanensis*) are out of scope for this register even though the API implements Ambrosian
calendars: the rite is a separate follow-up. Record the act, leave `diocese_id` null.

## Output

Write `docs/decrees/register/<year>.json`: a JSON array of entries for your volume only, in page order. Do not touch any
other file. When the same decree appears in full and again as a *Summarium* line, write **one** entry citing the full text in
`source` and the Summarium line in `also_in`.
