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
	 * The Referrer-Policy values a browser honours.
	 *
	 * @since 1.9.0
	 *
	 * @var array<int, string>
	 */
	private const array REFERRER_POLICIES = array(
		'no-referrer',
		'no-referrer-when-downgrade',
		'origin',
		'origin-when-cross-origin',
		'same-origin',
		'strict-origin',
		'strict-origin-when-cross-origin',
		'unsafe-url',
	);

	/**
	 * Per-request CSP nonce, generated once and reused.
	 *
	 * @since 1.0.0
	 */
	private static ?string $nonce = null;

	/**
	 * The nonce for this request.
	 *
	 * A nonce is preferable to `'unsafe-inline'`, which permits anything
	 * injected into the page as well as the script you meant to allow.
	 *
	 * "This request" means this process, which under PHP-FPM is the same
	 * thing. Under a runtime that keeps the process between requests —
	 * FrankenPHP's worker mode, RoadRunner, Swoole — it is not, and a nonce
	 * that outlives its request is one a visitor can read off their own page
	 * and use in a script they inject into somebody else's. Call
	 * reset_nonce() at the start of every request there.
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
			$directive = self::directive( (string) $directive );

			if ( '' !== $directive ) {
				$policy[ $directive ] = trim( ( $policy[ $directive ] ?? '' ) . ' ' . self::sources( implode( ' ', $sources ) ) );
			}
		}

		if ( null !== $frame_ancestors ) {
			$policy['frame-ancestors'] = self::sources( $frame_ancestors );
		}

		$headers = array(
			'Content-Type'            => 'text/html; charset=utf-8',
			'X-Content-Type-Options'  => 'nosniff',
			'X-Frame-Options'         => 'DENY',
			'Referrer-Policy'         => 'no-referrer',
			'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=(), interest-cohort=()',
			'Content-Security-Policy' => self::compile( $policy ),
		);

		if ( ! self::refuses_framing( $policy['frame-ancestors'] ) ) {
			unset( $headers['X-Frame-Options'] );
		}

		return $headers;
	}

	/**
	 * Security headers from a strict baseline, every value yours to set.
	 *
	 * For an application that knows exactly what its pages load and wants
	 * the header to say so and no more. html() starts permissive enough to
	 * work anywhere — `'unsafe-inline'` styles, images from any `https:`
	 * host — and can only add to that; this starts from nothing but
	 * `'self'` and replaces a directive's sources outright.
	 *
	 * The baseline: `default-src`, `style-src`, `font-src`, `connect-src`,
	 * `form-action` and `base-uri` are `'self'`, `script-src` is `'self'`
	 * and the nonce, `img-src` is `'self' data:` and `frame-ancestors` is
	 * `'none'`. A directive you name replaces the baseline's, and one set to
	 * null is left out. Yours come first, in the order you gave them, and
	 * then whatever of the baseline you did not mention — so a policy
	 * written out in full comes back exactly as written.
	 *
	 * The nonce stays in `script-src` however it is replaced: straight after
	 * `'self'`, or first when there is no `'self'`. A script-src that no
	 * longer carried it would drop every inline script silently, in the
	 * browser rather than in a test. Only `'none'` is left alone.
	 *
	 * No Content-Type, because these belong on every response — JSON and
	 * plain text as well as pages. X-Frame-Options stays DENY unless
	 * frame-ancestors names somebody, as in html(). Add HSTS with
	 * with_hsts().
	 *
	 * ```php
	 * Headers::strict(
	 *     array(
	 *         'script-src' => array( "'self'", 'https://challenges.cloudflare.com' ),
	 *         'frame-src'  => 'https://challenges.cloudflare.com',
	 *     ),
	 *     $nonce,
	 * );
	 * ```
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, string|array<int, string>|null> $directives  Directive =>
	 *                                                                   sources, as a
	 *                                                                   string or a
	 *                                                                   list; null
	 *                                                                   leaves the
	 *                                                                   directive out.
	 * @param string|null                                   $nonce       The script
	 *                                                                   nonce; null
	 *                                                                   uses nonce().
	 * @param string                                        $referrer    Referrer-Policy:
	 *                                                                   one of the
	 *                                                                   policies the
	 *                                                                   spec names.
	 * @param array<int, string>                            $permissions Features turned
	 *                                                                   off for every
	 *                                                                   origin, in
	 *                                                                   order; none
	 *                                                                   leaves the
	 *                                                                   header out.
	 *
	 * @return array<string, string>
	 *
	 * @throws \InvalidArgumentException When the nonce is not base64, or the
	 *                                   referrer policy is not one a browser
	 *                                   would honour — it would ignore the
	 *                                   header, not fall back to anything
	 *                                   stricter.
	 */
	public static function strict(
		array $directives = array(),
		?string $nonce = null,
		string $referrer = 'strict-origin-when-cross-origin',
		array $permissions = array( 'geolocation', 'microphone', 'camera', 'payment' ),
	): array {
		$nonce ??= self::nonce();

		if ( 1 !== preg_match( '/^[A-Za-z0-9+\/_-]+={0,2}$/', $nonce ) ) {
			throw new \InvalidArgumentException( 'Not a usable nonce: ' . json_encode( $nonce ) );
		}

		if ( ! in_array( $referrer, self::REFERRER_POLICIES, true ) ) {
			throw new \InvalidArgumentException( 'Not a referrer policy: ' . json_encode( $referrer ) );
		}

		$baseline = array(
			'default-src'     => "'self'",
			'script-src'      => "'self'",
			'style-src'       => "'self'",
			'img-src'         => "'self' data:",
			'font-src'        => "'self'",
			'connect-src'     => "'self'",
			'form-action'     => "'self'",
			'frame-ancestors' => "'none'",
			'base-uri'        => "'self'",
		);
		$policy   = array();

		foreach ( $directives as $directive => $sources ) {
			$directive = self::directive( (string) $directive );

			if ( '' === $directive ) {
				continue;
			}

			unset( $baseline[ $directive ], $policy[ $directive ] );

			if ( null !== $sources ) {
				$policy[ $directive ] = self::sources( implode( ' ', (array) $sources ) );
			}
		}

		$policy += $baseline;

		if ( isset( $policy['script-src'] ) ) {
			$policy['script-src'] = self::with_nonce( $policy['script-src'], "'nonce-" . $nonce . "'" );
		}

		$denied = array();

		foreach ( $permissions as $feature ) {
			$feature = self::directive( $feature );

			if ( '' !== $feature ) {
				$denied[] = $feature . '=()';
			}
		}

		$headers = array(
			'X-Content-Type-Options'  => 'nosniff',
			'X-Frame-Options'         => 'DENY',
			'Referrer-Policy'         => $referrer,
			'Permissions-Policy'      => implode( ', ', $denied ),
			'Content-Security-Policy' => self::compile( $policy ),
		);

		if ( array() === $denied ) {
			unset( $headers['Permissions-Policy'] );
		}

		if ( isset( $policy['frame-ancestors'] ) && ! self::refuses_framing( $policy['frame-ancestors'] ) ) {
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

		$directives['frame-ancestors'] = trim( 'frame-ancestors ' . self::sources( $ancestors ) );
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
	 * A policy as its header value.
	 *
	 * @since 1.9.0
	 *
	 * @param array<string, string> $policy Directive => sources.
	 *
	 * @return string
	 */
	private static function compile( array $policy ): string {
		$directives = array();

		foreach ( $policy as $directive => $sources ) {
			$directives[] = '' === $sources ? $directive : $directive . ' ' . $sources;
		}

		return implode( '; ', $directives );
	}

	/**
	 * A script-src source list with the nonce in it.
	 *
	 * @since 1.9.0
	 *
	 * @param string $sources Space-separated sources.
	 * @param string $nonce   The `'nonce-…'` source.
	 *
	 * @return string Unchanged when it already has the nonce or is `'none'`.
	 */
	private static function with_nonce( string $sources, string $nonce ): string {
		$list = '' === $sources ? array() : explode( ' ', $sources );

		if ( in_array( $nonce, $list, true ) || array( "'none'" ) === array_map( strtolower( ... ), $list ) ) {
			return $sources;
		}

		$self = array_search( "'self'", array_map( strtolower( ... ), $list ), true );

		array_splice( $list, false === $self ? 0 : $self + 1, 0, array( $nonce ) );

		return implode( ' ', $list );
	}

	/**
	 * A directive name, reduced to the characters a directive name has.
	 *
	 * @since 1.6.0
	 *
	 * @param string $name As given.
	 *
	 * @return string Lower-cased, or '' when nothing was left.
	 */
	private static function directive( string $name ): string {
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9-]/', '', $name ) );
	}

	/**
	 * A source list that cannot become more than a source list.
	 *
	 * `;` starts another directive and `,` another policy, and a control
	 * character makes header() refuse the whole Content-Security-Policy —
	 * leaving the page with none. A frame-ancestors list built from a
	 * seller's own domains is the obvious way for any of them to arrive.
	 *
	 * @since 1.6.0
	 *
	 * @param string $sources Space-separated sources.
	 *
	 * @return string
	 */
	private static function sources( string $sources ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[\x00-\x08\x0A-\x1F\x7F;,]/', ' ', $sources ) ) );
	}

	/**
	 * Whether a frame-ancestors value refuses every framer.
	 *
	 * @since 1.5.0
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
	 * @since 1.5.0
	 *
	 * @param string $url An origin or URL.
	 *
	 * @return string
	 */
	private static function origin( string $url ): string {
		$parts = parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || isset( $parts['user'] ) || ! isset( $parts['host'] ) || '' === $parts['host'] ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] ?? '' );

		return in_array( $scheme, array( 'http', 'https' ), true )
			? $scheme . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
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
