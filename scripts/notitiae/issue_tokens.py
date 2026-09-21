#!/usr/bin/env python3
"""One-off normalisation (final review, item A2): `source.issue` is the token spelled in the PDF file name.

Transcribers of the early volumes wrote the issue number as printed on the cover (`"85"`), while the guide
requires the token from the file name, zero-padded exactly as the file spells it (`Notitiae-085-1973.pdf`
→ `"085"`). Protocol-less ids are built from that token by `merge.py`, so a drifted token renames entries.
Runs over docs/decrees/register/*.json when the fragments exist, else over the register. Re-run
`merge.py` afterwards so `normalise_id` rebuilds the affected protocol-less ids.
"""
import json
import re

from notitiae.merge import FRAGMENTS, REGISTER

# `Notitiae-<issue>-<year>.pdf`; the one cumulative index is `Notitiae-113-1976-indice-1965-1975.pdf`, hence the optional suffix.
_PDF_NAME = re.compile(r"^Notitiae-(.+?)-\d{4}(?:-[^.]+)?\.pdf$")


def issue_token(pdf_filename: str) -> str:
    """The issue token of a corpus file name: the text between `Notitiae-` and `-<year>`."""
    m = _PDF_NAME.match(pdf_filename)
    if not m:
        raise ValueError(f"not a Notitiae corpus file name: {pdf_filename!r}")
    return m.group(1)


def fix_issue_tokens(entries: list[dict]) -> tuple[list[dict], int]:
    """Set `source.issue` and every `also_in[].issue` to the token spelled in its `pdf`; returns (entries, how many changed)."""
    changed = 0
    for e in entries:
        for citation in [e["source"], *e.get("also_in", [])]:
            token = issue_token(citation["pdf"])
            if citation["issue"] != token:
                citation["issue"] = token
                changed += 1
    return entries, changed


def main() -> None:
    paths = sorted(FRAGMENTS.glob("*.json")) if FRAGMENTS.exists() else [REGISTER]
    total = 0
    for path in paths:
        entries, n = fix_issue_tokens(json.loads(path.read_text(encoding="utf-8")))
        if n:
            path.write_text(json.dumps(entries, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
            print(f"{path.name}: {n}")
        total += n
    print(f"{total} issue token(s) corrected")


if __name__ == "__main__":
    main()
