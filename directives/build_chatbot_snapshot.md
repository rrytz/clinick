# Build Chatbot Snapshot — Static Export

## Goal
Build the `mediconnect-chatbot-module` Next.js app and emit a **static HTML/JS snapshot**
into `mediconnect-chatbot-module/out/` so it can be embedded/served without a Node runtime
(the PHP side of CLINICK serves it). This is what the old `bun run snapshot:build` script did.

## Inputs
- `mediconnect-chatbot-module/` — the Next.js project (source must be present, see Edge Cases)
- Node.js >= 18 (installed at `C:\Program Files\nodejs\`) — provides `npm` / `npx` / `node`
- `mediconnect-chatbot-module/node_modules/` (Next.js 14.2.x is already installed locally)

## Tools / Scripts to Use
- `execution/build_chatbot_snapshot.py` — deterministic build runner (Layer 3).
  It prefers `bun` if present, otherwise falls back to `npx next build` (Next static export).
- `mediconnect-chatbot-module/next.config.js` — MUST set `output: 'export'` for static export.

## Outputs
- `mediconnect-chatbot-module/out/` — the static snapshot (deliverable, served by PHP).
- `.tmp/snapshot_build.log` — build log (intermediate).

## Steps
1. Run `python execution/build_chatbot_snapshot.py` from the repo root (`C:\xampp\htdocs\CLINICK`).
2. The script checks for `bun`; if absent it uses `npx next build` with `output:'export'`.
3. Next.js writes the static site to `out/`.
4. Script prints the output path and a success/failure summary.

## Edge Cases
- **`bun` not installed**: script auto-falls-back to `node`/`npx` — no manual change needed.
- **Missing source files** (no `package.json`, `app/`, `components/`, `lib/`, `api/`):
  the build cannot run. The source was wiped (only `.next/` + empty dirs remained).
  Recreate the module source first (see `mediconnect-chatbot-module/app/...` referenced
  files), then rebuild. Script reports this clearly instead of failing obscurely.
- **Stale `.next/` cache**: script runs a clean build; remove `.next/` if build errors persist.
- **`output:'export'` missing**: API routes (`app/api/chatbot`) are incompatible with static
  export. Either set `output:'export'` and convert the runtime endpoint to a client-side call,
  or keep the PHP `chatbot-api.php` as the endpoint and have the widget POST to it.

## Notes
- The PHP port (`clinick-chatbot-php/`) is the canonical runtime endpoint for CLINICK.
  The Next.js snapshot is the embeddable widget UI; it should POST to the PHP endpoint.
- Keep `.tmp/` out of version control (already in `.gitignore`).
