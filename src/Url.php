<?php
/**
 * A link to a path on this site, with a query string.
 *
 * @package   SugarCommerce\Http
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.9.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Http;

/**
 * Class Url
 *
 * For the links a list page builds back to itself — a filter, a sort, a
 * search box's results — where most of the parameters are usually empty.
 * http_build_query() keeps an empty string as `q=`, so a link built from a
 * form with nothing typed into it reads `?q=&status=&sort=`, and the page
 * it lands on has to treat every one of those as "not set" as well.
 *
 * Empty parameters are dropped instead, and a link with nothing left is the
 * path alone.
 *
 * @since 1.9.0
 */
final readonly class Url {

	/**
	 * A local path with the parameters that have a value.
	 *
	 * null and '' are always dropped. `$drop_zero` drops 0 and '0' too, for
	 * an id or a page number where zero means "none" — and is better left
	 * out of the link than carried round every page after it.
	 *
	 * The path is held to Request::local_path()'s rules, and anything else
	 * throws rather than becoming a link: a path built from the request
	 * that turned out to be `//evil.example` would send every link on the
	 * page to another site. A path that already has a query string gets the
	 * parameters after it, and a fragment stays at the end.
	 *
	 * ```php
	 * Url::to( '/admin/customers', array( 'q' => 'alf', 'status' => '' ) ); // '/admin/customers?q=alf'
	 * Url::to( '/admin/customers', array( 'q' => '' ) );                    // '/admin/customers'
	 * Url::to( '/orders', array( 'customer' => 0 ), drop_zero: true );      // '/orders'
	 * ```
	 *
	 * @since 1.9.0
	 *
	 * @param string               $path      A path on this site.
	 * @param array<string, mixed> $params    Query parameters. Only the top
	 *                                        level is filtered; an array is
	 *                                        passed to http_build_query() as
	 *                                        it is.
	 * @param bool                 $drop_zero Whether 0 and '0' are dropped.
	 *
	 * @return string
	 *
	 * @throws \InvalidArgumentException When the path is not a local path.
	 */
	public static function to( string $path, array $params = array(), bool $drop_zero = false ): string {
		if ( '' === Request::local_path( $path, '' ) ) {
			throw new \InvalidArgumentException( 'Not a local path: ' . json_encode( $path ) );
		}

		$query = http_build_query(
			array_filter(
				$params,
				static fn( mixed $value ): bool => null !== $value && '' !== $value && ! ( $drop_zero && ( 0 === $value || '0' === $value ) ),
			),
			'',
			'&',
		);

		if ( '' === $query ) {
			return $path;
		}

		$fragment = '';
		$hash     = strpos( $path, '#' );

		if ( false !== $hash ) {
			$fragment = substr( $path, $hash );
			$path     = substr( $path, 0, $hash );
		}

		$separator = match ( true ) {
			! str_contains( $path, '?' )                              => '?',
			str_ends_with( $path, '?' ) || str_ends_with( $path, '&' ) => '',
			default                                                  => '&',
		};

		return $path . $separator . $query . $fragment;
	}
}
