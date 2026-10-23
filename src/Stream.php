<?php
/**
 * A response sent as it is produced.
 *
 * @package   SugarCommerce\Http
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.13.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Http;

/**
 * Class Stream
 *
 * For a body too big to hold — a file relayed from storage, an archive
 * built as it goes — where Response, which carries its body as a string,
 * would need all of it in memory first.
 *
 * The headers go out with the first byte, not before. A relay whose source
 * fails before sending anything can still answer with an error status; one
 * that sent `200` and the file's name first has promised a download it then
 * cuts short. Output buffers are dropped when it starts, and nginx is told
 * not to buffer either (`X-Accel-Buffering: no`), so bytes reach the client
 * as they are written instead of after the last one.
 *
 * ```php
 * $out      = new Stream( array( 'Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $size ) );
 * $relayed  = $http->stream( $url, $out->writer() );
 *
 * if ( ! $out->started() ) {
 *     $relayed->ok ? $out->start() : http_response_code( 502 );   // empty file, or nothing came
 * }
 * ```
 *
 * @since 1.13.0
 */
final class Stream {

	/**
	 * Whether the status and headers have gone out.
	 */
	private bool $started = false;

	/**
	 * @since 1.13.0
	 *
	 * @param array<string, string> $headers  Headers to send with the first byte.
	 * @param int                   $status   Status to send with them.
	 * @param bool                  $unbuffer Drop PHP's output buffers when
	 *                                        starting. Tests pass false.
	 *
	 * @throws \UnexpectedValueException When a header name is not a token or
	 *                                   a value carries a line break — refused
	 *                                   here, before anything is sent.
	 */
	public function __construct(
		private readonly array $headers,
		private readonly int $status = 200,
		private readonly bool $unbuffer = true,
	) {
		foreach ( $this->lines() as $line ) {
			[ $name, $value ] = explode( ': ', $line, 2 );

			if ( 1 !== preg_match( '/^[A-Za-z0-9!#$%&\'*+\-.^_`|~]+$/D', $name ) || 1 === preg_match( '/[\x00-\x08\x0A-\x1F\x7F]/', $value ) ) {
				throw new \UnexpectedValueException( 'Refusing to stream with a malformed header: ' . json_encode( $name ) );
			}
		}
	}

	/**
	 * Send the status and headers now, if they have not gone. For an empty
	 * body, or one about to be written in a loop.
	 *
	 * @since 1.13.0
	 *
	 * @return void
	 */
	public function start(): void {
		if ( $this->started ) {
			return;
		}

		$this->started = true;

		if ( $this->unbuffer ) {
			while ( ob_get_level() > 0 ) {
				ob_end_flush();
			}
		}

		if ( ! headers_sent() ) {
			http_response_code( $this->status );

			foreach ( $this->lines() as $line ) {
				header( $line, ! str_starts_with( strtolower( $line ), 'set-cookie:' ) );
			}
		}
	}

	/**
	 * Send some of the body, starting the response first if need be.
	 *
	 * @since 1.13.0
	 *
	 * @param string $bytes Body bytes.
	 *
	 * @return void
	 */
	public function write( string $bytes ): void {
		$this->start();

		echo $bytes;
		flush();
	}

	/**
	 * write() as a closure, for a client's or an archive's sink.
	 *
	 * @since 1.13.0
	 *
	 * @return \Closure(string): void
	 */
	public function writer(): \Closure {
		return $this->write( ... );
	}

	/**
	 * Whether anything has been sent — after which the status cannot change.
	 *
	 * @since 1.13.0
	 *
	 * @return bool
	 */
	public function started(): bool {
		return $this->started;
	}

	/**
	 * The header lines start() sends, buffering off included.
	 *
	 * @since 1.13.0
	 *
	 * @return list<string>
	 */
	public function lines(): array {
		$lines = array();

		foreach ( $this->headers + array( 'X-Accel-Buffering' => 'no' ) as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		return $lines;
	}
}
