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
