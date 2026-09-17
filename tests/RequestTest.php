<?php
/**
 * Request tests.
 *
 * @package SugarCommerce\Http\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Http\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Http\Request;
use SugarCommerce\Network\TrustedProxies;

/**
 * Class RequestTest
 */
final class RequestTest extends TestCase {

	/**
	 * A request carrying whatever the case under test needs.
	 *
	 * @param array $overrides Constructor arguments to replace.
	 */
	private function request( array $overrides = array() ): Request {
		return new Request( ...( $overrides + array(
			'method' => 'GET',
			'path'   => '/',
			'host'   => 'example.com',
		) ) );
	}

	public function test_a_cookie_is_read_by_name(): void {
		$request = new Request( 'GET', '/', 'example.test', cookies: array( 'session' => 'abc' ) );

		$this->assertSame( 'abc', $request->cookie( 'session' ) );
	}

	public function test_a_missing_cookie_is_an_empty_string(): void {
		$this->assertSame( '', ( new Request( 'GET', '/', 'example.test' ) )->cookie( 'session' ) );
	}

	/**
	 * $_COOKIE is whatever the client sent, and a client can send an array.
	 */
	public function test_a_cookie_that_is_not_a_string_is_an_empty_string(): void {
		$request = new Request( 'GET', '/', 'example.test', cookies: array( 'session' => array( 'a' ) ) );

		$this->assertSame( '', $request->cookie( 'session' ) );
	}

	public function test_headers_are_read_case_insensitively(): void {
		$request = $this->request( array( 'headers' => array( 'x-csrf-token' => 'abc' ) ) );

		$this->assertSame( 'abc', $request->header( 'X-CSRF-Token' ) );
		$this->assertSame( '', $request->header( 'x-absent' ) );
	}

	public function test_json_decodes_the_body(): void {
		$this->assertSame( array( 'a' => 1 ), $this->request( array( 'body' => '{"a":1}' ) )->json() );
	}

	public function test_json_survives_a_body_that_is_not_json(): void {
		$this->assertSame( array(), $this->request( array( 'body' => 'not json' ) )->json() );
		$this->assertSame( array(), $this->request( array( 'body' => '"a string"' ) )->json() );
		$this->assertSame( array(), $this->request( array( 'body' => '' ) )->json() );
	}

	public function test_input_reads_the_form_body_first(): void {
		// The bug this exists to prevent: an HTML form posts form-encoded, and
		// a version that read only JSON handed every form a blank string.
		$request = $this->request( array(
			'form'  => array( 'name' => 'from form' ),
			'body'  => '{"name":"from json"}',
			'query' => array( 'name' => 'from query' ),
		) );

		$this->assertSame( 'from form', $request->input( 'name' ) );
	}

	public function test_input_falls_back_to_json_then_query(): void {
		$this->assertSame( 'j', $this->request( array(
			'body'  => '{"name":"j"}',
			'query' => array( 'name' => 'q' ),
		) )->input( 'name' ) );

		$this->assertSame( 'q', $this->request( array( 'query' => array( 'name' => 'q' ) ) )->input( 'name' ) );
	}

	public function test_input_returns_the_default_when_absent(): void {
		$this->assertSame( 'fallback', $this->request()->input( 'name', 'fallback' ) );
	}

	public function test_input_refuses_a_non_scalar(): void {
		// An array in the query string must not become the string "Array".
		$request = $this->request( array( 'query' => array( 'name' => array( 'a', 'b' ) ) ) );

		$this->assertSame( '', $request->input( 'name' ) );
	}

	public function test_url_prefixes_with_the_local_mount_point(): void {
		$this->assertSame( '/admin/orders', $this->request( array( 'prefix' => '/admin' ) )->url( '/orders' ) );
		$this->assertSame( '/orders', $this->request()->url( '/orders' ) );
	}

	public function test_https_is_read_from_the_forwarded_header(): void {
		// Behind a proxy the connection to this process is plain HTTP.
		$request = $this->request( array( 'headers' => array( 'x-forwarded-proto' => 'https' ) ) );

		$this->assertTrue( $request->is_https() );
	}

	public function test_https_is_read_from_the_connection_when_not_proxied(): void {
		$this->assertTrue( $this->request( array( 'secure' => true ) )->is_https() );
		$this->assertFalse( $this->request()->is_https() );
	}

	public function test_the_client_address_ignores_forwarding_headers_by_default(): void {
		$request = $this->request( array(
			'headers' => array( 'cf-connecting-ip' => '9.9.9.9' ),
			'remote'  => '203.0.113.10',
		) );

		$this->assertSame( '203.0.113.10', $request->ip() );
	}

	public function test_the_client_address_believes_a_trusted_proxy(): void {
		$request = $this->request( array(
			'headers' => array( 'x-forwarded-for' => '9.9.9.9' ),
			'remote'  => '127.0.0.1',
		) );

		$this->assertSame( '9.9.9.9', $request->ip( TrustedProxies::local() ) );
	}

	public function test_the_client_address_refuses_an_untrusted_forwarding_header(): void {
		// Anyone who reaches the origin directly could otherwise claim to be
		// any address they liked, poisoning every log and rate limit.
		$request = $this->request( array(
			'headers' => array( 'x-forwarded-for' => '9.9.9.9' ),
			'remote'  => '198.51.100.7',
		) );

		$this->assertSame( '198.51.100.7', $request->ip( TrustedProxies::local() ) );
	}

	/**
	 * An unchecked checkbox sends nothing, which input() cannot tell apart
	 * from a text field somebody cleared.
	 */
	public function test_has_distinguishes_absent_from_empty(): void {
		$request = $this->request( array( 'form' => array( 'cleared' => '' ) ) );

		$this->assertTrue( $request->has( 'cleared' ) );
		$this->assertFalse( $request->has( 'unchecked' ) );
		$this->assertSame( '', $request->input( 'cleared' ) );
		$this->assertSame( '', $request->input( 'unchecked' ) );
	}

	public function test_has_looks_everywhere_input_does(): void {
		$this->assertTrue( $this->request( array( 'query' => array( 'a' => '1' ) ) )->has( 'a' ) );
		$this->assertTrue( $this->request( array( 'body' => '{"a":null}' ) )->has( 'a' ) );
		$this->assertFalse( $this->request()->has( 'a' ) );
	}
}
