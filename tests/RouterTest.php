<?php
/**
 * Router and View tests.
 *
 * @package SugarCommerce\Http\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Http\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Http\{Request, Response, Router, View};

/**
 * Class RouterTest
 */
final class RouterTest extends TestCase {

	/**
	 * A request for one method and path.
	 */
	private function request( string $method, string $path ): Request {
		return new Request( method: $method, path: $path, host: 'example.com' );
	}

	public function test_a_literal_path_matches(): void {
		$router = new Router();
		$router->get( '/orders', fn() => Response::text( 'ok' ) );

		$this->assertSame( 'ok', $router->dispatch( $this->request( 'GET', '/orders' ) )?->body );
	}

	public function test_an_unmatched_path_returns_null(): void {
		$router = new Router();
		$router->get( '/orders', fn() => Response::text( 'ok' ) );

		$this->assertNull( $router->dispatch( $this->request( 'GET', '/nope' ) ) );
	}

	public function test_the_method_has_to_match(): void {
		$router = new Router();
		$router->get( '/orders', fn() => Response::text( 'ok' ) );

		$this->assertNull( $router->dispatch( $this->request( 'POST', '/orders' ) ) );
	}

	public function test_any_matches_every_method(): void {
		$router = new Router();
		$router->any( '/hook', fn() => Response::text( 'ok' ) );

		$this->assertSame( 'ok', $router->dispatch( $this->request( 'PUT', '/hook' ) )?->body );
		$this->assertSame( 'ok', $router->dispatch( $this->request( 'GET', '/hook' ) )?->body );
	}

	public function test_captured_segments_are_passed_to_the_handler(): void {
		$router = new Router();
		$router->get( '/orders/{id}/files/{file}', fn( $r, $id, $file ) => Response::text( "{$id}:{$file}" ) );

		$this->assertSame( '7:cover.png', $router->dispatch( $this->request( 'GET', '/orders/7/files/cover.png' ) )?->body );
	}

	public function test_a_pattern_needs_the_same_number_of_segments(): void {
		$router = new Router();
		$router->get( '/orders/{id}', fn( $r, $id ) => Response::text( $id ) );

		$this->assertNull( $router->dispatch( $this->request( 'GET', '/orders/7/extra' ) ) );
		$this->assertNull( $router->dispatch( $this->request( 'GET', '/orders' ) ) );
	}

	public function test_an_empty_segment_does_not_capture(): void {
		$router = new Router();
		$router->get( '/orders/{id}', fn( $r, $id ) => Response::text( $id ) );

		$this->assertNull( $router->dispatch( $this->request( 'GET', '/orders/' ) ) );
	}

	public function test_the_first_registered_match_wins(): void {
		$router = new Router();
		$router->get( '/orders/{id}', fn() => Response::text( 'pattern' ) );
		$router->get( '/orders/new', fn() => Response::text( 'literal' ) );

		$this->assertSame( 'pattern', $router->dispatch( $this->request( 'GET', '/orders/new' ) )?->body );
	}

	public function test_the_request_reaches_the_handler(): void {
		$router = new Router();
		$router->get( '/here', fn( Request $r ) => Response::text( $r->path ) );

		$this->assertSame( '/here', $router->dispatch( $this->request( 'GET', '/here' ) )?->body );
	}

	/* ── groups ──────────────────────────────────────────────────────── */

	public function test_a_group_hands_its_guards_answer_to_the_handler(): void {
		$router = ( new Router() )->group(
			static fn( Request $request ): string => 'acct_1',
			static function ( Router $routes ): void {
				$routes->get( '/files/{id}', static fn( Request $r, string $account, string $id ): Response => Response::html( $account . ':' . $id ) );
			}
		);

		$this->assertSame( 'acct_1:42', $router->dispatch( $this->request( 'GET', '/files/42' ) )?->body );
	}

	/**
	 * The point of a group: a handler that forgot to check cannot exist.
	 */
	public function test_a_refusing_guard_stops_every_route_in_the_group(): void {
		$reached = false;
		$router  = ( new Router() )->group(
			static fn( Request $request ): Response => new Response( 401 ),
			static function ( Router $routes ) use ( &$reached ): void {
				$routes->post( '/files', static function () use ( &$reached ): Response {
					$reached = true;

					return new Response( 200 );
				} );
			}
		);

		$this->assertSame( 401, $router->dispatch( $this->request( 'POST', '/files' ) )?->status );
		$this->assertFalse( $reached );
	}

