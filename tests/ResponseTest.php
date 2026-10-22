<?php
/**
 * Response and Headers tests.
 *
 * @package SugarCommerce\Http\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Http\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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

	/**
	 * @return array<string, array{string}>
	 */
	public static function allowed_destinations(): array {
		return array(
			'root'             => array( '/' ),
			'path'             => array( '/orders/7?tab=files#top' ),
			'prefixed path'    => array( '/admin/accounts/acct_1?problem=comp&why=Bad%20date' ),
			'https'            => array( 'https://checkout.stripe.com/c/pay/cs_test_1#fid%2Fabc' ),
			'http'             => array( 'http://localhost:8000/app/enter/abc.def' ),
			'loopback ipv6'    => array( 'http://[::1]:33418/callback?code=x&state=y' ),
			'upper-case https' => array( 'HTTPS://example.com/' ),
		);
	}

	#[DataProvider( 'allowed_destinations' )]
	public function test_redirect_accepts_a_local_path_or_an_absolute_url( string $url ): void {
		$this->assertSame( $url, Response::redirect( $url )->headers['Location'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function refused_destinations(): array {
		return array(
			'empty'                => array( '' ),
			'protocol-relative'    => array( '//evil.example/' ),
			'backslash'            => array( '/\\evil.example/' ),
			'tab then slash'       => array( "/\t/evil.example/" ),
			'line break'           => array( "/orders\r\nX-Evil: 1" ),
			'space'                => array( '/orders 7' ),
			'relative'             => array( 'orders' ),
			'javascript'           => array( 'javascript:alert(1)' ),
			'javascript authority' => array( 'javascript://x%0Aalert(1)' ),
			'data'                 => array( 'data:text/html,<script>alert(1)</script>' ),
			'mailto'               => array( 'mailto:someone@example.com' ),
			'ftp'                  => array( 'ftp://example.com/file' ),
			'no host'              => array( 'https:///orders' ),
			'scheme only'          => array( 'https:' ),
			'space in url'         => array( 'https://example.com/a b' ),
		);
	}

	#[DataProvider( 'refused_destinations' )]
	public function test_redirect_refuses_anything_else( string $url ): void {
		$this->expectException( \InvalidArgumentException::class );

		Response::redirect( $url );
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

	/* ─── Downloads ────────────────────────────────────────────────── */

	public function test_a_download_carries_its_type_and_disposition(): void {
		$response = Response::download( "a,b\n", 'text/csv; charset=utf-8', 'attachment; filename="x.csv"' );

		$this->assertSame( 200, $response->status );
		$this->assertSame( "a,b\n", $response->body );
		$this->assertSame( 'text/csv; charset=utf-8', $response->headers['Content-Type'] );
		$this->assertSame( 'attachment; filename="x.csv"', $response->headers['Content-Disposition'] );
	}

	/**
	 * A download built per request is somebody's own data, and a CSV that
	 * looks like HTML must not be rendered as a page.
	 */
	public function test_a_download_is_never_cached_or_sniffed(): void {
		$response = Response::download( '{}', 'application/json', 'attachment' );

		$this->assertSame( 'private, no-store', $response->headers['Cache-Control'] );
		$this->assertSame( 'nosniff', $response->headers['X-Content-Type-Options'] );
	}

	public function test_a_download_takes_extra_headers_which_win(): void {
		$response = Response::download( '', 'text/plain', 'attachment', array( 'Cache-Control' => 'no-store', 'X-Extra' => '1' ) );

		$this->assertSame( 'no-store', $response->headers['Cache-Control'] );
		$this->assertSame( '1', $response->headers['X-Extra'] );
	}

	/* ─── After the response ───────────────────────────────────────── */

	/**
	 * Registered, not run: the work happens after everything else the
	 * process does, which in a test process is after the test.
	 */
	public function test_deferred_work_runs_after_the_rest_of_the_process(): void {
		$script = sprintf(
			'require %s; SugarCommerce\Http\Response::defer( static function (): void { echo "later"; } ); echo "first ";',
			var_export( dirname( __DIR__ ) . '/vendor/autoload.php', true )
		);

		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $script ) );

		$this->assertSame( 'first later', $output );
	}

	/* ─── Framing ──────────────────────────────────────────────────── */

	public function test_pages_refuse_to_be_framed_by_default(): void {
		$headers = Headers::html();

		$this->assertSame( 'DENY', $headers['X-Frame-Options'] );
		$this->assertStringContainsString( "frame-ancestors 'none'", $headers['Content-Security-Policy'] );
	}

	/**
	 * X-Frame-Options cannot say "these sites", so it would go on refusing.
	 */
	public function test_html_can_name_who_may_frame_it(): void {
		$headers = Headers::html( frame_ancestors: '*' );

		$this->assertArrayNotHasKey( 'X-Frame-Options', $headers );
		$this->assertStringContainsString( 'frame-ancestors *', $headers['Content-Security-Policy'] );
		$this->assertStringNotContainsString( "'none'", $headers['Content-Security-Policy'] );
	}

	public function test_naming_none_keeps_x_frame_options(): void {
		$this->assertSame( 'DENY', Headers::html( frame_ancestors: "'none'" )['X-Frame-Options'] );
	}

	public function test_embeddable_rewrites_only_frame_ancestors(): void {
		$before = Headers::html( array( 'script-src' => array( 'https://cdn.example.com' ) ) );
		$after  = Headers::embeddable( $before );

		$this->assertArrayNotHasKey( 'X-Frame-Options', $after );
		$this->assertStringContainsString( 'frame-ancestors *', $after['Content-Security-Policy'] );
		$this->assertStringNotContainsString( "frame-ancestors 'none'", $after['Content-Security-Policy'] );
		$this->assertStringContainsString( 'https://cdn.example.com', $after['Content-Security-Policy'] );
		$this->assertSame( substr_count( $before['Content-Security-Policy'], ';' ), substr_count( $after['Content-Security-Policy'], ';' ) );
		$this->assertSame( Headers::html( frame_ancestors: '*' ), Headers::embeddable( Headers::html() ) );
	}

	/**
	 * A string search for `frame-ancestors 'none'` silently does nothing
	 * once the directive says anything else.
	 */
	public function test_embeddable_does_not_depend_on_the_directive_it_replaces(): void {
		$headers = array( 'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'self' https://a.example", 'X-Frame-Options' => 'SAMEORIGIN' );
		$after   = Headers::embeddable( $headers, 'https://b.example' );

		$this->assertSame( "default-src 'self'; frame-ancestors https://b.example", $after['Content-Security-Policy'] );
		$this->assertArrayNotHasKey( 'X-Frame-Options', $after );
	}

	public function test_embeddable_adds_the_directive_when_there_was_none(): void {
		$after = Headers::embeddable( array( 'Content-Security-Policy' => "default-src 'self'" ) );

		$this->assertSame( "default-src 'self'; frame-ancestors *", $after['Content-Security-Policy'] );
	}

	/* ─── Merging sources ──────────────────────────────────────────── */

	/**
	 * `+` on arrays keeps only the first set, and the page breaks in the
	 * browser rather than in a test.
	 */
	public function test_merged_sources_are_appended_not_replaced(): void {
		$merged = Headers::merge_sources(
			array( 'connect-src' => array( 'https://a.example' ) ),
			array( 'connect-src' => array( 'https://b.example' ), 'media-src' => "'self'" ),
		);

		$this->assertSame(
			array( 'connect-src' => array( 'https://a.example', 'https://b.example' ), 'media-src' => array( "'self'" ) ),
			$merged
		);
	}

	public function test_merged_sources_are_listed_once_in_order(): void {
		$merged = Headers::merge_sources(
			array( 'frame-src' => array( 'https://b.example', 'https://a.example' ) ),
			array( 'frame-src' => array( 'https://a.example', 'https://c.example', 'https://b.example' ) ),
		);

		$this->assertSame( array( 'https://b.example', 'https://a.example', 'https://c.example' ), $merged['frame-src'] );
	}

	public function test_merging_nothing_is_nothing(): void {
		$this->assertSame( array(), Headers::merge_sources() );
	}

	/* ─── The nonce attribute ──────────────────────────────────────── */

	public function test_the_nonce_attribute_carries_the_policy_nonce(): void {
		$this->assertSame( ' nonce="' . Headers::nonce() . '"', Headers::nonce_attribute() );
		$this->assertStringContainsString( "'nonce-" . Headers::nonce() . "'", Headers::html()['Content-Security-Policy'] );
	}

	/* ─── CORS for named origins ───────────────────────────────────── */

	public function test_an_allowed_origin_is_echoed_back(): void {
		$cors = Headers::cors_for( 'https://shop.example.com', array( 'https://shop.example.com' ), array( 'Content-Type' ), array( 'POST', 'OPTIONS' ) );

		$this->assertSame( 'https://shop.example.com', $cors['Access-Control-Allow-Origin'] );
		$this->assertSame( 'POST, OPTIONS', $cors['Access-Control-Allow-Methods'] );
		$this->assertSame( 'Origin', $cors['Vary'] );
	}

	public function test_origins_match_case_insensitively(): void {
		$cors = Headers::cors_for( 'https://Shop.Example.com', array( 'HTTPS://shop.example.com/' ) );

		$this->assertSame( 'https://shop.example.com', $cors['Access-Control-Allow-Origin'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function strangers(): array {
		return array(
			'another site'      => array( 'https://evil.example' ),
			'a suffix'          => array( 'https://evil-shop.example.com' ),
			'a subdomain'       => array( 'https://a.shop.example.com' ),
			'another scheme'    => array( 'http://shop.example.com' ),
			'another port'      => array( 'https://shop.example.com:8443' ),
			'a sandboxed frame' => array( 'null' ),
			'nothing'           => array( '' ),
		);
	}

	/**
	 * Vary either way: a cache keeping one caller's answer for everybody
	 * would hand the headers to a stranger, or the refusal to a friend.
	 */
	#[DataProvider( 'strangers' )]
	public function test_any_other_origin_gets_only_vary( string $origin ): void {
		$this->assertSame( array( 'Vary' => 'Origin' ), Headers::cors_for( $origin, array( 'https://shop.example.com' ) ) );
	}

	public function test_nothing_allowed_allows_nothing(): void {
		$this->assertSame( array( 'Vary' => 'Origin' ), Headers::cors_for( 'https://shop.example.com', array() ) );
	}

	/**
	 * PHP's header() drops a header with a line break and only warns — a
	 * security header quietly missing. send() refuses before sending anything.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function malformed(): array {
		return array(
			'CRLF in a value'   => array( array( 'Location' => "/x\r\nSet-Cookie: admin=1" ) ),
			'LF in a value'     => array( array( 'X-A' => "a\nb" ) ),
			'NUL in a value'    => array( array( 'X-A' => "a\0b" ) ),
			'colon in a name'   => array( array( 'X-A: b' => 'c' ) ),
			'newline in a name' => array( array( "X-A\n" => 'c' ) ),
		);
	}

	/**
	 * @param array<string, string> $headers
	 */
	#[DataProvider( 'malformed' )]
	public function test_a_malformed_header_is_refused_before_anything_is_sent( array $headers ): void {
		$this->expectException( \UnexpectedValueException::class );

		( new Response( 200, 'body', $headers ) )->send();
	}

	/**
	 * `;` starts a directive, `,` a policy, and a line break makes header()
	 * drop the policy altogether.
	 */
	public function test_a_frame_ancestors_list_cannot_add_directives(): void {
		$csp = Headers::html( array(), "https://a.example; script-src *, \r\nX: y" )['Content-Security-Policy'];

		$this->assertStringContainsString( 'frame-ancestors https://a.example script-src * X: y', $csp );
		$this->assertSame( 1, substr_count( $csp, 'script-src' ) - substr_count( $csp, 'frame-ancestors https://a.example script-src' ) );
		$this->assertStringNotContainsString( "\n", $csp );
	}

	public function test_embeddable_ancestors_cannot_add_directives(): void {
		$csp = Headers::embeddable( Headers::html(), "https://a.example; report-uri https://evil.example" )['Content-Security-Policy'];

		$this->assertStringNotContainsString( '; report-uri', $csp );
	}

	public function test_allowed_sources_cannot_add_directives(): void {
		$csp = Headers::html( array( 'img-src' => array( "https://cdn.example; script-src 'unsafe-inline'" ) ) )['Content-Security-Policy'];

		$this->assertStringNotContainsString( "; script-src 'unsafe-inline'", $csp );
	}

	public function test_strict_reproduces_a_policy_written_out_in_full(): void {
		$nonce   = 'AbC-123_xyz';
		$headers = Headers::with_hsts(
			Headers::strict(
				array(
					'default-src'     => "'self'",
					'script-src'      => array( "'self'", 'https://challenges.cloudflare.com' ),
					'style-src'       => array( "'self'", 'https://fonts.googleapis.com', 'https://cdn.jsdelivr.net' ),
					'img-src'         => "'self' data:",
					'font-src'        => array( "'self'", 'https://fonts.gstatic.com', 'https://cdn.jsdelivr.net' ),
					'connect-src'     => array( "'self'", 'https://challenges.cloudflare.com' ),
					'frame-src'       => 'https://challenges.cloudflare.com',
					'form-action'     => "'self'",
					'frame-ancestors' => "'none'",
					'base-uri'        => "'self'",
				),
				$nonce,
			),
			true,
		);

		$this->assertSame(
			array(
				'X-Content-Type-Options'    => 'nosniff',
				'X-Frame-Options'           => 'DENY',
				'Referrer-Policy'           => 'strict-origin-when-cross-origin',
				'Permissions-Policy'        => 'geolocation=(), microphone=(), camera=(), payment=()',
				'Content-Security-Policy'   => "default-src 'self'; "
					. "script-src 'self' 'nonce-$nonce' https://challenges.cloudflare.com; "
					. "style-src 'self' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
					. "img-src 'self' data:; "
					. "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; "
					. "connect-src 'self' https://challenges.cloudflare.com; "
					. "frame-src https://challenges.cloudflare.com; "
					. "form-action 'self'; "
					. "frame-ancestors 'none'; "
					. "base-uri 'self'",
				'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
			),
			$headers,
		);
	}

	public function test_strict_starts_from_self_and_the_nonce(): void {
		$this->assertSame(
			"default-src 'self'; script-src 'self' 'nonce-" . Headers::nonce() . "'; style-src 'self'; img-src 'self' data:; "
			. "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'",
			Headers::strict()['Content-Security-Policy'],
		);
	}

	public function test_strict_has_no_content_type(): void {
		$this->assertArrayNotHasKey( 'Content-Type', Headers::strict() );
	}

	public function test_strict_replaces_rather_than_appends(): void {
		$csp = Headers::strict( array( 'img-src' => 'https://img.example' ) )['Content-Security-Policy'];

		$this->assertStringStartsWith( 'img-src https://img.example; default-src', $csp );
		$this->assertStringNotContainsString( 'data:', $csp );
		$this->assertStringNotContainsString( 'unsafe-inline', $csp );
	}

	public function test_strict_leaves_out_a_directive_set_to_null(): void {
		$csp = Headers::strict( array( 'img-src' => null, 'Base-URI' => null ) )['Content-Security-Policy'];

		$this->assertStringNotContainsString( 'img-src', $csp );
		$this->assertStringNotContainsString( 'base-uri', $csp );
	}

	public function test_strict_keeps_the_nonce_in_a_replaced_script_src(): void {
		$this->assertStringContainsString(
			"script-src 'nonce-n' https://cdn.example",
			Headers::strict( array( 'script-src' => 'https://cdn.example' ), 'n' )['Content-Security-Policy'],
		);
		$this->assertStringContainsString(
			"script-src 'self' 'nonce-n'",
			Headers::strict( array( 'script-src' => "'self' 'nonce-n'" ), 'n' )['Content-Security-Policy'],
		);
		$this->assertStringContainsString(
			"script-src 'none';",
			Headers::strict( array( 'script-src' => "'none'" ), 'n' )['Content-Security-Policy'],
		);
	}

	public function test_strict_uses_the_request_nonce_by_default(): void {
		$this->assertStringContainsString( "'nonce-" . Headers::nonce() . "'", Headers::strict()['Content-Security-Policy'] );
	}

	public function test_strict_takes_a_referrer_and_permissions_policy(): void {
		$headers = Headers::strict( array(), null, 'no-referrer', array( 'camera', 'usb' ) );

		$this->assertSame( 'no-referrer', $headers['Referrer-Policy'] );
		$this->assertSame( 'camera=(), usb=()', $headers['Permissions-Policy'] );
		$this->assertArrayNotHasKey( 'Permissions-Policy', Headers::strict( permissions: array() ) );
	}

	public function test_strict_drops_x_frame_options_only_when_framing_is_allowed(): void {
		$this->assertArrayNotHasKey( 'X-Frame-Options', Headers::strict( array( 'frame-ancestors' => 'https://a.example' ) ) );
		$this->assertSame( 'DENY', Headers::strict( array( 'frame-ancestors' => null ) )['X-Frame-Options'] );
	}

	public function test_strict_sources_cannot_add_directives(): void {
		$csp = Headers::strict( array( 'img-src' => "https://cdn.example; script-src 'unsafe-inline'" ) )['Content-Security-Policy'];

		$this->assertStringNotContainsString( "; script-src 'unsafe-inline'", $csp );
	}

	/**
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function bad_strict_arguments(): array {
		return array(
			'quote in the nonce'   => array( "n' 'unsafe-inline", 'no-referrer' ),
			'empty nonce'          => array( '', 'no-referrer' ),
			'unknown referrer'     => array( null, 'strict-origin-when-crossorigin' ),
			'header in a referrer' => array( null, "no-referrer\r\nX: y" ),
		);
	}

	#[DataProvider( 'bad_strict_arguments' )]
	public function test_strict_refuses_a_bad_nonce_or_referrer( ?string $nonce, string $referrer ): void {
		$this->expectException( \InvalidArgumentException::class );

		Headers::strict( array(), $nonce, $referrer );
	}
}
