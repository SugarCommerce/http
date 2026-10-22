<?php
/**
 * The outgoing response.
 *
 * @package SugarCommerce\Http
 */

declare( strict_types=1 );

namespace SugarCommerce\Http;

/**
 * Class Response
 *
 * Built and returned, not echoed. A handler that writes output directly cannot
 * be tested without capturing the output buffer, and cannot be wrapped by
 * anything that wants to add a header after the fact.
 *
 * @since 1.0.0
 */
final readonly class Response {

	/**
	 * What a header name may be: an RFC 9110 token.
	 *
	 * @since 1.6.0
	 */
	private const string TOKEN = '/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/D';

	/**
	 * Build a response from its parts. The named constructors below are the
	 * usual way in, because each carries the headers its kind of body needs.
	 *
	 * @param int                   $status  HTTP status code.
	 * @param string                $body    Already-encoded body.
	 * @param array<string, string> $headers Response headers.
	 */
	public function __construct(
		public int $status = 200,
		public string $body = '',
		public array $headers = array(),
	) {}

	/**
	 * A JSON response.
	 *
	 * @since 1.0.0
	 *
	 * @param array<array-key, mixed> $data  Payload.
	 * @param int                   $status  HTTP status code.
	 * @param array<string, string> $headers Extra headers — CORS, rate-limit.
	 *
	 * @return self
	 */
	public static function json( array $data, int $status = 200, array $headers = array() ): self {
		return new self( $status, (string) json_encode( $data ), $headers + Headers::json() );
	}

	/**
	 * A JSON error.
	 *
	 * Worth having as its own method rather than a `json()` call with an
	 * `error` key, because the alternative is what every API ends up with:
	 * the same literal string written out at twenty call sites, three of
	 * which say something slightly different for no reason.
	 *
	 * @since 1.0.0
	 *
	 * @param string                $message Human-readable, and safe to show.
	 * @param int                   $status  HTTP status code.
	 * @param array<array-key, mixed> $extra Machine-readable detail merged
	 *                                       into the body, e.g. a `reason`
	 *                                       a client can branch on.
	 * @param array<string, string> $headers Extra headers.
	 *
	 * @return self
	 */
	public static function error( string $message, int $status = 400, array $extra = array(), array $headers = array() ): self {
		return self::json( array( 'error' => $message ) + $extra, $status, $headers );
	}

	/**
	 * An HTML response.
	 *
	 * @since 1.0.0
	 *
	 * @param string                $html    Rendered markup.
	 * @param int                   $status  HTTP status code.
	 * @param array<string, string> $headers Extra headers.
	 *
	 * @return self
	 */
	public static function html( string $html, int $status = 200, array $headers = array() ): self {
		return new self( $status, $html, $headers + Headers::html() );
	}

	/**
	 * A plain-text response.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text   Body.
	 * @param int    $status HTTP status code.
	 *
	 * @return self
	 */
	public static function text( string $text, int $status = 200 ): self {
		return new self( $status, $text, array(
			'Content-Type'           => 'text/plain; charset=utf-8',
			'X-Content-Type-Options' => 'nosniff',
		) );
	}

	/**
	 * A redirect.
	 *
	 * The destination has to be one of two things: a path on this site, by
	 * Request::local_path()'s rules, or an absolute http or https URL. Any
	 * other shape throws. `//evil.example` and `/\evil.example` are both "a
	 * path" to a glance and another site to a browser; `javascript:` and
	 * `data:` are not places at all; and a relative `orders` resolves
	 * against wherever the visitor happens to be.
	 *
	 * An absolute URL is still used as given, so never build one from
	 * something the visitor sent — a `?return=` parameter, a Referer —
	 * without checking it first. Request::local_path() is that check.
	 *
	 * @since 1.0.0
	 * @since 1.7.0 Refuses a destination that is neither a local path nor an
	 *              absolute http(s) URL.
	 *
	 * @param string $url    Destination.
	 * @param int    $status 302 unless the move is permanent.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException When the destination is neither.
	 */
	public static function redirect( string $url, int $status = 302 ): self {
		if ( ! self::destination( $url ) ) {
			throw new \InvalidArgumentException( 'Refusing to redirect to ' . json_encode( $url ) );
		}

		return new self( $status, '', array( 'Location' => $url ) );
	}

	/**
	 * An empty 204, for a CORS preflight.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $headers The CORS headers to answer with.
	 * @param int                   $max_age How long the browser may cache
	 *                                       the answer, in seconds.
	 *
	 * @return self
	 */
	public static function preflight( array $headers = array(), int $max_age = 86_400 ): self {
		return new self( 204, '', $headers + array( 'Access-Control-Max-Age' => (string) $max_age ) );
	}

	/**
	 * A file for the browser to save.
	 *
	 * `private, no-store` because a download built per request is almost
	 * always somebody's own data — an export, a list of their customers —
	 * and a shared cache keeping a copy is a leak. nosniff so a CSV that
	 * happens to look like HTML is never rendered as a page.
	 *
	 * The Content-Disposition value is taken whole rather than built from a
	 * filename here. Doing it properly is RFC 6266: an ASCII fallback for
	 * old clients plus `filename*=UTF-8''…` for everybody else, with quotes,
	 * backslashes and control characters handled — and that already lives
	 * in sugarcommerce/s3-signer's `ContentDisposition::attachment()`.
	 * Build it there; a second copy here would be the one that drifts.
	 *
	 * @since 1.5.0
	 *
	 * @param string                $body        The file's bytes.
	 * @param string                $type        Content-Type, e.g.
	 *                                           `text/csv; charset=utf-8`.
	 * @param string                $disposition A complete Content-Disposition
	 *                                           value.
	 * @param array<string, string> $headers     Extra headers. These win.
	 *
	 * @return self
	 */
	public static function download( string $body, string $type, string $disposition, array $headers = array() ): self {
		return new self( 200, $body, $headers + array(
			'Content-Type'           => $type,
			'Content-Disposition'    => $disposition,
			'Cache-Control'          => 'private, no-store',
			'X-Content-Type-Options' => 'nosniff',
		) );
	}

	/**
	 * Run some work once the visitor has their response.
	 *
	 * The one place in this package that schedules anything, and it is here
	 * because the alternative is worse: work done before the response is
	 * returned — a webhook, an email, a counter — is time the visitor
	 * waits for and, for a request that can be timed, a side channel.
	 * Under PHP-FPM the connection is closed first, so the visitor neither
	 * waits nor can measure. Elsewhere (the built-in server, the CLI) the
	 * work still runs, just before the process ends.
	 *
	 * Nothing is sent by calling this; it only registers the work.
	 *
	 * @since 1.5.0
	 *
	 * @param \Closure $work What to run.
	 *
	 * @return void
	 */
	public static function defer( \Closure $work ): void {
		register_shutdown_function( static function () use ( $work ): void {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			}

			$work();
		} );
	}

	/**
	 * The same response with extra headers.
	 *
	 * Existing headers win, so a handler cannot have its content type
	 * rewritten by something wrapping it.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, string> $headers Headers to add.
	 *
	 * @return self
	 */
	public function with( array $headers ): self {
		return clone( $this, array( 'headers' => $this->headers + $headers ) );
	}

	/**
	 * Send it.
	 *
	 * Every header is checked before anything is sent. PHP's header() refuses
	 * a line break itself, but by warning and dropping that one header — and
	 * a Content-Security-Policy or a Location quietly missing is worse than
	 * a request that fails. So a name that is not a token, or a value with a
	 * control character in it, throws instead, and nothing goes out.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 *
	 * @throws \UnexpectedValueException When a header could not be sent as
	 *                                   it was given.
	 */
	public function send(): void {
		foreach ( $this->headers as $name => $value ) {
			if ( 1 !== preg_match( self::TOKEN, (string) $name ) || 1 === preg_match( '/[\x00-\x08\x0A-\x1F\x7F]/', $value ) ) {
				throw new \UnexpectedValueException( 'Refusing to send a malformed header: ' . json_encode( (string) $name ) );
			}
		}

		http_response_code( $this->status );

		foreach ( $this->headers as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo $this->body;
	}

	/**
	 * Whether a URL is somewhere a redirect may send the browser.
	 *
	 * FILTER_VALIDATE_URL alone is not that: it passes `javascript://x%0A…`
	 * and `mailto:`, so the scheme is checked as well. It also refuses
	 * whitespace and control characters, which a browser would strip and
	 * then read the URL without.
	 *
	 * @since 1.7.0
	 *
	 * @param string $url Destination.
	 *
	 * @return bool
	 */
	private static function destination( string $url ): bool {
		if ( str_starts_with( $url, '/' ) ) {
			return Request::local_path( $url, '' ) === $url;
		}

		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) && false !== filter_var( $url, FILTER_VALIDATE_URL );
	}
}
