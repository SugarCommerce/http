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
	 * Register routes that all pass the same check first.
	 *
	 * The check runs before the handler, on every request that matches one
	 * of the group's routes. It returns a Response to refuse — a 401, a
	 * redirect to sign in — or anything else to let the request through, and
	 * whatever it returned is handed to the handler as its second argument,
	 * after the request and before the captured segments. So a guard that
	 * authenticates returns the caller, and no handler has to ask again.
	 *
	 * Without this, every handler behind the same check repeats it, and the
	 * one that forgets is the hole.
	 *
	 * ```php
	 * $router->group(
	 *     fn( Request $r ) => $auth->account( $r ) ?? Response::json( array( 'error' => 'Sign in' ), 401 ),
	 *     function ( Router $routes ): void {
	 *         $routes->get( '/api/files', fn( $r, string $account ) => $this->files( $account ) );
	 *         $routes->get( '/api/files/{id}', fn( $r, string $account, $id ) => $this->file( $account, $id ) );
	 *     }
	 * );
	 * ```
	 *
	 * @since 1.4.0
	 *
	 * @param callable $guard  Receives the request; returns a Response to
	 *                         refuse, or a value to pass on.
	 * @param callable $define Receives a Router to register the group on.
	 * @param string   $prefix Prepended to every pattern in the group.
	 *
	 * @return self
	 */
	public function group( callable $guard, callable $define, string $prefix = '' ): self {
		$inner = new self();

		$define( $inner );

		foreach ( $inner->routes as $route ) {
			$handler = $route['handler'];

			$this->add(
				$route['method'],
				$prefix . $route['pattern'],
				static function ( Request $request, string ...$params ) use ( $guard, $handler ): Response {
					$passed = $guard( $request );

					return $passed instanceof Response ? $passed : $handler( $request, $passed, ...$params );
				}
			);
		}

		return $this;
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
