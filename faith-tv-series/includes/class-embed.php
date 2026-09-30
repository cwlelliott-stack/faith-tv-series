<?php
/**
 * Embeds: one video section as its own small page, to put on other websites with an iframe.
 *
 *   https://yourchurch.com/?ftvs_embed=<category>&ftvs_layout=row&ftvs_theme=dark&...&ftvs_sig=...
 *
 * Kinds: a category (any layout), one video, Sunday live, or the sermon library. The companion
 * assets/embed.js (loaded by the host page) lets the frame grow to fit, including while the
 * player is open. Every option is signed by the admin's embed builder: an address someone edits
 * by hand shows only the category with the default look, so nobody can put made-up words on
 * the church's address or make endless variations of the page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Embed {

	/** Options an embed can carry. */
	const OPTIONS = array( 'kind', 'video', 'layout', 'theme', 'bg', 'title', 'eyebrow', 'limit', 'open', 'preview' );
	// Look & feel settings the admin's preview tries before saving (signed, admin-made only).
	const LOOK = array( 'accent', 'layout', 'mobile_layout', 'theme', 'style', 'font', 'label', 'badge', 'powered_by' );

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
		foreach ( self::LOOK as $key ) {
			$keys[] = 'look_' . $key;
		}
		return $keys;
	}

	/** Web address of an embed with these options, signed. */
	public static function url( $args ) {
		$query = array( 'ftvs_embed' => isset( $args['category'] ) && '' !== (string) $args['category'] ? (string) $args['category'] : ( isset( $args['kind'] ) ? (string) $args['kind'] : '' ) );
		foreach ( self::option_keys() as $key ) {
			if ( isset( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) && ! ( 'limit' === $key && ! absint( $args[ $key ] ) ) && ! ( 'kind' === $key && 'category' === $args[ $key ] ) ) {
				$query[ 'ftvs_' . $key ] = trim( (string) $args[ $key ] );
			}
		}
		$query['ftvs_sig'] = self::sign( $query );
		return add_query_arg( array_map( 'rawurlencode', $query ), home_url( '/' ) );
	}

	/** The code to paste on another website. */
	public static function code( $args ) {
		$title = '' !== trim( (string) ( isset( $args['title'] ) ? $args['title'] : '' ) ) ? $args['title'] : __( 'Videos', 'faith-tv-series' );
		$kind  = isset( $args['kind'] ) ? $args['kind'] : 'category';
		$start = in_array( $kind, array( 'video', 'live', 'library' ), true ) ? $kind : ( isset( $args['layout'] ) ? $args['layout'] : '' );
		return '<iframe src="' . esc_url( self::url( $args ) ) . '" title="' . esc_attr( $title ) . '" data-ftvs-embed loading="lazy" allow="autoplay; fullscreen; picture-in-picture; web-share" allowfullscreen style="width:100%;height:' . (int) self::start_height( $start ) . 'px;border:0;display:block"></iframe>' . "\n"
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
			'video'     => 520,
			'live'      => 520,
			'library'   => 900,
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
		foreach ( self::OPTIONS as $key ) {
			if ( isset( $post[ $key ] ) && 'preview' !== $key ) {
				$args[ $key ] = sanitize_text_field( $post[ $key ] );
			}
		}
		foreach ( FTVS_Settings::TEXT_PARTS as $part ) {
			foreach ( array( '_size', '_color' ) as $what ) {
				if ( isset( $post[ $part . $what ] ) ) {
					$args[ $part . $what ] = sanitize_text_field( $post[ $part . $what ] );
				}
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
		$sig    = isset( $get['ftvs_sig'] ) ? (string) $get['ftvs_sig'] : '';
		$signed = '' !== $sig && ( hash_equals( self::sign( $query ), $sig ) || hash_equals( self::sign( $query, true ), $sig ) );
		if ( ! $signed ) {
			// Hand-made or edited address: the category with the default look, nothing else.
			$query = array( 'ftvs_embed' => $query['ftvs_embed'] );
		}
		$value = function ( $key, $default = '' ) use ( $query ) {
			return isset( $query[ 'ftvs_' . $key ] ) ? $query[ 'ftvs_' . $key ] : $default;
		};
		if ( $signed && '1' === $value( 'preview' ) ) {
			self::preview_look( $query );
		}

		$category = $query['ftvs_embed'];
		$kind     = in_array( $value( 'kind' ), array( 'video', 'live', 'library' ), true ) ? $value( 'kind' ) : 'category';
		$theme    = in_array( $value( 'theme' ), array( 'light', 'dark' ), true ) ? $value( 'theme' ) : FTVS_Settings::get( 'theme' );
		$clear    = 'clear' === $value( 'bg' );
		$atts     = array(
			'category' => $category,
			'layout'   => in_array( $value( 'layout' ), FTVS_Renderer::LAYOUTS, true ) ? $value( 'layout' ) : null,
			'theme'    => $theme,
			'limit'    => absint( $value( 'limit', '0' ) ),
			'play'     => 'channel' === $value( 'open' ) ? 'faithtv' : 'site',
			'title'    => $value( 'title' ),
			'eyebrow'  => $value( 'eyebrow' ),
		);
		foreach ( FTVS_Settings::TEXT_PARTS as $part ) {
			$atts[ $part . '_size' ]  = $value( $part . '_size' );
			$atts[ $part . '_color' ] = $value( $part . '_color' );
		}

		$html = '';
		if ( 'live' === $kind ) {
			$html = FTVS_Renderer::live( array( 'title' => $atts['title'], 'eyebrow' => $atts['eyebrow'], 'theme' => $theme ) );
		} elseif ( 'video' === $kind && '' !== $value( 'video' ) ) {
			$html = FTVS_Renderer::render( array_merge( $atts, array( 'video' => $value( 'video' ), 'category' => '' ) ) );
		} elseif ( 'library' === $kind ) {
			$html = FTVS_Renderer::library( array( 'category' => '@' === substr( $category, 0, 1 ) ? '' : $category, 'title' => $atts['title'], 'eyebrow' => $atts['eyebrow'], 'theme' => $theme ) );
		} elseif ( '@' === substr( $category, 0, 1 ) || FTVS_Catalog::is_known( 'gideo' === FTVS_Catalog::source() ? strtolower( $category ) : $category ) ) {
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
		if ( '1' === $value( 'preview' ) || is_user_logged_in() ) {
			header( 'Cache-Control: private, no-store' ); // an admin's preview, or what an editor sees
		} else {
			header( 'Cache-Control: ' . ( $signed ? 'public, max-age=300, s-maxage=600, stale-while-revalidate=86400' : 'public, max-age=120' ) );
		}
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
		<?php wp_print_styles( array( 'faith-tv-series' ) ); ?>
<style>
html,body{margin:0;background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $text ); ?>}
body{padding:16px;font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;overflow-x:hidden}
		<?php echo FTVS_Renderer::site_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from sanitized settings. ?>
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

	/** Look & feel preview: the settings being tried stand in for the saved ones on this page only. */
	private static function preview_look( $query ) {
		$look = array();
		foreach ( self::LOOK as $key ) {
			if ( isset( $query[ 'ftvs_look_' . $key ] ) ) {
				$look[ $key ] = $query[ 'ftvs_look_' . $key ];
			}
		}
		$text = array();
		foreach ( FTVS_Settings::TEXT_PARTS as $part ) {
			$text[ $part ] = array(
				'size'  => isset( $query[ 'ftvs_' . $part . '_size' ] ) ? $query[ 'ftvs_' . $part . '_size' ] : '',
				'color' => isset( $query[ 'ftvs_' . $part . '_color' ] ) ? $query[ 'ftvs_' . $part . '_color' ] : '',
			);
		}
		$look['text'] = $text;
		// Through sanitize, like a save would (before the filter, which sanitize itself would hit).
		$clean = array_intersect_key( FTVS_Settings::sanitize( $look ), $look );
		add_filter(
			'option_' . FTVS_Settings::OPTION,
			function ( $saved ) use ( $clean ) {
				return array_merge( is_array( $saved ) ? $saved : array(), $clean );
			}
		);
	}

	/** This site's own secret for signing embed addresses (kept apart from WordPress's login salts). */
	private static function secret() {
		$secret = (string) get_option( 'ftvs_embed_secret', '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'ftvs_embed_secret', $secret, true );
		}
		return $secret;
	}

	/** Short signature over the embed's options. $legacy checks addresses made by 1.2 (keyed with the auth salt). */
	private static function sign( $query, $legacy = false ) {
		unset( $query['ftvs_sig'] );
		ksort( $query );
		return substr( hash_hmac( 'sha256', http_build_query( $query ), $legacy ? wp_salt( 'auth' ) : self::secret() ), 0, 20 );
	}
}
