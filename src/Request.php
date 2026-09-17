<?php
/**
 * The incoming request.
 *
 * @package SugarCommerce\Http
 */

declare( strict_types=1 );

namespace SugarCommerce\Http;

use SugarCommerce\Network\{ClientIp, TrustedProxies};

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
