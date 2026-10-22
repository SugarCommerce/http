<?php
/**
 * The incoming request.
 *
 * @package SugarCommerce\Http
 */

declare( strict_types=1 );

namespace SugarCommerce\Http;

use SugarCommerce\Network\{ClientIp, Cloudflare, TrustedProxies};

/**
 * Class Request
 *
 * A read of the superglobals, captured once, immutable afterwards. The point
 * of the class is that nothing downstream touches `$_SERVER` again: a handler
 * that reaches for a superglobal cannot be tested without one, and a test that
 * has to build `$_SERVER` is a test nobody writes.
 *
 * Nothing here trusts the client. The host picks a surface and is never used
 * to build a URL; the forwarded protocol is read but only where it is the
 * authoritative answer; the client address goes through ClientIp rather than
 * a header read.
 *
 * @since 1.0.0
 */
final readonly class Request {

	/**
	 * Headers a proxy or tunnel adds when it forwards a request.
	 *
	 * Behind any of them the socket address is the proxy's — loopback, for a
	 * tunnel or a reverse proxy on the same box — so their presence alone
	 * proves the request crossed a network, whatever address they claim.
	 *
	 * The list is generous on purpose (since 1.6.0 it names every header a
	 * common proxy, CDN or tunnel adds by default), because a name missing
	 * from it is a proxy whose traffic looks local. It cannot be complete:
	 * a reverse proxy configured to add nothing at all is indistinguishable
	 * from this machine, and is_local() says so in its own documentation.
	 *
	 * @since 1.5.0
	 *
	 * @var list<string>
	 */
	public const array FORWARDED = array(
		'x-forwarded-for',
		'x-forwarded-host',
		'x-forwarded-proto',
		'x-forwarded-port',
		'x-forwarded-server',
		'x-forwarded',
		'x-original-forwarded-for',
		'x-real-ip',
		'x-client-ip',
		'x-cluster-client-ip',
		'forwarded',
		'forwarded-for',
		'via',
		'cf-connecting-ip',
		'cf-ray',
		'true-client-ip',
		'fastly-client-ip',
	);

	/**
	 * Socket addresses that mean "this request did not cross a network".
	 *
	 * An empty address is not here: "we could not tell" is not the same as
	 * "this machine", and treating it as local lets in anything that hides
	 * its address.
	 *
	 * @since 1.5.0
	 *
	 * @var list<string>
	 */
	public const array LOOPBACK = array( '127.0.0.1', '::1' );

	/**
	 * Build a request from its parts. capture() is the usual way in; this is
	 * for tests, and for anything that has a request from somewhere other
	 * than the superglobals.
	 *
	 * @param string                $method  Upper-case verb.
	 * @param string                $path    Path, with any surface prefix
	 *                                       already resolved.
	 * @param string                $host    Lower-cased host.
	 * @param array<array-key, mixed> $query Query parameters, as PHP parsed
	 *                                       them: values may be nested
	 *                                       arrays.
	 * @param array<string, string> $headers Lower-cased header names.
	 * @param string                $body    Raw body, read once.
	 * @param array<array-key, mixed> $form  Form-encoded body parameters,
	 *                                       as PHP parsed them.
	 * @param string                $surface Which surface this is for.
	 * @param string                $prefix  The surface's local mount point,
	 *                                       '' when it has its own hostname.
	 * @param string                $remote  REMOTE_ADDR, before any
	 *                                       forwarding header is considered.
	 * @param bool                  $secure  Whether the connection to this
	 *                                       process was itself TLS.
	 * @param array<string, string> $cookies Cookies sent with the request.
	 * @param string                $uri_path The path as the client sent it,
	 *                                        before any surface was
	 *                                        resolved — for routing that has
	 *                                        to happen ahead of surfaces,
	 *                                        such as a custom domain. ''
	 *                                        when the request was built by
	 *                                        hand rather than captured.
	 */
	public function __construct(
		public string $method,
		public string $path,
		public string $host,
		public array $query = array(),
		public array $headers = array(),
		public string $body = '',
		public array $form = array(),
		public string $surface = '',
		public string $prefix = '',
		public string $remote = '',
		public bool $secure = false,
		public array $cookies = array(),
		public string $uri_path = '',
	) {}

	/**
	 * Capture the current request.
	 *
	 * @since 1.0.0
	 * @since 1.8.0 Takes an optional body cap.
	 *
	 * @param Surfaces|null $surfaces How to split the application, or null
	 *                                when it has only one surface.
	 * @param int           $max_body Largest body read, in bytes; a larger
	 *                                one reads as empty. 0 for no cap beyond
	 *                                post_max_size.
	 *
	 * @return self
	 */
	public static function capture( ?Surfaces $surfaces = null, int $max_body = 0 ): self {
		$host     = strtolower( self::server_string( 'HTTP_HOST', 'localhost' ) );
		$uri_path = parse_url( self::server_string( 'REQUEST_URI', '/' ), PHP_URL_PATH );
		$uri_path = is_string( $uri_path ) && '' !== $uri_path ? $uri_path : '/';
		$path     = '/' . trim( $uri_path, '/' );

		$resolved = $surfaces?->resolve( $host, $path )
			?? array( 'surface' => '', 'path' => $path, 'prefix' => '' );

		$https = self::server_string( 'HTTPS' );

		return new self(
			method:  strtoupper( self::server_string( 'REQUEST_METHOD', 'GET' ) ),
			path:    $resolved['path'],
			host:    $host,
			query:   $_GET,
			headers: self::read_headers(),
			body:    self::read_body( $max_body ),
			form:    $_POST,
			surface: $resolved['surface'],
			prefix:  $resolved['prefix'],
			remote:  self::server_string( 'REMOTE_ADDR' ),
			secure:  '' !== $https && 'off' !== strtolower( $https ),
			cookies: self::read_cookies(),
			uri_path: $uri_path,
		);
	}

	/**
	 * One cookie, or ''.
	 *
	 * Reading `$request->cookies['x'] ?? ''` at every call site is the same
	 * thing with more chances to forget the coalesce, and a cookie is
	 * missing far more often than a header is.
	 *
	 * @since 1.2.0
	 *
	 * @param string $name Cookie name.
	 *
	 * @return string
	 */
	public function cookie( string $name ): string {
		// The property is documented as strings, and capture() keeps it so,
		// but a request built by hand can be handed anything.
		$value = $this->cookies[ $name ] ?? '';

		return is_string( $value ) ? $value : ''; // @phpstan-ignore function.alreadyNarrowedType (a hand-built request is not bound by the PHPDoc; only capture() keeps the promise)
	}

	/**
	 * Prefix a path with this surface's local mount point.
	 *
	 * A no-op in production, where each surface has its own hostname. Use it
	 * for every redirect and every form action, or the ones you forget work
	 * on the server and not on your machine.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Path as the surface thinks of it.
	 *
	 * @return string
	 */
	public function url( string $path ): string {
		return $this->prefix . $path;
	}

	/**
	 * A redirect target the visitor supplied, if it stays on this site.
	 *
	 * For a `?return=` or `next` parameter: the target is kept only when it
	 * is a path on this host, and anything else becomes the fallback. "A
	 * path" is narrower than it looks — `//evil.example` is another site,
	 * and so is `/\evil.example`, because browsers read a backslash as a
	 * slash; a tab or a line break is dropped by the browser before it reads
	 * the URL, so `/<TAB>/evil.example` is the first of those again. So the
	 * target has to start with exactly one `/`, and carry no backslash and
	 * no control character or space anywhere.
	 *
	 * ```php
	 * return Response::redirect( $request->url( Request::local_path( $request->input( 'return' ), '/orders' ) ) );
	 * ```
	 *
	 * @since 1.6.0
	 *
	 * @param string $target   The target as sent.
	 * @param string $fallback Used when the target is not a local path.
	 *
	 * @return string
	 */
	public static function local_path( string $target, string $fallback = '/' ): string {
		if ( ! str_starts_with( $target, '/' ) || str_starts_with( $target, '//' ) ) {
			return $fallback;
		}

		return 1 === preg_match( '/[\\\\\x00-\x20\x7F]/', $target ) ? $fallback : $target;
	}

	/**
	 * One header, or an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Header name, any case.
	 *
	 * @return string
	 */
	public function header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
	}

	/**
	 * The decoded JSON body, or an empty array.
	 *
	 * A body that decodes to anything but an object or array — a bare
	 * string, a number, invalid JSON — is an empty array, not an error.
	 *
	 * @since 1.0.0
	 *
	 * @return array<array-key, mixed>
	 */
	public function json(): array {
		$data = json_decode( $this->body, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * A submitted value, wherever it came from.
	 *
	 * Checks the form body, then JSON, then the query string. The form body
	 * has to come first and has to be there at all: an HTML form posts
	 * form-encoded, and a version of this that reads only JSON hands every
	 * form an empty string — saving blanks with no error anywhere, because a
	 * blank string is a valid string. That is a real bug this method has had.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Field name.
	 * @param string $default Returned when the field is absent everywhere.
	 *
	 * @return string
	 */
	public function input( string $key, string $default = '' ): string {
		if ( isset( $this->form[ $key ] ) && is_scalar( $this->form[ $key ] ) ) {
			return (string) $this->form[ $key ];
		}

		$json = $this->json();

		$value = $json[ $key ] ?? $this->query[ $key ] ?? $default;

		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * Was this field submitted at all?
	 *
	 * Distinct from `'' !== input( $key )`, and the difference is a checkbox:
	 * an unchecked one sends nothing, which input() cannot tell apart from a
	 * text field somebody cleared. A form that saves settings needs to know
	 * which — the first means false, the second means empty.
	 *
	 * @since 1.1.0
	 *
	 * @param string $key Field name.
	 *
	 * @return bool
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->form )
			|| array_key_exists( $key, $this->query )
			|| array_key_exists( $key, $this->json() );
	}

	/**
	 * Did this request arrive over TLS?
	 *
	 * Behind a proxy the connection to this process may be plain HTTP while
	 * the browser's was HTTPS, so the forwarded header is the authoritative
	 * answer and `$_SERVER['HTTPS']` is not.
	 *
	 * But X-Forwarded-Proto is a header like any other, and a client that
	 * reaches this process without a proxy can send it. Pass the proxies
	 * (since 1.6.0) and it is believed only from them, the same rule ip()
	 * follows. Without them it is believed from anyone, as it always has
	 * been — which is what an application behind a proxy it never named
	 * relies on, and why that is still the default.
	 *
	 * @since 1.0.0
	 *
	 * @param TrustedProxies|null $proxies The proxies whose X-Forwarded-Proto
	 *                                     is believed, or null to believe it
	 *                                     from anyone.
	 *
	 * @return bool
	 */
	public function is_https( ?TrustedProxies $proxies = null ): bool {
		if ( $this->secure ) {
			return true;
		}

		if ( null !== $proxies && ! $proxies->trusts( $this->remote ) ) {
			return false;
		}

		return 'https' === strtolower( trim( $this->header( 'x-forwarded-proto' ) ) );
	}

	/**
	 * Does this request want an HTML page rather than JSON?
	 *
	 * For endpoints a browser and a script both reach — a download link a
	 * customer clicks, or the same URL called from code — so a failure can
	 * be a rendered page for one and structured JSON for the other. No
	 * Accept header, or `*\/*`, counts as a browser: scripts that want JSON
	 * say so with `Accept: application/json`.
	 *
	 * @since 1.8.0
	 *
	 * @return bool
	 */
	public function wants_html(): bool {
		$accept = trim( $this->header( 'accept' ) );

		return '' === $accept || '*/*' === $accept || str_contains( strtolower( $accept ), 'text/html' );
	}

	/**
	 * The absolute origin to build links with, e.g. `https://example.com`.
	 *
	 * Pass the site's configured URL whenever there is one, and that is what
	 * comes back — scheme, host and any non-standard port, nothing else. The
	 * request's own Host header is only the fallback, for a fresh install or
	 * a local dev server, because it is whatever the client sent: a link
	 * minted from it for an email or a recovery page can point at somebody
	 * else's host. Its scheme comes from {@see self::is_https()} with the
	 * same proxies, and a default port (80, 443) is dropped.
	 *
	 * @since 1.8.0
	 *
	 * @param string              $configured The site's own URL ('' when
	 *                                        unset). Only its scheme, host
	 *                                        and port are used.
	 * @param TrustedProxies|null $proxies    Proxies whose X-Forwarded-Proto
	 *                                        is believed for the fallback.
	 *
	 * @return string Origin without a trailing slash, or '' when the
	 *                configured URL is unusable and there is no Host.
	 */
	public function origin( string $configured = '', ?TrustedProxies $proxies = null ): string {
		if ( '' !== trim( $configured ) ) {
			return self::origin_of( $configured );
		}

		$host = (string) preg_replace( '/:(80|443)$/D', '', $this->host );

		if ( '' === $host || 1 !== preg_match( '/^[a-z0-9.\-]+(:\d{1,5})?$|^\[[0-9a-f:.]+\](:\d{1,5})?$/iD', $host ) ) {
			return '';
		}

		return ( $this->is_https( $proxies ) ? 'https' : 'http' ) . '://' . strtolower( $host );
	}

	/**
	 * Reduce a URL to its origin: scheme, host and a non-default port.
	 *
	 * @param string $url An absolute http(s) URL.
	 *
	 * @return string The origin, or '' when the URL is not an absolute
	 *                http(s) URL with a host.
	 */
	private static function origin_of( string $url ): string {
		$parts  = parse_url( trim( $url ) );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || 1 === preg_match( '/[\s\\@]/', $host ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) && ! in_array( $parts['port'], array( 80, 443 ), true ) ? ':' . $parts['port'] : '';

		return $scheme . '://' . $host . $port;
	}

	/**
	 * The client address.
	 *
	 * Resolved through ClientIp rather than by reading a header, because the
	 * rule that matters is easy to get wrong: a forwarding header may only be
	 * believed when REMOTE_ADDR is inside a trusted range. Trusting
	 * `CF-Connecting-IP` unconditionally means anyone who can reach the origin
	 * directly — a misconfigured firewall, a leaked origin address — can claim
	 * to be any address they like, which poisons every log and every rate
	 * limit keyed on it.
	 *
	 * @since 1.0.0
	 *
	 * @param TrustedProxies|null $proxies Which proxies may be believed.
	 *                                     Defaults to none, which returns
	 *                                     REMOTE_ADDR and is the only safe
	 *                                     default — name your proxies.
	 *
	 * @return string The address, or '' when it cannot be determined.
	 */
	public function ip( ?TrustedProxies $proxies = null ): string {
		return ( new ClientIp( $proxies ?? TrustedProxies::none() ) )->resolve( $this->server() ) ?? '';
	}

	/**
	 * The token from `Authorization: Bearer …`, or ''.
	 *
	 * The header only, never the body or the query: a key in a URL ends up
	 * in logs and Referer headers, and one in a body is one a form on
	 * another site could be made to send. Anything that is not exactly one
	 * Bearer credential — Basic, two tokens, a bare scheme — is no token,
	 * rather than a guess at which part was meant.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	public function bearer(): string {
		return 1 === preg_match( '/^Bearer\s+(\S+)$/iD', trim( $this->header( 'authorization' ) ), $match ) ? $match[1] : '';
	}

	/**
	 * The path this surface's cookies should be confined to.
	 *
	 * Where every surface shares one hostname — locally, behind path
	 * prefixes — a cookie set at `/` would be sent to all of them: a
	 * seller's session riding along on every buyer download. Its own
	 * prefix keeps it home. Where a surface has its own hostname the
	 * hostname is already the boundary, and the answer is `/`.
	 *
	 * @since 1.5.0
	 *
	 * @return string
	 */
	public function cookie_path(): string {
		return '' === $this->prefix ? '/' : $this->prefix;
	}

	/**
	 * The country Cloudflare says the visitor is in, or ''.
	 *
	 * Believed only when the request actually came from one of the given
	 * proxies — otherwise `CF-IPCountry` is a header anybody can set, and a
	 * shopper could pick their own prices, or their own tax, by typing one.
	 *
	 * @since 1.5.0
	 *
	 * @param TrustedProxies $proxies The proxies whose headers are believed.
	 *
	 * @return string ISO 3166-1 alpha-2 code (or Cloudflare's `XX`/`T1`), or ''.
	 */
	public function country( TrustedProxies $proxies ): string {
		$cloudflare = new Cloudflare( $proxies );

		return $cloudflare->is_active( $this->server_vars() )
			? (string) ( $cloudflare->country( $this->server_vars() ) ?? '' )
			: '';
	}

	/**
	 * Did this request come from this machine, and nowhere else?
	 *
	 * For gating the development conveniences that must never be reachable
	 * from outside — a panel with no password set, an unsigned fallback.
	 *
	 * Deliberately never consults a forwarding header. ip() believes one
	 * from a trusted proxy, which is right for rate limits and wrong here: a
	 * client can put 127.0.0.1 in X-Forwarded-For and a CDN edge will pass
	 * it along. So the socket address must be loopback **and** nothing may
	 * have forwarded the request at all — which also shuts out a tunnel or a
	 * reverse proxy on the same box, whose requests arrive from loopback
	 * carrying exactly those headers.
	 *
	 * The one thing it cannot see is a proxy on this machine configured to
	 * add none of them: its requests are, byte for byte, local ones. Do not
	 * put one in front of anything this method gates.
	 *
	 * @since 1.5.0
	 *
	 * @param list<string> $local Socket addresses that count as this machine.
	 *
	 * @return bool
	 */
	public function is_local( array $local = self::LOOPBACK ): bool {
		if ( '' === $this->remote || ! in_array( $this->remote, $local, true ) ) {
			return false;
		}

		foreach ( self::FORWARDED as $header ) {
			if ( '' !== $this->header( $header ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * This request as a `$_SERVER`-shaped array.
	 *
	 * For libraries that read the superglobal's shape — the network
	 * package's ClientIp and Cloudflare among them — so they can be handed
	 * this request instead of the process's globals, and be tested with one.
	 * Only REMOTE_ADDR and the `HTTP_*` headers are present.
	 *
	 * @since 1.5.0
	 *
	 * @return array<string, string>
	 */
	public function server_vars(): array {
		return $this->server();
	}

	/**
	 * This request as a `$_SERVER`-shaped array, for the network library.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private function server(): array {
		$server = array( 'REMOTE_ADDR' => $this->remote );

		foreach ( $this->headers as $name => $value ) {
			$server[ 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) ) ] = $value;
		}

		return $server;
	}

	/**
	 * Request headers, lower-cased.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private static function read_headers(): array {
		$headers = array();

		foreach ( $_SERVER as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && str_starts_with( $key, 'HTTP_' ) ) {
				$headers[ strtolower( str_replace( '_', '-', substr( $key, 5 ) ) ) ] = $value;
			}
		}

		return $headers;
	}

	/**
	 * Cookies sent with the request, strings only.
	 *
	 * PHP turns `a[]=1` into an array, so `$_COOKIE` is not the map of
	 * strings the property promises until anything else is dropped.
	 *
	 * @since 1.6.0
	 *
	 * @return array<string, string>
	 */
	private static function read_cookies(): array {
		$cookies = array();

		foreach ( $_COOKIE as $name => $value ) {
			if ( is_string( $value ) ) {
				$cookies[ (string) $name ] = $value;
			}
		}

		return $cookies;
	}

	/**
	 * Read the request body, up to $max bytes.
	 *
	 * A body larger than the cap is discarded rather than truncated: half a
	 * JSON document is not a smaller JSON document.
	 *
	 * @param int $max Byte cap, or 0 for none (post_max_size still applies).
	 *
	 * @return string
	 */
	private static function read_body( int $max ): string {
		if ( $max < 1 ) {
			return (string) file_get_contents( 'php://input' );
		}

		$body = file_get_contents( 'php://input', false, null, 0, $max + 1 );

		return is_string( $body ) && strlen( $body ) <= $max ? $body : '';
	}

	/**
	 * One `$_SERVER` entry as a string, or the default when it is missing
	 * or is not one.
	 *
	 * @since 1.6.0
	 *
	 * @param string $key     The `$_SERVER` key.
	 * @param string $default Returned when the entry is absent or not scalar.
	 *
	 * @return string
	 */
	private static function server_string( string $key, string $default = '' ): string {
		$value = $_SERVER[ $key ] ?? null;

		return is_scalar( $value ) ? (string) $value : $default;
	}
}
