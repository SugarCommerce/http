<?php
/**
 * Request tests.
 *
 * @package SugarCommerce\Http\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Http\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Http\{Request, Surfaces};
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

	/* ─── The path as sent ─────────────────────────────────────────── */

	/**
	 * Routing that happens before surfaces — a custom domain — needs the
	 * path the client asked for, not the one a surface prefix was taken off.
	 */
	public function test_capture_keeps_the_path_as_the_client_sent_it(): void {
		$server = $_SERVER;

		$_SERVER['HTTP_HOST']   = 'localhost';
		$_SERVER['REQUEST_URI'] = '/admin/orders?page=2';

		try {
			$request = Request::capture( new Surfaces( array( 'admin' ), default: 'site' ) );
		} finally {
			$_SERVER = $server;
		}

		$this->assertSame( '/orders', $request->path );
		$this->assertSame( '/admin/orders', $request->uri_path );
	}

	public function test_a_request_built_by_hand_has_no_uri_path(): void {
		$this->assertSame( '', $this->request()->uri_path );
	}

	/* ─── Bearer tokens ────────────────────────────────────────────── */

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function authorizations(): array {
		return array(
			'bearer'             => array( 'Bearer abc.def', 'abc.def' ),
			'any case'           => array( 'bearer abc', 'abc' ),
			'padded'             => array( '  Bearer   abc  ', 'abc' ),
			'absent'             => array( '', '' ),
			'basic'              => array( 'Basic dXNlcjpwYXNz', '' ),
			'bare scheme'        => array( 'Bearer', '' ),
			'two tokens'         => array( 'Bearer abc def', '' ),
			'token without one'  => array( 'abc', '' ),
		);
	}

	#[DataProvider( 'authorizations' )]
	public function test_bearer_reads_one_token_from_the_header( string $header, string $expected ): void {
		$this->assertSame( $expected, $this->request( array( 'headers' => array( 'authorization' => $header ) ) )->bearer() );
	}

	/**
	 * A key in a URL ends up in logs, and one in a body is one a form on
	 * another site could be made to send.
	 */
	public function test_bearer_never_reads_the_query_or_the_body(): void {
		$request = $this->request( array(
			'query' => array( 'authorization' => 'Bearer abc' ),
			'form'  => array( 'authorization' => 'Bearer abc' ),
		) );

		$this->assertSame( '', $request->bearer() );
	}

	/* ─── Cookie paths ─────────────────────────────────────────────── */

	public function test_a_surface_with_its_own_hostname_sets_cookies_at_the_root(): void {
		$this->assertSame( '/', $this->request()->cookie_path() );
	}

	/**
	 * Otherwise one surface's session would ride along on every other's
	 * requests.
	 */
	public function test_a_prefixed_surface_keeps_its_cookies_under_its_prefix(): void {
		$this->assertSame( '/admin', $this->request( array( 'prefix' => '/admin' ) )->cookie_path() );
	}

	/* ─── $_SERVER shape ───────────────────────────────────────────── */

	public function test_server_vars_has_the_address_and_the_headers(): void {
		$request = $this->request( array(
			'remote'  => '203.0.113.9',
			'headers' => array( 'cf-ipcountry' => 'DE', 'x-forwarded-for' => '198.51.100.1' ),
		) );

		$this->assertSame(
			array( 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_IPCOUNTRY' => 'DE', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1' ),
			$request->server_vars()
		);
	}

	/* ─── Country ──────────────────────────────────────────────────── */

	public function test_country_is_read_from_cloudflare(): void {
		$request = $this->request( array( 'remote' => '173.245.48.1', 'headers' => array( 'cf-ipcountry' => 'de' ) ) );

		$this->assertSame( 'DE', $request->country( TrustedProxies::cloudflare() ) );
	}

	/**
	 * Otherwise a shopper picks their own prices by typing a header.
	 */
	public function test_country_from_anywhere_but_the_proxy_is_not_believed(): void {
		$request = $this->request( array( 'remote' => '203.0.113.9', 'headers' => array( 'cf-ipcountry' => 'DE' ) ) );

		$this->assertSame( '', $request->country( TrustedProxies::cloudflare() ) );
		$this->assertSame( '', $request->country( TrustedProxies::none() ) );
	}

	public function test_country_is_empty_when_cloudflare_did_not_say(): void {
		$request = $this->request( array( 'remote' => '173.245.48.1', 'headers' => array( 'cf-ipcountry' => 'nope' ) ) );

		$this->assertSame( '', $request->country( TrustedProxies::cloudflare() ) );
		$this->assertSame( '', $this->request( array( 'remote' => '173.245.48.1' ) )->country( TrustedProxies::cloudflare() ) );
	}

	/* ─── This machine ─────────────────────────────────────────────── */

	public function test_loopback_with_nothing_forwarded_is_local(): void {
		$this->assertTrue( $this->request( array( 'remote' => '127.0.0.1' ) )->is_local() );
		$this->assertTrue( $this->request( array( 'remote' => '::1' ) )->is_local() );
	}

	/**
	 * "We could not tell" is not "this machine".
	 */
	public function test_an_unknown_address_is_not_local(): void {
		$this->assertFalse( $this->request()->is_local() );
		$this->assertFalse( $this->request( array( 'remote' => '' ) )->is_local( array( '127.0.0.1', '' ) ) );
	}

	/**
	 * A client can put 127.0.0.1 in X-Forwarded-For, and an edge passes it on.
	 */
	public function test_a_remote_address_claiming_loopback_is_not_local(): void {
		$request = $this->request( array(
			'remote'  => '173.245.48.1',
			'headers' => array( 'x-forwarded-for' => '127.0.0.1', 'cf-connecting-ip' => '127.0.0.1' ),
		) );

		$this->assertFalse( $request->is_local() );
	}

	/**
	 * A tunnel or reverse proxy on the same box arrives from loopback, and
	 * carries a forwarding header saying so.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function forwarded(): array {
		return array_combine( Request::FORWARDED, array_map( static fn ( string $h ): array => array( $h ), Request::FORWARDED ) );
	}

	#[DataProvider( 'forwarded' )]
	public function test_loopback_that_was_forwarded_is_not_local( string $header ): void {
		$request = $this->request( array( 'remote' => '127.0.0.1', 'headers' => array( $header => 'x' ) ) );

		$this->assertFalse( $request->is_local() );
	}

	public function test_what_counts_as_this_machine_can_be_named(): void {
		$this->assertTrue( $this->request( array( 'remote' => '10.0.0.5' ) )->is_local( array( '10.0.0.5' ) ) );
		$this->assertFalse( $this->request( array( 'remote' => '127.0.0.1' ) )->is_local( array( '10.0.0.5' ) ) );
	}

	/**
	 * A client reaching the process directly can send X-Forwarded-Proto
	 * itself; with the proxies named, only theirs is believed.
	 */
	public function test_forwarded_https_is_believed_only_from_a_named_proxy(): void {
		$headers = array( 'x-forwarded-proto' => 'https' );

		$this->assertFalse( $this->request( array( 'headers' => $headers, 'remote' => '198.51.100.7' ) )->is_https( TrustedProxies::local() ) );
		$this->assertTrue( $this->request( array( 'headers' => $headers, 'remote' => '127.0.0.1' ) )->is_https( TrustedProxies::local() ) );
		$this->assertTrue( $this->request( array( 'secure' => true, 'remote' => '198.51.100.7' ) )->is_https( TrustedProxies::local() ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function foreign_targets(): array {
		return array(
			'protocol-relative'       => array( '//evil.example/x' ),
			'backslash'               => array( '/\\evil.example' ),
			'tab the browser strips'  => array( "/\t/evil.example" ),
			'newline'                 => array( "/\n/evil.example" ),
			'absolute'                => array( 'https://evil.example/' ),
			'scheme'                  => array( 'javascript:alert(1)' ),
			'relative'                => array( 'orders' ),
			'empty'                   => array( '' ),
		);
	}

	#[DataProvider( 'foreign_targets' )]
	public function test_a_target_that_leaves_the_site_becomes_the_fallback( string $target ): void {
		$this->assertSame( '/home', Request::local_path( $target, '/home' ) );
	}

	public function test_a_local_path_is_kept(): void {
		$this->assertSame( '/orders/42?tab=files#top', Request::local_path( '/orders/42?tab=files#top' ) );
		$this->assertSame( '/', Request::local_path( '/' ) );
	}

	/* ─── wants_html() ─────────────────────────────────────────────── */

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function accepts(): array {
		return array(
			'no header'        => array( '', true ),
			'anything'         => array( '*/*', true ),
			'browser'          => array( 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', true ),
			'upper case'       => array( 'TEXT/HTML', true ),
			'json'             => array( 'application/json', false ),
			'json then any'    => array( 'application/json, */*;q=0.1', false ),
		);
	}

	#[DataProvider( 'accepts' )]
	public function test_wants_html_reads_the_accept_header( string $accept, bool $html ): void {
		$headers = '' === $accept ? array() : array( 'accept' => $accept );

		$this->assertSame( $html, $this->request( array( 'headers' => $headers ) )->wants_html() );
	}

	/* ─── origin() ─────────────────────────────────────────────────── */

	public function test_origin_prefers_the_configured_url(): void {
		$request = $this->request( array( 'host' => 'evil.example', 'secure' => true ) );

		$this->assertSame( 'https://shop.example.com', $request->origin( 'https://shop.example.com/some/path?q=1' ) );
		$this->assertSame( 'https://shop.example.com:8443', $request->origin( 'https://Shop.Example.com:8443/' ) );
		$this->assertSame( 'https://shop.example.com', $request->origin( 'https://shop.example.com:443' ) );
	}

	public function test_an_unusable_configured_url_is_not_replaced_by_the_host_header(): void {
		$request = $this->request( array( 'host' => 'evil.example' ) );

		$this->assertSame( '', $request->origin( 'not a url' ) );
		$this->assertSame( '', $request->origin( 'ftp://files.example.com' ) );
		$this->assertSame( '', $request->origin( 'javascript:alert(1)' ) );
	}

	public function test_origin_falls_back_to_the_host_without_a_configured_url(): void {
		$this->assertSame( 'http://localhost:8099', $this->request( array( 'host' => 'localhost:8099' ) )->origin() );
		$this->assertSame( 'https://example.com', $this->request( array( 'host' => 'example.com:443', 'secure' => true ) )->origin() );
		$this->assertSame( 'http://example.com', $this->request( array( 'host' => 'example.com:80' ) )->origin() );
	}

	public function test_origin_believes_forwarded_proto_only_from_named_proxies(): void {
		$request = $this->request( array(
			'host'    => 'example.com',
			'remote'  => '198.51.100.7',
			'headers' => array( 'x-forwarded-proto' => 'https' ),
		) );

		$this->assertSame( 'http://example.com', $request->origin( '', TrustedProxies::cloudflare() ) );
		$this->assertSame( 'https://example.com', $request->origin( '', new TrustedProxies( array( '198.51.100.0/24' ) ) ) );
	}

	public function test_a_malformed_host_gives_no_origin(): void {
		foreach ( array( 'evil.example/path', 'a b', 'user@evil.example', "example.com\n" ) as $host ) {
			$this->assertSame( '', $this->request( array( 'host' => $host ) )->origin(), $host );
		}
	}
}
