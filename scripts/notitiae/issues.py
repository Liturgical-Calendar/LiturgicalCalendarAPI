#!/usr/bin/env python3
"""Render the two tracking epics from the register.

Two kinds of output:

- The full per-entry checklists (`docs/decrees/epics/implemented.md` and `not-implemented.md`) are
  committed generated files: one `- [ ]`/`- [x]` line per register entry, grouped by calendar.
- The GitHub epic bodies (`scripts/notitiae/out/epic-*.md`, gitignored) hold a short explanation, a
  summary table per calendar, and a link to the checklist file. GitHub issue bodies cap at 65,536
  characters and the full not-implemented checklist alone is ~1.5 MB, so it cannot live in the body.
  The implemented epic's body additionally appends a compact per-entry checklist beneath the table,
  but only while the whole body stays under `checklist_threshold` characters.
"""
import json
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path

from notitiae.corpus import REPO_ROOT

REGISTER = REPO_ROOT / "docs/decrees/notitiae-register.json"
OUT = Path(__file__).with_name("out")
EPICS_DIR = REPO_ROOT / "docs/decrees/epics"
DIOCESES_ROOT = REPO_ROOT / "jsondata/sourcedata/rite/roman/calendars/dioceses"

CHECKLIST_URL_TEMPLATE = "https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/blob/development/docs/decrees/epics/{name}.md"
DEFAULT_CHECKLIST_THRESHOLD = 60_000

# Group-key "kind" -> sort rank, shared by every table/checklist grouping in this module.
LEVEL_ORDER = {
    "general": 0,
    "wider_region": 1,
    "national": 2,
    "diocesan": 3,
    "diocesan_other": 4,
    "religious": 5,
    "other": 6,
}

IMPL_TITLE = "Notitiae survey — decrees for implemented calendars"
NOTIMPL_TITLE = "Notitiae survey — decrees for calendars not yet implemented"

IMPL_INTRO = (
    "This epic tracks decrees and concessions recorded in the Notitiae survey "
    "(`docs/decrees/notitiae-register.json`) that target calendars the API already implements: the "
    "General Roman Calendar, wider regions, nations and dioceses. Each row below counts survey entries "
    "for that calendar; a decree becomes \"Applied\" once a PR lands it in `jsondata/` and sets "
    "`api.status` to `applied` in the register."
)
NOTIMPL_INTRO = (
    "This epic tracks decrees and concessions recorded in the Notitiae survey for calendars the API "
    "does not yet implement, plus religious institutes and other targets. It doubles as the backlog "
    "for adding new national and diocesan calendars: a nation's row below counts both nation-level "
    "decrees and diocesan decrees whose diocese has not yet been assigned a `diocese_id`."
)

CHECKLIST_FOOTER_NOTE = (
    "Do not edit checkboxes by hand — update the register and re-render with `python3 -m notitiae.issues`."
)


@dataclass
class Rendered:
    impl_body: str
    notimpl_body: str
    impl_checklist: str
    notimpl_checklist: str


def load_diocese_names() -> dict[str, str]:
    """Map diocese_id -> metadata.diocese_name, scanned from the diocesan calendar source tree."""
    names: dict[str, str] = {}
    if not DIOCESES_ROOT.is_dir():
        return names
    for nation_dir in sorted(DIOCESES_ROOT.iterdir()):
        if not nation_dir.is_dir():
            continue
        for diocese_dir in sorted(nation_dir.iterdir()):
            if not diocese_dir.is_dir():
                continue
            for data_file in sorted(diocese_dir.glob("*.json")):
                try:
                    data = json.loads(data_file.read_text(encoding="utf-8"))
                except (OSError, json.JSONDecodeError):
                    continue
                name = data.get("metadata", {}).get("diocese_name")
                if name:
                    names[diocese_dir.name] = name
                break
    return names


