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

	public function test_set_header_fills_in_first_party_defaults(): void {
		$header = Cookie::set_header( 'theme', 'dark', 3600 );

		$this->assertStringStartsWith( 'theme=dark; Expires=', $header );
		$this->assertMatchesRegularExpression( '/; Max-Age=(3599|3600);/', $header );
		$this->assertStringContainsString( '; Path=/', $header );
		$this->assertStringContainsString( '; SameSite=Lax', $header );
		$this->assertStringContainsString( '; HttpOnly', $header );
		$this->assertStringNotContainsString( 'Secure', $header );
	}

	public function test_set_header_options_win_over_the_defaults(): void {
		$header = Cookie::set_header( 'theme', 'dark', 60, array(
			'path'     => '/admin',
			'domain'   => 'example.test',
			'samesite' => 'Strict',
			'secure'   => true,
			'httponly' => false,
		) );

		$this->assertStringContainsString( '; Path=/admin', $header );
		$this->assertStringContainsString( '; Domain=example.test', $header );
		$this->assertStringContainsString( '; SameSite=Strict', $header );
		$this->assertStringContainsString( '; Secure', $header );
		$this->assertStringNotContainsString( 'HttpOnly', $header );
	}

	/**
	 * $max_age is the expiry; an `expires` passed alongside it would make
	 * the two disagree.
	 */
	public function test_set_header_ignores_an_expires_option(): void {
		$header = Cookie::set_header( 'theme', 'dark', 60, array( 'expires' => time() + 999_999 ) );

		$this->assertMatchesRegularExpression( '/; Max-Age=(59|60);/', $header );
	}

	public function test_a_max_age_of_zero_is_a_session_cookie(): void {
		$header = Cookie::set_header( 'theme', 'dark', 0 );

		$this->assertStringNotContainsString( 'Expires', $header );
		$this->assertStringNotContainsString( 'Max-Age', $header );
	}

	public function test_a_negative_max_age_clears(): void {
		$this->assertStringContainsString( '; Max-Age=0', Cookie::set_header( 'theme', 'dark', -60 ) );
	}

	public function test_set_header_still_validates_the_name(): void {
		$this->expectException( \InvalidArgumentException::class );

		Cookie::set_header( "theme\r\nX-Evil", 'dark', 60 );
	}

	public function test_set_header_still_validates_samesite(): void {
		$this->expectException( \InvalidArgumentException::class );

		Cookie::set_header( 'theme', 'dark', 60, array( 'samesite' => 'Stirct' ) );
	}

	public function test_clear_header_is_empty_and_in_the_past(): void {
		$header = Cookie::clear_header( 'theme' );

		$this->assertStringStartsWith( 'theme=; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0', $header );
		$this->assertStringContainsString( '; Path=/', $header );
		$this->assertStringContainsString( '; SameSite=Lax', $header );
		$this->assertStringContainsString( '; HttpOnly', $header );
	}

	/**
	 * A browser keys a cookie on name, domain and path: clearing anywhere
	 * else leaves the original in place.
	 */
	public function test_clear_header_keeps_the_path_and_domain_given(): void {
		$header = Cookie::clear_header( 'session', array( 'path' => '/admin', 'domain' => 'example.test', 'expires' => time() + 3600 ) );

		$this->assertStringContainsString( '; Path=/admin', $header );
		$this->assertStringContainsString( '; Domain=example.test', $header );
		$this->assertStringContainsString( '; Max-Age=0', $header );
	}

	public function test_set_and_clear_send_without_complaint(): void {
		// header() is a no-op under the CLI; this proves only that nothing
		// throws or warns on the way there.
		Cookie::set( 'theme', 'dark', 60 );
		Cookie::clear( 'theme' );

		$this->addToAssertionCount( 1 );
	}

	public function test_read_returns_the_value(): void {
		$this->assertSame( 'dark', Cookie::read( 'theme', array( 'theme' => 'dark' ) ) );
	}

	public function test_read_returns_empty_when_absent(): void {
		$this->assertSame( '', Cookie::read( 'theme', array() ) );
	}

	/**
	 * `theme[]=x` arrives as an array.
	 */
	public function test_read_treats_an_array_as_absent(): void {
		$this->assertSame( '', Cookie::read( 'theme', array( 'theme' => array( 'x' ) ) ) );
		$this->assertSame( '', Cookie::read( 'theme', array( 'theme' => 7 ) ) );
	}

	public function test_read_defaults_to_the_superglobal(): void {
		$saved   = $_COOKIE;
		$_COOKIE = array( 'theme' => 'light' );

		try {
			$this->assertSame( 'light', Cookie::read( 'theme' ) );
		} finally {
			$_COOKIE = $saved;
		}
	}
}
