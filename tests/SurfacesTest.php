<?php
/**
 * Surfaces tests.
 *
 * @package SugarCommerce\Http\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Http\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Http\Surfaces;

/**
 * Class SurfacesTest
 */
final class SurfacesTest extends TestCase {

	private Surfaces $surfaces;

	protected function setUp(): void {
		$this->surfaces = new Surfaces( array( 'api', 'admin', 'app' ), default: 'site', keep_prefix: array( 'api' ) );
	}

	public function test_a_subdomain_picks_the_surface(): void {
		$resolved = $this->surfaces->resolve( 'admin.example.com', '/orders' );

		$this->assertSame( 'admin', $resolved['surface'] );
		$this->assertSame( '/orders', $resolved['path'] );
		$this->assertSame( '', $resolved['prefix'] );
	}

	public function test_a_path_prefix_picks_the_surface_locally(): void {
		$resolved = $this->surfaces->resolve( 'localhost', '/admin/orders' );

		$this->assertSame( 'admin', $resolved['surface'] );
		$this->assertSame( '/orders', $resolved['path'] );
		$this->assertSame( '/admin', $resolved['prefix'] );
	}

	public function test_both_routes_arrive_at_the_same_path(): void {
		// The whole point: one routing table for two deployments.
		$this->assertSame(
			$this->surfaces->resolve( 'admin.example.com', '/orders' )['path'],
			$this->surfaces->resolve( 'localhost', '/admin/orders' )['path'],
		);
	}

	public function test_an_unmatched_request_falls_to_the_default(): void {
		$resolved = $this->surfaces->resolve( 'example.com', '/pricing' );

		$this->assertSame( 'site', $resolved['surface'] );
		$this->assertSame( '/pricing', $resolved['path'] );
	}

	public function test_a_kept_prefix_survives_on_a_subdomain(): void {
		$resolved = $this->surfaces->resolve( 'api.example.com', '/orders' );

		$this->assertSame( 'api', $resolved['surface'] );
		$this->assertSame( '/api/orders', $resolved['path'] );
	}

	public function test_a_kept_prefix_is_not_doubled_locally(): void {
		$resolved = $this->surfaces->resolve( 'localhost', '/api/orders' );

		$this->assertSame( 'api', $resolved['surface'] );
		$this->assertSame( '/api/orders', $resolved['path'] );
		$this->assertSame( '/api', $resolved['prefix'] );
	}

	public function test_a_kept_prefix_survives_a_bare_root(): void {
		$this->assertSame( '/api', $this->surfaces->resolve( 'api.example.com', '/' )['path'] );
		$this->assertSame( '/api', $this->surfaces->resolve( 'localhost', '/api' )['path'] );
	}

	public function test_the_root_resolves(): void {
		$resolved = $this->surfaces->resolve( 'example.com', '/' );

		$this->assertSame( 'site', $resolved['surface'] );
		$this->assertSame( '/', $resolved['path'] );
	}

	public function test_the_host_is_matched_case_insensitively(): void {
		$this->assertSame( 'admin', $this->surfaces->resolve( 'ADMIN.example.com', '/' )['surface'] );
	}

	public function test_a_path_segment_that_merely_starts_the_same_is_not_a_surface(): void {
		$resolved = $this->surfaces->resolve( 'example.com', '/administrators' );

		$this->assertSame( 'site', $resolved['surface'] );
		$this->assertSame( '/administrators', $resolved['path'] );
	}

	public function test_names_are_reported(): void {
		$this->assertSame( array( 'api', 'admin', 'app' ), $this->surfaces->names() );
	}

	/**
	 * Where a hostname is a boundary, `www.example.com/admin` must not reach
	 * the admin surface around whatever guards `admin.example.com`.
	 */
	public function test_path_prefixes_can_be_confined_to_named_hosts(): void {
		$surfaces = new Surfaces( array( 'api', 'admin' ), default: 'site', path_hosts: array( 'localhost' ) );

		$this->assertSame( 'site', $surfaces->resolve( 'www.example.com', '/admin/orders' )['surface'] );
		$this->assertSame( '/admin/orders', $surfaces->resolve( 'www.example.com', '/admin/orders' )['path'] );
		$this->assertSame( 'admin', $surfaces->resolve( 'localhost:8000', '/admin/orders' )['surface'] );
		$this->assertSame( 'admin', $surfaces->resolve( 'admin.example.com', '/orders' )['surface'] );
	}
}
