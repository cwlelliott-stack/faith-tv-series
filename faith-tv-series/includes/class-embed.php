<?php
/**
 * Embeds: one video section as its own small page, to put on other websites with an iframe.
 *
 *   https://yourchurch.com/?ftvs_embed=<category>&ftvs_layout=row&ftvs_theme=dark ...
 *
 * The companion assets/embed.js (loaded by the host page) lets the frame grow to fit,
 * including while the player is open.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Embed {

	public static function init() {
		// Late on init: our scripts are registered, and WordPress's main query never runs.
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 100 );
	}

	/** Web address of an embed with these options. */
	public static function url( $args ) {
		$query = array( 'ftvs_embed' => $args['category'] );
		foreach ( array( 'layout', 'theme', 'bg', 'title', 'eyebrow', 'limit', 'open' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
				$query[ 'ftvs_' . $key ] = $args[ $key ];
			}
		}
		return add_query_arg( array_map( 'rawurlencode', $query ), home_url( '/' ) );
	}

	/** The code to paste on another website. */
	public static function code( $args ) {
		$title = '' !== trim( (string) ( isset( $args['title'] ) ? $args['title'] : '' ) ) ? $args['title'] : __( 'Videos', 'faith-tv-series' );
		return '<iframe src="' . esc_url( self::url( $args ) ) . '" title="' . esc_attr( $title ) . '" data-ftvs-embed loading="lazy" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen style="width:100%;height:' . (int) self::start_height( isset( $args['layout'] ) ? $args['layout'] : '' ) . 'px;border:0;display:block"></iframe>' . "\n"
			. '<script src="' . esc_url( FTVS_URL . 'assets/embed.js' ) . '" async></script>';
	}

	/** A sensible height before the page tells the frame its real size. */
	public static function start_height( $layout ) {
		$heights = array(
			'showcase'  => 760,
			'coverflow' => 640,
			'list'      => 900,
			'row'       => 380,
			'grid'      => 720,
		);
		return isset( $heights[ $layout ] ) ? $heights[ $layout ] : 700;
	}

	public static function maybe_serve() {
		// phpcs:disable WordPress.Security.NonceVerification -- a public, read-only page.
		if ( ! isset( $_GET['ftvs_embed'] ) ) {
			return;
		}
		$get      = wp_unslash( $_GET );
		$value    = function ( $key, $default = '' ) use ( $get ) {
			return isset( $get[ 'ftvs_' . $key ] ) ? sanitize_text_field( $get[ 'ftvs_' . $key ] ) : $default;
		};
		$category = sanitize_text_field( $get['ftvs_embed'] );
		$layout   = in_array( $value( 'layout' ), FTVS_Renderer::LAYOUTS, true ) ? $value( 'layout' ) : null;
		$theme    = 'light' === $value( 'theme' ) ? 'light' : 'dark';
		$clear    = 'clear' === $value( 'bg' );
		$open     = 'channel' === $value( 'open' ) ? 'faithtv' : 'site';
		// phpcs:enable

		$html = FTVS_Renderer::render(
			array(
				'category' => $category,
				'layout'   => $layout,
				'theme'    => $theme,
				'title'    => $value( 'title' ),
				'eyebrow'  => $value( 'eyebrow' ),
				'limit'    => absint( $value( 'limit', '0' ) ),
				'play'     => $open,
			)
		);
		if ( false !== strpos( $html, 'ftvs-notice' ) || 0 === strpos( $html, '<!--' ) ) {
			// Nothing to show (bad category, or no church connected): say so plainly.
			$html = '<p style="margin:0;padding:24px;font:15px/1.5 system-ui,sans-serif;color:#888">' . esc_html__( 'These videos are not available right now.', 'faith-tv-series' ) . '</p>';
		}

		status_header( 200 );
		header_remove( 'X-Frame-Options' );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		header( 'Content-Security-Policy: frame-ancestors *' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'X-Robots-Tag: noindex' );

		$bg   = $clear ? 'transparent' : ( 'light' === $theme ? '#ffffff' : '#222222' );
		$text = 'light' === $theme ? '#1b1b1b' : '#ffffff';
		wp_add_inline_script( 'faith-tv-series', 'window.FTVS_CONFIG && (window.FTVS_CONFIG.embed = true);', 'before' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700;800&display=swap">
		<?php wp_print_styles( array( 'faith-tv-series' ) ); ?>
<style>
html,body{margin:0;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $text ); ?>}
body{padding:16px;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;overflow-x:hidden}
.ftvs,.ftvs-dialog{--ftvs-font:Roboto,"Helvetica Neue",Arial,sans-serif}
</style>
</head>
<body class="ftvs-embed">
		<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the renderer. ?>
		<?php wp_print_scripts( array( 'faith-tv-series' ) ); ?>
</body>
</html>
		<?php
		exit;
	}
}
