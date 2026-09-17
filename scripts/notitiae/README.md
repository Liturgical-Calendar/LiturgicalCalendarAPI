# Notitiae survey tools

Tooling for the survey described in
`docs/superpowers/specs/2026-09-16-notitiae-decree-survey-design.md`.
The corpus is looked up at `~/development/sources/notitiae/` unless `NOTITIAE_CACHE`
points elsewhere.

| Script      | Reads                                        | Writes                                              |
|-------------|----------------------------------------------|-----------------------------------------------------|
| `locate.py` | corpus text                                  | `scripts/notitiae/worklist.json`                    |
| `gate.py`   | worklist, corpus text, `decrees.json`        | report on stdout; exit 1 on a missed decree         |
| `merge.py`  | `docs/decrees/register/*.json`, calendars    | `docs/decrees/notitiae-register.json`               |
| `issues.py` | `docs/decrees/notitiae-register.json`        | two markdown bodies under `scripts/notitiae/out/`   |

Run from `scripts/`, as a module (a bare `python3 notitiae/locate.py` fails on the
package-relative imports): `python3 -m notitiae.locate`, `python3 -m notitiae.gate`,
`python3 -m notitiae.merge`, `python3 -m notitiae.issues`. Tests:
`python3 -m unittest discover -s notitiae/tests -t .`.

`worklist.json` and `out/` are build products and are gitignored.

`merge.py` canonicalises every id before folding (`normalise_id`: protocol-less ids gain the issue
token, a bare `N.` token is dropped) and refuses — with both citations named — to fold two entries that
share an id but come from different volumes under different or missing protocols, since those are
plausibly two different acts. Resolve such a pair in the fragment, never in the merged output.
