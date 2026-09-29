<?php
/**
 * Embeds: one video section as its own small page, to put on other websites with an iframe.
 *
 *   https://yourchurch.com/?ftvs_embed=<category>&ftvs_layout=row&ftvs_theme=dark&...&ftvs_sig=...
 *
 * The companion assets/embed.js (loaded by the host page) lets the frame grow to fit,
 * including while the player is open. Links are signed by the admin's embed builder, so a
 * heading or text on the church's own address can't be made up by someone else.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Embed {

	/** Options an embed can carry, in the order they appear in the address. */
	const OPTIONS = array( 'layout', 'theme', 'bg', 'title', 'eyebrow', 'limit', 'open' );

	public static function init() {
		// Late on init: our scripts are registered, and WordPress's main query never runs.
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 100 );
		add_action( 'wp_ajax_ftvs_embed_code', array( __CLASS__, 'ajax_code' ) );
	}

	private static function option_keys() {
		$keys = self::OPTIONS;
		foreach ( FTVS_Settings::TEXT_PARTS as $part ) {
			$keys[] = $part . '_size';
			$keys[] = $part . '_color';
		}
		return $keys;
	}

	/** Web address of an embed with these options, signed. */
	public static function url( $args ) {
		$query = array( 'ftvs_embed' => (string) $args['category'] );
		foreach ( self::option_keys() as $key ) {
			if ( isset( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) && ! ( 'limit' === $key && ! absint( $args[ $key ] ) ) ) {
				$query[ 'ftvs_' . $key ] = trim( (string) $args[ $key ] );
			}
		}
		$query['ftvs_sig'] = self::sign( $query );
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

	/** The admin's embed builder asks for the signed address and code as options change. */
	public static function ajax_code() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'ftvs_embed_code' );
		$post = wp_unslash( $_POST );
		$args = array( 'category' => isset( $post['category'] ) ? sanitize_text_field( $post['category'] ) : '' );
		foreach ( self::option_keys() as $key ) {
			if ( isset( $post[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( $post[ $key ] );
			}
		}
		wp_send_json_success(
			array(
				'url'  => self::url( $args ),
				'code' => self::code( $args ),
			)
		);
	}

	public static function maybe_serve() {
		// phpcs:disable WordPress.Security.NonceVerification -- a public, read-only page.
		if ( ! isset( $_GET['ftvs_embed'] ) ) {
			return;
		}
		$get   = wp_unslash( $_GET );
		$query = array( 'ftvs_embed' => sanitize_text_field( $get['ftvs_embed'] ) );
		foreach ( self::option_keys() as $key ) {
			if ( isset( $get[ 'ftvs_' . $key ] ) ) {
				$query[ 'ftvs_' . $key ] = sanitize_text_field( $get[ 'ftvs_' . $key ] );
			}
		}
		// phpcs:enable
		$signed = isset( $get['ftvs_sig'] ) && hash_equals( self::sign( $query ), (string) $get['ftvs_sig'] );
		$value  = function ( $key, $default = '' ) use ( $query ) {
			return isset( $query[ 'ftvs_' . $key ] ) ? $query[ 'ftvs_' . $key ] : $default;
		};

		$category = $query['ftvs_embed'];
		$theme    = 'light' === $value( 'theme' ) ? 'light' : 'dark';
		$clear    = 'clear' === $value( 'bg' );
		$atts     = array(
			'category' => $category,
			'layout'   => in_array( $value( 'layout' ), FTVS_Renderer::LAYOUTS, true ) ? $value( 'layout' ) : null,
			'theme'    => $theme,
			'limit'    => absint( $value( 'limit', '0' ) ),
			'play'     => 'channel' === $value( 'open' ) ? 'faithtv' : 'site',
			// Words shown on the church's address only when the link came from its own builder.
			'title'    => $signed ? $value( 'title' ) : '',
			'eyebrow'  => $signed ? $value( 'eyebrow' ) : '',
		);
		foreach ( FTVS_Settings::TEXT_PARTS as $part ) {
			$atts[ $part . '_size' ]  = $value( $part . '_size' );
			$atts[ $part . '_color' ] = $value( $part . '_color' );
		}

		$html = '';
		if ( FTVS_Catalog::is_known( 'gideo' === FTVS_Catalog::source() ? strtolower( $category ) : $category ) ) {
			$html = FTVS_Renderer::render( $atts );
		}
		if ( '' === $html || false !== strpos( $html, 'ftvs-notice' ) || 0 === strpos( $html, '<!--' ) ) {
			// Unknown category, or no church connected: say so plainly without calling the platform.
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

	/** Short signature over the embed's options, keyed with this site's secret salt. */
	private static function sign( $query ) {
		unset( $query['ftvs_sig'] );
		ksort( $query );
		return substr( hash_hmac( 'sha256', http_build_query( $query ), wp_salt( 'auth' ) ), 0, 20 );
	}
}
