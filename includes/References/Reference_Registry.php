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
