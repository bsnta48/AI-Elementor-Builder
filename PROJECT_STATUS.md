# Project Status

> Shared handoff file for ANY AI CLI (Claude Code, Codex, Cursor, Gemini, Aider).
> **Read this first.** **Update it before you stop.** This is the single source of truth
> so any CLI can continue where another left off (e.g. when one runs out of credits).

## Current Task
<!-- one line: what is being worked on right now -->
DONE: session-based conversational planning (chat → finalize brief → generate). Plan: ~/.claude/plans/serene-petting-fiddle.md.

## Update (AIEB_VERSION 1.9.2) — conversational sessions
<!-- 1.9.1/1.9.2 = builder.js + builder.css polish/asset cache-bust on the session feature; no structural change beyond items below. -->

- [x] CPT + store `includes/Sessions/Session_Store.php` (private `aieb_session`, per-user, JSON in post_content: messages/brief/template/provider/scope/reference).
- [x] `includes/Rest/Sessions_Controller.php` — GET/POST/DELETE `/sessions(+/{id})`, author-scoped (`owns_check`).
- [x] `includes/Rest/Chat_Controller.php` — `POST /chat` → `{reply, brief, ready}` design-consultant (no template JSON), mock mode. Reuses Design_Spec + Clarify parse pattern.
- [x] Wired in Plugin.php; Menu localizes `chatUrl`/`sessionsUrl` + i18n.
- [x] Frontend (builder.js/php/css): pre-template send → `/chat` (discussion); editable **Design plan** panel (#aieb-brief) filled by AI; **Generate design** button → `/generate` from brief; post-template send still `/refine`. History tab → **Chats** (sessions list: open/delete, search), debounced `persistSession`, lazy session create, auto-title. Old generation-History UI + `/clarify` call removed from JS (controllers still registered).

## DONE earlier this session (see below): conversational builder A+B+C+E, design-quality WS1-5, native Gutenberg + Save-as-Pattern, responsive flex fix.

---
## (history) Design-quality + Gutenberg pass

## Done (design-quality + Gutenberg, AIEB_VERSION 1.7.0)
- [x] WS1 preview fidelity (`assets/js/builder.js`): box-shadow (`boxShadowDecls`), boxed/max-width centering, flex-wrap on rows, icon-box/icon widgets + `iconCircle`. Base CSS for cards/icons.
- [x] WS2 design system: new `includes/Prompts/Design_Spec.php` (`rules()`), appended to Generate + Refine system prompts.
- [x] WS3 auto-references: `Reference_Registry::auto_select(scope,prompt)`; Generate_Controller injects best exemplar(s) when no explicit `reference`. New `scope` REST arg, sent from JS `state.scope`.
- [x] WS4 enrichment: Clarify `enriched_prompt` now bakes palette hex + font pairing + ordered section list (system prompt + mock).
- [x] WS5 Gutenberg: `includes/Converter/Blocks_Converter.php` (Elementor tree → per-section `core/html` blocks + scoped `<style>`, PHP port of preview renderer) + `includes/Rest/Push_Gutenberg_Controller.php` (`POST /push-gutenberg`, writes post_content, edit_mode off). Registered in Plugin.php. "Push to Gutenberg" button + `onPushGutenberg` + localized `pushGutenbergUrl`.
- [x] All `php -l` + `node --check` clean.

## Update (AIEB_VERSION 1.8.0) — native Gutenberg + pattern + responsive fix
- [x] `Blocks_Converter` rewritten to emit NATIVE blocks (core/group, core/columns+column for rows, heading, paragraph, buttons, image, list, quote, spacer, separator) instead of core/html. Styling via block `style` attr + `wp_style_engine_get_styles()` so inline CSS matches core's save() (avoids "invalid block" recovery). Rows → core/columns = native mobile stacking.
- [x] New `includes/Rest/Save_Pattern_Controller.php` (`POST /save-pattern`) → creates a synced `wp_block` pattern from the converted markup. Registered in Plugin.php. "Save as Pattern" button + `onSavePattern` + localized `savePatternUrl`.
- [x] Responsive preview fix: row flex children `flex:1 1 260px` (was `1 1 0`, which squished and never wrapped) + `@media(max-width:600px)` force-stack. Applied in builder.js preview AND Blocks_Converter scoped CSS (note: converter no longer emits scoped CSS — native blocks handle it).

## Gotchas
- Gutenberg push: `wp_update_post` runs kses for users WITHOUT `unfiltered_html` (multisite/restricted) — the `<style>` block + inline styles get stripped there. Admins on single-site keep `unfiltered_html`, so fine. Builder needs `manage_options`, push needs `edit_post`.
- core/html chosen over native blocks deliberately: native blocks must byte-match core's serializer or trigger "block recovery". core/html is robust + renders exactly like the preview.

## Prior task: conversational builder (A+B+C+E) — DONE
Chat-thread UI; `/clarify` + `/refine` controllers; Compose pane = chat thread + collapsible
Options (Provider + Reference image only); reference folded into an auto-injected "Match a
saved design style?" clarify question; scope/reference live in JS `state`.

## In Progress
- [ ] (nothing — feature complete, needs in-WP manual test)

## Next
- [ ] Manual test (Mock mode, WP_DEBUG on): "make me a website" → questions → Build → confirm
  cards have shadows / sections centered / rows wrap / icons render in preview. Then
  "make hero darker" → refine. Then "Push to Gutenberg" → open page in block editor.
- [ ] With a real provider, verify auto-injected exemplars + richer enriched_prompt lift quality.
- [ ] Consider stronger default model than `claude-fable-5` (Settings.php) for design.
- [ ] Tune `isVague()` heuristic (builder.js) if it over/under-triggers.
- [ ] Optional: per-section regenerate (feature D), streaming progress (F), native Gutenberg blocks.

## Notes / Gotchas
- Uncommitted at last check (2026-06-30): ENTIRE conversational-sessions + Gutenberg + design-quality
  work is still untracked/unstaged — nothing committed since `1b122f8`. New files: `includes/Converter/`,
  `includes/Prompts/`, `includes/Sessions/`, `includes/Rest/{Chat,Clarify,Refine,Sessions,Save_Pattern,Push_Gutenberg}_Controller.php`,
  plus modified `Plugin.php`, `Menu.php`, `builder.{js,php,css}`, `Generate_Controller.php`, `Reference_Registry.php`,
  `ai-elementor-builder.php` (v1.9.2). Also dangling: `.DS_Store`, `founders-testimonial.json`. Needs a commit.
- See `CLAUDE.md` for architecture + invariants. Don't duplicate that here — this file is
  task STATE only (what's done / doing / next), not docs.

## Last Updated
2026-06-30 — by claude (auto-stamp)
