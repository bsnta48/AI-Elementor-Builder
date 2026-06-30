# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Task handoff (READ FIRST)

Before starting work, read **`PROJECT_STATUS.md`** at the repo root — it holds the live task
state (done / in-progress / next) shared across every AI CLI (Claude Code, Codex, Cursor,
Gemini, Aider). **Update `PROJECT_STATUS.md` before you stop** so another CLI can continue
where you left off if this session ends or runs out of credits.

## What this is

WordPress plugin: AI-assisted Elementor page generation. User writes a natural-language prompt (optionally with a reference design or screenshot), an AI provider returns Elementor template JSON, and the plugin validates it and pushes it into a page or the Elementor Library. Hard dependency on the Elementor plugin — self-deactivates with an admin notice if Elementor is absent (`Plugin::init()`).

## Commands

- Build distributable zip: `./build.sh` → produces `ai-elementor-builder.zip` (excludes `.git`, `.DS_Store`, `node_modules`).
- No test suite, linter config, or `composer.json`/`package.json` — vanilla PHP + browser JS, no build step for assets.
- WordPress coding standards apply (code carries `phpcs:ignore` annotations); run `phpcs` against the WordPress ruleset if available.

## Architecture

Single namespace `AI_Elementor_Builder`. Custom PSR-4 autoloader in `ai-elementor-builder.php` maps `AI_Elementor_Builder\Sub\Name` → `includes/Sub/Name.php`. No Composer. Entry point boots `Plugin::instance()` (singleton) on `plugins_loaded`.

`Plugin::init()` is the composition root — it instantiates Settings, the Provider_Factory, Validator, Reference_Registry, and registers all four REST controllers + the admin Menu. Wiring is constructor injection; there is no DI container.

### Generation flow (the core path)

1. Builder admin page (`assets/js/builder.js`) POSTs to `POST /ai-elementor/v1/generate` (`Generate_Controller`).
2. Controller builds the system prompt (hardcoded in `Generate_Controller::system_prompt()` — it defines the exact Elementor JSON contract the model must emit), optionally appends a reference exemplar and/or a vision image.
3. `Provider_Factory::make()` returns a configured `AI_Provider` subclass; `provider->generate()` calls the vendor HTTP API; `provider->extract_text()` pulls the text out of the vendor-specific response.
4. `Elementor_Validator::validate()` strips code fences, JSON-decodes, and **normalizes** every element recursively (fills missing `id`/`elType`/`settings`/`elements`, generates 8-char hex IDs). Returns `{valid, data, error}`.
5. `History::add()` records the generation in per-user meta (`ai_elementor_history`, capped at 10, newest first).
6. Result returned to JS for preview. User then pushes via `POST /ai-elementor/v1/push` (writes `_elementor_data` to a page) or `POST /ai-elementor/v1/push-template` (creates an `elementor_library` Saved Template).

### Key invariants

- **Elementor data shape**: Elementor stores the *elements tree only* (the `content` array), NOT the document envelope, as a JSON string in `_elementor_data`. Always `wp_slash( wp_json_encode( $content ) )` — `update_post_meta` strips slashes. Push controllers also flip `_elementor_edit_mode` to `builder` and clear cached CSS (`_elementor_css`, `_elementor_css_<id>` option, `_elementor_inline_css`).
- **Re-validation on push**: push controllers re-run the validator on incoming JSON — never trust the client payload.
- Validator targets Elementor schema `version 0.4`.

### Providers (`includes/Providers/`)

All extend abstract `AI_Provider`, which normalizes every call to `{success, json, error}` via the shared `post()` helper (90s timeout). Each concrete provider implements `generate()` + `extract_text()`. Registered in `Provider_Factory::MAP` (provider key → class + settings field for the API key):
- `anthropic` (Provider_Claude), `openai`, `gemini`, `openrouter`, `nvidia` — all API-key based.
- `ollama` — **keyless**, local LLM, configured by URL + model instead of an API key (special-cased in the factory).

To add a provider: create the subclass, add it to `Provider_Factory::MAP`, add the secret field to `Settings::$secret_fields` + `Settings::providers()` (label + model list), and to the field maps in `Settings::register_settings()`/`render_key_field()`.

When working with the Anthropic/Claude provider or model IDs, consult the `claude-api` skill — model IDs in `Settings::providers()` (e.g. `claude-opus-4-8`, `claude-fable-5`) must stay current.

### Settings & secrets (`includes/Settings/`)

WordPress Settings API, single option `aieb_settings`. API keys stored via `Crypto` (XOR against `wp_salt('secure_auth')` + base64, prefix `aieb$1$`) — **obfuscation, not encryption**; do not treat it as protection against DB+filesystem access. Keys display masked (`••••••••cd34`); a submission containing the `•` bullet means "unchanged" and retains the stored value. `Crypto::decrypt()` returns unprefixed values as-is (legacy plaintext tolerance).

**Mock mode** (`is_mock_mode()`): gated behind `WP_DEBUG`. When on, `Generate_Controller` returns a canned template (`mock_template()`) without calling any provider — use this to test the generate→preview→push flow with no API key or cost.

### REST surface (`includes/Rest/`, namespace `ai-elementor/v1`)

- `POST /generate` — needs `edit_posts`.
- `POST /push` — needs `edit_post` on the target page.
- `POST /push-template` — needs `publish_posts`.
- `/test-key` + `/ollama-test` (`Test_Key_Controller`) — settings-page connection probes; `make_with_key()` tests an unsaved key.

Auth is `wp_rest` nonce + capability `permission_callback` per route.

### References (`includes/References/`)

`Reference_Registry` loads curated Elementor JSON exemplars from `includes/References/library/*.json` (each: `name`, `description`, `tags`, `content`). The chosen reference's `content` is injected into the generation prompt as a few-shot example. `listing()` returns metadata only (for the UI); `get()` includes the heavy `content`. Add a design by dropping a new valid JSON file in `library/` — the id is the filename (sanitized).

### Admin UI (`includes/Admin/`)

`Menu` registers the top-level menu + Builder/Settings subpages (`manage_options`), enqueues `assets/js/builder.js` (Builder) or `settings.js` (Settings), and injects runtime config + i18n strings via `wp_localize_script` (`AIEB` / `AIEB_SETTINGS` globals — REST URLs, nonce, history, references). Views are plain PHP in `includes/Admin/views/`.

## Version note

`AIEB_VERSION` constant (`ai-elementor-builder.php`, currently `1.1.0`) is the asset cache-bust version and differs from the plugin header `Version` (`1.0.0`). Bump `AIEB_VERSION` when changing JS/CSS so browsers reload assets.
