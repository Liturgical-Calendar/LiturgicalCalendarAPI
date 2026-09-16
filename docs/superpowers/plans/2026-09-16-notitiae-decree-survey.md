# Notitiae Decree Survey Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cache the 418-PDF *Notitiae* corpus once, locate every page that can carry a calendar decree, transcribe those pages into a
schema-validated register in this repository, and generate two GitHub epics from it.

**Architecture:** A standalone corpus repository at `~/development/sources/notitiae/` owns download and text extraction (plain Python 3 +
poppler). This repository gains `scripts/notitiae/` — locator, validation gate, fragment merger, issue renderer — plus
`docs/decrees/notitiae-register.json` and its schema. Transcription is done by reading PDF pages visually, one subagent per volume, into
per-year fragments that the merger folds into the register.

**Tech Stack:** Python 3.12 (stdlib only), poppler-utils (`pdftotext`, `pdfinfo`), PHPUnit 12 + `swaggest/json-schema` for the register
test, `gh` for the epics.

**Spec:** `docs/superpowers/specs/2026-09-16-notitiae-decree-survey-design.md`

## Global Constraints

- Work only in the worktree `.worktrees/notitiae-survey` on branch `feature/notitiae-decree-survey` (never commit in the shared checkout).
- Corpus cache lives at `~/development/sources/notitiae/`; PDFs and text are never committed anywhere.
- Python scripts: stdlib only, Python ≥ 3.12, executable with `python3 <script>`; tests run with `python3 -m unittest discover -s <dir>`.
- Register entries recorded from 1969 onward; 1965–1968 only `kind: general_calendar`.
- Never invent a protocol number or date; `null` + `needs_review: true` when illegible.
- All markdown must pass `npx --yes markdownlint-cli <file>` (line length ≤ 180, aligned tables).
- Commits are GPG-signed and go through CaptainHook; never `--no-verify`.
- No calendar under `jsondata/` is modified; `decrees.json` is not modified.

---

## File map

Corpus repository (`~/development/sources/notitiae/`, new git repo):

- `README.md` — provenance, layout, refresh procedure.
- `.gitignore` — `pdf/`, `txt/`, `txt-layout/`.
- `manifest.json` — array of records, one per PDF.
- `tools/notitiae_index.py` — parse the index HTML into manifest records (pure function, tested).
- `tools/fetch.py` — CLI: refresh manifest from the web, download missing PDFs.
- `tools/extract.py` — CLI: `pdftotext` (both modes) and `pdfinfo` page counts for every PDF lacking them.
- `tools/tests/test_notitiae_index.py`, `tools/tests/test_extract.py`.

This repository:

- `scripts/notitiae/README.md` — how the four scripts fit together.
- `scripts/notitiae/corpus.py` — locate the cache (`NOTITIAE_CACHE` env or `../../sources/notitiae` relative to repo root), load manifest,
  read page-split text.
- `scripts/notitiae/locate.py` — build `worklist.json`.
- `scripts/notitiae/gate.py` — the decrees.json validation gate.
- `scripts/notitiae/merge.py` — fold `docs/decrees/register/*.json` fragments into the register; derive `calendar_implemented`.
- `scripts/notitiae/issues.py` — render the two epic bodies.
- `scripts/notitiae/tests/test_locate.py`, `test_gate.py`, `test_merge.py`, `test_issues.py`.
- `docs/decrees/notitiae-register.schema.json`, `docs/decrees/notitiae-register.json`.
- `docs/decrees/notitiae-transcription-guide.md` — the rules every transcribing subagent follows.
- `docs/decrees/register/<year>.json` — transient fragments; deleted in Task 10.
- `phpunit_tests/Docs/NotitiaeRegisterSchemaTest.php`.

---

### Task 1: Corpus repository scaffold and index parser

**Files:**

- Create: `~/development/sources/notitiae/README.md`, `.gitignore`, `tools/notitiae_index.py`, `tools/tests/test_notitiae_index.py`

**Interfaces:**

- Produces: `notitiae_index.parse_index(html: str, base: str = "https://www.cultodivino.va") -> list[dict]` returning records
  `{file, url, volume, year, issues, new_series, index_of}` sorted by `(year, issues[0])`.

- [ ] **Step 1: Create the repository skeleton**

```bash
mkdir -p ~/development/sources/notitiae/tools/tests && cd ~/development/sources/notitiae && git init -q -b main
printf 'pdf/\ntxt/\ntxt-layout/\n__pycache__/\n' > .gitignore
touch tools/__init__.py tools/tests/__init__.py
```

- [ ] **Step 2: Write the failing parser test**

`tools/tests/test_notitiae_index.py`:

```python
import unittest
from tools.notitiae_index import parse_index

HTML = """
<a href="/content/dam/cultodivino/rivista-notitiae/1970/notitiae-12-(1976)/Notitiae-116-1976.pdf">x</a>
<a href="/content/dam/cultodivino/rivista-notitiae/1970/notitiae-12-(1976)/Notitiae-113-1976-indice-1965-1975.pdf">x</a>
<a href="/content/dam/cultodivino/rivista-notitiae/1990/notitiae-28-(1992)/Notitiae-306-307-1992.pdf">x</a>
<a href="/content/dam/cultodivino/rivista-notitiae/2010/notitiae-53-(2017)/Notitiae-594-NS-002-2017.pdf">x</a>
<a href="/content/dam/cultodivino/rivista-notitiae/1970/notitiae-12-(1976)/Notitiae-116-1976.pdf">dup</a>
"""


class ParseIndexTest(unittest.TestCase):
    def test_parses_fields_and_dedupes(self):
        recs = parse_index(HTML)
        self.assertEqual(4, len(recs))
        by_file = {r["file"]: r for r in recs}
        plain = by_file["Notitiae-116-1976.pdf"]
        self.assertEqual(12, plain["volume"])
        self.assertEqual(1976, plain["year"])
        self.assertEqual([116], plain["issues"])
        self.assertIsNone(plain["new_series"])
        self.assertIsNone(plain["index_of"])
        self.assertTrue(plain["url"].startswith("https://www.cultodivino.va/content/dam/"))

    def test_double_issue(self):
        recs = {r["file"]: r for r in parse_index(HTML)}
        self.assertEqual([306, 307], recs["Notitiae-306-307-1992.pdf"]["issues"])

    def test_new_series(self):
        recs = {r["file"]: r for r in parse_index(HTML)}
        self.assertEqual([594], recs["Notitiae-594-NS-002-2017.pdf"]["issues"])
        self.assertEqual(2, recs["Notitiae-594-NS-002-2017.pdf"]["new_series"])

    def test_cumulative_index(self):
        recs = {r["file"]: r for r in parse_index(HTML)}
        self.assertEqual([1965, 1975], recs["Notitiae-113-1976-indice-1965-1975.pdf"]["index_of"])

    def test_sorted_by_year_then_issue(self):
        files = [r["file"] for r in parse_index(HTML)]
        self.assertEqual(["Notitiae-113-1976-indice-1965-1975.pdf", "Notitiae-116-1976.pdf", "Notitiae-306-307-1992.pdf",
                          "Notitiae-594-NS-002-2017.pdf"], files)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 3: Run it to see it fail**

Run: `cd ~/development/sources/notitiae && python3 -m unittest discover -s tools/tests -t . -v`
Expected: `ModuleNotFoundError: No module named 'tools.notitiae_index'`

- [ ] **Step 4: Implement the parser**

`tools/notitiae_index.py`:

```python
"""Parse the Dicastery's Notitiae index page into manifest records."""
import re

