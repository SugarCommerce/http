<?php
/**
 * Hosts tests.
 *
 * @package SugarCommerce\Http\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Http\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Http\Hosts;
use SugarCommerce\Http\Request;
use SugarCommerce\Http\Surfaces;

/**
 * Class HostsTest
 */
final class HostsTest extends TestCase {

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function normalised(): array {
		return array(
			'a name'                    => array( 'example.com', 'example.com' ),
			'upper case'                => array( 'Shop.EXAMPLE.com', 'shop.example.com' ),
			'a port'                    => array( 'example.com:8080', 'example.com' ),
			'an empty port'             => array( 'example.com:', 'example.com' ),
			'a trailing dot'            => array( 'example.com.', 'example.com' ),
			'a dot and a port'          => array( 'Example.COM.:443', 'example.com' ),
			'whitespace'                => array( "  example.com\t", 'example.com' ),
			'a single label'            => array( 'localhost:8000', 'localhost' ),
			'an underscore'             => array( 'dev_box.local', 'dev_box.local' ),
			'IPv4'                      => array( '127.0.0.1', '127.0.0.1' ),
			'IPv4 with a port'          => array( '127.0.0.1:8099', '127.0.0.1' ),
			'IPv6 in brackets'          => array( '[::1]', '[::1]' ),
			'IPv6 with a port'          => array( '[::1]:8080', '[::1]' ),
			'IPv6 bare'                 => array( '::1', '[::1]' ),
			'IPv6 written out'          => array( '[0:0:0:0:0:0:0:1]', '[::1]' ),
			'IPv6 upper case'           => array( '[2001:DB8::A]:443', '[2001:db8::a]' ),
			'IPv6 bare, written out'    => array( '2001:0db8:0000:0000:0000:0000:0000:000a', '[2001:db8::a]' ),
			'IPv4 mapped into IPv6'     => array( '[::ffff:127.0.0.1]', '[::ffff:127.0.0.1]' ),
			'punycode'                  => array( 'xn--bcher-kva.example', 'xn--bcher-kva.example' ),
		);
	}

