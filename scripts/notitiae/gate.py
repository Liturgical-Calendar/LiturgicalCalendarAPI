#!/usr/bin/env python3
"""Validation gate: every decree already in decrees.json that the corpus contains must be on a selected page."""
import json
import re
import sys
from pathlib import Path

from notitiae.corpus import REPO_ROOT, load_manifest, pages

DECREES = REPO_ROOT / "jsondata/sourcedata/rite/roman/decrees/decrees.json"
WORKLIST = Path(__file__).with_name("worklist.json")


def protocol_number(decree_protocol: str) -> str | None:
    m = re.search(r"(\d+)\s*/\s*(\d+)", decree_protocol or "")
    return f"{m.group(1)}/{m.group(2)}" if m else None


def find_protocol(number: str, corpus_pages: dict[str, list[str]]) -> list[tuple[str, int]]:
    a, b = number.split("/")
    rx = re.compile(rf"(?<!\d){a}\s*/\s*{b}(?!\d)")
    return [(file, i) for file, texts in corpus_pages.items() for i, t in enumerate(texts, 1) if rx.search(t)]


def gate_report(hits: list[tuple[str, int]], selected: dict[str, set[int]]) -> dict:
    if not hits:
        return {"status": "NOT_IN_CORPUS", "hits": []}
    missed = [h for h in hits if h[1] not in selected.get(h[0], set())]
    return {"status": "MISSED" if len(missed) == len(hits) else "OK", "hits": hits, "missed": missed}


def main() -> None:
    decrees = json.loads(DECREES.read_text())
    worklist = {w["file"]: {s["pdf_page"] for s in w["selected"]} for w in json.loads(WORKLIST.read_text())}
    manifest = load_manifest()
    failures = 0
    for d in decrees:
        number = protocol_number(d.get("decree_protocol", ""))
        year = int(d["decree_date"][:4])
        if not number or year < 1965 or year > 2022:
            print(f"SKIP           {d['decree_id']}: no protocol or outside corpus years")
            continue
        corpus = {r["file"]: pages(r) for r in manifest if r["year"] in (year, year + 1)}
        report = gate_report(find_protocol(number, corpus), worklist)
        where = ", ".join(f"{f}#p{p}" for f, p in report["hits"][:3])
        print(f"{report['status']:14} {d['decree_id']} ({number}) {where}")
        failures += report["status"] == "MISSED"
    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