HREF = re.compile(r'href="(/content/dam/cultodivino/rivista-notitiae/[^"]+\.pdf)"')
VOLUME_DIR = re.compile(r"notitiae-(\d+)-\((\d{4})\)")
FILE = re.compile(r"^Notitiae-(?P<nums>(?:\d{3}-)*\d{3})(?:-NS-(?P<ns>\d{3}))?-(?P<year>\d{4})(?:-indice-(?P<ifrom>\d{4})-(?P<ito>\d{4}))?\.pdf$")


def parse_index(html: str, base: str = "https://www.cultodivino.va") -> list[dict]:
    seen: dict[str, dict] = {}
    for path in HREF.findall(html):
        file = path.rsplit("/", 1)[-1]
        if file in seen:
            continue
        vol = VOLUME_DIR.search(path)
        m = FILE.match(file)
        if not vol or not m:
            raise ValueError(f"unrecognised Notitiae path: {path}")
        seen[file] = {
            "file": file,
            "url": base + path,
            "volume": int(vol.group(1)),
            "year": int(m.group("year")),
            "issues": [int(n) for n in m.group("nums").split("-")],
            "new_series": int(m.group("ns")) if m.group("ns") else None,
            "index_of": [int(m.group("ifrom")), int(m.group("ito"))] if m.group("ifrom") else None,
        }
    return sorted(seen.values(), key=lambda r: (r["year"], r["issues"][0]))
```

- [ ] **Step 5: Run the tests**

Run: `cd ~/development/sources/notitiae && python3 -m unittest discover -s tools/tests -t . -v`
Expected: 5 tests, OK.

- [ ] **Step 6: Write the README**

`README.md`:

`````markdown
# Notitiae corpus

Local mirror of the review *Notitiae* (Congregation / Dicastery for Divine Worship and the Discipline of the Sacraments),
as published by the Dicastery at <https://www.cultodivino.va/it/rivista-notitiae.html>. 418 PDFs, 1965–2022, about 3 GB.

Shared by the LiturgicalCalendar workspace (decree survey) and SaintsDB (martyrology). Tools and the manifest are
tracked; PDFs and extracted text are not.

## Layout

```text
manifest.json          one record per PDF: file, url, volume, year, issues, new_series, index_of, sha256, bytes, pages, fetched_at
pdf/<year>/            the PDFs, named as on the Dicastery site
txt/<year>/            pdftotext output, reading-order mode, pages separated by form feeds
txt-layout/<year>/     pdftotext -layout output (tabular sections keep their columns)
tools/                 fetch.py, extract.py and their tests
```

## Refresh

```bash
python3 tools/fetch.py      # re-reads the index page, downloads what is missing, updates manifest.json
python3 tools/extract.py    # extracts text and page counts for anything not yet extracted
```

Both are idempotent and never delete. A newly published volume appears after running them again.

## Provenance notes

- The PDFs carry an OCR text layer of mixed quality: good enough to find sections, not good enough to transcribe
  the tabular *Summarium Decretorum* entries — read those pages visually.
- `Notitiae-113-1976-indice-1965-1975.pdf` is the cumulative index of the first eleven volumes.
- From 2016 (no. 593) the review is a *nuova serie* with one volume per year.
`````

- [ ] **Step 7: Lint and commit**

```bash
cd ~/development/sources/notitiae && npx --yes markdownlint-cli README.md
git add -A && git commit -q -m "Corpus repo scaffold: index parser, README"
```

---

### Task 2: Fetch tool and full download

**Files:**

- Create: `tools/fetch.py` in the corpus repo

**Interfaces:**

- Consumes: `parse_index`.
- Produces: `manifest.json` records with `sha256`, `bytes`, `fetched_at` filled for every downloaded PDF; PDFs under `pdf/<year>/`.

- [ ] **Step 1: Write the fetch tool**

`tools/fetch.py`:

```python
#!/usr/bin/env python3
"""Refresh manifest.json from the Dicastery index page and download missing PDFs."""
import hashlib
import json
import sys
import time
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tools.notitiae_index import parse_index  # noqa: E402

ROOT = Path(__file__).resolve().parents[1]
INDEX_URL = "https://www.cultodivino.va/it/rivista-notitiae.html"
MANIFEST = ROOT / "manifest.json"
UA = {"User-Agent": "notitiae-corpus/1.0 (liturgical-calendar project; contact: priest@johnromanodorazio.com)"}


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def download(url: str, dest: Path, attempts: int = 4) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp = dest.with_suffix(".part")
    for i in range(attempts):
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=120) as r, tmp.open("wb") as f:
                for chunk in iter(lambda: r.read(1 << 20), b""):
                    f.write(chunk)
            tmp.replace(dest)
            return
        except Exception as e:  # noqa: BLE001
            print(f"  retry {i + 1}/{attempts} after {e}", file=sys.stderr)
            time.sleep(5 * (i + 1))
    raise SystemExit(f"failed to download {url}")


def main() -> None:
    existing = {r["file"]: r for r in json.loads(MANIFEST.read_text())} if MANIFEST.exists() else {}
    html = urllib.request.urlopen(urllib.request.Request(INDEX_URL, headers=UA), timeout=60).read().decode("utf-8")
    records = parse_index(html)
    for rec in records:
        old = existing.get(rec["file"], {})
        rec.update({k: old.get(k) for k in ("sha256", "bytes", "pages", "fetched_at")})
        dest = ROOT / "pdf" / str(rec["year"]) / rec["file"]
        if dest.exists() and rec["bytes"] == dest.stat().st_size and rec["sha256"]:
            continue
        print(f"fetching {rec['file']}")
        download(rec["url"], dest)
        rec["bytes"] = dest.stat().st_size
        rec["sha256"] = sha256(dest)
        rec["fetched_at"] = datetime.now(timezone.utc).astimezone().isoformat(timespec="seconds")
        MANIFEST.write_text(json.dumps(records, indent=2, ensure_ascii=False) + "\n")
        time.sleep(1)
    MANIFEST.write_text(json.dumps(records, indent=2, ensure_ascii=False) + "\n")
    print(f"{len(records)} records, {sum(1 for r in records if r['sha256'])} downloaded")


if __name__ == "__main__":
    main()
```

- [ ] **Step 2: Smoke-test on one file, then run the full download in the background**

```bash
cd ~/development/sources/notitiae && python3 - <<'PY'
import json, urllib.request
from tools.notitiae_index import parse_index
html = urllib.request.urlopen("https://www.cultodivino.va/it/rivista-notitiae.html").read().decode()
print(len(parse_index(html)))   # expect 418
PY
nohup python3 tools/fetch.py > fetch.log 2>&1 &
```

Expected: `418`; `fetch.log` grows with one `fetching …` line per file. The run takes on the order of an hour. Continue with Task 3
while it runs; Task 4 needs it finished.

- [ ] **Step 3: Commit the tool (not the manifest yet — it is being written)**

```bash
cd ~/development/sources/notitiae && git add tools/fetch.py && git commit -q -m "fetch.py: idempotent download of the Notitiae PDFs"
```

---

### Task 3: Extract tool

**Files:**

- Create: `tools/extract.py`, `tools/tests/test_extract.py` in the corpus repo

**Interfaces:**

- Produces: `extract.plan(manifest: list[dict], root: Path) -> list[dict]` (records still needing extraction);
  `txt/<year>/<stem>.txt`, `txt-layout/<year>/<stem>.txt`; `pages` filled in the manifest.

- [ ] **Step 1: Write the failing test**

`tools/tests/test_extract.py`:

```python
import tempfile
import unittest
from pathlib import Path