	public function test_the_guard_runs_only_for_a_matching_route(): void {
		$runs   = 0;
		$router = ( new Router() )->group(
			static function () use ( &$runs ): string {
				++$runs;

				return 'x';
			},
			static fn( Router $routes ) => $routes->get( '/inside', static fn(): Response => new Response( 200 ) )
		);

		$router->dispatch( $this->request( 'GET', '/outside' ) );

		$this->assertSame( 0, $runs );
	}

	public function test_a_group_prefix_applies_to_its_patterns(): void {
		$router = ( new Router() )->group(
			static fn(): string => 'x',
			static fn( Router $routes ) => $routes->get( '/files', static fn(): Response => new Response( 204 ) ),
			'/api'
		);

		$this->assertSame( 204, $router->dispatch( $this->request( 'GET', '/api/files' ) )?->status );
		$this->assertNull( $router->dispatch( $this->request( 'GET', '/files' ) ) );
	}

	public function test_a_constraint_has_to_match_the_whole_segment(): void {
		$router = new Router();
		$router->get( '/orders/{id:\d+}', fn( $r, string $id ) => Response::text( 'id ' . $id ) );

		$this->assertSame( 'id 42', $router->dispatch( $this->request( 'GET', '/orders/42' ) )?->body );

		foreach ( array( '/orders/abc', '/orders/42a', '/orders/a42', '/orders/-1', '/orders/4%0A' ) as $path ) {
			$this->assertNull( $router->dispatch( $this->request( 'GET', $path ) ), $path );
		}
	}

	public function test_a_refused_constraint_falls_through_to_the_next_route(): void {
		$router = new Router();
		$router->get( '/orders/{id:\d+}', fn() => Response::text( 'by id' ) );
		$router->get( '/orders/{ref:[A-Za-z0-9_-]{1,64}}', fn( $r, string $ref ) => Response::text( 'by ref ' . $ref ) );

		$this->assertSame( 'by id', $router->dispatch( $this->request( 'GET', '/orders/7' ) )?->body );
		$this->assertSame( 'by ref MANUAL-1A', $router->dispatch( $this->request( 'GET', '/orders/MANUAL-1A' ) )?->body );
		$this->assertNull( $router->dispatch( $this->request( 'GET', '/orders/' . str_repeat( 'a', 65 ) ) ) );
		$this->assertNull( $router->dispatch( $this->request( 'GET', '/orders/a.b' ) ) );
	}

	public function test_constraints_and_plain_segments_mix(): void {
		$router = new Router();
		$router->post( '/r/{token:[A-Za-z0-9_.-]{1,512}}/{n}', fn( $r, string $t, string $n ) => Response::text( $t . ':' . $n ) );

		$this->assertSame( 'a.b-c:x', $router->dispatch( $this->request( 'POST', '/r/a.b-c/x' ) )?->body );
		$this->assertNull( $router->dispatch( $this->request( 'POST', '/r/a~b/x' ) ) );
	}

	public function test_a_constraint_with_a_hash_is_literal(): void {
		$router = new Router();
		$router->get( '/tag/{t:#[a-z]+}', fn( $r, string $t ) => Response::text( $t ) );

		$this->assertSame( '#php', $router->dispatch( $this->request( 'GET', '/tag/#php' ) )?->body );
	}

