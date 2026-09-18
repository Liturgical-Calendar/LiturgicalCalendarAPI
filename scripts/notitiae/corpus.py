"""Access to the shared Notitiae corpus cache (see docs/superpowers/specs/2026-09-16-notitiae-decree-survey-design.md §1)."""
import json
import os
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]


def cache_root() -> Path:
    env = os.environ.get("NOTITIAE_CACHE")
    root = Path(env).expanduser() if env else (Path.home() / "development" / "sources" / "notitiae")
    if not (root / "manifest.json").exists():
        raise SystemExit(f"Notitiae cache not found at {root}; set NOTITIAE_CACHE or run tools/fetch.py there")
    return root


def load_manifest() -> list[dict]:
    return json.loads((cache_root() / "manifest.json").read_text())


def pages(rec: dict, layout: bool = False) -> list[str]:
    folder = "txt-layout" if layout else "txt"
    path = cache_root() / folder / str(rec["year"]) / (rec["file"][:-4] + ".txt")
    return path.read_text(errors="replace").split("\f")
