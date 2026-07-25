#!/usr/bin/env python3
"""
execution/build_chatbot_snapshot.py

Layer 3 (Execution) — deterministic build runner for the CLINICK chatbot
Next.js static-export snapshot.

What it does:
  - Locates the Next.js module at mediconnect-chatbot-module/
  - Prefers `bun run snapshot:build` if bun is available, otherwise falls
    back to `npx next build` (static export via output:'export').
  - Emits the static snapshot to mediconnect-chatbot-module/out/ and a log
    to .tmp/snapshot_build.log.

Self-annealing: if the module source is missing (no package.json / app/),
it reports the exact problem instead of producing a cryptic Next.js error.

Usage:
  python execution/build_chatbot_snapshot.py
Run from the repo root (C:\\xampp\\htdocs\\CLINICK).
"""

import os
import shutil
import subprocess
import sys

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MODULE_DIR = os.path.join(REPO_ROOT, "mediconnect-chatbot-module")
TMP_DIR = os.path.join(REPO_ROOT, ".tmp")
LOG_PATH = os.path.join(TMP_DIR, "snapshot_build.log")

# Source files/dirs that MUST exist for a static export to be possible.
REQUIRED = [
    "package.json",
    "next.config.js",
    "app",
    "app/layout.tsx",
    "app/page.tsx",
    "components",
    "lib",
]


def _safe(text: str) -> str:
    # Console may be cp1252; replace un-encodable chars for terminal output only.
    try:
        text.encode(sys.stdout.encoding or "utf-8")
        return text
    except Exception:
        return text.encode("utf-8", "replace").decode("utf-8")


def log(msg: str) -> None:
    line = f"[snapshot] {msg}"
    print(_safe(line))
    with open(LOG_PATH, "a", encoding="utf-8") as f:
        f.write(line + "\n")


def ensure_tmp() -> None:
    os.makedirs(TMP_DIR, exist_ok=True)
    # Fresh log each run.
    with open(LOG_PATH, "w", encoding="utf-8") as f:
        f.write("")


def check_source() -> list[str]:
    missing = []
    for rel in REQUIRED:
        path = os.path.join(MODULE_DIR, rel)
        if not os.path.exists(path):
            missing.append(rel)
    return missing


def find_runner() -> tuple[str, list[str], str]:
    """Return (runner_name, base_cmd, label). Prefers bun, falls back to node."""
    if shutil.which("bun"):
        return "bun", ["bun", "run", "snapshot:build"], "bun run snapshot:build"

    # Prefer invoking the local Next.js binary with node directly.
    # (npx is a .ps1 wrapper on Windows that subprocess can't launch directly.)
    local_next = os.path.join(MODULE_DIR, "node_modules", "next", "dist", "bin", "next")
    node = shutil.which("node")
    if node and os.path.isfile(local_next):
        return "node", [node, local_next, "build"], f"node next build ({local_next})"
    if node:
        return "node", [node, "node_modules/next/dist/bin/next", "build"], "node next build"

    if shutil.which("npx"):
        return "npx", ["npx", "--no-install", "next", "build"], "npx next build"
    return "none", [], "NO RUNNER"


def run_build(base_cmd: list[str]) -> int:
    log(f"Running build in {MODULE_DIR}")
    try:
        proc = subprocess.run(
            base_cmd,
            cwd=MODULE_DIR,
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
        )
    except FileNotFoundError as e:
        log(f"ERROR: could not launch build command: {e}")
        return 127

    log("--- stdout ---")
    for l in (proc.stdout or "").splitlines():
        log(l)
    log("--- stderr ---")
    for l in (proc.stderr or "").splitlines():
        log(l)
    log(f"exit code: {proc.returncode}")
    return proc.returncode


def main() -> int:
    ensure_tmp()
    log("Starting chatbot snapshot build")

    if not os.path.isdir(MODULE_DIR):
        log(f"ERROR: module directory not found: {MODULE_DIR}")
        return 2

    missing = check_source()
    if missing:
        log("ERROR: module source is incomplete. Missing:")
        for m in missing:
            log(f"   - {m}")
        log("Recreate the missing source files, then re-run this script.")
        return 3

    runner, base_cmd, label = find_runner()
    if runner == "none":
        log("ERROR: no JS runner found (need bun, npx, or node). Install Node.js.")
        return 4

    log(f"Runner: {label}")
    rc = run_build(base_cmd)

    out_dir = os.path.join(MODULE_DIR, "out")
    if rc == 0 and os.path.isdir(out_dir):
        log(f"SUCCESS: static snapshot written to {out_dir}")
        return 0
    elif rc == 0:
        log(f"WARNING: build exited 0 but no out/ dir. Check next.config.js has output:'export'.")
        return 5
    else:
        log(f"FAILURE: build exited with code {rc}. See log above for details.")
        return rc


if __name__ == "__main__":
    sys.exit(main())
