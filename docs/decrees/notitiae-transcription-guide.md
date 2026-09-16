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

Years 1965–1968: record only `general_calendar` items. Everything else on those pages is skipped.

## Fields

- `id`: `N<year of the decree>-<protocol with non-alphanumerics replaced by ->`, e.g. `Prot. CD 1131/76` → `N1976-CD-1131-76`;
  `Prot. N. 257/16` → `N2016-257-16`. No protocol: `N<year>-p<pdf_page>-<n>` with `n` counting entries on that page from 1.
- `source.issue`: the issue number(s) from the file name (`"116"`, `"306-307"`, `"593-NS-001"`).
- `source.pdf_pages` / `printed_pages`: first and last page of the entry, `[n, n]` when it is one page.
- `protocol` and `date`: **as printed**. Illegible → `null` and `needs_review: true`. Never reconstruct.
- `target.nation`: ISO 3166-1 alpha-2. Common Latin forms: Hollandia → `NL`, Italia → `IT`, Foederatae Civitates Americae
  Septentrionalis / Statuum Foederatorum → `US`, Canada → `CA`, Croatia → `HR`, Anglia et Cambria → `GB`, Scotia → `GB`,
  Hibernia → `IE`, Lusitania → `PT`, Hispania → `ES`, Gallia → `FR`, Germania → `DE`, Helvetia → `CH`, Polonia → `PL`,
  Hungaria → `HU`, Cecoslovachia → `CZ` (with `needs_review`), Iugoslavia → `HR`/`SI`/… only when the diocese makes it
  unambiguous, otherwise `null` with `needs_review`. A diocese implies its nation.
- `target.diocese`: the Latin name exactly as printed (`Boscoducensis`). `target.diocese_id`: only when the diocese is one the
  API implements — see the table below; otherwise `null`.
- `target.institute`: the institute's name as printed, for `level: religious`.
- `celebration`: fill when the decree names a single celebration; `event_key` only when you are sure of the key used in
  `jsondata/sourcedata/rite/roman/missals/` or the calendar files, else `null`. `grade` uses the API scale
  (0 weekday · 1 commemoration · 2 optional memorial · 3 memorial · 4 feast · 5 feast of the Lord · 6 solemnity · 7 higher solemnity).
- `summary_en`: one sentence, what changed for whom.
- `excerpt`: verbatim, original language, one to three lines. Keep OCR-style artefacts out: type what the page shows.
- `api`: always `{ "calendar_implemented": false, "status": "recorded", "applied_in": null }` — `merge.py` recomputes the flag.

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

## Output

Write `docs/decrees/register/<year>.json`: a JSON array of entries for your volume only, in page order. Do not touch any
other file. When the same decree appears in full and again as a *Summarium* line, write **one** entry citing the full text in
`source` and the Summarium line in `also_in`.
