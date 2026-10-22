<?php
/**
 * Template rendering.
 *
 * @package SugarCommerce\Http
 */

declare( strict_types=1 );

namespace SugarCommerce\Http;

/**
 * Class View
 *
 * Plain PHP templates, one layout deep. For an application with a handful of
 * pages, where a template engine would be more machinery than the thing it
 * renders, and where the templates are written by whoever writes the PHP.
 *
 * Not for templates you did not write. `require` executes them, so a template
 * is code — this is a renderer, not a sandbox.
 *
 * Every template is handed `$e()` for escaping, so forgetting it takes effort
 * rather than being the default.
 *
 * @since 1.0.0
 */
final readonly class View {

	/**
	 * Point the renderer at its templates.
	 *
	 * @param string $root Directory templates are resolved against.
	 */
	public function __construct( private string $root ) {}

	/**
	 * Render a template inside a layout.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $template Path below the root, without
	 *                                       .php.
	 * @param array<string, mixed> $data     Extracted into the template's
	 *                                       scope.
	 * @param string               $layout   Layout to wrap it in, or '' for
	 *                                       none. The layout receives the
	 *                                       rendered template as $content,
	 *                                       plus the same data.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException       When the template does not exist.
	 * @throws \InvalidArgumentException When a name would leave the root.
	 */
	public function render( string $template, array $data = array(), string $layout = 'layout' ): string {
		$content = $this->capture( $template, $data );

		if ( '' === $layout ) {
			return $content;
		}

		// The rendered template wins over a `content` key in the data. The
		// layout prints $content unescaped, because it is markup this
		// renderer produced — so a `content` in the data, which might be
		// anything the handler was given, must never take its place.
		return $this->capture( $layout, array( 'content' => $content ) + $data );
	}

	/**
	 * Run one template and return its output.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $template Path below the root, without
	 *                                       .php.
	 * @param array<string, mixed> $data     Extracted into the template's
	 *                                       scope.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException       When the template does not exist.
	 * @throws \InvalidArgumentException When the name would leave the root.
	 */
	private function capture( string $template, array $data ): string {
		// A template is code, so a name that climbs out of the root is a way
		// to run any .php file on the disk — and a name built from the URL,
		// `'pages/' . $slug`, is how one arrives. Refused outright rather
		// than cleaned up, so the caller finds out.
		if ( 1 === preg_match( '#(^|[/\\\\])\.\.?([/\\\\]|$)|[\x00-\x1F\x7F\\\\]#D', $template ) ) {
			throw new \InvalidArgumentException( 'Invalid template name: ' . json_encode( $template ) );
		}

		$path = $this->root . '/' . $template . '.php';

		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( "Template not found: {$template}" );
		}

		// Templates receive $e() for escaping; forgetting it should be hard.
		$e = static fn( mixed $value ): string => htmlspecialchars( self::text( $value ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		extract( $data, EXTR_SKIP );
		ob_start();
		require $path;

		return (string) ob_get_clean();
	}

	/**
	 * A template value as text, for $e().
	 *
	 * Scalars and anything with __toString() as themselves; null, arrays and
	 * other objects as '' — "Array" on a page, or a fatal error half-way
	 * through one, helps nobody.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $value Whatever the template was given.
	 *
	 * @return string
	 */
	private static function text( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}

		return is_scalar( $value ) || $value instanceof \Stringable ? (string) $value : '';
	}
}
