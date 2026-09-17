<?php
/**
 * Security and content headers for a response.
 *
 * @package SugarCommerce\Http
 */

declare( strict_types=1 );

namespace SugarCommerce\Http;

/**
 * Class Headers
 *
 * So that sniff guards, framing protection, referrer policy and a content
 * security policy are not things an individual handler can forget. A header
 * that is only right on the pages somebody remembered is not protection.
 *
 * The defaults are deliberately strict — no CORS, no CDN, no inline script —
 * and everything is opened up by argument. A permissive default is one nobody
 * ever tightens.
 *
 * @since 1.0.0
 */
final class Headers {

	/**
	 * Per-request CSP nonce, generated once and reused.
	 */
	private static ?string $nonce = null;

	/**
	 * The nonce for this request.
	 *
	 * A nonce is preferable to `'unsafe-inline'`, which permits anything
	 * injected into the page as well as the script you meant to allow.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function nonce(): string {
		return self::$nonce ??= rtrim( strtr( base64_encode( random_bytes( 16 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * The default content security policy, as directive => sources.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public static function policy(): array {
		return array(
			'default-src'     => "'self'",
			'script-src'      => "'self' 'nonce-" . self::nonce() . "'",
			'style-src'       => "'self' 'unsafe-inline'",
			'font-src'        => "'self' data:",
			'img-src'         => "'self' data: https:",
			'connect-src'     => "'self'",
			'frame-ancestors' => "'none'",
			'base-uri'        => "'self'",
			'form-action'     => "'self'",
		);
	}

	/**
	 * Headers for an HTML response.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<int, string>> $allow Extra sources to append
	 *                                                 to policy directives,
	 *                                                 e.g. `array( 'script-src'
	 *                                                 => array( 'https://cdn…' ) )`.
	 *                                                 Appending rather than
	 *                                                 replacing is what keeps
	 *                                                 the nonce in place.
	 *
	 * @return array<string, string>
	 */
	public static function html( array $allow = array() ): array {
		$policy = self::policy();

		foreach ( $allow as $directive => $sources ) {
			$policy[ $directive ] = trim( ( $policy[ $directive ] ?? '' ) . ' ' . implode( ' ', (array) $sources ) );
		}

		$directives = array();

		foreach ( $policy as $directive => $sources ) {
			$directives[] = '' === $sources ? $directive : $directive . ' ' . $sources;
		}

		return array(
			'Content-Type'            => 'text/html; charset=utf-8',
			'X-Content-Type-Options'  => 'nosniff',
			'X-Frame-Options'         => 'DENY',
			'Referrer-Policy'         => 'no-referrer',
			'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
			'Content-Security-Policy' => implode( '; ', $directives ),
		);
	}

	/**
	 * Headers for a JSON response.
	 *
	 * `no-store` rather than `no-cache`: an API answer is usually the thing
	 * you least want written to a shared cache on the way past.
	 *
	 * No CORS. Add it explicitly with cors() where an endpoint needs it.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public static function json(): array {
		return array(
			'Content-Type'           => 'application/json',
			'X-Content-Type-Options' => 'nosniff',
			'Cache-Control'          => 'no-store',
		);
	}

	/**
	 * CORS headers, to merge into a JSON response.
	 *
	 * `*` is right in exactly one situation worth naming: a caller that has
	 * no origin to allow-list, such as a sandboxed iframe with a null origin.
	 * Everywhere else, name the origin. And an endpoint that answers `*` has
	 * to authenticate every request itself, because the browser is no longer
	 * doing any of that work for it.
	 *
	 * @since 1.0.0
	 *
	 * @param string             $origin  Allowed origin.
	 * @param array<int, string> $headers Allowed request headers.
	 * @param array<int, string> $methods Allowed methods.
	 *
	 * @return array<string, string>
	 */
	public static function cors(
		string $origin = '*',
		array $headers = array( 'Content-Type' ),
		array $methods = array( 'GET', 'POST', 'OPTIONS' ),
	): array {
		return array(
			'Access-Control-Allow-Origin'  => $origin,
			'Access-Control-Allow-Headers' => implode( ', ', $headers ),
			'Access-Control-Allow-Methods' => implode( ', ', $methods ),
		);
	}

	/**
	 * Add HSTS when the request arrived over TLS.
	 *
	 * Sending it over plain HTTP is meaningless, and on a local host actively
	 * harmful — the browser remembers it and then refuses to load
	 * http://localhost for the next six months, for every project you have.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $headers  Headers so far.
	 * @param bool                  $is_https Whether the request was TLS.
	 * @param int                   $max_age  Seconds.
	 *
	 * @return array<string, string>
	 */
	public static function with_hsts( array $headers, bool $is_https, int $max_age = 31_536_000 ): array {
		if ( $is_https ) {
			$headers['Strict-Transport-Security'] = 'max-age=' . $max_age . '; includeSubDomains';
		}

		return $headers;
	}

	/**
	 * Forget the generated nonce.
	 *
	 * For tests, which would otherwise share one across every case in the
	 * process and never notice if it stopped being generated at all.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function reset_nonce(): void {
		self::$nonce = null;
	}
}
