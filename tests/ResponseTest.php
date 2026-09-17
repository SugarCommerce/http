<?php
/**
 * Response and Headers tests.
 *
 * @package SugarCommerce\Http\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Http\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Http\{Headers, Response};

/**
 * Class ResponseTest
 */
final class ResponseTest extends TestCase {

	protected function setUp(): void {
		Headers::reset_nonce();
	}

	public function test_json_encodes_and_sets_the_content_type(): void {
		$response = Response::json( array( 'a' => 1 ) );

		$this->assertSame( 200, $response->status );
		$this->assertSame( '{"a":1}', $response->body );
		$this->assertSame( 'application/json', $response->headers['Content-Type'] );
	}

	public function test_json_is_never_cached(): void {
		$this->assertSame( 'no-store', Response::json( array() )->headers['Cache-Control'] );
	}

	public function test_json_has_no_cors_unless_asked(): void {
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', Response::json( array() )->headers );
	}

	public function test_json_takes_extra_headers(): void {
		$response = Response::json( array(), 200, Headers::cors( '*' ) );

		$this->assertSame( '*', $response->headers['Access-Control-Allow-Origin'] );
		$this->assertSame( 'application/json', $response->headers['Content-Type'] );
	}

	public function test_error_carries_a_message_and_a_status(): void {
		$response = Response::error( 'Not authorised.', 401 );

		$this->assertSame( 401, $response->status );
		$this->assertSame( array( 'error' => 'Not authorised.' ), json_decode( $response->body, true ) );
	}

	public function test_error_merges_machine_readable_detail(): void {
		$response = Response::error( 'Over your plan limit.', 402, array( 'reason' => 'plan_limit' ) );

		$this->assertSame(
			array( 'error' => 'Over your plan limit.', 'reason' => 'plan_limit' ),
			json_decode( $response->body, true ),
		);
	}

	public function test_html_carries_the_security_headers(): void {
		$headers = Response::html( '<p>hi</p>' )->headers;

		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( 'DENY', $headers['X-Frame-Options'] );
		$this->assertStringContainsString( "frame-ancestors 'none'", $headers['Content-Security-Policy'] );
	}

	public function test_redirect_sets_location_and_no_body(): void {
		$response = Response::redirect( '/somewhere' );

		$this->assertSame( 302, $response->status );
		$this->assertSame( '/somewhere', $response->headers['Location'] );
		$this->assertSame( '', $response->body );
	}

	public function test_preflight_is_an_empty_204(): void {
		$response = Response::preflight( Headers::cors() );

		$this->assertSame( 204, $response->status );
		$this->assertSame( '', $response->body );
		$this->assertSame( '86400', $response->headers['Access-Control-Max-Age'] );
	}

	public function test_with_adds_headers_without_overwriting(): void {
		$response = Response::json( array() )->with( array(
			'Content-Type' => 'text/plain',
			'X-Extra'      => 'yes',
		) );

		$this->assertSame( 'application/json', $response->headers['Content-Type'] );
		$this->assertSame( 'yes', $response->headers['X-Extra'] );
	}

	public function test_text_sets_a_plain_content_type(): void {
		$this->assertSame( 'text/plain; charset=utf-8', Response::text( 'hi' )->headers['Content-Type'] );
	}

	public function test_the_csp_carries_a_nonce(): void {
		$policy = Headers::html()['Content-Security-Policy'];

		$this->assertStringContainsString( "'nonce-" . Headers::nonce() . "'", $policy );
	}

	public function test_the_nonce_is_stable_within_a_request(): void {
		$this->assertSame( Headers::nonce(), Headers::nonce() );
	}

	public function test_the_nonce_changes_between_requests(): void {
		$first = Headers::nonce();
		Headers::reset_nonce();

		$this->assertNotSame( $first, Headers::nonce() );
	}

	public function test_allowed_sources_are_appended_not_replaced(): void {
		$policy = Headers::html( array( 'script-src' => array( 'https://cdn.example.com' ) ) )['Content-Security-Policy'];

		// Replacing the directive wholesale would silently drop the nonce.
		$this->assertStringContainsString( "'nonce-" . Headers::nonce() . "'", $policy );
		$this->assertStringContainsString( 'https://cdn.example.com', $policy );
	}

	public function test_hsts_is_only_sent_over_tls(): void {
		$this->assertArrayHasKey( 'Strict-Transport-Security', Headers::with_hsts( array(), true ) );
		$this->assertArrayNotHasKey( 'Strict-Transport-Security', Headers::with_hsts( array(), false ) );
	}

	public function test_cors_joins_its_lists(): void {
		$cors = Headers::cors( 'https://example.com', array( 'Content-Type', 'X-Signature' ) );

		$this->assertSame( 'https://example.com', $cors['Access-Control-Allow-Origin'] );
		$this->assertSame( 'Content-Type, X-Signature', $cors['Access-Control-Allow-Headers'] );
	}
}
