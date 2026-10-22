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
 * For a plain PHP page that sends its own headers rather than returning a
 * Response, set(), clear() and read() do the whole job with the defaults a
 * first-party cookie wants, and still go through header() here — so a bad
 * name or SameSite throws rather than reaching the browser.
 *
 * @since 1.3.0
 */
final readonly class Cookie {

	/**
	 * What set() and clear() fill in for an option the caller leaves out.
	 *
	 * The whole site, out of reach of script, and Lax so it rides along on a
	 * link from elsewhere but not on another site's form. Secure is off only
	 * because it has to follow the request: on a plain-HTTP development host
	 * a Secure cookie is dropped by the browser without a word.
	 *
	 * @since 1.7.0
	 */
	private const array DEFAULTS = array(
		'path'     => '/',
		'httponly' => true,
		'samesite' => 'Lax',
		'secure'   => false,
	);

	/**
	 * Send a cookie.
	 *
	 * ```php
	 * Cookie::set( 'theme', 'dark', 86_400 * 365, array( 'secure' => $request->is_https( $proxies ) ) );
	 * ```
	 *
	 * Sent with header() rather than setcookie(), so the name and SameSite
	 * are checked exactly as header() checks them. Several cookies in one
	 * response each get their own line.
	 *
	 * @since 1.7.0
	 *
	 * @param string               $name    Cookie name.
	 * @param string               $value   Cookie value.
	 * @param int                  $max_age Seconds it lasts. 0 is a session
	 *                                      cookie, gone when the browser
	 *                                      closes; below 0 clears it.
	 * @param array<string, mixed> $options As header(); path, httponly,
	 *                                      samesite and secure default to
	 *                                      DEFAULTS. Pass `secure` from the
	 *                                      request.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException As header().
	 */
	public static function set( string $name, string $value, int $max_age, array $options = array() ): void {
		header( 'Set-Cookie: ' . self::set_header( $name, $value, $max_age, $options ), false );
	}

	/**
	 * The Set-Cookie value set() sends, without sending it.
	 *
	 * @since 1.7.0
	 *
	 * @param string               $name    Cookie name.
	 * @param string               $value   Cookie value.
	 * @param int                  $max_age Seconds it lasts. 0 is a session
	 *                                      cookie; below 0 clears it.
	 * @param array<string, mixed> $options As set(). An `expires` here is
	 *                                      ignored: $max_age is the expiry.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException As header().
	 */
	public static function set_header( string $name, string $value, int $max_age, array $options = array() ): string {
		// header() reads 0 as "no expiry". A cookie meant to last no time at
		// all is one being cleared, so it gets a date that has passed.
		$expires = 0 === $max_age ? 0 : max( 1, time() + $max_age );

		return self::header( $name, $value, array( 'expires' => $expires ) + $options + self::DEFAULTS );
	}

	/**
	 * Remove a cookie from the browser.
	 *
	 * Pass the path and domain it was set with. A browser keys a cookie on
	 * its name, domain and path together, so clearing `/` does nothing to
	 * a cookie set at `/admin`, and the visitor stays signed in after
	 * pressing sign out.
	 *
	 * @since 1.7.0
	 *
	 * @param string               $name    Cookie name.
	 * @param array<string, mixed> $options As set().
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException As header().
	 */
	public static function clear( string $name, array $options = array() ): void {
		header( 'Set-Cookie: ' . self::clear_header( $name, $options ), false );
	}

	/**
	 * The Set-Cookie value clear() sends, without sending it: empty, and
	 * expired at the start of 1970 with a Max-Age of 0.
	 *
	 * @since 1.7.0
	 *
	 * @param string               $name    Cookie name.
	 * @param array<string, mixed> $options As set(). An `expires` here is
	 *                                      ignored.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException As header().
	 */
	public static function clear_header( string $name, array $options = array() ): string {
		return self::header( $name, '', array( 'expires' => 1 ) + $options + self::DEFAULTS );
	}

	/**
	 * A cookie's value, or '' when there is none.
	 *
	 * A cookie sent as `name[]=x` arrives as an array, and counts as not
	 * sent: cast, it would be the string "Array" for everybody.
	 *
	 * @since 1.7.0
	 *
	 * @param string                       $name    Cookie name.
	 * @param array<array-key, mixed>|null $cookies Cookie jar. Defaults to
	 *                                              $_COOKIE; passed
	 *                                              explicitly by tests.
	 *
	 * @return string
	 */
	public static function read( string $name, ?array $cookies = null ): string {
		$value = ( $cookies ?? $_COOKIE )[ $name ] ?? '';

		return is_string( $value ) ? $value : '';
	}

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