	#[DataProvider( 'normalised' )]
	public function test_a_host_is_normalised( string $host, string $expected ): void {
		$this->assertSame( $expected, Hosts::normalize( $host ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function garbage(): array {
		return array(
			'nothing'                  => array( '' ),
			'only space'               => array( '   ' ),
			'only a port'              => array( ':8080' ),
			'only a dot'               => array( '.' ),
			'two trailing dots'        => array( 'example.com..' ),
			'an empty label'           => array( 'a..example.com' ),
			'a leading dot'            => array( '.example.com' ),
			'a word for a port'        => array( 'example.com:abc' ),
			'a port too big'           => array( 'example.com:65536' ),
			'a port too long'          => array( 'example.com:000080' ),
			'two ports'                => array( 'example.com:80:80' ),
			'a backslash'              => array( 'evil.com\\.example.com' ),
			'userinfo'                 => array( 'example.com@evil.example' ),
			'a slash'                  => array( 'example.com/admin' ),
			'a space inside'           => array( 'exa mple.com' ),
			'a line break'             => array( "example.com\r\nX-Evil: 1" ),
			'unicode'                  => array( 'bücher.example' ),
			'a label over 63'          => array( str_repeat( 'a', 64 ) . '.example' ),
			'a name over 253'          => array( implode( '.', array_fill( 0, 51, 'abcd' ) ) ),
			'an unclosed bracket'      => array( '[::1' ),
			'empty brackets'           => array( '[]' ),
			'not IPv6 in brackets'     => array( '[example.com]' ),
			'IPv4 in brackets'         => array( '[127.0.0.1]' ),
			'junk after the bracket'   => array( '[::1]x' ),
			'a bad port after IPv6'    => array( '[::1]:99999' ),
			'a zone id'                => array( '[fe80::1%25eth0]' ),
			'bare IPv6 that is not'    => array( '1:2:3:4:5:6:7:8:9' ),
		);
	}

	#[DataProvider( 'garbage' )]
	public function test_what_is_not_a_host_normalises_to_nothing( string $host ): void {
		$this->assertSame( '', Hosts::normalize( $host ) );
	}

	public function test_a_name_of_253_is_a_host(): void {
		$name = implode( '.', array_fill( 0, 50, 'abcd' ) ) . '.abc';

		$this->assertSame( 253, strlen( $name ) );
		$this->assertSame( $name, Hosts::normalize( $name ) );
	}

	public function test_the_list_is_normalised_without_repeats(): void {
		$hosts = new Hosts( array( 'Example.com', 'example.com:443', 'example.com.', '::1', '[::1]:8080', '' ) );

		$this->assertSame( array( 'example.com', '[::1]' ), $hosts->all() );
	}

	public function test_an_entry_that_is_not_a_host_is_dropped(): void {
		$this->assertSame( array( 'example.com' ), ( new Hosts( array( 'not a host', 'example.com', '/api' ) ) )->all() );
	}

	public function test_a_url_on_the_list_gives_its_host(): void {
		$hosts = new Hosts( array( 'https://API.example.com:8443/v1', 'http://[::1]:8080/', 'ftp://files.example.com' ) );

		$this->assertSame( array( 'api.example.com', '[::1]' ), $hosts->all() );
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function requests(): array {
		return array(
			'the site'               => array( 'example.com', true ),
			'with a port'            => array( 'example.com:443', true ),
			'any case, a dot'        => array( 'Example.COM.', true ),
			'listed with a port'     => array( 'cdn.example.net', true ),
			'loopback v4'            => array( '127.0.0.1:8099', true ),
			'loopback v6'            => array( '[::1]:8080', true ),
			'loopback v6, bare'      => array( '::1', true ),
			'loopback v6, long hand' => array( '[0:0:0:0:0:0:0:1]', true ),
			'loopback by name'       => array( 'LOCALHOST:3000', true ),
			'somebody else'          => array( 'evil.example', false ),
			'ours inside theirs'     => array( 'example.com.evil.example', false ),
			'theirs ending in ours'  => array( 'evil-example.com', false ),
			'a subdomain not listed' => array( 'admin.example.com', false ),
			'other loopback'         => array( '127.0.0.2', false ),
			'a name under localhost' => array( 'app.localhost', false ),
			'nothing'                => array( '', false ),
			'garbage'                => array( 'example.com\\@evil', false ),
			'a bad port'             => array( 'example.com:http', false ),
		);
	}

	#[DataProvider( 'requests' )]
	public function test_only_the_listed_hosts_are_allowed( string $host, bool $allowed ): void {
		$hosts = new Hosts( array( 'example.com', 'cdn.example.net:8443', ...Hosts::LOOPBACK ) );

		$this->assertSame( $allowed, $hosts->allows( $host ) );
	}

	public function test_a_request_is_checked_by_its_host(): void {
		$hosts = new Hosts( array( 'example.com' ) );

		$this->assertTrue( $hosts->allows( new Request( method: 'GET', path: '/', host: 'example.com:8080' ) ) );
		$this->assertFalse( $hosts->allows( new Request( method: 'GET', path: '/', host: 'admin.evil.example' ) ) );
	}

	public function test_an_empty_list_allows_nothing(): void {
		$this->assertFalse( ( new Hosts( array() ) )->allows( 'localhost' ) );
		$this->assertFalse( ( new Hosts( array( '' ) ) )->allows( '' ) );
	}

	public function test_a_site_gives_its_domain_its_www_and_its_subdomains(): void {
		$this->assertSame(
			array( 'example.com', 'www.example.com', 'api.example.com', 'admin.example.com' ),
			Hosts::site( 'https://example.com', array( 'api', 'admin' ) )->all(),
		);
	}

	public function test_a_site_given_with_its_www_is_the_same_site(): void {
		$this->assertSame( Hosts::site( 'example.com' )->all(), Hosts::site( 'https://WWW.Example.com:8443/shop' )->all() );
		$this->assertSame( array( 'example.com', 'www.example.com' ), Hosts::site( 'www.example.com' )->all() );
	}

	public function test_a_site_by_address_is_only_itself(): void {
		$this->assertSame( array( '127.0.0.1' ), Hosts::site( 'http://127.0.0.1:8000', array( 'admin' ) )->all() );
		$this->assertSame( array( '[::1]' ), Hosts::site( 'http://[::1]:8000', array( 'admin' ) )->all() );
	}

	public function test_no_site_is_an_empty_list(): void {
		$this->assertSame( array(), Hosts::site( '', array( 'admin' ) )->all() );
		$this->assertSame( array(), Hosts::site( 'https://', array( 'admin' ) )->all() );
	}

	public function test_more_hosts_can_be_added(): void {
		$site  = Hosts::site( 'example.com' );
		$local = $site->with( ...Hosts::LOOPBACK );

		$this->assertSame( array( 'example.com', 'www.example.com', 'localhost', '127.0.0.1', '[::1]' ), $local->all() );
		$this->assertFalse( $site->allows( 'localhost' ), 'with() leaves the original alone.' );
		$this->assertTrue( $local->allows( 'localhost:8000' ) );
		$this->assertSame( $local->all(), $local->with( 'example.com', '::1', '' )->all() );
	}

	/**
	 * all() feeds Surfaces' path_hosts, which compares IPv6 in brackets.
	 */
	public function test_the_list_suits_surfaces_path_hosts(): void {
		$hosts    = Hosts::site( 'example.com' )->with( '::1' );
		$surfaces = new Surfaces( array( 'admin' ), default: 'site', path_hosts: $hosts->all() );

		$this->assertSame( 'admin', $surfaces->resolve( '[::1]:8080', '/admin' )['surface'] );
		$this->assertSame( 'admin', $surfaces->resolve( 'www.example.com', '/admin' )['surface'] );
		$this->assertSame( 'site', $surfaces->resolve( 'shop.example.com', '/admin' )['surface'] );
	}
}