def group_key(e: dict, diocese_names: dict[str, str]) -> tuple[str, str]:
    """Full-detail grouping shared by both checklists and the implemented epic's table.

    Diocesan entries group by `diocese_name` (looked up from `diocese_id`), never by the printed Latin
    spelling in `target.diocese` — two different spellings of the same diocese must land in one section.
    A diocesan entry with no `diocese_id` groups by nation instead, under its own "other dioceses" kind
    so it never collides with a named-diocese section.
    """
    t = e["target"]
    level = t["level"]
    if level == "general":
        return "general", "General Roman Calendar"
    if level == "wider_region":
        return "wider_region", f"Wider region: {t.get('wider_region') or 'Unknown region'}"
    if level == "national":
        return "national", t.get("nation") or "Unknown nation"
    if level == "diocesan":
        diocese_id = t.get("diocese_id")
        if diocese_id:
            name = diocese_names.get(diocese_id) or diocese_id
            return "diocesan", name
        nation = t.get("nation") or "??"
        return "diocesan_other", f"{nation} — other dioceses"
    if level == "religious":
        return "religious", "Religious institutes"
    return "other", "Other targets"


def table_group_key_notimpl(e: dict) -> tuple[str, str]:
    """Aggregated grouping for the not-implemented epic's summary table.

    Rows are: each nation (rolling up its national-level entries together with its diocesan entries
    that have no `diocese_id` yet — there is no separate calendar for those to be counted against),
    then "Religious institutes", then "Other targets".
    """
    t = e["target"]
    level = t["level"]
    if level == "wider_region":
        return "wider_region", f"Wider region: {t.get('wider_region') or 'Unknown region'}"
    if level in ("national", "diocesan"):
        return "national", t.get("nation") or "Unknown nation"
    if level == "religious":
        return "religious", "Religious institutes"
    return "other", "Other targets"


def checkbox_line(e: dict) -> str:
    box = "x" if e["api"]["status"] == "applied" else " "
    s = e["source"]
    applied = f" — applied in {e['api']['applied_in']}" if e["api"]["applied_in"] else ""
    review = " ⚠ needs review" if e["needs_review"] else ""
    return (
        f"- [{box}] `{e['id']}` {e['date'] or '????-??-??'} · {e['protocol'] or 'no protocol'} · {e['summary_en']} "
        f"([Notitiae {s['year']} p. {s['printed_pages'][0] or '?'}]({s['url']}#page={s['pdf_pages'][0]})){applied}{review}"
    )


def compact_checkbox_line(e: dict) -> str:
    box = "x" if e["api"]["status"] == "applied" else " "
    s = e["source"]
    summary = e["summary_en"]
    if len(summary) > 100:
        summary = summary[:100] + "…"
    page = s["pdf_pages"][0]
    return f"- [{box}] `{e['id']}` {e['date'] or '????-??-??'} · {e['protocol'] or 'no protocol'} · {summary} · [p. {page}]({s['url']}#page={page})"


def _grouped(entries: list[dict], group_fn) -> list[tuple[tuple[str, str], list[dict]]]:
    groups: dict[tuple[str, str], list[dict]] = defaultdict(list)
    for e in entries:
        groups[group_fn(e)].append(e)
    return sorted(groups.items(), key=lambda kv: (LEVEL_ORDER[kv[0][0]], kv[0][1]))


def render_checklist(entries: list[dict], title: str, diocese_names: dict[str, str]) -> str:
    parts = [
        "<!-- markdownlint-disable MD013 -->",
        "",
        f"# {title}",
        "",
        "Generated by `python3 -m notitiae.issues` from `docs/decrees/notitiae-register.json`; "
        + CHECKLIST_FOOTER_NOTE,
        "",
    ]
    for (_, name), items in _grouped(entries, lambda e: group_key(e, diocese_names)):
        parts += [f"## {name}", ""] + [checkbox_line(e) for e in items] + [""]
    return "\n".join(parts)


