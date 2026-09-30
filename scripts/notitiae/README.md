# Notitiae survey tools

Tooling for the survey described in
`docs/superpowers/specs/2026-09-16-notitiae-decree-survey-design.md`.
The corpus is looked up at `~/development/sources/notitiae/` unless `NOTITIAE_CACHE`
points elsewhere.

| Script             | Reads                                     | Writes                                                   |
|--------------------|-------------------------------------------|----------------------------------------------------------|
| `locate.py`        | corpus text                               | `scripts/notitiae/worklist.json`                         |
| `gate.py`          | worklist, corpus text, `decrees.json`     | report on stdout; exit 1 on a missed decree              |
| `merge.py`         | the register, fragments if any, calendars | `docs/decrees/notitiae-register.json`                    |
| `issues.py`        | the register, diocesan calendar sources   | see below                                                |
| `patron_grades.py` | fragments if present, else the register   | the same files, with unprinted patron grades nulled (B4) |
| `patron_dates.py`  | fragments if present, else the register   | the same files, with unprinted patron month/day nulled   |
| `issue_tokens.py`  | fragments if present, else the register   | the same files, with `source.issue` set from the pdf     |

Run from `scripts/`, as a module (a bare `python3 notitiae/locate.py` fails on the
package-relative imports): `python3 -m notitiae.locate`, `python3 -m notitiae.gate`,
`python3 -m notitiae.merge`, `python3 -m notitiae.issues`. Tests:
`python3 -m unittest discover -s notitiae/tests -t .`.

`worklist.json` and `out/` are build products and are gitignored.

`merge.py` canonicalises every id before folding (`normalise_id`: protocol-less ids gain the issue
token, a bare `N.` token is dropped) and refuses — with both citations named — to fold two entries that
share an id but come from different volumes under different or missing protocols, since those are
plausibly two different acts. Resolve such a pair in the fragment, never in the merged output.

`issues.py` writes four files, because a full per-entry checklist (one line per register entry) is far
too large for a GitHub issue body — the not-implemented checklist alone runs to ~1.5 MB, well past the
65,536-character body cap. The full checklists are committed generated files, one per epic:
`docs/decrees/epics/implemented.md` and `docs/decrees/epics/not-implemented.md`, grouped by calendar
(diocesan entries group by `metadata.diocese_name` looked up from `diocese_id`, never by the printed
Latin spelling, so two spellings of the same diocese never split into separate sections). The epic
bodies themselves go to `scripts/notitiae/out/epic-implemented.md` and `epic-not-implemented.md`
(gitignored, opened with `gh issue create --body-file`): a short explanation, a summary table of
entries/applied/needs-review per calendar, and a link to the matching checklist file. Only the
implemented epic's body also appends a compact per-entry checklist beneath its table, and only while
the whole body stays under a size threshold (`render()`'s `checklist_threshold`, default 60,000
characters) — otherwise it says so instead.

## Re-running the merge

Three things a future merge must repeat or keep in mind:

- **Same protocol, distinct acts.** `merge.py` folds by id, and the id is the protocol, so one protocol
  that covers two *distinct* acts (a patron confirmation and a calendar change under one number) must
  be split by hand before merging: the second act gets the `-2` suffix (`N2002-444-02-L-2`), the third
  `-3`. The refusal above only catches the cross-volume case; two acts printed in one volume under one
  protocol fold silently into a single entry, so that check is manual and must be repeated for every
  new fragment.
- **The register is an input.** `main()` folds the existing `notitiae-register.json` back in *before*
  the fragments, so a fragment re-transcribed after a merge does not replace the entry it produced the
  first time — the earlier version wins and the new one is folded into its `also_in`. To re-import a
  volume, delete its entries from the register (or the whole register) first, and remove the fragments
  once they are folded so they are not merged again.
- **`calendar_implemented` is stored.** `merge.py` recomputes the flag from the calendars present under
  `jsondata/sourcedata/rite/roman/calendars/`; `issues.py` only reads the stored value. After adding a
  calendar, run `python3 -m notitiae.merge` and then `python3 -m notitiae.issues`, or the new calendar's
  entries stay in the not-implemented epic.
