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
	 * @since 1.5.0
	 */
	public const array FORWARDED = array(
		'x-forwarded-for',
		'x-forwarded-host',
		'x-real-ip',
		'forwarded',
		'cf-connecting-ip',
		'cf-ray',
		'true-client-ip',
	);

	/**
	 * Socket addresses that mean "this request did not cross a network".
	 *
	 * An empty address is not here: "we could not tell" is not the same as
	 * "this machine", and treating it as local lets in anything that hides
	 * its address.
	 *
	 * @since 1.5.0
	 */
	public const array LOOPBACK = array( '127.0.0.1', '::1' );

	/**
	 * @param string                $method  Upper-case verb.
	 * @param string                $path    Path, with any surface prefix
	 *                                       already resolved.
	 * @param string                $host    Lower-cased host.
	 * @param array                 $query   Query parameters.
	 * @param array<string, string> $headers Lower-cased header names.
	 * @param string                $body    Raw body, read once.
	 * @param array                 $form    Form-encoded body parameters.
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
	 *
	 * @param Surfaces|null $surfaces How to split the application, or null
	 *                                when it has only one surface.
	 *
	 * @return self
	 */
	public static function capture( ?Surfaces $surfaces = null ): self {
		$host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' ) );
		$path = '/' . trim( (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ?: '' ), '/' );

		$resolved = $surfaces?->resolve( $host, $path )
			?? array( 'surface' => '', 'path' => $path, 'prefix' => '' );

		return new self(
			method:  strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ),
			path:    $resolved['path'],
			host:    $host,
			query:   $_GET,
			headers: self::read_headers(),
			body:    (string) file_get_contents( 'php://input' ),
			form:    $_POST,
			surface: $resolved['surface'],
			prefix:  $resolved['prefix'],
			remote:  (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ),
			secure:  ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ),
			cookies: $_COOKIE,
			uri_path: (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ?: '/' ),
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
		$value = $this->cookies[ $name ] ?? '';

		return is_string( $value ) ? $value : '';
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
	 * One header, or an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Header name, any case.
	 *
	 * @return string
	 */
	public function header( string $name ): string {
		return (string) ( $this->headers[ strtolower( $name ) ] ?? '' );
	}

	/**
	 * The decoded JSON body, or an empty array.
	 *
	 * @since 1.0.0
	 *
	 * @return array
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
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_https(): bool {
		return 'https' === strtolower( $this->header( 'x-forwarded-proto' ) ) || $this->secure;
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
		return 1 === preg_match( '/^Bearer\s+(\S+)$/i', trim( $this->header( 'authorization' ) ), $match ) ? $match[1] : '';
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
	 * @since 1.5.0
	 *
	 * @param string[] $local Socket addresses that count as this machine.
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
			if ( str_starts_with( (string) $key, 'HTTP_' ) ) {
				$headers[ strtolower( str_replace( '_', '-', substr( (string) $key, 5 ) ) ) ] = (string) $value;
			}
		}

		return $headers;
	}
}
