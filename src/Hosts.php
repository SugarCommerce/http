<?php
/**
 * The hostnames an application answers to.
 *
 * @package   SugarCommerce\Http
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.14.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Http;

/**
 * Class Hosts
 *
 * The Host header is whatever the client typed. Point `admin.evil.example`
 * at a server whose surfaces are picked by subdomain and it is the admin
 * panel; every absolute link a page builds from the host is the attacker's,
 * and so is every password-reset email. The answer is a list of the names
 * this install serves, checked before anything is routed.
 *
 * ```php
 * $hosts = Hosts::site( 'https://www.example.com', $surfaces->names() )
 *     ->with( 'files.example-cdn.com', ...( $local ? Hosts::LOOPBACK : array() ) );
 *
 * if ( ! $hosts->allows( $request ) ) {
 *     return Response::error( 'Not found.', 404 );
 * }
 * ```
 *
 * Hosts are compared as {@see self::normalize()} leaves them, the list's
 * and the request's alike, so `Example.COM.:443` is `example.com`, and
 * `[::1]:8080`, `[0:0::1]` and a bare `::1` are all `[::1]`. There are no
 * wildcards: a name is on the list or it is not.
 *
 * @since 1.14.0
 */
final readonly class Hosts {

	/**
	 * This machine, by the names a browser on it sends.
	 *
	 * For development only. On a server, a request naming localhost came
	 * through something that should not have passed it. Pair it with
	 * Request::is_local() wherever the answer unlocks anything.
	 *
	 * @since 1.14.0
	 *
	 * @var list<string>
	 */
	public const array LOOPBACK = array( 'localhost', '127.0.0.1', '[::1]' );

	/**
	 * The hosts, normalised, without repeats.
	 *
	 * @var list<string>
	 */
	private array $hosts;

	/**
	 * Name the hosts.
	 *
	 * Each is normalised, and one that does not normalise to anything — an
	 * empty setting, a value that is not a host — is dropped rather than
	 * kept as something nothing can match.
	 *
	 * @since 1.14.0
	 *
	 * @param list<string> $hosts Hosts as a Host header carries them, any
	 *                            case, with or without a port; a bare IPv6
	 *                            address; or an http(s) URL, whose host is
	 *                            taken.
	 */
	public function __construct( array $hosts ) {
		$this->hosts = array_values( array_unique( array_filter( array_map( self::entry( ... ), $hosts ), static fn ( string $host ): bool => '' !== $host ) ) );
	}

	/**
	 * A site's hosts: its domain, its www, and the subdomains given.
	 *
	 * A leading `www.` on the site is dropped first, so `www.example.com`
	 * and `example.com` give the same list. An IP address gives only
	 * itself: `www.127.0.0.1` is not a name anyone serves.
	 *
	 * ```php
	 * Hosts::site( 'https://example.com', array( 'api', 'admin' ) )->all();
	 * // example.com, www.example.com, api.example.com, admin.example.com
	 * ```
	 *
	 * @since 1.14.0
	 *
	 * @param string       $site       The site's host, or its URL. '' for
	 *                                 none, which gives an empty list.
	 * @param list<string> $subdomains Labels under the site, such as
	 *                                 Surfaces::names().
	 *
	 * @return self
	 */
	public static function site( string $site, array $subdomains = array() ): self {
		$site = self::entry( $site );

		if ( '' === $site ) {
			return new self( array() );
		}

		if ( str_starts_with( $site, '[' ) || false !== filter_var( $site, FILTER_VALIDATE_IP ) ) {
			return new self( array( $site ) );
		}

		$site = str_starts_with( $site, 'www.' ) ? substr( $site, 4 ) : $site;

		return new self( array( $site, 'www.' . $site, ...array_map( static fn ( string $label ): string => $label . '.' . $site, $subdomains ) ) );
	}

	/**
	 * The same list with more hosts on it.
	 *
	 * @since 1.14.0
	 *
	 * @param string ...$hosts Hosts, as the constructor takes them.
	 *
	 * @return self
	 */
	public function with( string ...$hosts ): self {
		return new self( array( ...$this->hosts, ...array_values( $hosts ) ) );
	}

	/**
	 * The hosts, normalised, in the order given.
	 *
	 * For Surfaces' `path_hosts`, which compares IPv6 in brackets as these
	 * are.
	 *
	 * @since 1.14.0
	 *
	 * @return list<string>
	 */
	public function all(): array {
		return $this->hosts;
	}

	/**
	 * Whether a request, or a Host header, names one of these hosts.
	 *
	 * Exact, after normalising: `admin.example.com.evil.example` and
	 * `evil-example.com` are not `example.com`. A Host that does not
	 * normalise — empty, a bad port, characters no hostname has — is
	 * never allowed.
	 *
	 * @since 1.14.0
	 *
	 * @param Request|string $host The request, or its Host header.
	 *
	 * @return bool
	 */
	public function allows( Request|string $host ): bool {
		$host = self::normalize( $host instanceof Request ? $host->host : $host );

		return '' !== $host && in_array( $host, $this->hosts, true );
	}

	/**
	 * A Host header as compared, or '' when it is not one.
	 *
	 * Lower case; the port dropped; one trailing dot dropped (`example.com.`
	 * is the same name, fully qualified). IPv6 comes back in brackets and
	 * in its shortest form — `[::1]:8080`, `[0:0:0:0:0:0:0:1]` and a bare
	 * `::1` are all `[::1]` — because that is how a Host header and a URL
	 * carry it, and so how a list of hosts is usually written. A bare
	 * address has no port: its colons are all its own.
	 *
	 * '' for anything else: an empty value, a port that is not a number up
	 * to 65535, an unclosed bracket, an empty label (`a..b`), a label over
	 * 63 characters, a name over 253, or any character outside letters,
	 * digits, `-`, `_` and `.` — so `evil.com\.example.com` and
	 * `example.com@evil` never reach a comparison. An internationalised
	 * name must be in its `xn--` form, as browsers send it.
	 *
	 * @since 1.14.0
	 *
	 * @param string $host Host header, any case, with or without a port.
	 *
	 * @return string e.g. `example.com`, `127.0.0.1`, `[::1]`, or ''.
	 */
	public static function normalize( string $host ): string {
		$host = strtolower( trim( $host ) );

		if ( str_starts_with( $host, '[' ) ) {
			if ( 1 !== preg_match( '/^\[([0-9a-f:.]+)\](?::(\d{0,5}))?$/D', $host, $parts ) || ! self::port( $parts[2] ?? '' ) ) {
				return '';
			}

			return self::ipv6( $parts[1] );
		}

		if ( substr_count( $host, ':' ) > 1 ) {
			return self::ipv6( $host );
		}

		[ $name, $port ] = array_pad( explode( ':', $host, 2 ), 2, '' );
		$name            = str_ends_with( $name, '.' ) ? substr( $name, 0, -1 ) : $name;

		if ( ! self::port( $port ) || strlen( $name ) > 253 || 1 !== preg_match( '/^[a-z0-9_-]{1,63}(?:\.[a-z0-9_-]{1,63})*$/D', $name ) ) {
			return '';
		}

		return $name;
	}

	/**
	 * A list entry, normalised: a host, or the host of an http(s) URL.
	 *
	 * @param string $entry Host or URL.
	 *
	 * @return string
	 */
	private static function entry( string $entry ): string {
		$entry = trim( $entry );

		if ( 1 === preg_match( '#^https?://#i', $entry ) ) {
			$entry = (string) parse_url( $entry, PHP_URL_HOST );
		}

		return self::normalize( $entry );
	}

	/**
	 * Whether what followed the colon is a port: digits up to 65535, or
	 * nothing (`example.com:` is allowed by RFC 3986, if never sent).
	 *
	 * @param string $port Candidate.
	 *
	 * @return bool
	 */
	private static function port( string $port ): bool {
		return '' === $port || ( 1 === preg_match( '/^\d{1,5}$/D', $port ) && (int) $port <= 65535 );
	}

	/**
	 * An IPv6 address, without brackets, as `[shortest form]`, or ''.
	 *
	 * @param string $ip Candidate.
	 *
	 * @return string
	 */
	private static function ipv6( string $ip ): string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return '';
		}

		$packed = inet_pton( $ip );
		$short  = false === $packed ? false : inet_ntop( $packed );

		return false === $short ? '' : '[' . $short . ']';
	}
}
