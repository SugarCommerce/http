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
	 * @throws \InvalidArgumentException When the name is not a valid token,
	 *                                   or SameSite is not Strict, Lax or
	 *                                   None.
	 */
	public static function header( string $name, string $value, array $options = array() ): string {
		// A name carrying a separator would let a caller inject attributes,
		// or a second cookie, into the header they thought they were naming.
		if ( ! preg_match( '/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/D', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid cookie name: ' . $name );
		}

		$parts   = array( $name . '=' . rawurlencode( $value ) );
		$expires = self::integer( $options['expires'] ?? 0 );

		if ( 0 !== $expires ) {
			$parts[] = 'Expires=' . gmdate( 'D, d M Y H:i:s \G\M\T', $expires );
			// Max-Age as well as Expires: every current browser prefers
			// Max-Age, and Expires is what an old one understands.
			$parts[] = 'Max-Age=' . max( 0, $expires - time() );
		}

		foreach ( array( 'path' => 'Path', 'domain' => 'Domain', 'samesite' => 'SameSite' ) as $key => $label ) {
			$option = $options[ $key ] ?? '';
			$option = is_scalar( $option ) ? self::attribute( (string) $option ) : '';

			if ( '' === $option ) {
				continue;
			}

			// A SameSite a browser does not recognise is ignored, and what it
			// falls back to differs by browser — None, in some. A typo that
			// quietly turns off CSRF protection in one of them is refused.
			if ( 'samesite' === $key && ! in_array( strtolower( $option ), array( 'strict', 'lax', 'none' ), true ) ) {
				throw new \InvalidArgumentException( 'Invalid SameSite value: ' . $option );
			}

			$parts[] = $label . '=' . $option;
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
	 * Every control character goes, not only CR and LF: PHP's header()
	 * refuses a value carrying one, and a cookie that silently fails to be
	 * set is a session that silently fails to start.
	 *
	 * @since 1.3.0
	 *
	 * @param string $value Raw value.
	 *
	 * @return string
	 */
	private static function attribute( string $value ): string {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F;,]/', '', $value ) );
	}

	/**
	 * An option as a whole number, or 0 when it is not one.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $value The option as given — usually an int, sometimes a
	 *                     numeric string read from configuration.
	 *
	 * @return int
	 */
	private static function integer( mixed $value ): int {
		return is_int( $value ) || ( is_string( $value ) && is_numeric( $value ) ) || is_float( $value ) ? (int) $value : 0;
	}
}
