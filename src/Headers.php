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
	 * `$frame_ancestors` (since 1.5.0) is for the page that is meant to be
	 * framed. X-Frame-Options goes with it, because that header cannot say
	 * "these sites" — only DENY or SAMEORIGIN — and would go on refusing
	 * whatever the policy allowed.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, array<int, string>> $allow           Extra sources to
	 *                                                           append to policy
	 *                                                           directives, e.g.
	 *                                                           `array( 'script-src'
	 *                                                           => array( … ) )`.
	 *                                                           Appending rather
	 *                                                           than replacing is
	 *                                                           what keeps the
	 *                                                           nonce in place.
	 * @param string|null                       $frame_ancestors Replaces the
	 *                                                           frame-ancestors
	 *                                                           directive, e.g.
	 *                                                           `*`. Anything
	 *                                                           but `'none'`
	 *                                                           also drops
	 *                                                           X-Frame-Options.
	 *
	 * @return array<string, string>
	 */
	public static function html( array $allow = array(), ?string $frame_ancestors = null ): array {
		$policy = self::policy();

		foreach ( $allow as $directive => $sources ) {
			$policy[ $directive ] = trim( ( $policy[ $directive ] ?? '' ) . ' ' . implode( ' ', (array) $sources ) );
		}

		if ( null !== $frame_ancestors ) {
			$policy['frame-ancestors'] = trim( $frame_ancestors );
		}

		$directives = array();

		foreach ( $policy as $directive => $sources ) {
			$directives[] = '' === $sources ? $directive : $directive . ' ' . $sources;
		}

		$headers = array(
			'Content-Type'            => 'text/html; charset=utf-8',
			'X-Content-Type-Options'  => 'nosniff',
			'X-Frame-Options'         => 'DENY',
			'Referrer-Policy'         => 'no-referrer',
			'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
			'Content-Security-Policy' => implode( '; ', $directives ),
		);

		if ( ! self::refuses_framing( $policy['frame-ancestors'] ) ) {
			unset( $headers['X-Frame-Options'] );
		}

		return $headers;
	}

	/**
	 * The same headers, letting other sites frame the page.
	 *
	 * For the one kind of page that is meant to sit inside somebody else's
	 * site — an embeddable card or widget. Every other page should keep
	 * refusing, which is what stops a page being laid under a transparent
	 * button of an attacker's own.
	 *
	 * The policy is parsed and the one directive replaced (or added), rather
	 * than a string search for `frame-ancestors 'none'`: a search like that
	 * silently does nothing the day the default changes, and leaves a page
	 * that is meant to be embeddable refusing to be.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, string> $headers   Headers from html().
	 * @param string                $ancestors Who may frame it: `*`, or a
	 *                                         list of origins.
	 *
	 * @return array<string, string>
	 */
	public static function embeddable( array $headers, string $ancestors = '*' ): array {
		$directives = array();

		foreach ( explode( ';', (string) ( $headers['Content-Security-Policy'] ?? '' ) ) as $directive ) {
			$directive = trim( $directive );

			if ( '' !== $directive ) {
				$name                = strtolower( (string) strtok( $directive, " \t" ) );
				$directives[ $name ] = $directive;
			}
		}

		$directives['frame-ancestors'] = 'frame-ancestors ' . trim( $ancestors );
		$headers['Content-Security-Policy'] = implode( '; ', $directives );

		if ( ! self::refuses_framing( $ancestors ) ) {
			unset( $headers['X-Frame-Options'] );
		}

		return $headers;
	}

	/**
	 * Several directive => sources maps, as one.
	 *
	 * Appended rather than replaced: a directive named in two maps ends up
	 * with both sets of sources, where `+` on arrays would silently keep only
	 * the first — and the page would break in the browser, not in a test.
	 * Duplicates are dropped, first one kept: harmless to a browser, but
	 * noise to anyone reading the header to find out why something was
	 * blocked.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, string|array<int, string>> ...$policies Maps to merge.
	 *
	 * @return array<string, array<int, string>> Ready for html().
	 */
	public static function merge_sources( array ...$policies ): array {
		$merged = array();

		foreach ( $policies as $policy ) {
			foreach ( $policy as $directive => $sources ) {
				$merged[ $directive ] = array_values( array_unique( array_merge( $merged[ $directive ] ?? array(), (array) $sources ) ) );
			}
		}

		return $merged;
	}

	/**
	 * The nonce as an HTML attribute: ` nonce="…"`, leading space included.
	 *
	 * Every inline script needs it. The policy allows `'self'` and this
	 * nonce and nothing else, so a script tag without one is silently
	 * dropped by the browser — no error in the response, no failing test,
	 * just a feature that quietly does not work.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	public static function nonce_attribute(): string {
		return ' nonce="' . htmlspecialchars( self::nonce(), ENT_QUOTES, 'UTF-8' ) . '"';
	}

	/**
	 * CORS headers for one caller, when it is on a list.
	 *
	 * For an endpoint called from browsers on sites somebody named — a
	 * seller's own pages — where `*` would be wrong. The request's Origin is
	 * echoed back only when it matches an allowed origin exactly (scheme,
	 * host and port, case-insensitively); a suffix match would let
	 * `evil-example.com` in on `example.com`'s name, and `null` — what a
	 * sandboxed frame or a privacy redirect sends — matches nothing.
	 *
	 * `Vary: Origin` comes back either way. The answer depends on the
	 * Origin, so a cache that stored one caller's answer for everybody would
	 * hand the allowed one's headers to a stranger, or the refusal to a
	 * site that is allowed.
	 *
	 * @since 1.5.0
	 *
	 * @param string             $origin  The request's Origin header.
	 * @param array<int, string> $allowed Allowed origins, e.g.
	 *                                    `https://shop.example.com`.
	 * @param array<int, string> $headers Allowed request headers.
	 * @param array<int, string> $methods Allowed methods.
	 *
	 * @return array<string, string> The CORS headers and Vary, or only Vary.
	 */
	public static function cors_for(
		string $origin,
		array $allowed,
		array $headers = array( 'Content-Type' ),
		array $methods = array( 'GET', 'POST', 'OPTIONS' ),
	): array {
		$origin  = self::origin( $origin );
		$allowed = array_map( self::origin( ... ), $allowed );

		return '' !== $origin && in_array( $origin, $allowed, true )
			? self::cors( $origin, $headers, $methods ) + array( 'Vary' => 'Origin' )
			: array( 'Vary' => 'Origin' );
	}

	/**
	 * Whether a frame-ancestors value refuses every framer.
	 *
	 * @param string $ancestors Directive value.
	 *
	 * @return bool
	 */
	private static function refuses_framing( string $ancestors ): bool {
		return "'none'" === strtolower( trim( $ancestors ) );
	}

	/**
	 * An origin as `scheme://host[:port]`, lower-cased, or ''.
	 *
	 * @param string $url An origin or URL.
	 *
	 * @return string
	 */
	private static function origin( string $url ): string {
		$parts = parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || isset( $parts['user'] ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		return in_array( $scheme, array( 'http', 'https' ), true )
			? $scheme . '://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' )
			: '';
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