from tools.extract import plan


class PlanTest(unittest.TestCase):
    def test_plan_lists_only_records_missing_an_output(self):
        with tempfile.TemporaryDirectory() as d:
            root = Path(d)
            for sub in ("pdf/1976", "txt/1976", "txt-layout/1976"):
                (root / sub).mkdir(parents=True)
            (root / "pdf/1976/Notitiae-116-1976.pdf").write_bytes(b"%PDF")
            (root / "pdf/1976/Notitiae-117-1976.pdf").write_bytes(b"%PDF")
            (root / "txt/1976/Notitiae-116-1976.txt").write_text("done")
            (root / "txt-layout/1976/Notitiae-116-1976.txt").write_text("done")
            manifest = [
                {"file": "Notitiae-116-1976.pdf", "year": 1976, "sha256": "x", "pages": 48},
                {"file": "Notitiae-117-1976.pdf", "year": 1976, "sha256": "x", "pages": None},
                {"file": "Notitiae-118-1976.pdf", "year": 1976, "sha256": None, "pages": None},
            ]
            todo = [r["file"] for r in plan(manifest, root)]
            self.assertEqual(["Notitiae-117-1976.pdf"], todo)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run it to see it fail**

Run: `cd ~/development/sources/notitiae && python3 -m unittest tools.tests.test_extract -v`
Expected: `ModuleNotFoundError: No module named 'tools.extract'`

- [ ] **Step 3: Implement**

`tools/extract.py`:

```python
#!/usr/bin/env python3
"""Extract text (two modes) and page counts for every downloaded PDF that lacks them."""
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "manifest.json"


def outputs(rec: dict, root: Path) -> tuple[Path, Path, Path]:
    stem = rec["file"][:-4]
    year = str(rec["year"])
    return (root / "pdf" / year / rec["file"], root / "txt" / year / f"{stem}.txt", root / "txt-layout" / year / f"{stem}.txt")


def plan(manifest: list[dict], root: Path) -> list[dict]:
    todo = []
    for rec in manifest:
        pdf, txt, lay = outputs(rec, root)
        if not rec.get("sha256") or not pdf.exists():
            continue
        if txt.exists() and lay.exists() and rec.get("pages"):
            continue
        todo.append(rec)
    return todo


def page_count(pdf: Path) -> int:
    out = subprocess.run(["pdfinfo", str(pdf)], capture_output=True, text=True, check=True).stdout
    m = re.search(r"^Pages:\s+(\d+)", out, re.M)
    if not m:
        raise RuntimeError(f"pdfinfo gave no page count for {pdf}")
    return int(m.group(1))


def main() -> None:
    manifest = json.loads(MANIFEST.read_text())
    todo = plan(manifest, ROOT)
    for i, rec in enumerate(todo, 1):
        pdf, txt, lay = outputs(rec, ROOT)
        txt.parent.mkdir(parents=True, exist_ok=True)
        lay.parent.mkdir(parents=True, exist_ok=True)
        print(f"[{i}/{len(todo)}] {rec['file']}")
        subprocess.run(["pdftotext", str(pdf), str(txt)], check=True)
        subprocess.run(["pdftotext", "-layout", str(pdf), str(lay)], check=True)
        rec["pages"] = page_count(pdf)
        MANIFEST.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n")
    print(f"{len(todo)} extracted; {sum(1 for r in manifest if r.get('pages'))}/{len(manifest)} have text")


if __name__ == "__main__":
    sys.exit(main())
```

- [ ] **Step 4: Run tests, then run extraction (after the download finishes)**

Run: `cd ~/development/sources/notitiae && python3 -m unittest discover -s tools/tests -t . -v`
Expected: 6 tests OK.

Then, once `fetch.log` ends with `418 records, 418 downloaded`:

```bash
cd ~/development/sources/notitiae && python3 tools/extract.py 2>&1 | tail -3
python3 -c "import json;m=json.load(open('manifest.json'));print(sum(r['pages'] or 0 for r in m),'pages total')"
```

Expected: `418 extracted; 418/418 have text` and a total page count in the tens of thousands.

- [ ] **Step 5: Commit tools and the completed manifest**

```bash
cd ~/development/sources/notitiae && git add -A && git commit -q -m "extract.py; manifest for all 418 PDFs"
```

---

### Task 4: Corpus access module and locator

**Files:**

- Create: `scripts/notitiae/corpus.py`, `scripts/notitiae/locate.py`, `scripts/notitiae/tests/__init__.py`, `scripts/notitiae/tests/test_locate.py`
- Create: `scripts/notitiae/README.md`

**Interfaces:**

- Produces (`corpus.py`): `cache_root() -> Path`; `load_manifest() -> list[dict]`; `pages(rec, layout=False) -> list[str]` (text of each
  PDF page, index 0 = PDF page 1).
- Produces (`locate.py`): `printed_offset(pages: list[str]) -> int | None`; `select_pages(pages: list[str]) -> dict[int, list[str]]`
  (1-based PDF page → reasons); `index_page_refs(text: str) -> set[int]`; CLI writes `scripts/notitiae/worklist.json`:

```json
[{"file": "Notitiae-116-1976.pdf", "year": 1976, "volume": 12, "pages": 48, "printed_offset": 88,
  "selected": [{"pdf_page": 5, "printed_page": 93, "reasons": ["heading:summarium"]}]}]
```

- [ ] **Step 1: Write the failing tests**

`scripts/notitiae/tests/test_locate.py`:

```python
import unittest

from notitiae.locate import index_page_refs, printed_offset, select_pages

FF = "\f"


def page(*lines):
    return "\n".join(lines) + "\n"


class PrintedOffsetTest(unittest.TestCase):
    def test_offset_is_mode_of_running_head_minus_pdf_page(self):
        pages = [page("cover"), page("90", "NOTITIAE", "text"), page("text", "91"), page("92 NOTITIAE")]
        # pdf page 2 → 90, 3 → 91, 4 → 92: offset 88
        self.assertEqual(88, printed_offset(pages))

    def test_no_running_heads(self):
        self.assertIsNone(printed_offset([page("just text"), page("more")]))


class SelectPagesTest(unittest.TestCase):
    def test_summarium_heading_and_following_prot_pages(self):
        pages = [
            page("Allocutio", "nothing here"),
            page("SUMMARIUM DECRETORUM", "I. Calendaria particularia"),
            page("Bostoniensis, 3 martii 1976 (Prot. CD 123/76): approbatur calendarium"),
            page("Prot. CD 124/76 confirmatur electio Patroni"),
            page("Bibliographia", "books"),
            page("more books"),
        ]
        sel = select_pages(pages)
        self.assertIn("heading:summarium", sel[2])
        self.assertIn("prot", sel[3])
        self.assertIn("prot", sel[4])
        self.assertNotIn(5, sel)
        self.assertNotIn(1, sel)

    def test_keyword_sweep_outside_summarium(self):
        pages = [page("DECRETUM", "Calendarium Romanum Generale ita immutatur: memoria S. N. inscribitur")]
        self.assertIn("keyword:calendarium-romanum", select_pages(pages)[1])

    def test_grade_words_near_action_verbs(self):
        pages = [page("celebratio", "ad gradum festi elevatur", "in calendario proprio")]
        self.assertIn("keyword:grade-action", select_pages(pages)[1])


class IndexPageRefsTest(unittest.TestCase):
    def test_collects_numbers_after_section_heading(self):
        text = page("INDEX VOLUMINIS XII (1976)", "IV. Nationes", "Belgium 134, 178; Dania 11.", "V. Dioeceses",
                    "Abellinensis 310; Alba 366, 368.")
        self.assertEqual({134, 178, 11, 310, 366, 368}, index_page_refs(text))


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run it to see it fail**

Run: `cd scripts && python3 -m unittest discover -s notitiae/tests -t . -v`
Expected: `ModuleNotFoundError: No module named 'notitiae'` (add `scripts/notitiae/__init__.py` in the next step).

- [ ] **Step 3: Implement corpus.py and locate.py**

`scripts/notitiae/__init__.py`: empty file.

`scripts/notitiae/corpus.py`:

```python
"""Access to the shared Notitiae corpus cache (see docs/superpowers/specs/2026-09-16-notitiae-decree-survey-design.md §1)."""
import json
import os
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def cache_root() -> Path:
    env = os.environ.get("NOTITIAE_CACHE")
    root = Path(env).expanduser() if env else (REPO_ROOT.parent.parent.parent / "sources" / "notitiae")
    if not (root / "manifest.json").exists():
        raise SystemExit(f"Notitiae cache not found at {root}; set NOTITIAE_CACHE or run tools/fetch.py there")
    return root


