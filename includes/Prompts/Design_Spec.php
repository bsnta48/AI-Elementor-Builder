<?php
/**
 * Shared design-system language injected into generation/refine system prompts.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's "design taste" — a reusable block of art-direction rules that turns
 * the bare Elementor JSON contract into instructions for producing polished,
 * professional layouts. Consumed by Generate_Controller and Refine_Controller so
 * both speak the same design language. Kept deliberately preview-friendly: it only
 * leans on settings the in-app preview renderer can show (flex rows, gradients,
 * box-shadow, border-radius, boxed max-width), avoiding CSS-grid containers.
 */
class Design_Spec {

	/**
	 * The design-system rules block.
	 *
	 * @return string
	 */
	public static function rules(): string {
		return <<<'PROMPT'
DESIGN SYSTEM — produce a polished, modern, professional layout. Treat this as art direction, not optional.

COLOR
- Choose ONE coherent palette and apply it consistently: a primary brand color, one accent, plus neutrals (a near-white page background, a white surface for cards, a dark ink for text). Limit to ~4–5 colors total.
- Ensure strong text/background contrast (dark text on light surfaces, white text on dark/gradient).
- Vary section backgrounds for rhythm: alternate white, a faint tint of the primary, and an occasional dark or gradient section (e.g. the hero or closing CTA). Never ship a page where every section is the same flat white.

SPACING (8px grid)
- Use multiples of 8: 8, 16, 24, 32, 48, 64, 96, 120.
- Section vertical padding: 80–120px desktop (use "padding" top/bottom). Inner horizontal padding: 24px.
- Gaps between cards/columns: 24–32px. Space between a heading and its body: 16–24px.

TYPOGRAPHY
- Type scale (px): body 16–18, small 14, h3 ~24, h2 ~32–40, h1/hero 48–64.
- Headings: font-weight 700, line-height ~1.15. Body: weight 400, line-height ~1.6, and slightly muted color.
- Add an eyebrow/kicker (small 13–14px uppercase label with letter-spacing) above major section headings.

LAYOUT & STRUCTURE
- Every section is an OUTER container (full width; vertical padding; optional background color/gradient) that holds ONE inner container with "content_width":"boxed" (this caps width ~1140px and centers it; set horizontal padding on it). Put the section's real content inside the inner container.
- Multi-column rows use a container with "flex_direction":"row" and a "gap"; columns/cards are child containers. They wrap automatically on small screens — also add "flex_direction_mobile":"column".
- CARDS: a child container with "background_background":"classic","background_color":"#ffffff", a "border_radius" of 12–16px, generous "padding" (24–32px), and a soft shadow (see below). Cards are how features, pricing tiers, testimonials, and stats should look — not bare text stacks.

DEPTH (box-shadow) — use Elementor's shadow keys so cards/buttons feel elevated:
- "box_shadow_box_shadow_type":"yes","box_shadow_box_shadow":{"horizontal":0,"vertical":10,"blur":30,"spread":-8,"color":"rgba(17,24,39,0.12)"}
- Keep shadows soft and subtle; one consistent elevation for all cards.

BUTTONS
- Primary: solid primary background, white text, "border_radius" 8–10px, "text_padding" ~{"top":"14","right":"32","bottom":"14","left":"32"}, weight 600. Pair with a secondary (outline/ghost) button in the hero.

ICONS — for feature/value/stat items, use the "icon-box" widget (an icon + title + description) rather than plain text, so cards read as designed. Give it "primary_color" from the palette.

SECTION VOCABULARY (compose a full page from several of these, each its own top-level section):
- Hero: eyebrow + large headline + supporting subline + primary & secondary CTA + a small trust/social-proof row. Often a tinted or gradient background.
- Features: centered heading + a row of 3 cards, each an icon-box on a white card with shadow.
- Alternating media/text rows; a stats band (3–4 big numbers); testimonials (quote cards with name/role); pricing (3 tiers, middle highlighted/elevated); FAQ; a bold closing CTA band; a simple footer.

Match the spacing rhythm, type scale, shadow, and palette across EVERY section so the page looks like one cohesive design.
PROMPT;
	}
}
