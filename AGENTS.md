# AGENTS.md

Context file for AI coding agents (Codex, and any tool that reads `AGENTS.md` —
Cursor, Aider, and others increasingly do).

## Read this first
1. Open **`PROJECT_STATUS.md`** at repo root — it is the live task state (done / in-progress / next).
2. Continue from the "In Progress" / "Next" sections.
3. **Before you stop or run out of credits, UPDATE `PROJECT_STATUS.md`** so the next
   agent (possibly a different CLI) can pick up exactly where you left off.

## Project docs
Architecture, build commands, and invariants live in `CLAUDE.md`. Read it for how the
code works. `PROJECT_STATUS.md` is for *what's happening right now*, not docs.

## Build
- Build zip: `./build.sh` → `ai-elementor-builder.zip`
- WordPress plugin, vanilla PHP + browser JS, no build step for assets.
- WordPress coding standards (`phpcs` against WordPress ruleset).
