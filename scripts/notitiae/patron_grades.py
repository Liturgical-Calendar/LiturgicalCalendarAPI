#!/usr/bin/env python3
"""One-off normalisation (Task 10, ruling B4): a patron confirmation carries a grade only when the page prints a rank.

Early volumes inferred a solemnity from the bare fact of a patron confirmation. The guide's ruling is
`celebration.grade` null unless printed, so a `patron_confirmation` whose excerpt contains no rank word
loses its grade. Runs over docs/decrees/register/*.json when the fragments exist, else over the register.
"""
import json
import re

from notitiae.merge import FRAGMENTS, REGISTER

RANK_WORD = re.compile(r"gradu|sollemnita|festum|festi|memoria", re.IGNORECASE)


def strip_unprinted_patron_grades(entries: list[dict]) -> int:
    """Null `celebration.grade` on patron confirmations whose excerpt prints no rank; returns how many changed."""
    changed = 0
    for e in entries:
        cel = e.get("celebration")
        if e.get("kind") != "patron_confirmation" or not cel or cel.get("grade") is None:
            continue
        if not RANK_WORD.search(e.get("excerpt", "")):
            cel["grade"] = None
            changed += 1
    return changed


def main() -> None:
    paths = sorted(FRAGMENTS.glob("*.json")) if FRAGMENTS.exists() else [REGISTER]
    total = 0
    for path in paths:
        raw = path.read_text()
        entries = json.loads(raw)
        n = strip_unprinted_patron_grades(entries)
        if n:
            indent = len(raw.split("\n", 2)[1]) - len(raw.split("\n", 2)[1].lstrip(" ")) if "\n" in raw else 2
            path.write_text(json.dumps(entries, indent=indent or 2, ensure_ascii=False) + "\n")
            print(f"{path.name}: {n}")
        total += n
    print(f"{total} patron_confirmation grade(s) nulled")


if __name__ == "__main__":
    main()
