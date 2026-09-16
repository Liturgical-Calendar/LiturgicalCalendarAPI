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

Run from `scripts/`: `python3 notitiae/locate.py`. Tests:
`python3 -m unittest discover -s notitiae/tests -t .`.

`worklist.json` and `out/` are build products and are gitignored.
