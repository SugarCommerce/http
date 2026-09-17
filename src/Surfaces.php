<?php
/**
 * Splitting one application into several addressable surfaces.
 *
 * @package SugarCommerce\Http
 */

declare( strict_types=1 );

namespace SugarCommerce\Http;

/**
 * Class Surfaces
 *
 * One codebase serving several audiences — a public site, a JSON API, an admin
 * panel — usually wants them on separate hostnames in production and on one
 * port in development. Writing that twice is how the two drift until something
 * only reproduces on the server.
 *
 * This resolves both from the same declaration: by subdomain when the host has
 * one that matches, and otherwise by first path segment.
 *
 * ```php
 * $surfaces = new Surfaces( array( 'api', 'admin' ), default: 'site' );
 *
 * $surfaces->resolve( 'api.example.com', '/orders' );  // api,   /orders,  ''
 * $surfaces->resolve( 'localhost',       '/api/orders' ); // api, /orders, '/api'
 * $surfaces->resolve( 'localhost',       '/pricing' );    // site, /pricing, ''
 * ```
 *
 * The prefix comes back separately so a surface can rebuild its own links:
 * a redirect written as `/orders` has to become `/api/orders` locally, or it
 * lands on a different surface entirely.
 *
 * @since 1.0.0
 */
final readonly class Surfaces {

	/**
	 * @param array<int, string> $names       Surface names. Each is both a
	 *                                        subdomain and a path prefix.
	 * @param string             $default     Surface for a request matching
	 *                                        none of them — the public one.
	 * @param array<int, string> $keep_prefix Surfaces whose prefix stays in
	 *                                        the path after resolving. For
	 *                                        an API whose published routes
	 *                                        already include `/api`, so that
	 *                                        clients and CSP entries do not
	 *                                        have to differ by environment.
	 */
	public function __construct(
		private array $names,
		private string $default = '',
		private array $keep_prefix = array(),
	) {}

	/**
	 * The surface names.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string>
	 */
	public function names(): array {
		return $this->names;
	}

	/**
	 * Which surface a request is for.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host Host header, any case.
	 * @param string $path Request path.
	 *
	 * @return array{surface: string, path: string, prefix: string}
	 */
	public function resolve( string $host, string $path ): array {
		$path      = '/' . trim( $path, '/' );
		$subdomain = explode( '.', strtolower( $host ) )[0];

		if ( in_array( $subdomain, $this->names, true ) ) {
			return array(
				'surface' => $subdomain,
				'path'    => $this->restore( $subdomain, $path ),
				'prefix'  => '',
			);
		}

		$first = explode( '/', trim( $path, '/' ) )[0] ?? '';

		if ( '' !== $first && in_array( $first, $this->names, true ) ) {
			$remainder = '/' . trim( substr( $path, strlen( $first ) + 1 ), '/' );

			return array(
				'surface' => $first,
				'path'    => $this->restore( $first, $remainder ),
				'prefix'  => '/' . $first,
			);
		}

		return array(
			'surface' => $this->default,
			'path'    => $path,
			'prefix'  => '',
		);
	}

	/**
	 * Put the prefix back for a surface that keeps it.
	 *
	 * Runs for the subdomain case too, where it was never there to begin
	 * with — which is the point. `api.example.com/orders` and
	 * `localhost/api/orders` both end up at `/api/orders`, so one routing
	 * table serves both.
	 *
	 * @since 1.0.0
	 *
	 * @param string $surface Resolved surface.
	 * @param string $path    Path with the prefix removed.
	 *
	 * @return string
	 */
	private function restore( string $surface, string $path ): string {
		if ( ! in_array( $surface, $this->keep_prefix, true ) ) {
			return $path;
		}

		$prefix = '/' . $surface;

		if ( str_starts_with( $path, $prefix . '/' ) || $path === $prefix ) {
			return $path;
		}

		return $prefix . ( '/' === $path ? '' : $path );
	}
}
