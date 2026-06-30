<?php
/**
 * Elementor element tree → native Gutenberg block markup.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Converter;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a normalized Elementor `content` tree into native WordPress block
 * markup (core/group, core/columns, core/heading, core/paragraph, core/buttons,
 * core/image, core/list, core/quote, core/spacer, core/separator).
 *
 * Styling rides on each block's `style` attribute. To keep the saved HTML byte-
 * compatible with what core's block `save()` produces — so the editor does NOT
 * flag "this block contains invalid content" — the inline CSS is generated with
 * the SAME core style engine the blocks use (`wp_style_engine_get_styles`), and
 * the support classes core adds for custom colors (`has-background`,
 * `has-text-color`) / alignment (`has-text-align-*`) are replicated.
 */
class Blocks_Converter {

	/**
	 * Convert a content tree to native block markup.
	 *
	 * @param array $content Normalized Elementor elements (the `content` array).
	 * @return string Block markup for post_content.
	 */
	public function convert( array $content ): string {
		$blocks = array();
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$markup = $this->render_node( $element );
			if ( '' !== $markup ) {
				$blocks[] = $markup;
			}
		}
		return implode( "\n\n", $blocks );
	}

	/* ---------------------------------------------------------------------
	 * Node dispatch
	 * ------------------------------------------------------------------- */

	/**
	 * Render any element node to block markup.
	 *
	 * @param array $el Element.
	 * @return string
	 */
	private function render_node( array $el ): string {
		$type = isset( $el['elType'] ) ? $el['elType'] : 'container';
		if ( 'container' === $type ) {
			return $this->render_container( $el );
		}
		if ( 'widget' === $type ) {
			return $this->render_widget( $el );
		}
		return '';
	}

	/**
	 * Render children of a node to concatenated block markup.
	 *
	 * @param array $el Parent element.
	 * @return string
	 */
	private function render_children( array $el ): string {
		$out = '';
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			foreach ( $el['elements'] as $child ) {
				if ( is_array( $child ) ) {
					$out .= $this->render_node( $child );
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Containers → core/columns (rows) or core/group
	 * ------------------------------------------------------------------- */

	/**
	 * Render a container. A row (flex_direction:row) becomes core/columns so it
	 * stacks responsively on mobile for free; everything else is a core/group.
	 *
	 * @param array $el Container element.
	 * @return string
	 */
	private function render_container( array $el ): string {
		$s = isset( $el['settings'] ) ? (array) $el['settings'] : array();

		if ( isset( $s['flex_direction'] ) && 'row' === $s['flex_direction'] ) {
			return $this->render_columns( $el, $s );
		}
		return $this->render_group( $el, $s );
	}

	/**
	 * core/columns with one core/column per child.
	 *
	 * @param array $el Container.
	 * @param array $s  Settings.
	 * @return string
	 */
	private function render_columns( array $el, array $s ): string {
		$cols = '';
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			foreach ( $el['elements'] as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}
				// A child container becomes the column itself (carrying its styling,
				// e.g. a card); a bare widget is wrapped in a plain column.
				if ( isset( $child['elType'] ) && 'container' === $child['elType'] ) {
					$inner = $this->render_children( $child );
					$cols .= $this->column_block( (array) ( isset( $child['settings'] ) ? $child['settings'] : array() ), $inner );
				} else {
					$cols .= $this->column_block( array(), $this->render_node( $child ) );
				}
			}
		}

		list( $attrs, $class, $style ) = $this->block_style( $s, array( 'gap' => true ) );
		$attr_json = $this->attr_json( $attrs );
		$class     = trim( 'wp-block-columns' . ( $class ? ' ' . $class : '' ) );

		return "<!-- wp:columns{$attr_json} -->\n"
			. '<div class="' . esc_attr( $class ) . '"' . $style . '>' . $cols . '</div>'
			. "\n<!-- /wp:columns -->";
	}

	/**
	 * A single core/column.
	 *
	 * @param array  $s     Column/child settings (styling).
	 * @param string $inner Inner block markup.
	 * @return string
	 */
	private function column_block( array $s, string $inner ): string {
		list( $attrs, $class, $style ) = $this->block_style( $s );
		$attr_json = $this->attr_json( $attrs );
		$class     = trim( 'wp-block-column' . ( $class ? ' ' . $class : '' ) );

		return "<!-- wp:column{$attr_json} -->\n"
			. '<div class="' . esc_attr( $class ) . '"' . $style . '>' . $inner . '</div>'
			. "\n<!-- /wp:column -->";
	}

	/**
	 * core/group. Uses a constrained layout (centered max-width) when the
	 * container is boxed / has a max width, otherwise the default flow layout.
	 *
	 * @param array $el Container.
	 * @param array $s  Settings.
	 * @return string
	 */
	private function render_group( array $el, array $s ): string {
		$inner = $this->render_children( $el );

		list( $attrs, $class, $style ) = $this->block_style( $s );

		// Layout: constrained centers content at a max width; default is plain flow.
		$max_w = $this->size_value( isset( $s['max_width'] ) ? $s['max_width'] : null );
		if ( '' === $max_w && isset( $s['content_width'] ) && 'boxed' === $s['content_width'] ) {
			$max_w = '1140px';
		}
		if ( '' !== $max_w ) {
			$attrs['layout'] = array(
				'type'        => 'constrained',
				'contentSize' => $max_w,
			);
		} else {
			$attrs['layout'] = array( 'type' => 'constrained' );
		}

		$attr_json = $this->attr_json( $attrs );
		$class     = trim( 'wp-block-group' . ( $class ? ' ' . $class : '' ) );

		return "<!-- wp:group{$attr_json} -->\n"
			. '<div class="' . esc_attr( $class ) . '"' . $style . '>' . $inner . '</div>'
			. "\n<!-- /wp:group -->";
	}

	/* ---------------------------------------------------------------------
	 * Widgets
	 * ------------------------------------------------------------------- */

	/**
	 * Render a widget node.
	 *
	 * @param array $el Widget.
	 * @return string
	 */
	private function render_widget( array $el ): string {
		$s    = isset( $el['settings'] ) ? (array) $el['settings'] : array();
		$type = isset( $el['widgetType'] ) ? $el['widgetType'] : '';

		switch ( $type ) {
			case 'heading':
				$tag   = $this->heading_tag( isset( $s['header_size'] ) ? $s['header_size'] : ( isset( $s['size'] ) ? $s['size'] : '' ) );
				$level = (int) substr( $tag, 1 );
				$txt   = isset( $s['title'] ) ? $s['title'] : ( isset( $s['text'] ) ? $s['text'] : '' );
				return $this->heading_block( $level, $txt, $s, 'title_color' );

			case 'text-editor':
				$content = isset( $s['editor'] ) ? $s['editor'] : ( isset( $s['content'] ) ? $s['content'] : ( isset( $s['text'] ) ? $s['text'] : '' ) );
				return $this->paragraph_block( $content, $s, 'text_color' );

			case 'button':
				return $this->buttons_block( $s );

			case 'image':
				$src = $this->image_src( $s );
				if ( '' === $src ) {
					return '';
				}
				$alt = isset( $s['alt'] ) ? $s['alt'] : '';
				return "<!-- wp:image -->\n"
					. '<figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"/></figure>'
					. "\n<!-- /wp:image -->";

			case 'icon-list':
				$items = ( isset( $s['icon_list'] ) && is_array( $s['icon_list'] ) ) ? $s['icon_list'] : array();
				if ( empty( $items ) ) {
					return '';
				}
				$lis = '';
				foreach ( $items as $item ) {
					$txt = is_array( $item ) ? ( isset( $item['text'] ) ? $item['text'] : ( isset( $item['title'] ) ? $item['title'] : '' ) ) : (string) $item;
					$lis .= "<!-- wp:list-item -->\n<li>" . esc_html( $txt ) . "</li>\n<!-- /wp:list-item -->\n";
				}
				return "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n" . $lis . "</ul>\n<!-- /wp:list -->";

			case 'icon-box':
				// No core icon block; render as a heading + paragraph group.
				$title = isset( $s['title_text'] ) ? $s['title_text'] : ( isset( $s['title'] ) ? $s['title'] : ( isset( $s['text'] ) ? $s['text'] : '' ) );
				$desc  = isset( $s['description_text'] ) ? $s['description_text'] : ( isset( $s['description'] ) ? $s['description'] : '' );
				$inner = '';
				if ( '' !== $title ) {
					$inner .= $this->heading_block( 3, $title, array( 'title_color' => isset( $s['title_color'] ) ? $s['title_color'] : '' ), 'title_color' );
				}
				if ( '' !== $desc ) {
					$inner .= $this->paragraph_block( $desc, array( 'text_color' => isset( $s['description_color'] ) ? $s['description_color'] : '' ), 'text_color' );
				}
				if ( '' === $inner ) {
					return '';
				}
				return "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n"
					. '<div class="wp-block-group">' . $inner . '</div>'
					. "\n<!-- /wp:group -->";

			case 'icon':
			case 'icons':
				return '';

			case 'spacer':
				$h = $this->size_value( isset( $s['space_height'] ) ? $s['space_height'] : ( isset( $s['space'] ) ? $s['space'] : null ) );
				if ( '' === $h ) {
					$h = '50px';
				}
				return '<!-- wp:spacer {"height":"' . esc_attr( $h ) . '"} -->'
					. "\n" . '<div style="height:' . esc_attr( $h ) . '" aria-hidden="true" class="wp-block-spacer"></div>'
					. "\n<!-- /wp:spacer -->";

			case 'divider':
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

			case 'blockquote':
				$quote  = isset( $s['blockquote_content'] ) ? $s['blockquote_content'] : ( isset( $s['editor'] ) ? $s['editor'] : ( isset( $s['content'] ) ? $s['content'] : ( isset( $s['text'] ) ? $s['text'] : '' ) ) );
				$author = isset( $s['author_name'] ) ? $s['author_name'] : '';
				$cite   = '' !== $author ? '<cite>' . esc_html( $author ) . '</cite>' : '';
				$para   = "<!-- wp:paragraph -->\n<p>" . esc_html( $quote ) . "</p>\n<!-- /wp:paragraph -->";
				return "<!-- wp:quote -->\n"
					. '<blockquote class="wp-block-quote">' . $para . $cite . '</blockquote>'
					. "\n<!-- /wp:quote -->";

			default:
				$label = isset( $s['title'] ) ? $s['title'] : ( isset( $s['text'] ) ? $s['text'] : ( isset( $s['editor'] ) ? $s['editor'] : ( isset( $s['content'] ) ? $s['content'] : '' ) ) );
				return '' !== $label ? $this->paragraph_block( $label, $s, 'text_color' ) : '';
		}
	}

	/**
	 * core/heading.
	 *
	 * @param int    $level     1–6.
	 * @param string $text      Heading text.
	 * @param array  $s         Settings (for color/typography/align).
	 * @param string $color_key Color setting key.
	 * @return string
	 */
	private function heading_block( int $level, string $text, array $s, string $color_key ): string {
		list( $attrs, $class, $style ) = $this->block_style( $s, array( 'text_color_key' => $color_key, 'typography' => true ) );

		$align = $this->text_align( $s );
		if ( '' !== $align ) {
			$attrs['textAlign'] = $align;
			$class              = trim( $class . ' has-text-align-' . $align );
		}
		if ( 2 !== $level ) {
			$attrs['level'] = $level;
		}

		$attr_json = $this->attr_json( $attrs );
		$class     = trim( 'wp-block-heading' . ( $class ? ' ' . $class : '' ) );
		$tag       = 'h' . $level;

		return "<!-- wp:heading{$attr_json} -->\n"
			. '<' . $tag . ' class="' . esc_attr( $class ) . '"' . $style . '>' . esc_html( $text ) . '</' . $tag . '>'
			. "\n<!-- /wp:heading -->";
	}

	/**
	 * core/paragraph.
	 *
	 * @param string $html      Paragraph content (inline HTML tolerated).
	 * @param array  $s         Settings.
	 * @param string $color_key Color setting key.
	 * @return string
	 */
	private function paragraph_block( string $html, array $s, string $color_key ): string {
		list( $attrs, $class, $style ) = $this->block_style( $s, array( 'text_color_key' => $color_key, 'typography' => true ) );

		$align = $this->text_align( $s );
		if ( '' !== $align ) {
			$attrs['align'] = $align;
			$class          = trim( $class . ' has-text-align-' . $align );
		}

		$attr_json  = $this->attr_json( $attrs );
		$class_attr = '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '';
		$inner      = $this->inline_html( $html );

		return "<!-- wp:paragraph{$attr_json} -->\n"
			. '<p' . $class_attr . $style . '>' . $inner . '</p>'
			. "\n<!-- /wp:paragraph -->";
	}

	/**
	 * core/buttons wrapping a single core/button.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private function buttons_block( array $s ): string {
		$text = isset( $s['button_text'] ) ? $s['button_text'] : ( isset( $s['text'] ) ? $s['text'] : 'Button' );
		$link = $this->button_link( $s );

		// Button colors/border ride on the <a> via style + support classes.
		$bstyles = array();
		$bg      = isset( $s['background_color'] ) ? $s['background_color'] : ( isset( $s['button_background_color'] ) ? $s['button_background_color'] : '' );
		$fg      = isset( $s['button_text_color'] ) ? $s['button_text_color'] : ( isset( $s['text_color'] ) ? $s['text_color'] : '' );
		if ( '' !== $bg ) {
			$bstyles['color']['background'] = $bg;
		}
		if ( '' !== $fg ) {
			$bstyles['color']['text'] = $fg;
		}
		$radius = $this->dimension_radius( isset( $s['border_radius'] ) ? $s['border_radius'] : null );
		if ( '' !== $radius ) {
			$bstyles['border']['radius'] = $radius;
		}

		$css   = $this->engine_css( $bstyles );
		$class = 'wp-block-button__link';
		if ( '' !== $bg ) {
			$class .= ' has-background';
		}
		if ( '' !== $fg ) {
			$class .= ' has-text-color';
		}
		$class .= ' wp-element-button';
		$style  = '' !== $css ? ' style="' . esc_attr( $css ) . '"' : '';
		$battrs = ! empty( $bstyles ) ? ' ' . wp_json_encode( array( 'style' => $bstyles ) ) : '';

		$button = "<!-- wp:button{$battrs} -->\n"
			. '<div class="wp-block-button"><a class="' . esc_attr( $class ) . '"' . $style . ' href="' . esc_url( $link ? $link : '#' ) . '">' . esc_html( $text ) . '</a></div>'
			. "\n<!-- /wp:button -->";

		// Center the row when the source aligned center.
		$align    = $this->text_align( $s );
		$wrap_attr = '';
		$wrap_cls  = 'wp-block-buttons';
		if ( 'center' === $align || 'right' === $align ) {
			$wrap_attr = ' {"layout":{"type":"flex","justifyContent":"' . $align . '"}}';
			$wrap_cls .= ' is-content-justification-' . $align . ' is-layout-flex';
		}

		return "<!-- wp:buttons{$wrap_attr} -->\n"
			. '<div class="' . esc_attr( $wrap_cls ) . '">' . $button . '</div>'
			. "\n<!-- /wp:buttons -->";
	}

	/* ---------------------------------------------------------------------
	 * Style mapping (Elementor settings → block style attr + class + inline CSS)
	 * ------------------------------------------------------------------- */

	/**
	 * Build a block's style attribute object, support classes, and inline style
	 * attribute from Elementor container/text settings.
	 *
	 * @param array $s    Settings.
	 * @param array $opts Flags: text_color_key, typography (bool), gap (bool).
	 * @return array{0:array,1:string,2:string} [ style-attrs, class string, ' style="…"' ]
	 */
	private function block_style( array $s, array $opts = array() ): array {
		$styles  = array();
		$classes = array();

		// Background (classic color or gradient).
		if ( isset( $s['background_background'] ) && 'gradient' === $s['background_background'] ) {
			$a = isset( $s['background_color'] ) ? $s['background_color'] : '#ffffff';
			$b = isset( $s['background_color_b'] ) ? $s['background_color_b'] : '#ffffff';
			if ( isset( $s['background_gradient_type'] ) && 'radial' === $s['background_gradient_type'] ) {
				$grad = 'radial-gradient(circle, ' . $a . ', ' . $b . ')';
			} else {
				$angle = isset( $s['background_gradient_angle']['size'] ) ? $s['background_gradient_angle']['size'] : 180;
				$grad  = 'linear-gradient(' . $angle . 'deg, ' . $a . ', ' . $b . ')';
			}
			$styles['color']['gradient'] = $grad;
			$classes[]                   = 'has-background';
		} elseif ( ! empty( $s['background_color'] ) ) {
			$styles['color']['background'] = $s['background_color'];
			$classes[]                     = 'has-background';
		}

		// Text color.
		if ( ! empty( $opts['text_color_key'] ) ) {
			$ck    = $opts['text_color_key'];
			$color = isset( $s[ $ck ] ) ? $s[ $ck ] : ( isset( $s['color'] ) ? $s['color'] : '' );
			if ( '' !== $color ) {
				$styles['color']['text'] = $color;
				$classes[]               = 'has-text-color';
			}
		}

		// Spacing.
		$pad = $this->dimension_sides( isset( $s['padding'] ) ? $s['padding'] : null );
		if ( ! empty( $pad ) ) {
			$styles['spacing']['padding'] = $pad;
		}
		$mar = $this->dimension_sides( isset( $s['margin'] ) ? $s['margin'] : null );
		if ( ! empty( $mar ) ) {
			$styles['spacing']['margin'] = $mar;
		}
		if ( ! empty( $opts['gap'] ) ) {
			$gap = isset( $s['flex_gap'] ) ? $s['flex_gap'] : ( isset( $s['gap'] ) ? $s['gap'] : null );
			$gv  = $this->size_value( $gap );
			if ( '' !== $gv ) {
				$styles['spacing']['blockGap'] = $gv;
			}
		}

		// Border radius.
		$radius = $this->dimension_radius( isset( $s['border_radius'] ) ? $s['border_radius'] : null );
		if ( '' !== $radius ) {
			$styles['border']['radius'] = $radius;
		}

		// Typography.
		if ( ! empty( $opts['typography'] ) ) {
			$t = array();
			$this->set_if( $t, 'fontSize', $this->size_value( isset( $s['typography_font_size'] ) ? $s['typography_font_size'] : null ) );
			$this->set_if( $t, 'fontWeight', isset( $s['typography_font_weight'] ) ? $s['typography_font_weight'] : '' );
			$this->set_if( $t, 'lineHeight', $this->raw_line_height( isset( $s['typography_line_height'] ) ? $s['typography_line_height'] : null ) );
			$this->set_if( $t, 'letterSpacing', $this->size_value( isset( $s['typography_letter_spacing'] ) ? $s['typography_letter_spacing'] : null ) );
			$this->set_if( $t, 'textTransform', isset( $s['typography_text_transform'] ) ? $s['typography_text_transform'] : '' );
			$this->set_if( $t, 'fontStyle', isset( $s['typography_font_style'] ) ? $s['typography_font_style'] : '' );
			if ( ! empty( $t ) ) {
				$styles['typography'] = $t;
			}
		}

		$css       = $this->engine_css( $styles );
		$style_str = '' !== $css ? ' style="' . esc_attr( $css ) . '"' : '';
		$attrs     = ! empty( $styles ) ? array( 'style' => $styles ) : array();

		return array( $attrs, implode( ' ', $classes ), $style_str );
	}

	/**
	 * Inline CSS string for a block style object, via core's style engine when
	 * available (so it matches what the block's save() emits), else a fallback.
	 *
	 * @param array $styles Block style structure.
	 * @return string
	 */
	private function engine_css( array $styles ): string {
		if ( empty( $styles ) ) {
			return '';
		}
		if ( function_exists( 'wp_style_engine_get_styles' ) ) {
			$res = wp_style_engine_get_styles( $styles );
			return isset( $res['css'] ) ? (string) $res['css'] : '';
		}
		return $this->fallback_css( $styles );
	}

	/**
	 * Minimal fallback CSS builder for very old WP without the style engine.
	 *
	 * @param array $styles Block style structure.
	 * @return string
	 */
	private function fallback_css( array $styles ): string {
		$out = array();
		if ( isset( $styles['color']['background'] ) ) {
			$out[] = 'background-color:' . $styles['color']['background'];
		}
		if ( isset( $styles['color']['gradient'] ) ) {
			$out[] = 'background:' . $styles['color']['gradient'];
		}
		if ( isset( $styles['color']['text'] ) ) {
			$out[] = 'color:' . $styles['color']['text'];
		}
		foreach ( array( 'padding', 'margin' ) as $box ) {
			if ( isset( $styles['spacing'][ $box ] ) && is_array( $styles['spacing'][ $box ] ) ) {
				foreach ( $styles['spacing'][ $box ] as $side => $val ) {
					$out[] = $box . '-' . $side . ':' . $val;
				}
			}
		}
		if ( isset( $styles['border']['radius'] ) ) {
			$out[] = 'border-radius:' . $styles['border']['radius'];
		}
		if ( isset( $styles['typography'] ) ) {
			$map = array(
				'fontSize'      => 'font-size',
				'fontWeight'    => 'font-weight',
				'lineHeight'    => 'line-height',
				'letterSpacing' => 'letter-spacing',
				'textTransform' => 'text-transform',
				'fontStyle'     => 'font-style',
			);
			foreach ( $map as $k => $css ) {
				if ( isset( $styles['typography'][ $k ] ) ) {
					$out[] = $css . ':' . $styles['typography'][ $k ];
				}
			}
		}
		return implode( ';', $out );
	}

	/* ---------------------------------------------------------------------
	 * Value helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Encode block attributes as the JSON the block comment expects (leading
	 * space included), or '' when empty.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	private function attr_json( array $attrs ): string {
		if ( empty( $attrs ) ) {
			return '';
		}
		return ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Set $arr[$key] = $val when $val is a non-empty string.
	 *
	 * @param array  $arr Target (by reference).
	 * @param string $key Key.
	 * @param mixed  $val Value.
	 * @return void
	 */
	private function set_if( array &$arr, string $key, $val ): void {
		if ( null !== $val && '' !== $val ) {
			$arr[ $key ] = $val;
		}
	}

	/**
	 * Elementor dimension → block spacing sides map ({top:'80px',...}); '' sides omitted.
	 *
	 * @param mixed $raw Dimension value.
	 * @return array
	 */
	private function dimension_sides( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$u   = $this->css_unit( isset( $raw['unit'] ) ? $raw['unit'] : 'px' );
		$out = array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			if ( isset( $raw[ $side ] ) && '' !== $raw[ $side ] && null !== $raw[ $side ] ) {
				$out[ $side ] = $raw[ $side ] . $u;
			}
		}
		return $out;
	}

	/**
	 * Elementor border-radius dimension → single CSS radius (uses the top value).
	 *
	 * @param mixed $raw Dimension value.
	 * @return string
	 */
	private function dimension_radius( $raw ): string {
		if ( ! is_array( $raw ) ) {
			return '';
		}
		$u = $this->css_unit( isset( $raw['unit'] ) ? $raw['unit'] : 'px' );
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			if ( isset( $raw[ $side ] ) && '' !== $raw[ $side ] && null !== $raw[ $side ] ) {
				return $raw[ $side ] . $u;
			}
		}
		return '';
	}

	/**
	 * Elementor size control → CSS length.
	 *
	 * @param mixed  $raw          Value.
	 * @param string $fallbackUnit Default unit.
	 * @return string
	 */
	private function size_value( $raw, string $fallbackUnit = 'px' ): string {
		if ( is_array( $raw ) ) {
			if ( ! isset( $raw['size'] ) || '' === $raw['size'] ) {
				return '';
			}
			return $raw['size'] . $this->css_unit( isset( $raw['unit'] ) ? $raw['unit'] : $fallbackUnit );
		}
		if ( null === $raw || '' === $raw ) {
			return '';
		}
		return $raw . $fallbackUnit;
	}

	/**
	 * Line-height: Elementor stores {size,unit}; CSS line-height is usually unitless.
	 *
	 * @param mixed $raw Value.
	 * @return string
	 */
	private function raw_line_height( $raw ): string {
		if ( is_array( $raw ) && isset( $raw['size'] ) && '' !== $raw['size'] ) {
			return (string) $raw['size'];
		}
		return '';
	}

	/**
	 * Whitelist CSS units.
	 *
	 * @param string $unit Unit.
	 * @return string
	 */
	private function css_unit( string $unit ): string {
		$ok = array( 'px', '%', 'em', 'rem', 'vw', 'vh' );
		return in_array( $unit, $ok, true ) ? $unit : 'px';
	}

	/**
	 * Resolve a text alignment (left|center|right) from settings.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private function text_align( array $s ): string {
		$a = isset( $s['align'] ) ? $s['align'] : '';
		return in_array( $a, array( 'left', 'center', 'right' ), true ) ? $a : '';
	}

	/**
	 * Constrain a heading tag to h1–h6.
	 *
	 * @param mixed $size Requested size.
	 * @return string
	 */
	private function heading_tag( $size ): string {
		$tag = strtolower( (string) ( $size ? $size : 'h2' ) );
		return in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $tag : 'h2';
	}

	/**
	 * Reduce arbitrary editor HTML to inline-safe paragraph content.
	 *
	 * @param string $html Raw content.
	 * @return string
	 */
	private function inline_html( string $html ): string {
		// Turn paragraph breaks into line breaks, drop block tags, keep inline ones.
		$html = preg_replace( '#</p>\s*<p[^>]*>#i', '<br><br>', $html );
		$html = preg_replace( '#</?p[^>]*>#i', '', (string) $html );
		return wp_kses(
			(string) $html,
			array(
				'a'      => array( 'href' => array(), 'title' => array() ),
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'br'     => array(),
				'span'   => array(),
			)
		);
	}

	/**
	 * Resolve a button link from the common setting shapes.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private function button_link( array $s ): string {
		if ( isset( $s['button_link'] ) ) {
			return is_array( $s['button_link'] ) ? ( isset( $s['button_link']['url'] ) ? $s['button_link']['url'] : '' ) : (string) $s['button_link'];
		}
		if ( isset( $s['link']['url'] ) ) {
			return $s['link']['url'];
		}
		return '';
	}

	/**
	 * Resolve an image src from the common setting shapes.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private function image_src( array $s ): string {
		if ( isset( $s['image']['url'] ) ) {
			return $s['image']['url'];
		}
		if ( isset( $s['image'] ) && is_string( $s['image'] ) ) {
			return $s['image'];
		}
		if ( isset( $s['url'] ) ) {
			return (string) $s['url'];
		}
		if ( isset( $s['src'] ) ) {
			return (string) $s['src'];
		}
		return '';
	}
}
