# Plan: Real Images (P0) + Multi-Page Site Generation (P1)

> Scope locked: **P0 + P1**, **Elementor primary**, **Elementor Free now** (Pro later),
> **Stock API + sideload** for images. Gutenberg path stays single-page export for now.

---

## P0 — Real images (stock API + sideload)

**Problem:** AI emits hallucinated `image.url` → broken images. `Blocks_Converter::image_src()`
and push controllers pass URLs through as-is.

**Design:** AI emits image *intent* (keyword/alt), not URL. A new resolver searches a stock
API, sideloads the best match into the Media Library, rewrites `image.url` + `image.id`
before push.

### Tasks
1. **Prompt change** — `Generate_Controller::system_prompt()` + `Prompts/Design_Spec.php`:
   instruct model to set every image as `{"url":"", "alt":"<descriptive keywords>"}`
   (and a section-level `image_query` where useful). Never invent real URLs.
2. **New `includes/Media/Stock_Image_Provider.php`** — interface + Unsplash and Pexels
   implementations. `search(string $query, array $opts): ?array` → `{url, width, height, credit}`.
   Use `wp_remote_get` (mirror `AI_Provider::post()` style, shorter timeout).
3. **New `includes/Media/Image_Resolver.php`** — walks the validated element tree, finds
   `image`/background-image settings with empty url + non-empty alt/query, calls stock
   provider, sideloads via `media_sideload_image( $url, 0, $alt, 'id' )` (requires
   `wp-admin/includes/media.php` + `file.php` + `image.php`), rewrites `url`+`id`. Cache by
   query (transient) to avoid dup downloads. Dedup within one generation.
4. **Wire into push** — run resolver inside `Push_Controller` and `Push_Template_Controller`
   (and Gutenberg push) right after re-validation, before `update_post_meta`. Sideload needs a
   real post context → resolve at push time, not generate time (keeps preview fast; preview can
   show stock remote URL without sideload).
   - Optionally resolve remote (hotlink) URLs at **generate** time for preview, then sideload at
     push. Two-phase: `resolve_preview()` (remote url only) vs `resolve_final()` (sideload).
5. **Settings** — add `unsplash_access_key` + `pexels_api_key` to `Settings::$secret_fields`,
   `Settings::providers()`/field maps, `render_key_field()`, and `register_settings()`. Same
   `Crypto` masking pattern as provider keys. Add a `/test-key` style probe (optional).
6. **Graceful fallback** — no key or no result → leave a deterministic placeholder
   (local SVG data-URI or picsum) so page never ships broken `<img>`.

### Acceptance
- Generate "SaaS landing page" with Unsplash key set → push → all images real, in Media Library,
  alt text present. No key → tasteful placeholders, zero broken images.

---

## P1 — Multi-page site generation

**Problem:** Plugin builds ONE page tree. "Build me a website" must produce multiple linked
pages + a nav menu.

**Design:** Two-stage generation. (1) AI returns a **sitemap** (pages + per-page section brief).
(2) Loop each page through existing single-page generate. Then create WP pages, push
`_elementor_data`, build a nav menu, set front page.

### New REST surface
- `POST /ai-elementor/v1/plan-site` → `Site_Plan_Controller`
  - In: `prompt`, `provider`, `model`, optional `scope`/reference, optional `page_count`.
  - Out: `{ site_title, pages:[{slug,title,nav_label,role:home|standard,brief,scope}], menu:[slug...] }`.
  - New prompt builder `Prompts/Site_Plan_Spec.php` (constrained JSON: 3–6 pages typical —
    Home, About, Services/Features, Pricing, Contact). Mock-mode canned sitemap.
- `POST /ai-elementor/v1/build-site` → `Build_Site_Controller`
  - In: the approved sitemap + provider/model.
  - For each page: reuse `Generate_Controller` generation logic (extract the
    provider-call + validate into a shared `Services/Page_Generator` so both controllers use it —
    avoids duplicating system_prompt logic), run **Image_Resolver (final/sideload)**.
  - `wp_insert_post( post_type=page, post_status=publish )` per page; write `_elementor_data`
    (`wp_slash(wp_json_encode($content))`), set `_elementor_edit_mode=builder`, clear CSS caches
    (mirror `Push_Controller` invariants — extract that into a `Services/Elementor_Page_Writer`).
  - Build nav menu: `wp_create_nav_menu()` (or reuse by name) + `wp_update_nav_menu_item()` per
    page; assign to theme's primary menu location via `get_registered_nav_menus()` +
    `set_theme_mod('nav_menu_locations', ...)`.
  - Set front page: `update_option('show_on_front','page')` +
    `update_option('page_on_front', $home_id)`.
  - Return `{ pages:[{id,title,edit_url,view_url}], menu_id, home_id }`.
  - **Long-run risk:** N pages × provider latency may exceed PHP/HTTP timeout. Mitigate: build
    one page per request, client drives the loop (progress UI), OR a background queue
    (`wp_schedule_single_event`) with a status endpoint. **Recommend client-driven loop first**
    (simplest, gives live progress) — `build-site` accepts a single `page` index.

### Refactor (shared services — do FIRST, low risk)
- `includes/Services/Page_Generator.php` — owns system_prompt + provider call + validate +
  exemplar injection. `Generate_Controller` and `Build_Site_Controller` both delegate.
- `includes/Services/Elementor_Page_Writer.php` — owns the `_elementor_data` write + edit_mode +
  CSS-cache-clear invariants. `Push_Controller` + build-site both delegate.

### Frontend (`assets/js/builder.js` + `builder.php` + `builder.css`)
- New mode toggle: **Single page** vs **Full site**.
- Full-site flow: prompt → `/plan-site` → render editable sitemap (page list, titles, reorder,
  add/remove, per-page brief) → **Build site** → loop `/build-site` per page with progress bar →
  results panel (links to each page + "View site").
- Reuse existing chat/brief panel to refine the sitemap before build.
- Localize `planSiteUrl` + `buildSiteUrl` in `Menu` (mirror existing `chatUrl` pattern).

### Wiring
- Register `Site_Plan_Controller` + `Build_Site_Controller` in `Plugin::init()` (after line 100
  block). Inject factory/validator/settings/references + new services.

### Acceptance
- "Build a website for a yoga studio" → sitemap of ~5 pages → approve → build → 5 published
  Elementor pages, primary nav menu linking them, Home set as front page, real images on each.

---

## Build order (smallest shippable first)
1. **Refactor** `Page_Generator` + `Elementor_Page_Writer` (no behavior change; `php -l` clean).
2. **P0 images** — prompt + Stock_Image_Provider + Image_Resolver + settings + push wiring + fallback.
3. **P1 site plan** — `Site_Plan_Spec` + `Site_Plan_Controller` + sitemap UI (mock mode testable).
4. **P1 build** — `Build_Site_Controller` (client-driven per-page loop) + menu + front page + UI.
5. Bump `AIEB_VERSION`; update `CLAUDE.md` (new services/controllers) + `PROJECT_STATUS.md`.

## Open risks / notes
- `media_sideload_image` + nav menu funcs need admin includes loaded in REST context —
  `require_once ABSPATH . 'wp-admin/includes/{media,file,image,nav-menu}.php'`.
- Stock API rate limits — cache by query (transient), dedup per build.
- Front-page override is destructive to site config — confirm in UI before `build-site`,
  make it opt-in ("set as homepage?").
- Header/footer (P2) deferred: Free path has no global parts — each page carries its own
  hero/footer sections for now. Pro Theme Builder = later.
- Gutenberg multi-page: out of scope this pass (Elementor primary).
