#!/usr/bin/env python3
"""One-off normalisation (Task 10 fix round 1, item 8): a patron confirmation carries a month/day only when the page prints one.

Transcribers often filled `celebration.month`/`day` of a patron confirmation from the saint's feast day rather than
from the page, which prints only the decree date. The guide's ruling is `month`/`day` null unless printed, so a
`patron_confirmation` whose excerpt prints no celebration date loses both. A month token that is immediately followed
by a four-digit year is the decree date (every Summarium line carries one) and does not count. Runs over
docs/decrees/register/*.json when the fragments exist, else over the register.
"""
import json
import re

from notitiae.merge import FRAGMENTS, REGISTER

_LATIN = (
    r"ian(?:uarii|uario|\.)|febr?(?:uarii|uario|\.)|mart(?:ii|io|\.)|apr(?:ilis|ili|\.)|mai(?:i|o|\.)|iun(?:ii|io|\.)"
    r"|iul(?:ii|io|\.)|aug(?:usti|usto|\.)|sept?(?:embris|embri|\.)|oct(?:obris|obri|\.)|nov(?:embris|embri|\.)"
    r"|dec(?:embris|embri|\.)"
)
_ITALIAN = (
    r"gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre"
    r"|gen\.|mar\.|giu\.|lug\.|ago\.|set\.|ott\.|dic\."
)
MONTH_TOKEN = re.compile(rf"(?<![A-Za-z])(?:{_LATIN}|{_ITALIAN})(?![A-Za-z])", re.IGNORECASE)
_YEAR_FOLLOWS = re.compile(r"^\s*(?:anni\s+)?\d{4}(?!\d)")


def excerpt_prints_month(excerpt: str) -> bool:
    """True when the excerpt contains a month token that is not part of a `<day> <month> <year>` decree date."""
    return any(not _YEAR_FOLLOWS.match(excerpt[m.end():]) for m in MONTH_TOKEN.finditer(excerpt))


def strip_unprinted_patron_dates(entries: list[dict]) -> int:
    """Null `celebration.month` and `day` on patron confirmations whose excerpt prints no celebration date; returns how many changed."""
    changed = 0
    for e in entries:
        cel = e.get("celebration")
        if e.get("kind") != "patron_confirmation" or not cel or (cel.get("month") is None and cel.get("day") is None):
            continue
        if not excerpt_prints_month(e.get("excerpt", "")):
            cel["month"] = None
            cel["day"] = None
            changed += 1
    return changed


def main() -> None:
    paths = sorted(FRAGMENTS.glob("*.json")) if FRAGMENTS.exists() else [REGISTER]
    total = 0
    for path in paths:
        raw = path.read_text()
        entries = json.loads(raw)
        n = strip_unprinted_patron_dates(entries)
        if n:
            indent = len(raw.split("\n", 2)[1]) - len(raw.split("\n", 2)[1].lstrip(" ")) if "\n" in raw else 2
            path.write_text(json.dumps(entries, indent=indent or 2, ensure_ascii=False) + "\n")
            print(f"{path.name}: {n}")
        total += n
    print(f"{total} patron_confirmation month/day pair(s) nulled")


if __name__ == "__main__":
    main()
