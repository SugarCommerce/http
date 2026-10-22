<?php
/**
 * Url tests.
 *
 * @package SugarCommerce\Http\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Http\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Http\Url;

/**
 * Class UrlTest
 */
final class UrlTest extends TestCase {

	public function test_parameters_become_a_query_string(): void {
		$this->assertSame( '/admin/customers?q=alf&status=blocked', Url::to( '/admin/customers', array( 'q' => 'alf', 'status' => 'blocked' ) ) );
	}

	public function test_empty_parameters_are_dropped(): void {
		$this->assertSame( '/admin/customers?status=active', Url::to( '/admin/customers', array( 'q' => '', 'status' => 'active', 'id' => null ) ) );
	}

	public function test_nothing_left_is_the_path_alone(): void {
		$this->assertSame( '/admin/customers', Url::to( '/admin/customers' ) );
		$this->assertSame( '/admin/customers', Url::to( '/admin/customers', array( 'q' => '', 'id' => null ) ) );
	}

	public function test_zero_is_kept_unless_asked(): void {
		$this->assertSame( '/orders?customer=0&page=0', Url::to( '/orders', array( 'customer' => 0, 'page' => '0' ) ) );
		$this->assertSame( '/orders?q=x', Url::to( '/orders', array( 'customer' => 0, 'page' => '0', 'q' => 'x' ), true ) );
	}

	public function test_false_is_not_empty(): void {
		$this->assertSame( '/orders?paid=0', Url::to( '/orders', array( 'paid' => false ) ) );
	}

	public function test_values_are_encoded(): void {
		$this->assertSame( '/search?q=a+b%26c%3Dd', Url::to( '/search', array( 'q' => 'a b&c=d' ) ) );
	}

	public function test_an_existing_query_string_and_fragment_are_kept(): void {
		$this->assertSame( '/orders?sort=date&q=x', Url::to( '/orders?sort=date', array( 'q' => 'x' ) ) );
		$this->assertSame( '/orders?q=x', Url::to( '/orders?', array( 'q' => 'x' ) ) );
		$this->assertSame( '/orders?q=x#list', Url::to( '/orders#list', array( 'q' => 'x' ) ) );
	}

	public function test_it_matches_a_filter_that_drops_null_empty_and_int_zero(): void {
		$params = array( 'q' => 'alf', 'status' => '', 'page' => 0, 'id' => null, 'sort' => 'name' );
		$clean  = array_filter( $params, static fn( $v ) => null !== $v && '' !== $v && 0 !== $v );

		$this->assertSame( '/admin/customers?' . http_build_query( $clean ), Url::to( '/admin/customers', $params, true ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function not_local(): array {
		return array(
			'empty'             => array( '' ),
			'relative'          => array( 'orders' ),
			'protocol-relative' => array( '//evil.example/x' ),
			'backslash'         => array( '/\\evil.example' ),
			'tab'               => array( "/\t/evil.example" ),
			'absolute'          => array( 'https://evil.example/' ),
			'javascript'        => array( 'javascript:alert(1)' ),
		);
	}

	#[DataProvider( 'not_local' )]
	public function test_anything_but_a_local_path_is_refused( string $path ): void {
		$this->expectException( \InvalidArgumentException::class );

		Url::to( $path, array( 'q' => 'x' ) );
	}
}
