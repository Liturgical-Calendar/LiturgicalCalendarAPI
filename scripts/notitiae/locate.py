#!/usr/bin/env python3
"""Build worklist.json: for every Notitiae PDF, the pages a reader must open, with reasons."""
import json
import re
from collections import Counter
from pathlib import Path

from notitiae.corpus import load_manifest, pages

RUNNING_HEAD = re.compile(r"^\s*(\d{1,4})\s*(?:NOTITIAE)?\s*$|^\s*(?:NOTITIAE|INDEX VOLUMINIS[^\n]*?)\s+(\d{1,4})\s*$", re.M)
HEADINGS = {
    "heading:summarium": re.compile(r"SUMMARIUM\s+DECRETORUM|Summarium\s+Decretorum", re.I),
    "heading:calendaria": re.compile(r"CALENDARIA\s+PARTICULARIA|Calendaria\s+particularia", re.I),
    "heading:decreta": re.compile(r"^\s*DECRET(UM|A)\s*$", re.M),
    "heading:index": re.compile(r"INDEX\s+VOLUMINIS", re.I),
}
KEYWORDS = {
    "prot": re.compile(r"Prot\.?\s*(N\.?|CD|n\.)?\s*\d", re.I),
    "keyword:calendarium-romanum": re.compile(r"Calendari(um|o)\s+Roman(um|o)\s+General", re.I),
    "keyword:kalendarium": re.compile(r"[CK]alendari", re.I),
    "keyword:patron": re.compile(r"\bPatron", re.I),
    "keyword:martyrologium": re.compile(r"Martyrolog", re.I),
}
GRADE = re.compile(r"sollemnita|festum|festi\b|memoria", re.I)
ACTION = re.compile(r"inscrib|conced|confirm|approb|elev|transfer|celebr", re.I)


def printed_offset(page_texts: list[str]) -> int | None:
    diffs = Counter()
    for i, text in enumerate(page_texts, 1):
        lines = text.strip().splitlines()
        first3 = lines[:3]
        last3 = [line for line in lines[-3:] if line not in first3]
        head = "\n".join(first3 + last3)
        for m in RUNNING_HEAD.finditer(head):
            n = int(m.group(1) or m.group(2))
            # Some volumes paginate cumulatively across the whole year and can exceed 1000
            # by year's end (e.g. issue 255, October 1987, prints pages past 1010), so the
            # ceiling is 1500 rather than 1000. Every corpus year (>= 1965) is still above
            # that ceiling, so this still excludes bare years (e.g. "1976" on a cover or a
            # dateline broken onto its own line), which would otherwise match as a running
            # head, while admitting every real printed page number seen in the corpus.
            if 0 < n < 1500:
                diffs[n - i] += 1
    return diffs.most_common(1)[0][0] if diffs else None


def select_pages(page_texts: list[str]) -> dict[int, list[str]]:
    selected: dict[int, list[str]] = {}
    in_summarium = False
    for i, text in enumerate(page_texts, 1):
        reasons = [name for name, rx in HEADINGS.items() if rx.search(text)]
        reasons += [name for name, rx in KEYWORDS.items() if rx.search(text)]
        if GRADE.search(text) and ACTION.search(text):
            reasons.append("keyword:grade-action")
        if "heading:summarium" in reasons:
            in_summarium = True
        elif in_summarium and "prot" not in reasons:
            in_summarium = False
        if in_summarium and "prot" in reasons and "heading:summarium" not in reasons:
            reasons.append("summarium-continuation")
        if reasons:
            selected[i] = reasons
    return selected


def index_page_refs(text: str) -> set[int]:
    if not HEADINGS["heading:index"].search(text):
        return set()
    refs = set()
    # \d{1,4}: the digit class must admit a 4th digit for the bound below to have any
    # effect -- the leading (?<![\d/]) lookbehind blocks a partial match inside a longer
    # digit run, so a {1,3} class would simply drop a 4-digit reference rather than
    # truncate it.
    for m in re.finditer(r"(?<![\d/])(\d{1,4})(?:-(\d{1,4}))?(?=[;,.\s])", text):
        a, b = int(m.group(1)), int(m.group(2) or m.group(1))
        # Same 1500 ceiling as printed_offset() (see its comment): a cumulative-paginated
        # volume can print index references past 1000, and this bound must not be tighter
        # than the offset it is meant to line up with.
        if 0 < a <= b < 1500 and b - a < 6:
            refs.update(range(a, b + 1))
    return refs


def harvest_index_refs(recs: list[dict], texts_by_file: dict[str, list[str]]) -> set[int]:
    """Union of index-volume page references across a year's records.

    Records with a non-null `index_of` are a cumulative index covering a *different* span of
    years (e.g. `Notitiae-113-1976-indice-1965-1975.pdf` indexes 1965-1975, filed under 1976
    because that is when it was published). Its page references belong to whichever of those
    eleven volumes each entry actually falls in, not to 1976 -- and there is no cheap way to
    tell which one from a bare page number, so distributing them across all eleven would just
    be noise. Excluding the record from harvesting is the minimal correct fix; a future pass
    could resolve a cumulative index's refs against its covered years' own offsets instead.
    """
    refs: set[int] = set()
    for rec in recs:
        if rec.get("index_of") is not None:
            continue
        for text in texts_by_file[rec["file"]]:
            refs |= index_page_refs(text)
    return refs


def main() -> None:
    manifest = load_manifest()
    worklist = []
    by_year: dict[int, list[dict]] = {}
    for rec in manifest:
        by_year.setdefault(rec["year"], []).append(rec)
    for year, recs in sorted(by_year.items()):
        offsets = {}
        selections = {}
        texts_by_file: dict[str, list[str]] = {}
        for rec in recs:
            texts = pages(rec)
            texts_by_file[rec["file"]] = texts
            offsets[rec["file"]] = printed_offset(texts)
            selections[rec["file"]] = select_pages(texts)
        index_refs = harvest_index_refs(recs, texts_by_file)
        for rec in recs:
            off = offsets[rec["file"]]
            sel = selections[rec["file"]]
            if off is not None and rec.get("index_of") is None:
                for printed in index_refs:
                    pdf_page = printed - off
                    if 1 <= pdf_page <= (rec["pages"] or 0):
                        sel.setdefault(pdf_page, []).append("index-ref")
            worklist.append({
                "file": rec["file"], "year": year, "volume": rec["volume"], "pages": rec["pages"], "printed_offset": off,
                "selected": [{"pdf_page": p, "printed_page": (p + off) if off is not None else None, "reasons": sorted(set(r))}
                             for p, r in sorted(sel.items())],
            })
    out = Path(__file__).with_name("worklist.json")
    out.write_text(json.dumps(worklist, indent=1, ensure_ascii=False) + "\n")
    total = sum(len(w["selected"]) for w in worklist)
    print(f"{len(worklist)} files, {total} pages selected, {sum(1 for w in worklist if w['printed_offset'] is None)} files without offset")


if __name__ == "__main__":
    main()
