# Project Status

> Shared handoff file for ANY AI CLI (Claude Code, Codex, Cursor, Gemini, Aider).
> **Read this first.** **Update it before you stop.** This is the single source of truth
> so any CLI can continue where another left off (e.g. when one runs out of credits).

## Current Task
<!-- one line: what is being worked on right now -->
DONE (1.10.0): P0 real-images + P1 multi-page site generation. Full plan: MULTIPAGE_PLAN.md.

## Update (AIEB_VERSION 1.10.0) — real images (P0) + multi-page site (P1)

Goal shift: plugin was single-page only. Added full-website capability + fixed broken AI images.

**Refactor (shared services, no behavior change):**
- [x] `includes/Services/Page_Generator.php` — owns system_prompt + mock_template + exemplar
  injection + provider call + validate. `Generate_Controller` + `Build_Site_Controller` both delegate.
- [x] `includes/Services/Elementor_Page_Writer.php` — owns `_elementor_data` write invariants
  (slash, edit_mode=builder, version stamp, CSS flush). `Push_Controller` + build-site delegate.

**P0 images:**
- [x] system_prompt (in Page_Generator) now tells model to leave image `url` EMPTY + supply
  descriptive `alt` keywords (widget `image` + container `background_image`).
- [x] `includes/Media/Stock_Image_Provider.php` — Unsplash (preferred) + Pexels search, transient-cached.
- [x] `includes/Media/Image_Resolver.php` — walks tree, `resolve_final()` sideloads photos into
  Media Library (rewrites url+id), `resolve_preview()` remote-only; inline-SVG placeholder fallback
  when no key/no result (never broken images). Dedupes per run.
- [x] Wired into `Generate_Controller` (resolve_final, post 0) — preview + push show real images.
- [x] Settings: `unsplash_api_key` + `pexels_api_key` (secret_fields + new "Stock Images" section).

**P1 multi-page:**
- [x] `includes/Prompts/Site_Plan_Spec.php` — sitemap JSON contract (pages[] + menu[]).
- [x] `includes/Rest/Site_Plan_Controller.php` — `POST /plan-site` → normalized {site_title,pages,menu}.
  Mock-mode canned sitemap. needs `publish_pages`.
- [x] `includes/Rest/Build_Site_Controller.php` — `POST /build-site` two modes:
  `mode=page` (generate 1 page → wp_insert_post → resolve_final images attached to page → write),
  `mode=finalize` (build/assign nav menu to primary theme location + optional set front page).
  Client drives the per-page loop (timeout-safe).
- [x] Wired both in `Plugin.php`. `Menu` localizes `planSiteUrl`/`buildSiteUrl` + ~20 i18n strings.
- [x] Frontend: builder.php mode toggle (Single page / Full website) + `#aieb-site` panel
  (prompt → Plan site → editable page cards [title/brief/home radio/add/remove] → Build site →
  progress + per-page result links). JS `initSiteBuilder()` module (self-contained, appended after
  `toast()`, reuses apiPost/toast/t/selectedProvider). CSS appended to builder.css.
- [x] All `php -l` + `node --check` clean. AIEB_VERSION → 1.10.0.

**Decisions locked (this session):** Elementor Free now (Pro Theme Builder later), stock API + sideload,
scope = P0+P1, Elementor primary (Gutenberg stays single-page export).

**NOT yet done / next:**
- [ ] Manual test in WP (Mock mode WP_DEBUG on): toggle Full website → "yoga studio site" → Plan →
  edit pages → Build → confirm pages created, nav menu assigned, home set. Then real provider +
  Unsplash key: confirm real images sideload.
- [ ] Gutenberg multi-page (deferred). Header/footer global parts = P2. Global theme tokens = P3.
  Widget coverage (forms/slider) = P4.
- [ ] Orphan media: single-page Generate sideloads to post 0 (unattached); if user never pushes,
  media lingers. Consider cleanup or defer sideload to push. (build-site attaches to its page — fine.)
- [ ] Everything still UNCOMMITTED (see note below) — now also new dirs `includes/Services/`,
  `includes/Media/`, files `Site_Plan_Spec.php`, `Site_Plan_Controller.php`, `Build_Site_Controller.php`,
  `MULTIPAGE_PLAN.md`. Needs a commit.

## (history) session-based conversational planning. Plan: ~/.claude/plans/serene-petting-fiddle.md.

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
