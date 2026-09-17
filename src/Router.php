<?php
/**
 * Pattern routing.
 *
 * @package SugarCommerce\Http
 */

declare( strict_types=1 );

namespace SugarCommerce\Http;

/**
 * Class Router
 *
 * Routes are literal paths, or patterns with `{name}` segments that are passed
 * to the handler as arguments. First registered match wins, so order is the
 * only precedence rule there is.
 *
 * Small enough to read in one screen, which is the point: a routing library
 * with compiled regular expressions and route caching is more code than most
 * applications route with it.
 *
 * ```php
 * $router = new Router();
 * $router->get( '/orders/{id}', fn( $request, $id ) => $this->order( $id ) );
 *
 * $response = $router->dispatch( $request ) ?? Response::error( 'No such endpoint.', 404 );
 * ```
 *
 * @since 1.0.0
 */
final class Router {

	/** @var array<int, array{method: string, pattern: string, handler: callable}> */
	private array $routes = array();

	/**
	 * Register a GET route.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $pattern Literal path, or one with `{name}` segments.
	 * @param callable $handler Receives the request, then each captured segment.
	 *
	 * @return self
	 */
	public function get( string $pattern, callable $handler ): self {
		return $this->add( 'GET', $pattern, $handler );
	}

	/**
	 * Register a POST route.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $pattern Literal path, or one with `{name}` segments.
	 * @param callable $handler Receives the request, then each captured segment.
	 *
	 * @return self
	 */
	public function post( string $pattern, callable $handler ): self {
		return $this->add( 'POST', $pattern, $handler );
	}

	/**
	 * Register a route that answers any method. For endpoints where the verb
	 * carries no meaning — a webhook, a callback — and rejecting the wrong one
	 * only produces a support question.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $pattern Literal path, or one with `{name}` segments.
	 * @param callable $handler Receives the request, then each captured segment.
	 *
	 * @return self
	 */
	public function any( string $pattern, callable $handler ): self {
		return $this->add( '*', $pattern, $handler );
	}

	/**
	 * Append a route. Order is registration order, first match wins.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $method  Verb, or '*' for any.
	 * @param string   $pattern Literal path, or one with `{name}` segments.
	 * @param callable $handler Receives the request, then each captured segment.
	 *
	 * @return self
	 */
	private function add( string $method, string $pattern, callable $handler ): self {
		$this->routes[] = array( 'method' => $method, 'pattern' => $pattern, 'handler' => $handler );

		return $this;
	}

	/**
	 * Match and run, or return null when nothing matched.
	 *
	 * Null rather than a 404, so the caller decides what "nothing matched"
	 * looks like — an HTML page on one surface and a JSON body on another.
	 *
	 * @since 1.0.0
	 *
	 * @param Request $request The request.
	 *
	 * @return Response|null
	 */
	public function dispatch( Request $request ): ?Response {
		foreach ( $this->routes as $route ) {
			if ( '*' !== $route['method'] && $route['method'] !== $request->method ) {
				continue;
			}

			$params = $this->match( $route['pattern'], $request->path );

			if ( null !== $params ) {
				return ( $route['handler'] )( $request, ...$params );
			}
		}

		return null;
	}

	/**
	 * Compare a pattern to a path, returning captured segments.
	 *
	 * @since 1.0.0
	 *
	 * @param string $pattern The route pattern.
	 * @param string $path    The request path.
	 *
	 * @return array<int, string>|null Captured segments, or null on no match.
	 */
	private function match( string $pattern, string $path ): ?array {
		if ( ! str_contains( $pattern, '{' ) ) {
			return $pattern === $path ? array() : null;
		}

		$pattern_parts = explode( '/', trim( $pattern, '/' ) );
		$path_parts    = explode( '/', trim( $path, '/' ) );

		if ( count( $pattern_parts ) !== count( $path_parts ) ) {
			return null;
		}

		$params = array();

		foreach ( $pattern_parts as $i => $part ) {
			if ( str_starts_with( $part, '{' ) ) {
				if ( '' === $path_parts[ $i ] ) {
					return null;
				}

				$params[] = $path_parts[ $i ];
				continue;
			}

			if ( $part !== $path_parts[ $i ] ) {
				return null;
			}
		}

		return $params;
	}
}
