<?php
/**
 * Router and View tests.
 *
 * @package SugarCommerce\Http\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Http\Tests;

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
}
