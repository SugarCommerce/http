<?php
/**
 * Cookie tests.
 *
 * @package SugarCommerce\Http\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Http\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Http\Cookie;

/**
 * Class CookieTest
 */
final class CookieTest extends TestCase {

	public function test_the_simplest_cookie(): void {
		$this->assertSame( 'session=abc', Cookie::header( 'session', 'abc' ) );
	}

	public function test_the_value_is_encoded(): void {
		$this->assertSame( 'session=a%20b%3Bc', Cookie::header( 'session', 'a b;c' ) );
	}

	public function test_flags_are_present_only_when_set(): void {
		$header = Cookie::header( 'session', 'abc', array( 'secure' => true, 'httponly' => true ) );

		$this->assertStringContainsString( '; Secure', $header );
		$this->assertStringContainsString( '; HttpOnly', $header );
		$this->assertStringNotContainsString( 'Path', $header );
	}

	public function test_an_unset_flag_is_omitted_rather_than_sent_false(): void {
		$header = Cookie::header( 'session', 'abc', array( 'secure' => false, 'httponly' => false ) );

		$this->assertSame( 'session=abc', $header );
	}

	public function test_path_domain_and_samesite(): void {
		$header = Cookie::header( 'session', 'abc', array(
			'path'     => '/admin',
			'domain'   => 'example.test',
			'samesite' => 'Lax',
		) );

		$this->assertStringContainsString( '; Path=/admin', $header );
		$this->assertStringContainsString( '; Domain=example.test', $header );
		$this->assertStringContainsString( '; SameSite=Lax', $header );
	}

	public function test_expiry_becomes_both_forms(): void {
		$header = Cookie::header( 'session', 'abc', array( 'expires' => time() + 3600 ) );

		$this->assertStringContainsString( '; Expires=', $header );
		$this->assertStringContainsString( ' GMT', $header );
		$this->assertMatchesRegularExpression( '/; Max-Age=\d+/', $header );
	}

	/**
	 * Clearing a cookie is setting one in the past, and Max-Age must not go
	 * negative — some clients ignore the whole attribute if it does.
	 */
	public function test_a_past_expiry_clamps_max_age_to_zero(): void {
		$this->assertStringContainsString( '; Max-Age=0', Cookie::header( 'session', '', array( 'expires' => time() - 3600 ) ) );
	}

	public function test_an_expiry_of_zero_is_a_session_cookie(): void {
		$this->assertStringNotContainsString( 'Expires', Cookie::header( 'session', 'abc', array( 'expires' => 0 ) ) );
	}

	/**
	 * A value in an attribute must not be able to add attributes of its own.
	 */
	public function test_an_attribute_cannot_break_out_of_the_header(): void {
		$header = Cookie::header( 'session', 'abc', array( 'path' => "/a;Secure\r\nX-Evil: 1" ) );

		$this->assertSame( 'session=abc; Path=/aSecureX-Evil: 1', $header );
	}

	public function test_a_name_with_a_separator_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Cookie::header( "session\r\nX-Evil", 'abc' );
	}

	public function test_an_empty_name_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Cookie::header( '', 'abc' );
	}

	/**
	 * `$` without /D matches before a final newline, which let a name
	 * ending in one through — and a line break in a Set-Cookie header.
	 */
	public function test_a_name_with_a_trailing_newline_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Cookie::header( "session\n", 'abc' );
	}

	public function test_every_control_character_is_removed_from_an_attribute(): void {
		$this->assertSame( 'session=abc; Path=/ab', Cookie::header( 'session', 'abc', array( 'path' => "/a\tb\x7F" ) ) );
	}

	/**
	 * Some browsers fall back to SameSite=None on a value they do not know.
	 */
	public function test_an_unknown_samesite_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Cookie::header( 'session', 'abc', array( 'samesite' => 'Stirct' ) );
	}

	public function test_samesite_is_case_insensitive(): void {
		$this->assertStringContainsString( '; SameSite=none', Cookie::header( 'session', 'abc', array( 'samesite' => 'none', 'secure' => true ) ) );
	}

	public function test_an_expiry_given_as_a_string_still_counts(): void {
		$this->assertStringContainsString( '; Max-Age=', Cookie::header( 'session', 'abc', array( 'expires' => (string) ( time() + 60 ) ) ) );
		$this->assertSame( 'session=abc', Cookie::header( 'session', 'abc', array( 'expires' => 'soon' ) ) );
	}
}
