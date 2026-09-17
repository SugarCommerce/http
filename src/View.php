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
	 * @param string $root Directory templates are resolved against.
	 */
	public function __construct( private string $root ) {}

	/**
	 * Render a template inside a layout.
	 *
	 * @since 1.0.0
	 *
	 * @param string $template Path below the root, without .php.
	 * @param array  $data     Extracted into the template's scope.
	 * @param string $layout   Layout to wrap it in, or '' for none. The
	 *                         layout receives the rendered template as
	 *                         $content, plus the same data.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When the template does not exist.
	 */
	public function render( string $template, array $data = array(), string $layout = 'layout' ): string {
		$content = $this->capture( $template, $data );

		if ( '' === $layout ) {
			return $content;
		}

		return $this->capture( $layout, $data + array( 'content' => $content ) );
	}

	/**
	 * Run one template and return its output.
	 *
	 * @since 1.0.0
	 *
	 * @param string $template Path below the root, without .php.
	 * @param array  $data     Extracted into the template's scope.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When the template does not exist.
	 */
	private function capture( string $template, array $data ): string {
		$path = $this->root . '/' . $template . '.php';

		if ( ! is_file( $path ) ) {
			throw new \RuntimeException( "Template not found: {$template}" );
		}

		// Templates receive $e() for escaping; forgetting it should be hard.
		$e = static fn( mixed $value ): string => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );

		extract( $data, EXTR_SKIP );
		ob_start();
		require $path;

		return (string) ob_get_clean();
	}
}
