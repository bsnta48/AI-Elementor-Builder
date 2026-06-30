<?php
/**
 * Design reference library.
 *
 * Loads curated, hand-built Elementor JSON exemplars from the bundled
 * `library/` directory. These are injected into the generation prompt as
 * few-shot examples so the model produces more complex, polished layouts.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\References;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers and serves design reference exemplars.
 */
class Reference_Registry {

	/**
	 * Library directory relative to the plugin root.
	 */
	const DIR = 'includes/References/library/';

	/**
	 * Loaded references, keyed by id. Null until first load.
	 *
	 * @var array<string,array>|null
	 */
	private $cache = null;

	/**
	 * Load every valid reference from the library directory.
	 *
	 * @return array<string,array> Map of id => reference (incl. content).
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$this->cache = array();
		$dir         = AIEB_PLUGIN_DIR . self::DIR;
		$files       = glob( $dir . '*.json' );
		if ( ! is_array( $files ) ) {
			return $this->cache;
		}

		foreach ( $files as $file ) {
			$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$data = json_decode( (string) $raw, true );

			// A reference must carry a non-empty Elementor content array.
			if ( ! is_array( $data ) || empty( $data['content'] ) || ! is_array( $data['content'] ) ) {
				continue;
			}

			$id = sanitize_key( basename( $file, '.json' ) );
			if ( '' === $id ) {
				continue;
			}

			$this->cache[ $id ] = array(
				'id'          => $id,
				'name'        => isset( $data['name'] ) ? (string) $data['name'] : $id,
				'description' => isset( $data['description'] ) ? (string) $data['description'] : '',
				'tags'        => ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) ? array_values( $data['tags'] ) : array(),
				'content'     => $data['content'],
			);
		}

		return $this->cache;
	}

	/**
	 * Fetch a single reference by id (including its content).
	 *
	 * @param string $id Reference id.
	 * @return array|null
	 */
	public function get( string $id ) {
		$all = $this->all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Pick the best exemplar(s) for a request when the user did not choose one,
	 * so every generation gets a strong few-shot anchor (not just manual picks).
	 *
	 * For a single-section scope, returns the one matching exemplar. For a full
	 * page (or unknown scope), returns a diverse multi-section set so the model
	 * learns page composition, biased by any keyword overlap with the prompt.
	 *
	 * @param string $scope  Inferred scope (fullpage|hero|pricing|about|features|testimonials|contact|custom|'').
	 * @param string $prompt User prompt text (for keyword matching).
	 * @param int    $limit  Max exemplars to return.
	 * @return array<int,array> List of reference arrays (each incl. content).
	 */
	public function auto_select( string $scope, string $prompt, int $limit = 3 ): array {
		$all = $this->all();
		if ( empty( $all ) ) {
			return array();
		}

		$scope = strtolower( trim( $scope ) );
		$text  = strtolower( $scope . ' ' . $prompt );

		// Section-scoped request: return the single exemplar that matches by tag/id.
		$section_scopes = array( 'hero', 'pricing', 'about', 'features', 'testimonials', 'contact' );
		if ( in_array( $scope, $section_scopes, true ) ) {
			foreach ( $all as $ref ) {
				if ( false !== strpos( $ref['id'], $scope ) || in_array( $scope, array_map( 'strtolower', $ref['tags'] ), true ) ) {
					return array( $ref );
				}
			}
		}

		// Full page / unknown: score by keyword overlap, then a composition-priority
		// bias so the default set reads like a real landing page.
		$priority = array( 'modern-saas-hero', 'feature-grid-3col', 'pricing-3tier', 'testimonial-quotes', 'split-about', 'cta-gradient' );

		$scored = array();
		$i      = 0;
		foreach ( $all as $id => $ref ) {
			$score = 0;
			foreach ( $ref['tags'] as $tag ) {
				if ( false !== strpos( $text, strtolower( (string) $tag ) ) ) {
					$score += 50;
				}
			}
			$rank = array_search( $id, $priority, true );
			if ( false !== $rank ) {
				$score += ( count( $priority ) - $rank );
			}
			// Stable tiebreak by discovery order.
			$scored[ $id ] = array( $score, $i );
			++$i;
		}

		uasort(
			$scored,
			static function ( $a, $b ) {
				if ( $a[0] === $b[0] ) {
					return $a[1] <=> $b[1];
				}
				return $b[0] <=> $a[0];
			}
		);

		$result = array();
		foreach ( array_keys( $scored ) as $id ) {
			$result[] = $all[ $id ];
			if ( count( $result ) >= $limit ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Lightweight listing for the UI — metadata only, no heavy content.
	 *
	 * @return array<int,array>
	 */
	public function listing(): array {
		return array_values(
			array_map(
				static function ( $ref ) {
					return array(
						'id'          => $ref['id'],
						'name'        => $ref['name'],
						'description' => $ref['description'],
						'tags'        => $ref['tags'],
					);
				},
				$this->all()
			)
		);
	}
}