def load_manifest() -> list[dict]:
    return json.loads((cache_root() / "manifest.json").read_text())


def pages(rec: dict, layout: bool = False) -> list[str]:
    folder = "txt-layout" if layout else "txt"
    path = cache_root() / folder / str(rec["year"]) / (rec["file"][:-4] + ".txt")
    return path.read_text(errors="replace").split("\f")
```

Note: `REPO_ROOT.parent.parent.parent` resolves `LiturgicalCalendarAPI/.worktrees/<wt>` → `~/development`; from the main checkout
(`LiturgicalCalendar/LiturgicalCalendarAPI`) it resolves to `~/` — so set `NOTITIAE_CACHE=~/development/sources/notitiae` in
`.env.local`-free shells when running from the main checkout. The README documents this.

`scripts/notitiae/locate.py`:

```python
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
        head = "\n".join(text.strip().splitlines()[:3] + text.strip().splitlines()[-3:])
        for m in RUNNING_HEAD.finditer(head):
            n = int(m.group(1) or m.group(2))
            if 0 < n < 2000:
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
    for m in re.finditer(r"(?<![\d/])(\d{1,3})(?:-(\d{1,3}))?(?=[;,.\s])", text):
        a, b = int(m.group(1)), int(m.group(2) or m.group(1))
        if 0 < a <= b < 1000 and b - a < 6:
            refs.update(range(a, b + 1))
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
        index_refs: set[int] = set()
        for rec in recs:
            texts = pages(rec)
            offsets[rec["file"]] = printed_offset(texts)
            selections[rec["file"]] = select_pages(texts)
            for text in texts:
                index_refs |= index_page_refs(text)
        for rec in recs:
            off = offsets[rec["file"]]
            sel = selections[rec["file"]]
            if off is not None:
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
```

- [ ] **Step 4: Run the tests**

Run: `cd scripts && python3 -m unittest discover -s notitiae/tests -t . -v`
Expected: 6 tests OK.

- [ ] **Step 5: Run the locator on the real corpus and sanity-check the numbers**

```bash
cd scripts && python3 notitiae/locate.py
python3 - <<'PY'
import json; w=json.load(open('notitiae/worklist.json'))
by=dict()
for f in w: by.setdefault(f['year']//10*10,[0,0]); by[f['year']//10*10][0]+=len(f['selected']); by[f['year']//10*10][1]+=f['pages'] or 0
for k,v in sorted(by.items()): print(k, f"{v[0]}/{v[1]} pages selected ({100*v[0]//max(v[1],1)}%)")
PY
```

Expected: every decade selects a minority of its pages (roughly 10–35%). If a decade selects > 60%, the keyword
`keyword:kalendarium` is too broad for that decade's OCR — restrict it to `\b[CK]alendari(um|a|i|o)\b` and re-run. If any file has
`printed_offset: null`, open its `txt` and adjust `RUNNING_HEAD` to the running-head style used there.

- [ ] **Step 6: Write the README and commit**

`scripts/notitiae/README.md`:

`````markdown
# Notitiae survey tools

Tooling for the survey described in `docs/superpowers/specs/2026-09-16-notitiae-decree-survey-design.md`.
The corpus itself lives in `~/development/sources/notitiae/` (override with `NOTITIAE_CACHE`).

| Script      | Reads                                        | Writes                                              |
|-------------|----------------------------------------------|-----------------------------------------------------|
| `locate.py` | corpus text                                  | `scripts/notitiae/worklist.json`                    |
| `gate.py`   | worklist, corpus text, `decrees.json`        | report on stdout; exit 1 on a missed decree         |
| `merge.py`  | `docs/decrees/register/*.json`, calendars    | `docs/decrees/notitiae-register.json`               |
| `issues.py` | `docs/decrees/notitiae-register.json`        | two markdown bodies under `scripts/notitiae/out/`   |

Run from `scripts/`: `python3 notitiae/locate.py`. Tests: `python3 -m unittest discover -s notitiae/tests -t .`.

`worklist.json` and `out/` are build products and are gitignored.
`````

```bash
printf 'scripts/notitiae/worklist.json\nscripts/notitiae/out/\n__pycache__/\n' >> .gitignore
npx --yes markdownlint-cli scripts/notitiae/README.md
git add .gitignore scripts/notitiae && git commit -q -m "feat(notitiae): corpus access and page locator"
```

---

### Task 5: Validation gate against decrees.json

**Files:**

- Create: `scripts/notitiae/gate.py`, `scripts/notitiae/tests/test_gate.py`

**Interfaces:**

- Produces: `protocol_number(decree_protocol: str) -> str | None` (`"Prot. N. 257/16"` → `"257/16"`);
  `find_protocol(number: str, corpus_pages: dict[str, list[str]]) -> list[tuple[str, int]]` (file, pdf_page) hits;
  CLI exit code 0 when every found protocol is on a selected page, 1 otherwise.

- [ ] **Step 1: Write the failing tests**

`scripts/notitiae/tests/test_gate.py`:

```python
import unittest

from notitiae.gate import find_protocol, gate_report, protocol_number


class ProtocolNumberTest(unittest.TestCase):
    def test_strips_prefix(self):
        self.assertEqual("257/16", protocol_number("Prot. N. 257/16"))
        self.assertEqual("1131/76", protocol_number("Prot. CD 1131/76"))

    def test_missing(self):
        self.assertIsNone(protocol_number(""))


class FindProtocolTest(unittest.TestCase):
    def test_finds_page_tolerating_ocr_spacing(self):
        corpus = {"Notitiae-593-NS-001-2016.pdf": ["cover", "Decretum (Prot. N. 257 / 16) de S. Maria Magdalena"]}
        self.assertEqual([("Notitiae-593-NS-001-2016.pdf", 2)], find_protocol("257/16", corpus))


class GateReportTest(unittest.TestCase):
    def test_hit_on_unselected_page_is_a_miss(self):
        hits = [("f.pdf", 2)]
        worklist = {"f.pdf": {3}}
        self.assertEqual("MISSED", gate_report(hits, worklist)["status"])

    def test_hit_on_selected_page_passes(self):
        self.assertEqual("OK", gate_report([("f.pdf", 2)], {"f.pdf": {2}})["status"])

    def test_no_hit_is_reported_not_failed(self):
        self.assertEqual("NOT_IN_CORPUS", gate_report([], {})["status"])


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run to see it fail**

Run: `cd scripts && python3 -m unittest notitiae.tests.test_gate -v`
Expected: `ModuleNotFoundError: No module named 'notitiae.gate'`

- [ ] **Step 3: Implement**

`scripts/notitiae/gate.py`:

```python
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
```

- [ ] **Step 4: Run tests, then run the gate for real**

Run: `cd scripts && python3 -m unittest discover -s notitiae/tests -t . -v` — Expected: 12 tests OK.

Run: `cd scripts && python3 notitiae/gate.py`
Expected: no `MISSED` line and exit 0. For each `MISSED`: open the cited PDF page, see why the locator skipped it, extend
`HEADINGS`/`KEYWORDS` in `locate.py` (add a unit test reproducing the missed page text first), re-run `locate.py`, re-run the gate.
`NOT_IN_CORPUS` lines are expected for decrees the review never printed (e.g. press-bulletin-only items); list them in the PR
description.

- [ ] **Step 5: Commit**

```bash
git add scripts/notitiae/gate.py scripts/notitiae/tests/test_gate.py && git commit -q -m "feat(notitiae): validation gate against decrees.json"
```

---

### Task 6: Register schema, empty register, PHPUnit test, transcription guide

**Files:**

- Create: `docs/decrees/notitiae-register.schema.json`, `docs/decrees/notitiae-register.json`,
  `docs/decrees/notitiae-transcription-guide.md`, `docs/decrees/README.md`, `phpunit_tests/Docs/NotitiaeRegisterSchemaTest.php`

- [ ] **Step 1: Write the failing PHPUnit test**

`phpunit_tests/Docs/NotitiaeRegisterSchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Docs;

use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * docs/decrees/notitiae-register.json is a hand-maintained register; this pins it to its schema so a
 * malformed entry (bad ISO code, unknown kind, missing citation) fails CI rather than rotting silently.
 */
final class NotitiaeRegisterSchemaTest extends TestCase
{
    private const string DOCS = __DIR__ . '/../../docs/decrees/';

    public function testRegisterValidatesAgainstSchema(): void
    {
        $schema = Schema::import(self::DOCS . 'notitiae-register.schema.json');
        $data   = json_decode((string) file_get_contents(self::DOCS . 'notitiae-register.json'));
        $schema->in($data);
        $this->addToAssertionCount(1);
    }

    public function testIdsAreUniqueAndSortedByDateThenId(): void
    {
        $entries = json_decode((string) file_get_contents(self::DOCS . 'notitiae-register.json'), true);
        assert(is_array($entries));
        $ids = array_column($entries, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'duplicate id');
        $keys = array_map(static fn(array $e): string => ($e['date'] ?? '9999-99-99') . ' ' . $e['id'], $entries);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys, 'register must be sorted by date then id (run scripts/notitiae/merge.py)');
    }

    public function testEntryWithUnknownKindIsRejected(): void
    {
        $schema = Schema::import(self::DOCS . 'notitiae-register.schema.json');
        $entry  = json_decode('{"id":"N1976-CD-1-76","source":{"volume":12,"year":1976,"issue":"116","pdf":"Notitiae-116-1976.pdf",'
            . '"pdf_pages":[5,5],"printed_pages":[93,93],"url":"https://www.cultodivino.va/x.pdf"},"protocol":"CD 1/76",'
            . '"date":"1976-01-01","kind":"bogus","target":{"level":"national","nation":"IT","diocese":null,"diocese_id":null,'
            . '"institute":null},"celebration":null,"summary_en":"x","excerpt":"x",'
            . '"api":{"calendar_implemented":true,"status":"recorded","applied_in":null},"needs_review":false}');
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        $schema->in([$entry]);
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `vendor/bin/phpunit phpunit_tests/Docs/NotitiaeRegisterSchemaTest.php`
Expected: errors — schema file not found.

- [ ] **Step 3: Write the schema and the empty register**

`docs/decrees/notitiae-register.schema.json`:

```json
{
  "$schema": "https://json-schema.org/draft-07/schema#",
  "title": "Notitiae decree register",
  "description": "Every calendar-relevant decree or concession found in the review Notitiae. See docs/superpowers/specs/2026-09-16-notitiae-decree-survey-design.md §4.",
  "type": "array",
  "items": { "$ref": "#/definitions/Entry" },
  "definitions": {
    "Entry": {
      "type": "object",
      "additionalProperties": false,
      "required": ["id", "source", "protocol", "date", "kind", "target", "celebration", "summary_en", "excerpt", "api", "needs_review"],
      "properties": {
        "id": { "type": "string", "pattern": "^N(19[6-9][0-9]|20[0-9]{2})-([A-Z0-9-]+|p[0-9]+-[0-9]+)$" },
        "source": { "$ref": "#/definitions/Source" },
        "also_in": { "type": "array", "items": { "$ref": "#/definitions/Source" } },
        "protocol": { "type": ["string", "null"] },
        "date": { "type": ["string", "null"], "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}$" },
        "kind": {
          "type": "string",
          "enum": ["general_calendar", "particular_calendar_approval", "particular_calendar_change", "new_celebration", "grade_change",
                   "transfer", "name_or_title_change", "patron_confirmation", "martyrology", "other"]
        },
        "target": {
          "type": "object",
          "additionalProperties": false,
          "required": ["level", "nation", "diocese", "diocese_id", "institute"],
          "properties": {
            "level": { "type": "string", "enum": ["general", "wider_region", "national", "diocesan", "religious", "other"] },
            "nation": { "type": ["string", "null"], "pattern": "^[A-Z]{2}$" },
            "wider_region": { "type": "string", "enum": ["Americas", "Asia", "Europe", "Africa", "Oceania"] },
            "diocese": { "type": ["string", "null"], "description": "Latin name as printed" },
            "diocese_id": { "type": ["string", "null"], "pattern": "^[a-z]{6}_[a-z]{2}$", "description": "diocese_id of an implemented diocesan calendar, when it is one" },
            "institute": { "type": ["string", "null"] }
          }
        },
        "celebration": {
          "type": ["object", "null"],
          "additionalProperties": false,
          "required": ["event_key", "name_latin", "month", "day", "grade"],
          "properties": {
            "event_key": { "type": ["string", "null"] },
            "name_latin": { "type": "string" },
            "month": { "type": ["integer", "null"], "minimum": 1, "maximum": 12 },
            "day": { "type": ["integer", "null"], "minimum": 1, "maximum": 31 },
            "grade": { "type": ["integer", "null"], "minimum": 0, "maximum": 7 }
          }
        },
        "summary_en": { "type": "string", "minLength": 1 },
        "excerpt": { "type": "string", "minLength": 1 },
        "api": {
          "type": "object",
          "additionalProperties": false,
          "required": ["calendar_implemented", "status", "applied_in"],
          "properties": {
            "calendar_implemented": { "type": "boolean" },
            "status": { "type": "string", "enum": ["recorded", "applied", "not_applicable", "rejected"] },
            "applied_in": { "type": ["string", "null"] }
          }
        },
        "needs_review": { "type": "boolean" }
      }
    },
    "Source": {
      "type": "object",
      "additionalProperties": false,
      "required": ["volume", "year", "issue", "pdf", "pdf_pages", "printed_pages", "url"],
      "properties": {
        "volume": { "type": "integer", "minimum": 1 },
        "year": { "type": "integer", "minimum": 1965 },
        "issue": { "type": "string", "description": "issue number(s) as in the file name, e.g. \"306-307\"" },
        "pdf": { "type": "string", "pattern": "^Notitiae-.*\\.pdf$" },
        "pdf_pages": { "type": "array", "items": { "type": "integer", "minimum": 1 }, "minItems": 2, "maxItems": 2 },
        "printed_pages": { "type": "array", "items": { "type": ["integer", "null"] }, "minItems": 2, "maxItems": 2 },
        "url": { "type": "string", "format": "uri" }
      }
    }
  }
}
```

`docs/decrees/notitiae-register.json`: `[]` followed by a newline.

- [ ] **Step 4: Run the PHPUnit test**

Run: `vendor/bin/phpunit phpunit_tests/Docs/NotitiaeRegisterSchemaTest.php`
Expected: 3 tests, OK. If `phpunit.xml` restricts test directories, add `phpunit_tests/Docs` to the `<testsuite>` and re-run; confirm with
`vendor/bin/phpunit --list-tests | grep Notitiae`.

- [ ] **Step 5: Write the transcription guide**

`docs/decrees/notitiae-transcription-guide.md`:

`````markdown
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

| `diocese_id` | Latin name(s) in Notitiae                      |
|--------------|------------------------------------------------|
| `boston_us`  | Bostoniensis                                   |
| `charlo_ca`  | Carolinopolitana (Charlottetown)               |
| `romamo_it`  | Romana, Urbis                                  |
| `agrige_it`  | Agrigentina                                    |
| `socaap_it`  | Sorana-Cassinensis-Aquinatensis-Pontiscurvi    |
| `rotter_nl`  | Roterodamensis                                 |
| `utrech_nl`  | Ultraiectensis                                 |
| `grolee_nl`  | Groningensis-Leovardiensis                     |
| `bredad_nl`  | Bredana                                        |
| `haaams_nl`  | Harlemensis-Amstelodamensis                    |
| `sherto_nl`  | Buscoducensis, Boscoducensis                   |
| `roermo_nl`  | Ruremundensis                                  |

(The executor completes this table from `jsondata/sourcedata/rite/roman/calendars/dioceses/*/*/*.json` — all 28 ids — and verifies
every Latin name against the diocese's entry on catholic-hierarchy.org before dispatching; the rows above are a starting point, not
verified. A transcriber must not guess an id that is not in the table.)

## Output

Write `docs/decrees/register/<year>.json`: a JSON array of entries for your volume only, in page order. Do not touch any
other file. When the same decree appears in full and again as a *Summarium* line, write **one** entry citing the full text in
`source` and the Summarium line in `also_in`.
`````

Complete the diocesan table by listing every `diocese_id` / `diocese_name` from the calendar files and adding the Latin name
(`Annuario Pontificio` Latin title) for each; the transcribers rely on it.

- [ ] **Step 6: docs/decrees/README.md**

`````markdown
# Decrees documentation

- `notitiae-register.json` — every calendar-relevant decree found in *Notitiae* (1965–2022), validated by
  `notitiae-register.schema.json` and `phpunit_tests/Docs/NotitiaeRegisterSchemaTest.php`.
- `notitiae-transcription-guide.md` — how entries are transcribed.
- Tools: `scripts/notitiae/`. Design: `docs/superpowers/specs/2026-09-16-notitiae-decree-survey-design.md`.

The register is a survey, not source data: nothing in it is applied to a calendar until a PR lands it in `jsondata/` and sets
`api.status` to `applied`.
`````

- [ ] **Step 7: Lint, run tests, commit**

```bash
npx --yes markdownlint-cli docs/decrees/*.md
vendor/bin/phpunit phpunit_tests/Docs/NotitiaeRegisterSchemaTest.php
git add docs/decrees phpunit_tests/Docs && git commit -q -m "feat(decrees): Notitiae register schema, guide and validation test"
```

---

### Task 7: Merge tool

**Files:**

- Create: `scripts/notitiae/merge.py`, `scripts/notitiae/tests/test_merge.py`

**Interfaces:**

- Produces: `implemented_calendars(repo_root: Path) -> dict` with keys `nations: set[str]`, `dioceses: set[str]` (diocese_ids),
  `wider_regions: set[str]`; `is_implemented(target: dict, impl: dict) -> bool`; `merge(fragments: list[list[dict]], impl: dict) -> list[dict]`
  (deduped by protocol, `also_in` folded, `calendar_implemented` set, sorted by `(date or "9999-99-99", id)`).

- [ ] **Step 1: Write the failing tests**

`scripts/notitiae/tests/test_merge.py`:

```python
import unittest

from notitiae.merge import is_implemented, merge

IMPL = {"nations": {"IT", "US"}, "dioceses": {"boston_us"}, "wider_regions": {"Europe"}}


def entry(id_, protocol, date, level="national", nation="IT", diocese_id=None, page=5):
    return {
        "id": id_, "protocol": protocol, "date": date, "kind": "particular_calendar_change",
        "source": {"volume": 12, "year": 1976, "issue": "116", "pdf": "Notitiae-116-1976.pdf", "pdf_pages": [page, page],
                   "printed_pages": [None, None], "url": "https://x/y.pdf"},
        "target": {"level": level, "nation": nation, "diocese": None, "diocese_id": diocese_id, "institute": None},
        "celebration": None, "summary_en": "s", "excerpt": "e",
        "api": {"calendar_implemented": False, "status": "recorded", "applied_in": None}, "needs_review": False,
    }


class IsImplementedTest(unittest.TestCase):
    def test_levels(self):
        self.assertTrue(is_implemented({"level": "general"}, IMPL))
        self.assertTrue(is_implemented({"level": "national", "nation": "IT"}, IMPL))
        self.assertFalse(is_implemented({"level": "national", "nation": "FR"}, IMPL))
        self.assertTrue(is_implemented({"level": "diocesan", "nation": "US", "diocese_id": "boston_us"}, IMPL))
        self.assertFalse(is_implemented({"level": "diocesan", "nation": "US", "diocese_id": None}, IMPL))
        self.assertTrue(is_implemented({"level": "wider_region", "wider_region": "Europe"}, IMPL))
        self.assertFalse(is_implemented({"level": "religious", "institute": "OFM"}, IMPL))


class MergeTest(unittest.TestCase):
    def test_sets_flag_and_sorts(self):
        out = merge([[entry("N1976-2-76", "CD 2/76", "1976-03-01"), entry("N1976-1-76", "CD 1/76", "1976-01-01", nation="FR")]], IMPL)
        self.assertEqual(["N1976-1-76", "N1976-2-76"], [e["id"] for e in out])
        self.assertFalse(out[0]["api"]["calendar_implemented"])
        self.assertTrue(out[1]["api"]["calendar_implemented"])

    def test_same_protocol_across_fragments_is_one_entry_with_also_in(self):
        a = entry("N1976-1-76", "CD 1/76", "1976-01-01", page=5)
        b = entry("N1976-1-76", "CD 1/76", "1976-01-01", page=40)
        out = merge([[a], [b]], IMPL)
        self.assertEqual(1, len(out))
        self.assertEqual([40, 40], out[0]["also_in"][0]["pdf_pages"])

    def test_null_dates_sort_last(self):
        out = merge([[entry("N1976-p9-1", None, None), entry("N1976-1-76", "CD 1/76", "1976-01-01")]], IMPL)
        self.assertEqual("N1976-p9-1", out[-1]["id"])


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run to see it fail**

Run: `cd scripts && python3 -m unittest notitiae.tests.test_merge -v`
Expected: `ModuleNotFoundError: No module named 'notitiae.merge'`

- [ ] **Step 3: Implement**

`scripts/notitiae/merge.py`:

```python
#!/usr/bin/env python3
"""Fold docs/decrees/register/<year>.json fragments into docs/decrees/notitiae-register.json."""
import json
from pathlib import Path

from notitiae.corpus import REPO_ROOT

CALENDARS = REPO_ROOT / "jsondata/sourcedata/rite/roman/calendars"
REGISTER = REPO_ROOT / "docs/decrees/notitiae-register.json"
FRAGMENTS = REPO_ROOT / "docs/decrees/register"


def implemented_calendars(repo_root: Path = REPO_ROOT) -> dict:
    cal = repo_root / "jsondata/sourcedata/rite/roman/calendars"
    return {
        "nations": {p.name for p in (cal / "nations").iterdir() if p.is_dir()},
        "dioceses": {p.name for n in (cal / "dioceses").iterdir() if n.is_dir() for p in n.iterdir() if p.is_dir()},
        "wider_regions": {p.name for p in (cal / "wider_regions").iterdir() if p.is_dir()},
    }


def is_implemented(target: dict, impl: dict) -> bool:
    level = target.get("level")
    if level == "general":
        return True
    if level == "wider_region":
        return target.get("wider_region") in impl["wider_regions"]
    if level == "national":
        return target.get("nation") in impl["nations"]
    if level == "diocesan":
        return target.get("diocese_id") in impl["dioceses"]
    return False


def sort_key(e: dict) -> tuple[str, str]:
    return (e.get("date") or "9999-99-99", e["id"])


def merge(fragments: list[list[dict]], impl: dict) -> list[dict]:
    by_id: dict[str, dict] = {}
    for frag in fragments:
        for e in frag:
            e = json.loads(json.dumps(e))
            e["api"]["calendar_implemented"] = is_implemented(e["target"], impl)
            if e["id"] in by_id:
                by_id[e["id"]].setdefault("also_in", []).append(e["source"])
            else:
                by_id[e["id"]] = e
    return sorted(by_id.values(), key=sort_key)


def main() -> None:
    existing = json.loads(REGISTER.read_text()) if REGISTER.exists() else []
    fragments = [json.loads(p.read_text()) for p in sorted(FRAGMENTS.glob("*.json"))] if FRAGMENTS.exists() else []
    out = merge([existing, *fragments], implemented_calendars())
    REGISTER.write_text(json.dumps(out, indent=2, ensure_ascii=False) + "\n")
    print(f"{len(out)} entries; {sum(e['api']['calendar_implemented'] for e in out)} on implemented calendars; "
          f"{sum(e['needs_review'] for e in out)} need review")


if __name__ == "__main__":
    main()
```

- [ ] **Step 4: Run tests and commit**

Run: `cd scripts && python3 -m unittest discover -s notitiae/tests -t . -v` — Expected: 16 tests OK.

```bash
git add scripts/notitiae/merge.py scripts/notitiae/tests/test_merge.py && git commit -q -m "feat(notitiae): fragment merger deriving calendar_implemented"
```

---

### Task 8: Issue renderer

**Files:**

- Create: `scripts/notitiae/issues.py`, `scripts/notitiae/tests/test_issues.py`

**Interfaces:**

- Produces: `render(entries: list[dict]) -> tuple[str, str]` (implemented body, not-yet-implemented body); CLI writes
  `scripts/notitiae/out/epic-implemented.md` and `out/epic-not-implemented.md`.

- [ ] **Step 1: Write the failing tests**

`scripts/notitiae/tests/test_issues.py`:

```python
import unittest

from notitiae.issues import render
from notitiae.tests.test_merge import entry


class RenderTest(unittest.TestCase):
    def test_split_by_flag_and_checkbox_state(self):
        a = entry("N1976-1-76", "CD 1/76", "1976-01-01")
        a["api"].update(calendar_implemented=True, status="applied", applied_in="#999")
        b = entry("N1976-2-76", "CD 2/76", "1976-02-01", nation="FR")
        impl, not_impl = render([a, b])
        self.assertIn("- [x] `N1976-1-76`", impl)
        self.assertIn("#999", impl)
        self.assertIn("- [ ] `N1976-2-76`", not_impl)
        self.assertNotIn("N1976-2-76", impl)

    def test_section_order_and_deep_link(self):
        g = entry("N2016-257-16", "N. 257/16", "2016-06-03", level="general", nation=None)
        g["api"]["calendar_implemented"] = True
        d = entry("N1976-1-76", "CD 1/76", "1976-01-01", level="diocesan", nation="US", diocese_id="boston_us")
        d["api"]["calendar_implemented"] = True
        impl, _ = render([d, g])
        self.assertLess(impl.index("## General Roman Calendar"), impl.index("## US"))
        self.assertIn("https://x/y.pdf#page=5", impl)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run to see it fail**

Run: `cd scripts && python3 -m unittest notitiae.tests.test_issues -v`
Expected: `ModuleNotFoundError: No module named 'notitiae.issues'`

- [ ] **Step 3: Implement**

`scripts/notitiae/issues.py`:

```python
#!/usr/bin/env python3
"""Render the two tracking epics from the register."""
import json
from collections import defaultdict
from pathlib import Path

from notitiae.corpus import REPO_ROOT

REGISTER = REPO_ROOT / "docs/decrees/notitiae-register.json"
OUT = Path(__file__).with_name("out")
LEVEL_ORDER = {"general": 0, "wider_region": 1, "national": 2, "diocesan": 3, "religious": 4, "other": 5}


def heading(e: dict) -> str:
    t = e["target"]
    return {
        "general": "General Roman Calendar",
        "wider_region": f"Wider region: {t.get('wider_region')}",
        "national": t.get("nation") or "Unknown nation",
        "diocesan": f"{t.get('nation') or '??'} / {t.get('diocese') or t.get('diocese_id')}",
        "religious": f"Religious: {t.get('institute')}",
    }.get(t["level"], "Other")


def line(e: dict) -> str:
    box = "x" if e["api"]["status"] == "applied" else " "
    s = e["source"]
    applied = f" — applied in {e['api']['applied_in']}" if e["api"]["applied_in"] else ""
    review = " ⚠ needs review" if e["needs_review"] else ""
    return (f"- [{box}] `{e['id']}` {e['date'] or '????-??-??'} · {e['protocol'] or 'no protocol'} · {e['summary_en']} "
            f"([Notitiae {s['year']} p. {s['printed_pages'][0] or '?'}]({s['url']}#page={s['pdf_pages'][0]})){applied}{review}")


def body(entries: list[dict], title: str) -> str:
    groups: dict[str, list[dict]] = defaultdict(list)
    for e in entries:
        groups[heading(e)].append(e)
    ordered = sorted(groups.items(), key=lambda kv: (LEVEL_ORDER[kv[1][0]["target"]["level"]], kv[0]))
    parts = [f"# {title}", "", "Generated by `scripts/notitiae/issues.py` from `docs/decrees/notitiae-register.json`; "
             "do not edit checkboxes by hand — update the register and re-render.", ""]
    for name, items in ordered:
        parts += [f"## {name}", ""] + [line(e) for e in items] + [""]
    return "\n".join(parts)


def render(entries: list[dict]) -> tuple[str, str]:
    impl = [e for e in entries if e["api"]["calendar_implemented"]]
    rest = [e for e in entries if not e["api"]["calendar_implemented"]]
    return (body(impl, "Notitiae survey — decrees for implemented calendars"),
            body(rest, "Notitiae survey — decrees for calendars not yet implemented"))


def main() -> None:
    impl, rest = render(json.loads(REGISTER.read_text()))
    OUT.mkdir(exist_ok=True)
    (OUT / "epic-implemented.md").write_text(impl)
    (OUT / "epic-not-implemented.md").write_text(rest)
    print(f"wrote {OUT}/epic-implemented.md and epic-not-implemented.md")


if __name__ == "__main__":
    main()
```

- [ ] **Step 4: Run tests and commit**

Run: `cd scripts && python3 -m unittest discover -s notitiae/tests -t . -v` — Expected: 18 tests OK.

```bash
git add scripts/notitiae/issues.py scripts/notitiae/tests/test_issues.py && git commit -q -m "feat(notitiae): epic renderer"
```

---

### Task 9: Transcription — one subagent per volume

This task is procedural. It produces `docs/decrees/register/<year>.json` for every year 1965–2022.

**Files:**

- Create: `docs/decrees/register/<year>.json` (58 fragments)

- [ ] **Step 1: Prepare per-volume assignments**

```bash
cd scripts && python3 - <<'PY'
import json, collections
w = json.load(open('notitiae/worklist.json'))
by = collections.defaultdict(list)
for f in w:
    for s in f['selected']:
        by[f['year']].append({"file": f['file'], "pdf_page": s['pdf_page'], "printed_page": s['printed_page'], "reasons": s['reasons']})
for y, items in by.items():
    json.dump(items, open(f'/tmp/claude-1000/notitiae-assign-{y}.json', 'w'), indent=1)
print({y: len(v) for y, v in sorted(by.items())})
PY
```

- [ ] **Step 2: Dispatch, six volumes at a time, oldest first**

Each dispatch is an `Agent` (general-purpose) with this prompt, `<YEAR>` and `<CACHE>` substituted:

```text
You are transcribing one volume of the review Notitiae into a JSON fragment. Work ONLY in the git worktree
/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI/.worktrees/notitiae-survey (verify with
`git rev-parse --show-toplevel` before writing anything; never cd to the main checkout). Do not commit.

1. Read docs/decrees/notitiae-transcription-guide.md and docs/decrees/notitiae-register.schema.json in full.
2. Your assignment is /tmp/claude-1000/notitiae-assign-<YEAR>.json: a list of {file, pdf_page, printed_page, reasons}.
   The PDFs are at <CACHE>/pdf/<YEAR>/<file>. Read the pages VISUALLY with the Read tool's `pages` parameter (at most 20 pages
   per call, group consecutive pages). Do not transcribe from <CACHE>/txt — use it only to search for a word you cannot
   place. When an entry continues past a selected page, read the next page too.
3. Write docs/decrees/register/<YEAR>.json: a JSON array of entries obeying the schema and the guide, in page order.
   Years 1965–1968: general_calendar items only.
4. Validate before finishing: `cd scripts && python3 - <<'PY'` … load your fragment, check every entry has all required keys and
   every `kind`/`level`/`status` value is in the schema enums; print the count. Fix anything reported.
5. Reply with: number of entries, number with needs_review, and any page you could not read (file + pdf_page) — say so
   plainly rather than guessing content.
Never invent a protocol number, date or name. Illegible → null + needs_review: true.
```

Run six concurrently. When a batch finishes, spot-check each fragment (open two or three cited pages yourself and compare
against the entries) before dispatching the next batch. A fragment with fabricated protocols or missing Summarium lines is
re-dispatched with the discrepancy named in the prompt.

- [ ] **Step 3: Commit fragments per batch**

```bash
git add docs/decrees/register && git commit -q -m "data(notitiae): transcription fragments <first year>–<last year>"
```

---

### Task 10: Merge, audit, final register, PR

**Files:**

- Modify: `docs/decrees/notitiae-register.json`
- Delete: `docs/decrees/register/`

- [ ] **Step 1: Merge and validate**

```bash
cd scripts && python3 notitiae/merge.py && cd .. && vendor/bin/phpunit phpunit_tests/Docs/NotitiaeRegisterSchemaTest.php
```

Expected: entry counts printed; 3 PHPUnit tests OK. Schema failures name the entry — fix it in the fragment, re-merge.

- [ ] **Step 2: Audit sample**

```bash
cd scripts && python3 - <<'PY'
import json, random
random.seed(20260916)
w = json.load(open('notitiae/worklist.json'))
pages = [(f['file'], s['pdf_page']) for f in w for s in f['selected']]
by_decade = {}
for file, p in pages: by_decade.setdefault(int(file.split('-')[-1][:4]) // 10 * 10, []).append((file, p))
for dec, ps in sorted(by_decade.items()):
    for file, p in random.sample(ps, max(1, len(ps) // 20)): print(dec, file, p)
PY
```

Read every listed page yourself and compare with the register (`grep -n '"pdf": "<file>"' docs/decrees/notitiae-register.json`).
Record the number of pages checked and the discrepancies found per decade in the PR description. A decade with more than a handful
of discrepancies goes back to Task 9 for a second pass on that decade.

- [ ] **Step 3: Remove fragments, run the full quick suite and linters**

```bash
git rm -rq docs/decrees/register
composer test:quick 2>&1 | tail -5
composer lint 2>&1 | tail -3
npx --yes markdownlint-cli "docs/decrees/*.md" scripts/notitiae/README.md
cd scripts && python3 -m unittest discover -s notitiae/tests -t . && cd ..
```

Expected: all green (the Routes tests hit the shared :8000 and prove nothing about this branch — that is fine, nothing here touches
the API).

- [ ] **Step 4: Commit and open the PR**

```bash
git add -A && git commit -q -m "data(decrees): Notitiae decree register 1965–2022

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
git push -u origin feature/notitiae-decree-survey
gh pr create --base development --title "Notitiae decree survey: register, tools, schema" --body-file - <<'EOF'
## Summary
- register of every calendar-relevant decree found in Notitiae 1965–2022 (`docs/decrees/notitiae-register.json`)
- schema + PHPUnit validation; transcription guide
- `scripts/notitiae/`: locator, validation gate, merger, epic renderer (stdlib Python, unit-tested)

## Verification
- gate: <paste gate.py output summary>
- audit: <pages checked / discrepancies per decade>
- decrees.json entries not found in the corpus: <list>

Spec: docs/superpowers/specs/2026-09-16-notitiae-decree-survey-design.md

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
```

---

### Task 11: Open the two epics

- [ ] **Step 1: Render and create**

```bash
cd scripts && python3 notitiae/issues.py && cd ..
gh issue create --title "Notitiae survey — decrees for implemented calendars" --label decrees --body-file scripts/notitiae/out/epic-implemented.md
gh issue create --title "Notitiae survey — decrees for calendars not yet implemented" --label decrees --body-file scripts/notitiae/out/epic-not-implemented.md
```

If the `decrees` label does not exist, `gh label create decrees --color 5319e7 --description "Dicastery decrees and concessions"` first.
If a body exceeds GitHub's 65,536-character limit, split the not-implemented epic by continent (add a `--continent` filter to
`issues.py` grouping on the nation's continent via a small dict in the script) and open one issue per part.

- [ ] **Step 2: Cross-link**

Add both issue URLs to the PR description and to `docs/decrees/README.md` (one line each), commit, push.

```bash
git add docs/decrees/README.md && git commit -q -m "docs(decrees): link the Notitiae tracking epics" && git push
```
