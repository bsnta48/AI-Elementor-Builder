<?php
/**
 * Reversible key obfuscation for stored API keys.
 *
 * NOTE: This XORs the value against wp_salt() and base64-encodes the result.
 * It hides keys from casual database inspection but is NOT strong cryptography
 * (the salt lives in wp-config.php / the same DB). Treat it as obfuscation,
 * not as protection against an attacker who already has DB + filesystem access.
 *
 * @package AI_Elementor_Builder
 */

namespace AI_Elementor_Builder\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypt / decrypt / mask helpers for sensitive settings.
 */
class Crypto {

	/**
	 * Prefix marking a value as already-encrypted, so we never double-encrypt
	 * or attempt to decrypt plaintext.
	 */
	const PREFIX = 'aieb$1$';

	/**
	 * The keystream used for the XOR.
	 *
	 * @return string
	 */
	private static function key() {
		return wp_salt( 'secure_auth' );
	}

	/**
	 * XOR a string against a repeating key.
	 *
	 * @param string $data Raw bytes.
	 * @param string $key  Key bytes.
	 * @return string
	 */
	private static function xor_cipher( $data, $key ) {
		$out     = '';
		$key_len = strlen( $key );
		$len     = strlen( $data );

		if ( 0 === $key_len ) {
			return $data;
		}

		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $data[ $i ] ^ $key[ $i % $key_len ];
		}

		return $out;
	}

	/**
	 * Encrypt a plaintext value for storage.
	 *
	 * @param string $plaintext Raw value.
	 * @return string Encrypted, prefixed, base64 string. Empty in => empty out.
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext || null === $plaintext ) {
			return '';
		}

		$cipher = self::xor_cipher( (string) $plaintext, self::key() );

		return self::PREFIX . base64_encode( $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored value.
	 *
	 * @param string $stored Stored value (may be prefixed-encrypted or plaintext).
	 * @return string Decrypted plaintext.
	 */
	public static function decrypt( $stored ) {
		if ( '' === $stored || null === $stored ) {
			return '';
		}

		// Not our format -> assume legacy plaintext, return as-is.
		if ( 0 !== strncmp( $stored, self::PREFIX, strlen( self::PREFIX ) ) ) {
			return (string) $stored;
		}

		$payload = substr( $stored, strlen( self::PREFIX ) );
		$cipher  = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $cipher ) {
			return '';
		}

		return self::xor_cipher( $cipher, self::key() );
	}

	/**
	 * Whether a stored value is in our encrypted format.
	 *
	 * @param string $stored Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $stored ) {
		return is_string( $stored ) && 0 === strncmp( $stored, self::PREFIX, strlen( self::PREFIX ) );
	}

	/**
	 * Build a masked display value, revealing only the last 4 characters.
	 *
	 * @param string $plaintext Decrypted value.
	 * @return string e.g. "••••••••cd34". Empty in => empty out.
	 */
	public static function mask( $plaintext ) {
		$plaintext = (string) $plaintext;
		$len       = strlen( $plaintext );

		if ( 0 === $len ) {
			return '';
		}

		if ( $len <= 4 ) {
			return str_repeat( '•', $len );
		}

		return str_repeat( '•', 8 ) . substr( $plaintext, -4 );
	}
}