def render_table(entries: list[dict], group_fn) -> str:
    """Render a `Calendar | Entries | Applied | Needs review` table, columns padded to their widest
    cell (header, separator and every row) so the pipes line up — markdownlint MD060 checks exactly
    that alignment.
    """
    headers = ["Calendar", "Entries", "Applied", "Needs review"]
    justify = [str.ljust, str.rjust, str.rjust, str.rjust]
    rows = []
    for (_, name), items in _grouped(entries, group_fn):
        applied = sum(1 for e in items if e["api"]["status"] == "applied")
        review = sum(1 for e in items if e["needs_review"])
        rows.append([name, str(len(items)), str(applied), str(review)])
    widths = [max([len(headers[i])] + [len(r[i]) for r in rows]) for i in range(4)]

    def fmt_row(cells: list[str]) -> str:
        return "| " + " | ".join(justify[i](cells[i], widths[i]) for i in range(4)) + " |"

    lines = [fmt_row(headers), "| " + " | ".join("-" * widths[i] for i in range(4)) + " |"]
    lines += [fmt_row(r) for r in rows]
    return "\n".join(lines)


def render_compact_checklist(entries: list[dict], diocese_names: dict[str, str]) -> str:
    parts = []
    for (_, name), items in _grouped(entries, lambda e: group_key(e, diocese_names)):
        parts += [f"### {name}", ""] + [compact_checkbox_line(e) for e in items] + [""]
    return "\n".join(parts)


def render_body(
    entries: list[dict],
    title: str,
    intro: str,
    group_fn,
    checklist_name: str,
    *,
    include_checklist: bool = False,
    threshold: int = DEFAULT_CHECKLIST_THRESHOLD,
    diocese_names: dict[str, str] | None = None,
) -> str:
    table = render_table(entries, group_fn)
    url = CHECKLIST_URL_TEMPLATE.format(name=checklist_name)
    header = [f"# {title}", "", intro, "", table, "", f"[Full checklist]({url})", ""]
    if not include_checklist:
        return "\n".join(header)
    compact = render_compact_checklist(entries, diocese_names or {})
    candidate = "\n".join(header + ["## Checklist", "", compact])
    if len(candidate) < threshold:  # strictly under the cap
        return candidate
    omitted_note = (
        "_The per-entry checklist is omitted here because including it would push this body over "
        "GitHub's issue size limit; see the linked checklist file above for the full list._"
    )
    return "\n".join(header + [omitted_note])


def render(
    entries: list[dict],
    diocese_names: dict[str, str] | None = None,
    checklist_threshold: int = DEFAULT_CHECKLIST_THRESHOLD,
) -> Rendered:
    diocese_names = diocese_names or {}
    impl = [e for e in entries if e["api"]["calendar_implemented"]]
    rest = [e for e in entries if not e["api"]["calendar_implemented"]]

    impl_checklist = render_checklist(impl, IMPL_TITLE, diocese_names)
    notimpl_checklist = render_checklist(rest, NOTIMPL_TITLE, diocese_names)

    impl_body = render_body(
        impl,
        IMPL_TITLE,
        IMPL_INTRO,
        lambda e: group_key(e, diocese_names),
        "implemented",
        include_checklist=True,
        threshold=checklist_threshold,
        diocese_names=diocese_names,
    )
    notimpl_body = render_body(
        rest,
        NOTIMPL_TITLE,
        NOTIMPL_INTRO,
        table_group_key_notimpl,
        "not-implemented",
        include_checklist=False,
    )

    return Rendered(
        impl_body=impl_body,
        notimpl_body=notimpl_body,
        impl_checklist=impl_checklist,
        notimpl_checklist=notimpl_checklist,
    )


def main() -> None:
    entries = json.loads(REGISTER.read_text(encoding="utf-8"))
    diocese_names = load_diocese_names()
    rendered = render(entries, diocese_names)

    OUT.mkdir(exist_ok=True)
    EPICS_DIR.mkdir(parents=True, exist_ok=True)

    outputs = {
        OUT / "epic-implemented.md": rendered.impl_body,
        OUT / "epic-not-implemented.md": rendered.notimpl_body,
        EPICS_DIR / "implemented.md": rendered.impl_checklist,
        EPICS_DIR / "not-implemented.md": rendered.notimpl_checklist,
    }
    for path, text in outputs.items():
        path.write_text(text, encoding="utf-8")
        print(f"wrote {path} ({len(text.encode('utf-8'))} bytes)")


if __name__ == "__main__":
    main()
