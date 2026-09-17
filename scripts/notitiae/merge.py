#!/usr/bin/env python3
"""Fold docs/decrees/register/<year>.json fragments into docs/decrees/notitiae-register.json."""
import json
import re
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


# Protocol-less ids end in ``p<pdf page>-<n>`` (optionally ``-<m>`` for a distinct act); whatever sits between the
# year and that tail is the issue token — absent in the oldest spelling, possibly mis-spelled in later ones.
_PROTOCOL_LESS_ID = re.compile(r"^N(\d{4})-(?:.+-)?(p\d+-\d+(?:-\d+)?)$")
_BARE_N_TOKEN = re.compile(r"^N(\d{4})-N-")


def normalise_id(entry: dict) -> str:
    """Canonical id for an entry, whatever spelling the transcriber used. Deterministic and idempotent.

    Protocol-less ids are unique per *file*, not per year, so they carry the issue token, always rebuilt from
    ``source.issue``: ``N2008-p51-1`` in Notitiae 521-522 becomes ``N2008-521-522-p51-1``, and an id written
    with the token as printed (``N1967-36-p27-1``) becomes ``N1967-036-p27-1`` once ``source.issue`` is the
    file-name spelling. Early volumes wrote the bare ``N.`` of ``Prot. N. 833/69`` into the id; that token is
    dropped (``N1969-N-833-69`` → ``N1969-833-69``).
    """
    id_ = entry["id"]
    m = _PROTOCOL_LESS_ID.match(id_)
    if m:
        return f"N{m.group(1)}-{entry['source']['issue']}-{m.group(2)}"
    return _BARE_N_TOKEN.sub(r"N\1-", id_)


def _unsafe_to_fold(kept: dict, new: dict) -> bool:
    """Same id, but plausibly two different acts: printed in different volumes under different (or no) protocols."""
    if kept["source"]["pdf"] == new["source"]["pdf"]:
        return False
    a, b = kept.get("protocol"), new.get("protocol")
    return a is None or b is None or a != b


def merge(fragments: list[list[dict]], impl: dict) -> list[dict]:
    by_id: dict[str, dict] = {}
    for frag in fragments:
        for e in frag:
            e = json.loads(json.dumps(e))
            e["id"] = normalise_id(e)
            e["api"]["calendar_implemented"] = is_implemented(e["target"], impl)
            if e["id"] in by_id:
                kept = by_id[e["id"]]
                if _unsafe_to_fold(kept, e):
                    raise ValueError(
                        f"refusing to fold {e['id']}: {kept['source']['pdf']} p.{kept['source']['pdf_pages'][0]} "
                        f"(protocol {kept.get('protocol')!r}) vs {e['source']['pdf']} p.{e['source']['pdf_pages'][0]} "
                        f"(protocol {e.get('protocol')!r}) — different volumes and different protocols; "
                        "fold by hand in the fragment or give one of them its own id"
                    )
                _cite(kept, e["source"])
                for extra in e.get("also_in", []):
                    _cite(kept, extra)
            else:
                extra = e.pop("also_in", [])
                by_id[e["id"]] = e
                for source in extra:
                    _cite(e, source)
    return sorted(by_id.values(), key=sort_key)


def _cite(kept: dict, source: dict) -> None:
    """Add a further printing to kept["also_in"], unless it is kept's own source or already cited (re-merges are no-ops)."""
    if source == kept["source"] or source in kept.get("also_in", []):
        return
    kept.setdefault("also_in", []).append(source)


def main() -> None:
    existing = json.loads(REGISTER.read_text()) if REGISTER.exists() else []
    fragments = [json.loads(p.read_text()) for p in sorted(FRAGMENTS.glob("*.json"))] if FRAGMENTS.exists() else []
    out = merge([existing, *fragments], implemented_calendars())
    REGISTER.write_text(json.dumps(out, indent=2, ensure_ascii=False) + "\n")
    print(f"{len(out)} entries; {sum(e['api']['calendar_implemented'] for e in out)} on implemented calendars; "
          f"{sum(e['needs_review'] for e in out)} need review")


if __name__ == "__main__":
    main()