	public function test_a_constraint_inside_a_group_applies(): void {
		$router = new Router();
		$router->group( fn() => 'acct', function ( Router $r ): void {
			$r->get( '/files/{id:\d+}', fn( $req, string $acct, string $id ) => Response::text( $acct . $id ) );
		}, '/api' );

		$this->assertSame( 'acct9', $router->dispatch( $this->request( 'GET', '/api/files/9' ) )?->body );
		$this->assertNull( $router->dispatch( $this->request( 'GET', '/api/files/x' ) ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function broken_patterns(): array {
		return array(
			'does not compile' => array( '/orders/{id:[0-9}' ),
			'empty'            => array( '/orders/{id:}' ),
			'contains a slash' => array( '/orders/{id:a/b}' ),
			'unclosed'         => array( '/orders/{id' ),
		);
	}

	#[DataProvider( 'broken_patterns' )]
	public function test_a_broken_pattern_is_refused_when_registered( string $pattern ): void {
		$this->expectException( \InvalidArgumentException::class );

		( new Router() )->get( $pattern, fn() => null );
	}

	public function test_a_view_renders_inside_a_layout(): void {
		$view = new View( __DIR__ . '/fixtures' );

		$this->assertSame( '<layout><p>Jordan</p>\n</layout>\n', str_replace( "\n", '\n', $view->render( 'page', array( 'name' => 'Jordan' ) ) ) );
	}

	public function test_a_view_can_render_without_a_layout(): void {
		$view = new View( __DIR__ . '/fixtures' );

		$this->assertSame( '<p>Jordan</p>', trim( $view->render( 'page', array( 'name' => 'Jordan' ), '' ) ) );
	}

	public function test_a_view_escapes_through_the_helper(): void {
		$view = new View( __DIR__ . '/fixtures' );

		$this->assertStringNotContainsString( '<script>', $view->render( 'page', array( 'name' => '<script>' ), '' ) );
	}

	public function test_a_missing_template_is_an_error_not_a_blank_page(): void {
		$this->expectException( \RuntimeException::class );

		( new View( __DIR__ . '/fixtures' ) )->render( 'nope', array(), '' );
	}

	/**
	 * A template is code: a name that climbs out of the root would run any
	 * .php file on the disk.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function escapes(): array {
		return array(
			'a parent segment'     => array( '../RouterTest' ),
			'a nested parent'      => array( 'fixtures/../../src/View' ),
			'a backslash'          => array( '..\\RouterTest' ),
			'a trailing parent'    => array( 'fixtures/..' ),
			'a NUL byte'           => array( "page\0" ),
		);
	}

	#[DataProvider( 'escapes' )]
	public function test_a_template_name_cannot_leave_the_root( string $template ): void {
		$this->expectException( \InvalidArgumentException::class );

		( new View( __DIR__ . '/fixtures' ) )->render( $template, array(), '' );
	}

	public function test_a_layout_name_cannot_leave_the_root(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new View( __DIR__ . '/fixtures' ) )->render( 'page', array( 'name' => 'x' ), '../RouterTest' );
	}

	/**
	 * The layout prints $content raw. A `content` key in the data — which
	 * may be anything a handler was handed — must not take its place.
	 */
	public function test_data_cannot_replace_the_rendered_content_in_the_layout(): void {
		$html = ( new View( __DIR__ . '/fixtures' ) )->render( 'page', array( 'name' => 'Jordan', 'content' => '<script>alert(1)</script>' ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '<p>Jordan</p>', $html );
	}

	public function test_the_escape_helper_survives_a_value_that_is_not_text(): void {
		$html = ( new View( __DIR__ . '/fixtures' ) )->render( 'page', array( 'name' => array( 'a' ) ), '' );

		$this->assertSame( '<p></p>', trim( $html ) );
	}

	public function test_data_named_like_the_renderers_own_variables_reaches_the_template(): void {
		$view = new View( __DIR__ . '/fixtures' );

		$this->assertSame( 'p|t|d|none', trim( $view->render( 'scoped', array( 'path' => 'p', 'template' => 't', 'data' => 'd' ), '' ) ) );
	}

	public function test_a_throwing_template_leaks_no_output_or_buffer(): void {
		$level = ob_get_level();

		try {
			( new View( __DIR__ . '/fixtures' ) )->render( 'throws', array(), '' );
			$this->fail( 'expected the template to throw' );
		} catch ( \LogicException $e ) {
			$this->assertSame( 'broken template', $e->getMessage() );
		}

		$this->assertSame( $level, ob_get_level() );
	}

	public function test_a_handler_must_return_a_response(): void {
		$router = ( new Router() )->get( '/x', fn() => 'a string' );

		$this->expectException( \UnexpectedValueException::class );

		$router->dispatch( $this->request( 'GET', '/x' ) );
	}
}
