<?php
/**
 * Set-Cookie, as a header rather than a side effect.
 *
 * @package   SugarCommerce\Http
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Http;

/**
 * Class Cookie
 *
 * PHP's setcookie() writes to the output of whichever request happens to be
 * running, which makes a handler that calls it untestable without capturing
 * headers, and untrue in any context that is not a live request — a queue
 * worker, a test, a second response built and thrown away.
 *
 * This builds the header value instead, so setting a cookie is something a
 * Response carries like any other header.
 *
 * The options are setcookie()'s own array form, so anything that already
 * produces them — a session library, an application's own defaults — can be
 * passed straight through.
 *
 * @since 1.3.0
 */
final readonly class Cookie {

	/**
	 * Build a Set-Cookie header value.
	 *
	 * @since 1.3.0
	 *
	 * @param string               $name    Cookie name.
	 * @param string               $value   Cookie value. URL-encoded, as
	 *                                      setcookie() does.
	 * @param array<string, mixed> $options expires, path, domain, secure,
	 *                                      httponly, samesite. Omitted keys
	 *                                      are omitted from the header,
	 *                                      which is not the same as sending
	 *                                      them empty.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException When the name is not a valid token.
	 */
	public static function header( string $name, string $value, array $options = array() ): string {
		// A name carrying a separator would let a caller inject attributes,
		// or a second cookie, into the header they thought they were naming.
		if ( ! preg_match( '/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid cookie name: ' . $name );
		}

		$parts = array( $name . '=' . rawurlencode( $value ) );

		if ( isset( $options['expires'] ) && 0 !== (int) $options['expires'] ) {
			$parts[] = 'Expires=' . gmdate( 'D, d M Y H:i:s \G\M\T', (int) $options['expires'] );
			// Max-Age as well as Expires: every current browser prefers
			// Max-Age, and Expires is what an old one understands.
			$parts[] = 'Max-Age=' . max( 0, (int) $options['expires'] - time() );
		}

		foreach ( array( 'path' => 'Path', 'domain' => 'Domain', 'samesite' => 'SameSite' ) as $key => $label ) {
			if ( '' !== (string) ( $options[ $key ] ?? '' ) ) {
				$parts[] = $label . '=' . self::attribute( (string) $options[ $key ] );
			}
		}

		if ( ! empty( $options['secure'] ) ) {
			$parts[] = 'Secure';
		}

		if ( ! empty( $options['httponly'] ) ) {
			$parts[] = 'HttpOnly';
		}

		return implode( '; ', $parts );
	}

	/**
	 * An attribute value with anything header-breaking removed.
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private static function attribute( string $value ): string {
		return str_replace( array( "\r", "\n", "\0", ';', ',' ), '', $value );
	}
}
