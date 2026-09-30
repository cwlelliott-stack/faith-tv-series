<?php
/**
 * The whole channel on one page of the church's website, laid out like the church's TV site: the big
 * banner, the Featured slider, a row of series for each section, series pages with a row per sub-series,
 * the player, search and Sunday live. Visitors never leave the website.
 *
 *   [faith_tv_channel]   (also the block "Faith TV Channel" and the Elementor widget of the same name)
 *
 * Every view is a real address, rendered on the server (search engines and people without JavaScript get
 * the same page), and the page script swaps views in place:
 *
 *   on the Watch page           /watch/  /watch/series/<id>/  /watch/<video>/?ftvs_series=<id>  /watch/live/<id>/
 *   on any other page           ?ftvs_series=<id>  ?ftvs_video=<id>  ?ftvs_live=<id>
 *   search (either)             ?ftvs_q=<words>
 *
 * Gideo does not say how its rows are laid out, so the first row of videos becomes the banner, a row named
 * "Featured" the slider, and rows of series become tiles; Faith Stream sends each row's layout. Either can be
 * changed per row under Faith Stream > Channel page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Channel {

	const SERIES_VAR = 'ftvs_series';
	const LIVE_VAR   = 'ftvs_live';
	const Q_VAR      = 'ftvs_q';
	// How a home row is laid out; "auto" is whatever the channel (or our guess) says.
	const STYLES = array( 'auto', 'hero', 'slider', 'tiles', 'videos', 'hide' );
	// A group page (Kids Rock) shows one row per series inside it; each is its own (cached) listing.
	const MAX_SECTIONS = 30;
	const REGISTRY_MAX = 3000;

	/** @var array|null The view this request shows (set on the channel's page before the theme prints). */
	private static $route = null;

	/** @var array|null id => { t, d, i, n, s, p }: what the channel said about each category it listed. */
	private static $registry = null;
	private static $registry_dirty = false;
	private static $registry_for = '';

	/** @var array post id => bool */
	private static $has_channel = array();

	/** @var int Channels on this page so far (for unique field ids). */
	private static $instances = 0;

	public static function init() {
		add_shortcode( 'faith_tv_channel', array( __CLASS__, 'shortcode' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'register_style' ), 6 );
		// After FTVS_Watch (priority 0) has found the message a /watch/<video>/ address asks for.
		add_action( 'wp', array( __CLASS__, 'on_request' ), 1 );
		add_action( 'shutdown', array( __CLASS__, 'save_registry' ) );
		// The channel has its own bar: the Hello Elementor theme's page title above it is left out.
		add_filter( 'hello_elementor_page_title', array( __CLASS__, 'theme_title' ) );
	}

	/** Hello Elementor shows the page's title above the content unless this says no. */
	public static function theme_title( $show ) {
		return is_singular() && self::page_has_channel( get_queried_object_id() ) ? false : $show;
	}

	public static function register_style() {
		wp_register_style( 'faith-tv-channel', FTVS_URL . 'assets/faith-tv-channel.css', array( 'faith-tv-series' ), FTVS_VERSION );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::SERIES_VAR;
		$vars[] = self::LIVE_VAR;
		return $vars;
	}

	/* ---------- Where the channel lives ---------- */

	/** Does this page (or post) hold the channel: shortcode, block or Elementor widget? */
	public static function page_has_channel( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		if ( isset( self::$has_channel[ $post->ID ] ) ) {
			return self::$has_channel[ $post->ID ];
		}
		$has = has_shortcode( $post->post_content, 'faith_tv_channel' ) || false !== strpos( $post->post_content, '<!-- wp:faith-tv/channel' );
		if ( ! $has ) {
			$el  = get_post_meta( $post->ID, '_elementor_data', true );
			$has = is_string( $el ) && false !== strpos( $el, '"widgetType":"faith_tv_channel"' );
		}
		self::$has_channel[ $post->ID ] = $has;
		return $has;
	}

	/** Is the channel on the Watch page? Then it has tidy addresses and every message's page is inside it. */
	public static function on_watch_page() {
		$page = FTVS_Watch::page_id();
		return $page && self::page_has_channel( $page );
	}

	/**
	 * How links are written for a channel on this page.
	 *
	 * @return array { page, base (the page's address, '' for "this address"), watch (it is the Watch page), pretty }
	 */
	public static function context( $page_id ) {
		$page_id = (int) $page_id;
		$post    = $page_id ? get_post( $page_id ) : null;
		if ( ! $post || ! in_array( $post->post_type, array( 'page', 'post' ), true ) || ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $page_id ) ) ) {
			$page_id = 0;
		}
		$watch  = $page_id && FTVS_Watch::page_id() === $page_id;
		$pretty = $watch && (bool) get_option( 'permalink_structure' );
		return array(
			'page'   => $page_id,
			// Tidy addresses are built the way message pages build theirs (FTVS_Watch::url), from the page's path.
			'base'   => $pretty ? self::under( $page_id, '' ) : ( $page_id ? (string) get_permalink( $page_id ) : '' ),
			'watch'  => $watch,
			'pretty' => $pretty,
		);
	}

	/** An address under the page: /watch/ and /watch/series/long-game/. */
	private static function under( $page_id, $rest ) {
		return home_url( user_trailingslashit( get_page_uri( $page_id ) . ( '' !== $rest ? '/' . $rest : '' ) ) );
	}

	/** The address of one view of the channel. */
	public static function link( $view, $id, $in, $ctx, $q = '' ) {
		$base = $ctx['base'];
		switch ( $view ) {
			case 'series':
				return $ctx['pretty'] ? self::under( $ctx['page'], 'series/' . rawurlencode( $id ) ) : add_query_arg( self::SERIES_VAR, rawurlencode( $id ), $base );
			case 'video':
				$url = $ctx['watch'] ? FTVS_Watch::url( $id ) : add_query_arg( FTVS_Watch::VAR, rawurlencode( $id ), $base );
				return '' !== $in ? add_query_arg( self::SERIES_VAR, rawurlencode( $in ), $url ) : $url;
			case 'live':
				return $ctx['pretty'] ? self::under( $ctx['page'], 'live/' . rawurlencode( $id ) ) : add_query_arg( self::LIVE_VAR, rawurlencode( $id ), $base );
			case 'search':
				return add_query_arg( self::Q_VAR, rawurlencode( $q ), $base );
		}
		return '' !== $base ? $base : '?';
	}

	/* ---------- The request ---------- */

	/** A channel id as the connected platform writes it, or '' (Gideo's ids are lowercase hex). */
	public static function clean_id( $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $raw ) ) {
			return '';
		}
		return 'gideo' === FTVS_Catalog::source() ? strtolower( $raw ) : $raw;
	}

	/**
	 * A view the channel can show: { v: home|series|video|live|search, id, in, q }. Anything unreadable is home.
	 */
	public static function route( $view, $id = '', $in = '', $q = '' ) {
		$route = array(
			'v'  => 'home',
			'id' => '',
			'in' => '',
			'q'  => '',
		);
		$view = is_string( $view ) ? $view : '';
		if ( 'search' === $view ) {
			$q          = is_string( $q ) ? trim( sanitize_text_field( $q ) ) : '';
			$route['v'] = 'search';
			$route['q'] = function_exists( 'mb_substr' ) ? mb_substr( $q, 0, 100 ) : substr( $q, 0, 100 );
			return $route;
		}
		if ( 'live' === $view ) {
			$route['v']  = 'live';
			$route['id'] = preg_replace( '/[^a-z0-9-]/', '', strtolower( is_string( $id ) ? $id : '' ) );
			$route['id'] = '' !== $route['id'] ? substr( $route['id'], 0, 128 ) : 'now';
			return $route;
		}
		if ( in_array( $view, array( 'series', 'video' ), true ) ) {
			$id = self::clean_id( $id );
			if ( '' !== $id ) {
				$route['v']  = $view;
				$route['id'] = $id;
				$route['in'] = 'video' === $view ? self::clean_id( $in ) : '';
			}
		}
		return $route;
	}

	/** The view the current page address asks for. */
	public static function from_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- reading which view to show.
		$video  = (string) get_query_var( FTVS_Watch::VAR );
		$series = (string) get_query_var( self::SERIES_VAR );
		$live   = (string) get_query_var( self::LIVE_VAR );
		$q      = isset( $_GET[ self::Q_VAR ] ) && is_string( $_GET[ self::Q_VAR ] ) ? wp_unslash( $_GET[ self::Q_VAR ] ) : null;
		// phpcs:enable
		if ( '' !== $video ) {
			return self::route( 'video', $video, $series );
		}
		if ( '' !== $series ) {
			return self::route( 'series', $series );
		}
		if ( '' !== $live ) {
			return self::route( 'live', $live );
		}
		if ( null !== $q ) {
			return self::route( 'search', '', '', $q );
		}
		return self::route( 'home' );
	}

	/**
	 * On the channel's own page: an unknown series is "not found", and each view gets its own title, address
	 * and description for search engines and link previews. (FTVS_Watch already did this for messages.)
	 */
	public static function on_request() {
		if ( is_admin() || ! is_singular() || ! self::page_has_channel( get_queried_object_id() ) ) {
			return;
		}
		$route = self::from_request();
		if ( 'home' === $route['v'] ) {
			return;
		}
		$hidden = FTVS_Catalog::is_demo() && ! current_user_can( 'edit_posts' );
		if ( $hidden || ( 'series' === $route['v'] && ! FTVS_Catalog::is_known( $route['id'] ) ) || ( 'video' === $route['v'] && ! self::resolve_video( $route['id'], $route['in'] ) ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		self::$route = $route;
		$ctx         = self::context( get_queried_object_id() );
		if ( 'search' === $route['v'] ) {
			// Search results are for people, not for search engines.
			add_filter(
				'wp_robots',
				function ( $robots ) {
					$robots['noindex'] = true;
					$robots['follow']  = true;
					return $robots;
				}
			);
		}
		$title = self::view_title( $route );
		if ( '' !== $title && ! ( 'video' === $route['v'] && null !== FTVS_Watch::current() ) ) {
			add_filter(
				'document_title_parts',
				function ( $parts ) use ( $title ) {
					$parts = is_array( $parts ) ? $parts : array();
					$page  = isset( $parts['title'] ) && '' !== $parts['title'] && $parts['title'] !== $title ? $parts['title'] : '';
					unset( $parts['title'] );
					// "Long Game - Watch - Faith Tabernacle": the view, then the page, then the rest (the site's name).
					return array_merge( array( 'title' => $title ), '' !== $page ? array( 'page' => $page ) : array(), $parts );
				},
				99
			);
		}
		if ( 'series' === $route['v'] ) {
			$info = self::category_info( $route['id'] );
			$url  = self::link( 'series', $route['id'], '', $ctx );
			$desc = '' !== $info['description'] ? wp_trim_words( $info['description'], 40, '…' ) : '';
			FTVS_Watch::speak_for( $title, $desc, $url, $info['image'] );
		}
	}

	/** The route this page shows (null when not on the channel's page, or on its home). */
	public static function current_route() {
		return self::$route;
	}

	/* ---------- Rendering ---------- */

	public static function defaults() {
		return array(
			'name'     => null,
			'logo'     => null,
			'backdrop' => null,
			'page'     => 0,
		);
	}

	public static function shortcode( $atts ) {
		return self::render( shortcode_atts( self::defaults(), $atts, 'faith_tv_channel' ) );
	}

	/** The channel: its own bar (name, search, menu) and the view the address asks for. */
	public static function render( $atts = array() ) {
		$atts = wp_parse_args( is_array( $atts ) ? $atts : array(), self::defaults() );
		if ( FTVS_Catalog::is_demo() && ! current_user_can( 'edit_posts' ) ) {
			return '<!-- Faith TV Series: sample videos are shown only to editors -->';
		}
		if ( ! FTVS_Catalog::connected() && ! FTVS_Manual::tree_row() ) {
			return FTVS_Renderer::problem( new WP_Error( 'ftvs_not_connected', __( 'No church is connected yet. Go to Faith Stream > Church in the WordPress admin.', 'faith-tv-series' ) ) );
		}
		$page = absint( $atts['page'] );
		if ( ! $page && ( is_singular() || in_the_loop() ) ) {
			$page = (int) get_the_ID();
		}
		$ctx   = self::context( $page );
		// The view the address asks for (checked and titled already on the channel's own page, see on_request()).
		$route = null !== self::$route ? self::$route : self::from_request();
		$view  = self::view( $route, $ctx );

		wp_enqueue_style( 'faith-tv-series' );
		wp_enqueue_style( 'faith-tv-channel' );
		wp_enqueue_script( 'faith-tv-series' );

		$name     = null !== $atts['name'] && '' !== trim( (string) $atts['name'] ) ? trim( (string) $atts['name'] ) : self::name();
		$logo     = null !== $atts['logo'] && '' !== trim( (string) $atts['logo'] ) ? esc_url_raw( trim( (string) $atts['logo'] ) ) : (string) FTVS_Settings::get( 'channel_logo' );
		$backdrop = null === $atts['backdrop'] || '' === $atts['backdrop'] ? (bool) FTVS_Settings::get( 'channel_backdrop' ) : ! in_array( strtolower( (string) $atts['backdrop'] ), array( 'no', 'off', '0', 'false' ), true );
		$home     = self::link( 'home', '', '', $ctx );
		$state    = wp_json_encode( self::state_of( $route ) );
		$style    = $backdrop && '' !== $view['backdrop'] ? '--ftvc-backdrop:' . self::css_url( $view['backdrop'] ) : '';

		ob_start();
		if ( FTVS_Catalog::is_demo() ) {
			echo '<div class="ftvs-demo-note" style="padding:10px 14px;border-left:4px solid #084073;background:#fff;color:#222;font:14px/1.4 sans-serif;margin:0 0 12px"><strong>' . esc_html__( 'Sample videos.', 'faith-tv-series' ) . '</strong> ' . esc_html__( 'Only people who can edit the site see this section. Connect your church under Faith Stream > Church to show your own videos.', 'faith-tv-series' ) . '</div>';
		}
		if ( ! $ctx['watch'] && $ctx['page'] && current_user_can( 'edit_posts' ) && ! FTVS_Watch::page_id() ) {
			echo '<div class="ftvs-notice" style="padding:10px 14px;border-left:4px solid #084073;background:#fff;color:#222;font:14px/1.4 sans-serif;margin:0 0 12px">' . esc_html__( 'Tip: choose this page as your Watch page (Faith Stream > Channel page) for tidy addresses like /watch/series/name/ and a page for every message. Only people who can edit the site see this note.', 'faith-tv-series' ) . '</div>';
		}
		?>
		<section class="<?php echo esc_attr( 'ftvc' . ( $backdrop ? ' ftvc--backdrop' : '' ) ); ?>" data-ftvs-channel data-page="<?php echo (int) $ctx['page']; ?>" data-home="<?php echo esc_url( $home ); ?>" data-video="<?php echo esc_url( self::link( 'video', 'FTVCID', '', $ctx ) ); ?>" data-video-in="<?php echo esc_url( self::link( 'video', 'FTVCID', 'FTVCIN', $ctx ) ); ?>"<?php echo FTVS_Catalog::has_live() || '' !== (string) FTVS_Settings::get( 'live_url' ) ? ' data-live-poll' : ''; ?> aria-label="<?php echo esc_attr( $name ); ?>"<?php echo '' !== $style ? ' style="' . esc_attr( $style ) . '"' : ''; ?>>
			<div class="ftvc-backdrop" aria-hidden="true"></div>
			<div class="ftvc-bar">
				<a class="ftvc-mark" href="<?php echo esc_url( $home ); ?>" data-ftvc="home||"><?php echo self::mark( $name, $logo ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?></a>
				<span class="ftvc-grow"></span>
				<form class="ftvc-search" role="search" method="get" action="<?php echo esc_url( '' !== $ctx['base'] ? strtok( $ctx['base'], '?' ) : '' ); ?>" data-ftvc-search<?php echo 'search' === $route['v'] ? ' data-open' : ''; ?>>
					<?php
					// Plain permalinks: the page's own ?page_id= has to ride along with the search words.
					$keep = array();
					if ( '' !== $ctx['base'] && false !== strpos( $ctx['base'], '?' ) ) {
						parse_str( (string) wp_parse_url( $ctx['base'], PHP_URL_QUERY ), $keep );
					}
					foreach ( $keep as $k => $v ) {
						if ( is_string( $v ) ) {
							echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
						}
					}
					$field = 'ftvc-q-' . ( ++self::$instances );
					?>
					<label class="ftvc-sr" for="<?php echo esc_attr( $field ); ?>"><?php esc_html_e( 'Search videos and series', 'faith-tv-series' ); ?></label>
					<input id="<?php echo esc_attr( $field ); ?>" type="search" name="<?php echo esc_attr( self::Q_VAR ); ?>" value="<?php echo esc_attr( $route['q'] ); ?>" placeholder="<?php esc_attr_e( 'Search videos and series', 'faith-tv-series' ); ?>" autocomplete="off">
				</form>
				<button type="button" class="ftvc-iconbtn" data-ftvc-search-toggle aria-expanded="<?php echo 'search' === $route['v'] ? 'true' : 'false'; ?>"><?php echo self::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'Search', 'faith-tv-series' ); ?></span></button>
				<button type="button" class="ftvc-iconbtn" data-ftvc-menu-toggle aria-expanded="false"><?php echo self::icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span><?php esc_html_e( 'Menu', 'faith-tv-series' ); ?></span></button>
				<nav class="ftvc-menu" data-ftvc-menu aria-label="<?php echo esc_attr( $name ); ?>" hidden>
					<?php echo self::menu( $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
				</nav>
			</div>
			<div class="ftvc-view" data-ftvc-view data-state="<?php echo esc_attr( $state ); ?>" data-title="<?php echo esc_attr( $view['title'] ); ?>" tabindex="-1">
				<?php echo $view['html']; // phpcs:ignore WordPress.Security.EscapeOutput -- every value is escaped where it is printed. ?>
			</div>
			<p class="ftvc-sr" aria-live="polite" data-ftvc-announce></p>
		</section>
		<?php
		return trim( ob_get_clean() );
	}

	/** What the page script needs to ask for this view again (history entries). */
	public static function state_of( $route ) {
		return array(
			'v'  => $route['v'],
			'id' => $route['id'],
			'in' => $route['in'],
			'q'  => $route['q'],
		);
	}

	/** The channel's name (its bar, and the page "Create my channel page" makes): the one set under Channel page, else Faith TV. */
	public static function name() {
		$name = trim( (string) FTVS_Settings::get( 'channel_name' ) );
		return '' !== $name ? $name : 'Faith TV';
	}

	/** A logo, or the name as a wordmark: "FAITH" in the church color and "TV" in white, like the TV site. */
	private static function mark( $name, $logo ) {
		if ( '' !== $logo && 0 === strpos( $logo, 'https://' ) ) {
			return '<img class="ftvc-mark__logo" src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '">';
		}
		$words = preg_split( '/\s+/', trim( $name ) );
		if ( count( $words ) < 2 ) {
			return self::icon( 'tv' ) . '<span class="ftvc-mark__word">' . esc_html( $name ) . '</span>';
		}
		$last = array_pop( $words );
		return self::icon( 'tv' ) . '<span class="ftvc-mark__word"><b>' . esc_html( implode( ' ', $words ) ) . '</b> ' . esc_html( $last ) . '</span>';
	}

	/** The bar's menu: home and every row. */
	private static function menu( $ctx ) {
		$out  = '<a href="' . esc_url( self::link( 'home', '', '', $ctx ) ) . '" data-ftvc="home||">' . esc_html__( 'Home', 'faith-tv-series' ) . '</a>';
		$rows = self::home_rows();
		if ( ! is_wp_error( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( 'hide' !== $row['style'] && 'hero' !== $row['style'] ) {
					$out .= '<a href="' . esc_url( self::link( 'series', $row['id'], '', $ctx ) ) . '" data-ftvc="series|' . esc_attr( $row['id'] ) . '|">' . esc_html( $row['title'] ) . '</a>';
				}
			}
		}
		return $out;
	}

	/**
	 * One view of the channel.
	 *
	 * @return array { html, title (for the page and screen readers), found (false: not on this channel), backdrop }
	 */
	public static function view( $route, $ctx ) {
		$out = array(
			'html'     => '',
			'title'    => '',
			'found'    => true,
			'backdrop' => '',
		);
		switch ( $route['v'] ) {
			case 'series':
				$out = array_merge( $out, self::series_view( $route['id'], $ctx ) );
				break;
			case 'video':
				$out = array_merge( $out, self::video_view( $route['id'], $route['in'], $ctx ) );
				break;
			case 'live':
				$out = array_merge( $out, self::live_view( $route['id'], $ctx ) );
				break;
			case 'search':
				$out = array_merge( $out, self::search_view( $route['q'], $ctx ) );
				break;
			default:
				$out = array_merge( $out, self::home_view( $ctx ) );
		}
		self::save_registry();
		return $out;
	}

	/** The view's title without rendering it (for the page's <title>). */
	public static function view_title( $route ) {
		switch ( $route['v'] ) {
			case 'series':
				return self::category_info( $route['id'] )['title'];
			case 'video':
				$video = self::resolve_video( $route['id'], $route['in'] );
				return $video ? $video['title'] : '';
			case 'live':
				return __( 'Live', 'faith-tv-series' );
			case 'search':
				return __( 'Search', 'faith-tv-series' );
		}
		return '';
	}

	/* ---------- Home ---------- */

	/**
	 * The channel's home rows, each laid out as the church (or its settings here) says.
	 *
	 * @return array|WP_Error List of { id, title, description, image, style, videos, categories }.
	 */
	public static function home_rows() {
		$over = (array) FTVS_Settings::get( 'channel_rows' );
		$rows = array();
		if ( 'faithstream' === FTVS_Catalog::source() && FTVS_Catalog::connected() ) {
			$home = FTVS_FaithStream_Client::get_home();
			if ( is_wp_error( $home ) ) {
				return $home;
			}
			foreach ( $home['rows'] as $row ) {
				$style = in_array( $row['style'], array( 'hero', 'slider', 'tiles', 'videos' ), true ) ? $row['style'] : ( $row['children'] ? 'tiles' : 'videos' );
				self::remember( '', array( $row['category'] ) );
				// A series in the Featured slider belongs to its own group, not to "Featured" (breadcrumbs, Back).
				self::remember( 'slider' === $style ? '' : $row['category']['id'], $row['children'] );
				$rows[] = array_merge(
					$row['category'],
					array(
						'style'      => $style,
						'videos'     => $row['videos'],
						'categories' => $row['children'],
					)
				);
			}
		} else {
			$tree = FTVS_Catalog::get_tree();
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}
			foreach ( $tree as $i => $row ) {
				$kids = isset( $row['children'] ) ? $row['children'] : array();
				if ( $kids ) {
					$style = preg_match( '/featured/i', $row['title'] ) ? 'slider' : 'tiles';
				} else {
					// The old TV site's banner is its first row when that row holds a video.
					$style = 0 === $i && ! empty( $row['videos'] ) ? 'hero' : 'videos';
				}
				self::remember( '', array( $row ) );
				self::remember( 'slider' === $style ? '' : $row['id'], $kids );
				$rows[] = array_merge(
					$row,
					array(
						'style'      => ! empty( $row['style'] ) && in_array( $row['style'], array( 'hero', 'slider', 'tiles', 'videos' ), true ) ? $row['style'] : $style,
						'videos'     => null, // fetched only when the row shows videos
						'categories' => $kids,
					)
				);
			}
		}
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['auto'] = $row['style'];
			if ( isset( $over[ $row['id'] ] ) && in_array( $over[ $row['id'] ], self::STYLES, true ) && 'auto' !== $over[ $row['id'] ] ) {
				$rows[ $i ]['style'] = $over[ $row['id'] ];
			}
		}
		return $rows;
	}

	/** A row's videos (Gideo lists them only when asked). */
	private static function row_videos( $row ) {
		if ( is_array( $row['videos'] ) ) {
			return $row['videos'];
		}
		$data = FTVS_Catalog::get_children( $row['id'] );
		return is_wp_error( $data ) ? array() : $data['videos'];
	}

	private static function home_view( $ctx ) {
		$rows = self::home_rows();
		if ( is_wp_error( $rows ) ) {
			return self::trouble( $rows );
		}
		foreach ( $rows as $i => $row ) {
			if ( in_array( $row['style'], array( 'hero', 'videos' ), true ) || ( 'hide' !== $row['style'] && ! $row['categories'] ) ) {
				$rows[ $i ]['videos'] = self::row_videos( $row );
			}
		}
		$live      = self::live_now();
		$hero_html = '';
		$backdrop  = '';
		$pinned    = null;
		foreach ( $rows as $row ) {
			if ( 'hero' === $row['style'] && $row['videos'] ) {
				$pinned = array( 'row' => $row['id'], 'video' => $row['videos'][0] );
				break;
			}
		}
		if ( $live ) {
			$hero_html = self::live_hero( $live, $ctx );
			$backdrop  = '' !== $live['image'] ? $live['image'] : ( $pinned ? self::big( $pinned['video'] ) : '' );
		} elseif ( $pinned ) {
			$hero_html = self::video_hero( $pinned['video'], $pinned['row'], true, $ctx );
			$backdrop  = self::big( $pinned['video'] );
		} else {
			$newest = self::newest_video( $rows );
			if ( $newest ) {
				$hero_html = self::video_hero( $newest, $newest['parent'], false, $ctx );
				$backdrop  = self::big( $newest );
			}
		}
		$html = '';
		foreach ( $rows as $row ) {
			if ( 'hide' === $row['style'] || ( 'hero' === $row['style'] && $pinned && ! $live ) ) {
				continue;
			}
			$style = 'hero' === $row['style'] ? 'videos' : $row['style'];
			if ( in_array( $style, array( 'slider', 'tiles' ), true ) && $row['categories'] ) {
				$items = array();
				foreach ( $row['categories'] as $cat ) {
					$items[] = 'slider' === $style ? self::feature_card( $cat, $ctx ) : self::tile( $cat, $ctx );
				}
				$html .= self::row( $row['title'], self::link( 'series', $row['id'], '', $ctx ), 'series|' . $row['id'] . '|', $style, $items );
				continue;
			}
			$videos = self::row_videos( $row );
			if ( ! $videos && $row['categories'] ) {
				$items = array();
				foreach ( $row['categories'] as $cat ) {
					$items[] = self::tile( $cat, $ctx );
				}
				$html .= self::row( $row['title'], self::link( 'series', $row['id'], '', $ctx ), 'series|' . $row['id'] . '|', 'tiles', $items );
				continue;
			}
			$items = array();
			foreach ( $videos as $video ) {
				$items[] = self::video_card( $video, $row['id'], $ctx );
			}
			$html .= self::row( $row['title'], self::link( 'series', $row['id'], '', $ctx ), 'series|' . $row['id'] . '|', 'videos', $items );
		}
		if ( '' === $html && '' === $hero_html ) {
			return array(
				'html'  => self::empty_state( __( 'Nothing here yet', 'faith-tv-series' ), __( 'Messages and series show up here as soon as they are published.', 'faith-tv-series' ) ),
				'title' => '',
			);
		}
		return array(
			'html'     => $hero_html . '<div class="ftvc-rows" data-ftvc-home data-live="' . ( $live ? '1' : '0' ) . '">' . $html . '</div>',
			'title'    => '',
			'backdrop' => $backdrop,
		);
	}

	/** Faith Stream sends its newest message; elsewhere, the newest video in the rows (no extra requests). */
	private static function newest_video( $rows ) {
		if ( 'faithstream' === FTVS_Catalog::source() ) {
			$home = FTVS_FaithStream_Client::get_home();
			if ( ! is_wp_error( $home ) && ! empty( $home['newest'] ) ) {
				return $home['newest'];
			}
		}
		$best = null;
		foreach ( $rows as $row ) {
			if ( 'hide' === $row['style'] || ! is_array( $row['videos'] ) ) {
				continue;
			}
			foreach ( $row['videos'] as $video ) {
				if ( ! $best || strtotime( (string) $video['added'] ) > strtotime( (string) $best['added'] ) ) {
					$best = $video;
				}
			}
		}
		return $best;
	}

	/** Live right now (Faith Stream, or service time with a pasted live link), else null. */
	private static function live_now() {
		if ( ! FTVS_Catalog::has_live() && '' === (string) FTVS_Settings::get( 'live_url' ) ) {
			return null;
		}
		$state = FTVS_Live::state();
		return 'live' === $state['status'] && ! empty( $state['play'] ) ? $state : null;
	}

	private static function video_hero( $video, $in, $pinned, $ctx ) {
		$meta = $pinned ? self::mins( $video['length'] ) : implode( '  ·  ', array_filter( array( $video['speaker'], self::date( $video['added'] ) ) ) );
		$url  = self::link( 'video', $video['id'], $in, $ctx );
		ob_start();
		?>
		<section class="ftvc-hero" aria-label="<?php echo esc_attr( $pinned ? __( 'Featured', 'faith-tv-series' ) : __( 'Latest message', 'faith-tv-series' ) ); ?>">
			<?php echo self::hero_img( self::big( $video ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="ftvc-hero__shade"></div>
			<div class="ftvc-hero__copy">
				<div>
					<?php if ( ! $pinned ) : ?>
						<p class="ftvc-eyebrow"><?php esc_html_e( 'Latest message', 'faith-tv-series' ); ?></p>
					<?php endif; ?>
					<h2 class="ftvc-hero__title" tabindex="-1"><?php echo esc_html( $video['title'] ); ?></h2>
					<?php if ( '' !== $meta ) : ?>
						<p class="ftvc-hero__meta"><?php echo esc_html( $meta ); ?></p>
					<?php endif; ?>
				</div>
				<a class="ftvc-pill" href="<?php echo esc_url( $url ); ?>" data-ftvc="<?php echo esc_attr( 'video|' . $video['id'] . '|' . $in ); ?>" data-ftvc-play><?php echo $pinned ? esc_html__( 'Watch now', 'faith-tv-series' ) : self::icon( 'play' ) . esc_html__( 'Play', 'faith-tv-series' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function live_hero( $live, $ctx ) {
		$id  = ! empty( $live['play']['id'] ) ? (string) $live['play']['id'] : 'now';
		$url = self::link( 'live', $id, '', $ctx );
		ob_start();
		?>
		<section class="ftvc-hero ftvc-hero--live" aria-label="<?php esc_attr_e( 'Live now', 'faith-tv-series' ); ?>">
			<?php echo self::hero_img( $live['image'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="ftvc-hero__shade"></div>
			<div class="ftvc-hero__copy">
				<div>
					<p class="ftvc-live"><?php esc_html_e( 'Live', 'faith-tv-series' ); ?></p>
					<h2 class="ftvc-hero__title" tabindex="-1"><?php echo esc_html( $live['title'] ); ?></h2>
					<?php if ( $live['viewers'] > 1 ) : ?>
						<p class="ftvc-hero__meta">
							<?php
							/* translators: %d: number of people */
							echo esc_html( sprintf( __( '%d watching', 'faith-tv-series' ), (int) $live['viewers'] ) );
							?>
						</p>
					<?php endif; ?>
				</div>
				<a class="ftvc-pill" href="<?php echo esc_url( $url ); ?>" data-ftvc="<?php echo esc_attr( 'live|' . $id . '|' ); ?>" data-ftvc-play><?php echo self::icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Watch live', 'faith-tv-series' ); ?></a>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function hero_img( $src ) {
		if ( '' === (string) $src ) {
			return '';
		}
		return '<img class="ftvc-hero__art" src="' . esc_url( $src ) . '" alt="" fetchpriority="high" decoding="async">';
	}

	/* ---------- Series ---------- */

	private static function series_view( $id, $ctx ) {
		if ( ! FTVS_Catalog::is_known( $id ) ) {
			return self::not_found( $ctx );
		}
		$data = FTVS_Catalog::get_children( $id );
		if ( is_wp_error( $data ) ) {
			return 'ftvs_gone' === $data->get_error_code() ? self::not_found( $ctx ) : self::trouble( $data );
		}
		if ( ! empty( $data['self'] ) ) {
			self::remember( '', array( $data['self'] ) );
		}
		self::remember( $id, $data['categories'] );
		$info     = self::category_info( $id );
		$crumbs   = self::crumbs( $id, $data );
		$parent   = $crumbs ? $crumbs[ count( $crumbs ) - 1 ] : null;
		$videos   = $data['videos'];
		$sections = $videos ? array() : self::sections( $data );
		$art      = '' !== $info['image'] ? $info['image'] : ( $videos ? self::big( $videos[0] ) : '' );
		if ( '' === $art ) {
			foreach ( $sections as $s ) {
				$art = $s['videos'] ? self::big( $s['videos'][0] ) : ( $s['categories'] ? $s['categories'][0]['image'] : '' );
				if ( '' !== $art ) {
					break;
				}
			}
		}
		ob_start();
		?>
		<header class="ftvc-series">
			<?php echo self::back( $parent ? self::link( 'series', $parent['id'], '', $ctx ) : self::link( 'home', '', '', $ctx ), $parent ? 'series|' . $parent['id'] . '|' : 'home||' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="ftvc-series__copy">
				<?php echo self::crumb_list( $crumbs, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<h2 class="ftvc-series__title" tabindex="-1"><?php echo esc_html( $info['title'] ); ?></h2>
				<?php if ( '' !== $info['description'] ) : ?>
					<div class="ftvc-series__desc"><?php echo wp_kses_post( wpautop( esc_html( $info['description'] ) ) ); ?></div>
				<?php endif; ?>
				<p class="ftvc-series__count">
					<?php
					if ( $videos ) {
						/* translators: %s: number of videos */
						echo esc_html( sprintf( _n( '%s video', '%s videos', count( $videos ), 'faith-tv-series' ), number_format_i18n( count( $videos ) ) ) );
					} elseif ( $data['categories'] ) {
						/* translators: %s: number of series */
						echo esc_html( sprintf( _n( '%s series', '%s series', count( $data['categories'] ), 'faith-tv-series' ), number_format_i18n( count( $data['categories'] ) ) ) );
					}
					?>
				</p>
				<?php if ( $videos ) : ?>
					<div class="ftvc-series__actions">
						<a class="ftvc-pill ftvc-pill--small" href="<?php echo esc_url( self::link( 'video', $videos[0]['id'], $id, $ctx ) ); ?>" data-ftvc="<?php echo esc_attr( 'video|' . $videos[0]['id'] . '|' . $id ); ?>" data-ftvc-play><?php echo self::icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Play from the start', 'faith-tv-series' ); ?></a>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( '' !== $art ) : ?>
				<div class="ftvc-series__art"><?php echo self::art( $art, $info['title'], '', true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
			<?php endif; ?>
		</header>
		<?php
		if ( $sections ) {
			echo '<div class="ftvc-rows">';
			foreach ( $sections as $s ) {
				$items = array();
				if ( $s['videos'] ) {
					foreach ( $s['videos'] as $video ) {
						$items[] = self::video_card( $video, $s['category']['id'], $ctx );
					}
					$style = 'videos';
				} else {
					foreach ( $s['categories'] as $cat ) {
						$items[] = self::tile( $cat, $ctx );
					}
					$style = 'tiles';
				}
				echo self::row( $s['category']['title'], self::link( 'series', $s['category']['id'], '', $ctx ), 'series|' . $s['category']['id'] . '|', $style, $items ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</div>';
		} elseif ( $data['categories'] && $videos ) {
			echo '<div class="ftvc-rows"><div><h3 class="ftvc-label">' . esc_html__( 'Series', 'faith-tv-series' ) . '</h3><ul class="ftvc-grid" role="list">';
			foreach ( $data['categories'] as $cat ) {
				echo '<li>' . self::tile( $cat, $ctx ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul></div></div>';
		}
		if ( $videos ) {
			echo '<div class="ftvc-rows"><div>' . ( $data['categories'] ? '<h3 class="ftvc-label">' . esc_html__( 'Videos', 'faith-tv-series' ) . '</h3>' : '' ) . '<ul class="ftvc-grid" role="list">';
			foreach ( $videos as $video ) {
				echo '<li>' . self::video_card( $video, $id, $ctx ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul></div></div>';
		}
		if ( ! $videos && ! $sections && ! $data['categories'] ) {
			echo self::empty_state( __( 'Nothing here yet', 'faith-tv-series' ), __( 'Videos filed here show up as soon as they are published.', 'faith-tv-series' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return array(
			'html'     => ob_get_clean(),
			'title'    => $info['title'],
			'backdrop' => $art,
		);
	}

	/**
	 * A group of series (Kids Rock): one row per series inside it. Faith Stream sends them with the group;
	 * Gideo lists each series on its own (every listing is cached).
	 *
	 * @return array List of { category, videos, categories }.
	 */
	private static function sections( $data ) {
		$out = array();
		if ( ! empty( $data['sections'] ) ) {
			foreach ( $data['sections'] as $s ) {
				self::remember( $s['category']['id'], $s['categories'] );
				if ( $s['videos'] || $s['categories'] ) {
					$out[] = $s;
				}
			}
			return $out;
		}
		foreach ( array_slice( $data['categories'], 0, self::MAX_SECTIONS ) as $cat ) {
			$inside = FTVS_Catalog::get_children( $cat['id'] );
			if ( is_wp_error( $inside ) ) {
				continue;
			}
			self::remember( $cat['id'], $inside['categories'] );
			if ( $inside['videos'] || $inside['categories'] ) {
				$out[] = array(
					'category'   => $cat,
					'videos'     => $inside['videos'],
					'categories' => $inside['categories'],
				);
			}
		}
		return $out;
	}

	/** The series above this one, top first (Faith Stream says; for Gideo, what this site has seen). */
	private static function crumbs( $id, $data = null ) {
		if ( is_array( $data ) && ! empty( $data['crumbs'] ) ) {
			return array_values(
				array_filter(
					$data['crumbs'],
					function ( $c ) use ( $id ) {
						return $c['id'] !== $id;
					}
				)
			);
		}
		$out  = array();
		$seen = array( $id => 1 );
		$info = self::registry_get( $id );
		while ( $info && '' !== $info['p'] && ! isset( $seen[ $info['p'] ] ) && count( $out ) < 6 ) {
			$seen[ $info['p'] ] = 1;
			$parent             = self::registry_get( $info['p'] );
			array_unshift(
				$out,
				array(
					'id'    => $info['p'],
					'title' => $parent ? $parent['t'] : '',
				)
			);
			$info = $parent;
		}
		return array_values(
			array_filter(
				$out,
				function ( $c ) {
					return '' !== $c['title'];
				}
			)
		);
	}

	private static function crumb_list( $crumbs, $ctx ) {
		if ( ! $crumbs ) {
			return '';
		}
		$out = '<ol class="ftvc-crumbs">';
		foreach ( $crumbs as $c ) {
			$out .= '<li><a href="' . esc_url( self::link( 'series', $c['id'], '', $ctx ) ) . '" data-ftvc="' . esc_attr( 'series|' . $c['id'] . '|' ) . '">' . esc_html( $c['title'] ) . '</a></li>';
		}
		return $out . '</ol>';
	}

	/** A category's title, description and picture, from what the channel has said about it. */
	public static function category_info( $id ) {
		$info = self::registry_get( $id );
		if ( ! $info || '' === $info['t'] ) {
			// Not listed yet on this site: its own listing names it (Faith Stream), or the category list does.
			$data = FTVS_Catalog::is_known( $id ) ? FTVS_Catalog::get_children( $id ) : null;
			if ( is_array( $data ) && ! empty( $data['self'] ) ) {
				self::remember( '', array( $data['self'] ) );
			}
			$info = self::registry_get( $id );
			if ( ! $info ) {
				$title = FTVS_Catalog::title_of( $id );
				$info  = array(
					't' => $title,
					'd' => '',
					'i' => '',
				);
			}
		}
		return array(
			'id'          => $id,
			'title'       => '' !== $info['t'] ? $info['t'] : __( 'Series', 'faith-tv-series' ),
			'description' => $info['d'],
			'image'       => $info['i'],
		);
	}

	/* ---------- One video ---------- */

	/**
	 * A video's details: from the series it was opened in, else the site's library, else the platform itself.
	 *
	 * @return array|null Video shape.
	 */
	public static function resolve_video( $id, $in = '' ) {
		$current = FTVS_Watch::current();
		if ( $current && $current['id'] === $id ) {
			return $current;
		}
		// The series it was opened in first: a shared link works even before this site has listed that series.
		if ( '' !== $in && FTVS_Catalog::is_known( $in ) ) {
			$list = FTVS_Catalog::get_children( $in );
			if ( ! is_wp_error( $list ) ) {
				foreach ( $list['videos'] as $video ) {
					if ( $video['id'] === $id ) {
						return $video;
					}
				}
			}
		}
		if ( ! FTVS_Catalog::is_known( $id ) ) {
			return null;
		}
		$video = FTVS_Catalog::find_video( $id );
		if ( $video ) {
			return $video;
		}
		if ( 'faithstream' === FTVS_Catalog::source() ) {
			$data = FTVS_Catalog::get_video( $id );
			if ( ! is_wp_error( $data ) && isset( $data['title'] ) ) {
				return $data;
			}
		}
		return null;
	}

	/** The series a video is watched in: the one it was opened from, else its smallest (the series itself). */
	private static function series_for( $video, $in ) {
		if ( '' !== $in && FTVS_Catalog::is_known( $in ) ) {
			return $in;
		}
		if ( 'faithstream' === FTVS_Catalog::source() ) {
			$data = FTVS_Catalog::get_video( $video['id'] );
			if ( ! is_wp_error( $data ) && ! empty( $data['series'] ) ) {
				$best = null;
				foreach ( $data['series'] as $i => $s ) {
					$info  = self::registry_get( $s['id'] );
					$count = $info && $info['n'] > 0 ? $info['n'] : PHP_INT_MAX - 1000 + $i;
					if ( null === $best || $count < $best[1] ) {
						$best = array( $s['id'], $count );
					}
				}
				return $best[0];
			}
		}
		return isset( $video['parent'] ) ? (string) $video['parent'] : '';
	}

	private static function video_view( $id, $in, $ctx ) {
		$video = self::resolve_video( $id, $in );
		if ( ! $video ) {
			return self::not_found( $ctx );
		}
		$series_id = self::series_for( $video, $in );
		$episodes  = array();
		if ( '' !== $series_id && FTVS_Catalog::is_known( $series_id ) ) {
			$list = FTVS_Catalog::get_children( $series_id );
			if ( ! is_wp_error( $list ) ) {
				$episodes = $list['videos'];
				if ( ! empty( $list['self'] ) ) {
					self::remember( '', array( $list['self'] ) );
				}
			}
		}
		$series  = '' !== $series_id ? self::category_info( $series_id ) : null;
		$related = array();
		if ( 'faithstream' === FTVS_Catalog::source() ) {
			$data = FTVS_Catalog::get_video( $id );
			if ( ! is_wp_error( $data ) && ! empty( $data['related'] ) ) {
				$in_list = array_flip( wp_list_pluck( $episodes, 'id' ) );
				foreach ( $data['related'] as $r ) {
					if ( $r['id'] !== $id && ! isset( $in_list[ $r['id'] ] ) ) {
						$related[] = $r;
					}
				}
			}
		}
		$meta  = array_filter( array( $video['speaker'], self::date( $video['added'] ), $video['scripture'], self::mins( $video['length'] ) ) );
		$share = FTVS_Watch::url( $id );
		$share = '' !== $share ? $share : self::link( 'video', $id, $series_id, $ctx );
		$eps   = array();
		foreach ( $episodes as $ep ) {
			$eps[] = array(
				'id'    => $ep['id'],
				'title' => $ep['title'],
				'image' => $ep['image'],
				'url'   => self::link( 'video', $ep['id'], $series_id, $ctx ),
			);
		}
		$item = json_decode( FTVS_Renderer::item_json( $video ), true );
		$item['parent'] = $series_id;
		ob_start();
		?>
		<div class="ftvc-watch" data-ftvc-video="<?php echo esc_attr( wp_json_encode( $item ) ); ?>" data-ftvc-eps="<?php echo esc_attr( wp_json_encode( $eps ) ); ?>" data-series-title="<?php echo esc_attr( $series ? $series['title'] : '' ); ?>">
			<?php echo self::stage( self::big( $video ), $video['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="ftvc-watch__info">
				<?php echo self::back( $series ? self::link( 'series', $series_id, '', $ctx ) : self::link( 'home', '', '', $ctx ), $series ? 'series|' . $series_id . '|' : 'home||' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div class="ftvc-watch__copy">
					<?php if ( $series ) : ?>
						<ol class="ftvc-crumbs"><li><a href="<?php echo esc_url( self::link( 'series', $series_id, '', $ctx ) ); ?>" data-ftvc="<?php echo esc_attr( 'series|' . $series_id . '|' ); ?>"><?php echo esc_html( $series['title'] ); ?></a></li></ol>
					<?php endif; ?>
					<h2 class="ftvc-watch__title" tabindex="-1"><?php echo esc_html( $video['title'] ); ?></h2>
					<?php if ( $meta ) : ?>
						<p class="ftvc-watch__meta"><?php echo esc_html( implode( '  ·  ', $meta ) ); ?></p>
					<?php endif; ?>
					<?php if ( FTVS_Settings::get( 'share' ) ) : ?>
						<div class="ftvc-watch__actions">
							<button type="button" class="ftvc-pill ftvc-pill--small ftvc-pill--quiet" data-ftvs-share="<?php echo esc_attr( $share ); ?>" data-title="<?php echo esc_attr( $video['title'] ); ?>"><?php echo self::icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Share', 'faith-tv-series' ); ?></button>
						</div>
					<?php endif; ?>
					<?php if ( '' !== $video['description'] ) : ?>
						<div class="ftvc-watch__desc"><?php echo wp_kses_post( wpautop( esc_html( $video['description'] ) ) ); ?></div>
					<?php endif; ?>
					<?php
					$steps = $video;
					$steps['parent'] = $series_id;
					echo FTVS_Renderer::next_steps_html( $steps ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
					?>
				</div>
			</div>
			<div class="ftvc-rows">
				<?php
				if ( count( $episodes ) > 1 ) {
					$items = array();
					foreach ( $episodes as $ep ) {
						$items[] = self::video_card( $ep, $series_id, $ctx, $ep['id'] === $id );
					}
					/* translators: %s: series name */
					echo self::row( sprintf( __( 'More from %s', 'faith-tv-series' ), $series['title'] ), self::link( 'series', $series_id, '', $ctx ), 'series|' . $series_id . '|', 'videos', $items ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				if ( $related ) {
					$items = array();
					foreach ( array_slice( $related, 0, 16 ) as $r ) {
						$items[] = self::video_card( $r, '', $ctx );
					}
					echo self::row( __( 'You may also like', 'faith-tv-series' ), '', '', 'videos', $items ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</div>
		</div>
		<?php
		return array(
			'html'     => ob_get_clean(),
			'title'    => $video['title'],
			'backdrop' => self::big( $video ),
		);
	}

	/** The player's place: the poster and a play button until the page script takes over. */
	private static function stage( $poster, $title ) {
		ob_start();
		?>
		<div class="ftvc-stage">
			<div class="ftvc-stage__in">
				<video class="ftvc-stage__video" playsinline controls preload="none"<?php echo '' !== $poster ? ' poster="' . esc_url( $poster ) . '"' : ''; ?> aria-label="<?php echo esc_attr( $title ); ?>"></video>
				<button type="button" class="ftvc-stage__play" data-ftvc-start aria-label="<?php echo esc_attr( sprintf( /* translators: %s: video title */ __( 'Play %s', 'faith-tv-series' ), $title ) ); ?>"><?php echo self::icon( 'play', 38 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
				<p class="ftvc-stage__msg" role="alert" hidden></p>
				<div class="ftvc-chip" role="status" hidden></div>
				<div class="ftvc-upnext" hidden></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------- Live ---------- */

	private static function live_view( $id, $ctx ) {
		$state = FTVS_Live::state( 'now' === $id ? '' : $id );
		$on    = 'live' === $state['status'] && ! empty( $state['play'] );
		$play  = $on ? $state['play'] : null;
		ob_start();
		?>
		<div class="ftvc-watch ftvc-watch--live" data-ftvc-live="<?php echo esc_attr( wp_json_encode( array( 'on' => $on, 'channel' => 'now' === $id ? '' : $id, 'play' => $play, 'title' => $state['title'], 'image' => $state['image'] ) ) ); ?>">
			<?php if ( $on ) : ?>
				<?php echo self::stage( $state['image'], $state['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php else : ?>
				<div class="ftvc-stage">
					<div class="ftvc-stage__in">
						<?php if ( '' !== $state['image'] ) : ?>
							<img class="ftvc-stage__poster" src="<?php echo esc_url( $state['image'] ); ?>" alt="">
						<?php endif; ?>
						<div class="ftvc-stage__off">
							<strong><?php esc_html_e( 'We are not live right now', 'faith-tv-series' ); ?></strong>
							<?php if ( '' !== $state['next_label'] ) : ?>
								<span>
									<?php
									/* translators: %s: day and time */
									echo esc_html( sprintf( __( 'Next service: %s', 'faith-tv-series' ), $state['next_label'] ) );
									?>
								</span>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endif; ?>
			<div class="ftvc-watch__info">
				<?php echo self::back( self::link( 'home', '', '', $ctx ), 'home||' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div class="ftvc-watch__copy">
					<?php if ( $on ) : ?>
						<p class="ftvc-live"><?php esc_html_e( 'Live', 'faith-tv-series' ); ?></p>
					<?php endif; ?>
					<h2 class="ftvc-watch__title" tabindex="-1"><?php echo esc_html( $state['title'] ); ?></h2>
					<?php if ( $on && $state['viewers'] > 1 ) : ?>
						<p class="ftvc-watch__meta">
							<?php
							/* translators: %d: number of people */
							echo esc_html( sprintf( __( '%d watching', 'faith-tv-series' ), (int) $state['viewers'] ) );
							?>
						</p>
					<?php endif; ?>
					<?php if ( $on ) : ?>
						<div class="ftvs-ci" data-ftvc-checkin hidden></div>
					<?php endif; ?>
					<?php if ( $on && '' !== self::http_url( $state['chat'] ) ) : ?>
						<p><a class="ftvc-pill ftvc-pill--small ftvc-pill--quiet" href="<?php echo esc_url( $state['chat'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the chat', 'faith-tv-series' ); ?></a></p>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( ! $on && ! empty( $state['replay'] ) ) : ?>
				<div class="ftvc-rows">
					<?php echo self::row( __( 'Watch the replay', 'faith-tv-series' ), '', '', 'videos', array( self::video_card( $state['replay'], '', $ctx ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return array(
			'html'     => ob_get_clean(),
			'title'    => $state['title'],
			'backdrop' => $state['image'],
		);
	}

	/* ---------- Search ---------- */

	private static function search_view( $q, $ctx ) {
		$videos = array();
		$series = array();
		if ( strlen( $q ) >= 2 ) {
			$found = FTVS_Catalog::search( $q );
			if ( is_wp_error( $found ) ) {
				return self::trouble( $found );
			}
			$videos = array_slice( $found, 0, 60 );
			$series = self::search_series( $q );
		}
		ob_start();
		?>
		<header class="ftvc-series ftvc-series--plain">
			<?php echo self::back( self::link( 'home', '', '', $ctx ), 'home||' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="ftvc-series__copy">
				<ol class="ftvc-crumbs"><li><?php esc_html_e( 'Search', 'faith-tv-series' ); ?></li></ol>
				<h2 class="ftvc-series__title" tabindex="-1">
					<?php
					/* translators: %s: search words */
					echo strlen( $q ) < 2 ? esc_html__( 'Search', 'faith-tv-series' ) : esc_html( sprintf( __( 'Results for "%s"', 'faith-tv-series' ), $q ) );
					?>
				</h2>
				<p class="ftvc-series__count">
					<?php
					if ( strlen( $q ) < 2 ) {
						esc_html_e( 'Type at least two letters.', 'faith-tv-series' );
					} else {
						/* translators: %s: number of videos */
						$found_videos = sprintf( _n( '%s video', '%s videos', count( $videos ), 'faith-tv-series' ), number_format_i18n( count( $videos ) ) );
						/* translators: %s: number of series */
						$found_series = sprintf( _n( '%s series', '%s series', count( $series ), 'faith-tv-series' ), number_format_i18n( count( $series ) ) );
						echo esc_html( $found_videos . ' · ' . $found_series );
					}
					?>
				</p>
			</div>
		</header>
		<?php
		if ( $series ) {
			echo '<div class="ftvc-rows"><div><h3 class="ftvc-label">' . esc_html__( 'Series', 'faith-tv-series' ) . '</h3><ul class="ftvc-grid" role="list">';
			foreach ( $series as $cat ) {
				echo '<li>' . self::tile( $cat, $ctx ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul></div></div>';
		}
		if ( $videos ) {
			echo '<div class="ftvc-rows"><div><h3 class="ftvc-label">' . esc_html__( 'Videos', 'faith-tv-series' ) . '</h3><ul class="ftvc-grid" role="list">';
			foreach ( $videos as $video ) {
				echo '<li>' . self::video_card( $video, '', $ctx ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul></div></div>';
		}
		if ( strlen( $q ) >= 2 && ! $videos && ! $series ) {
			echo self::empty_state( __( 'Nothing matched', 'faith-tv-series' ), __( 'Try a series name, a speaker or a word from the title.', 'faith-tv-series' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return array(
			'html'  => ob_get_clean(),
			'title' => strlen( $q ) < 2 ? __( 'Search', 'faith-tv-series' ) : sprintf( /* translators: %s: search words */ __( 'Results for "%s"', 'faith-tv-series' ), $q ),
		);
	}

	/** Series whose name or description has every word (Faith Stream also searches its own). */
	private static function search_series( $q ) {
		$lower = function_exists( 'mb_strtolower' ) ? 'mb_strtolower' : 'strtolower';
		$words = array_filter( preg_split( '/\s+/', $lower( $q ) ) );
		$out   = array();
		$tree  = FTVS_Catalog::get_tree();
		$pool  = array();
		foreach ( is_wp_error( $tree ) ? array() : $tree as $row ) {
			$pool[] = $row;
			foreach ( $row['children'] as $child ) {
				$pool[] = $child;
			}
		}
		foreach ( $pool as $cat ) {
			$hay = $lower( $cat['title'] . ' ' . $cat['description'] );
			$hit = true;
			foreach ( $words as $word ) {
				if ( false === strpos( $hay, $word ) ) {
					$hit = false;
					break;
				}
			}
			if ( $hit && ! isset( $out[ $cat['id'] ] ) ) {
				$out[ $cat['id'] ] = $cat;
			}
		}
		if ( 'faithstream' === FTVS_Catalog::source() ) {
			$more = FTVS_FaithStream_Client::search( $q );
			if ( ! is_wp_error( $more ) ) {
				foreach ( $more['categories'] as $cat ) {
					if ( ! isset( $out[ $cat['id'] ] ) ) {
						$out[ $cat['id'] ] = $cat;
					}
				}
			}
		}
		$out = array_values( $out );
		self::remember( '', $out );
		return array_slice( $out, 0, 24 );
	}

	/* ---------- Pieces ---------- */

	/**
	 * A sliding row with arrows, like the TV site.
	 *
	 * @param string $to   The row's own page ('' for none).
	 * @param string $nav  Its data-ftvc route ("series|<id>|").
	 */
	private static function row( $title, $to, $nav, $style, $items ) {
		if ( ! $items ) {
			return '';
		}
		$head = '' !== $to
			? '<h3 class="ftvc-row__title"><a href="' . esc_url( $to ) . '" data-ftvc="' . esc_attr( $nav ) . '">' . esc_html( $title ) . '</a></h3><a class="ftvc-row__all" href="' . esc_url( $to ) . '" data-ftvc="' . esc_attr( $nav ) . '" aria-label="' . esc_attr( sprintf( /* translators: %s: row name */ __( 'See all of %s', 'faith-tv-series' ), $title ) ) . '">' . esc_html__( 'See all', 'faith-tv-series' ) . self::icon( 'right', 16 ) . '</a>'
			: '<h3 class="ftvc-row__title">' . esc_html( $title ) . '</h3>';
		$out  = '<section class="ftvc-row" data-style="' . esc_attr( $style ) . '"><div class="ftvc-row__head">' . $head . '</div><div class="ftvc-rail">';
		$out .= '<button type="button" class="ftvc-arrow ftvc-arrow--prev" data-ftvc-prev aria-label="' . esc_attr( sprintf( /* translators: %s: row name */ __( 'Scroll %s back', 'faith-tv-series' ), $title ) ) . '" hidden>' . self::icon( 'left' ) . '</button>';
		$out .= '<ul class="ftvc-track" role="list" aria-label="' . esc_attr( $title ) . '">';
		foreach ( $items as $item ) {
			$out .= '<li>' . $item . '</li>';
		}
		$out .= '</ul><button type="button" class="ftvc-arrow ftvc-arrow--next" data-ftvc-next aria-label="' . esc_attr( sprintf( /* translators: %s: row name */ __( 'Scroll %s forward', 'faith-tv-series' ), $title ) ) . '" hidden>' . self::icon( 'right' ) . '</button>';
		return $out . '</div></section>';
	}

	/** A series tile: its picture (or its name on a dark tile) and its name under it. */
	private static function tile( $cat, $ctx ) {
		$n     = isset( $cat['videos'] ) ? (int) $cat['videos'] : 0;
		/* translators: 1: series name, 2: number of videos */
		$label = $n ? sprintf( _n( '%1$s, %2$s video', '%1$s, %2$s videos', $n, 'faith-tv-series' ), $cat['title'], number_format_i18n( $n ) ) : $cat['title'];
		return '<a class="ftvc-tile" href="' . esc_url( self::link( 'series', $cat['id'], '', $ctx ) ) . '" data-ftvc="' . esc_attr( 'series|' . $cat['id'] . '|' ) . '" aria-label="' . esc_attr( $label ) . '">'
			. self::art( $cat['image'], '', $cat['title'] )
			. '<span class="ftvc-tile__name">' . esc_html( $cat['title'] ) . '</span></a>';
	}

	/** A Featured slide: wide art with the series' name over it, its description, and a round arrow. */
	private static function feature_card( $cat, $ctx ) {
		return '<a class="ftvc-feature" href="' . esc_url( self::link( 'series', $cat['id'], '', $ctx ) ) . '" data-ftvc="' . esc_attr( 'series|' . $cat['id'] . '|' ) . '">'
			. '<span class="ftvc-feature__art">' . self::art( $cat['image'], '', $cat['title'] ) . '<span class="ftvc-feature__name">' . esc_html( $cat['title'] ) . '</span></span>'
			. ( '' !== $cat['description'] ? '<span class="ftvc-feature__desc">' . esc_html( $cat['description'] ) . '</span>' : '<span class="ftvc-feature__desc"></span>' )
			. '<span class="ftvc-feature__go" aria-hidden="true">' . self::icon( 'arrow' ) . '</span></a>';
	}

	/** A video card; $current marks the one playing. */
	private static function video_card( $video, $in, $ctx, $current = false ) {
		$meta  = implode( ' · ', array_filter( array( $video['speaker'], self::date( $video['added'] ) ) ) );
		$extra = ( $video['length'] > 0 ? '<span class="ftvc-dur">' . esc_html( FTVS_Renderer::duration( $video['length'] ) ) . '</span>' : '' )
			. ( $current ? '<span class="ftvc-now">' . esc_html__( 'Now playing', 'faith-tv-series' ) . '</span>' : '' );
		return '<a class="ftvc-card" href="' . esc_url( self::link( 'video', $video['id'], $in, $ctx ) ) . '" data-ftvc="' . esc_attr( 'video|' . $video['id'] . '|' . $in ) . '" data-id="' . esc_attr( $video['id'] ) . '"' . ( $current ? ' aria-current="true"' : '' ) . '>'
			. self::art( $video['image'], '', $video['title'], false, $extra )
			. '<span class="ftvc-card__title">' . esc_html( $video['title'] ) . '</span>'
			. ( '' !== $meta ? '<span class="ftvc-card__meta">' . esc_html( $meta ) . '</span>' : '' )
			. '</a>';
	}

	private static function art( $src, $alt, $blank_label, $eager = false, $extra = '' ) {
		if ( '' === (string) $src ) {
			return '<span class="ftvc-art ftvc-art--blank"><span>' . esc_html( $blank_label ) . '</span>' . $extra . '</span>';
		}
		return '<span class="ftvc-art"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"' . ( $eager ? ' decoding="async"' : ' loading="lazy" decoding="async"' ) . '>' . $extra . '</span>';
	}

	private static function back( $url, $nav ) {
		return '<a class="ftvc-back" href="' . esc_url( $url ) . '" data-ftvc="' . esc_attr( $nav ) . '" data-ftvc-back><span class="ftvc-back__ring">' . self::icon( 'back' ) . '</span>' . esc_html__( 'Back', 'faith-tv-series' ) . '</a>';
	}

	private static function empty_state( $title, $text ) {
		return '<div class="ftvc-empty"><h2 tabindex="-1">' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p></div>';
	}

	private static function not_found( $ctx ) {
		return array(
			'html'  => '<div class="ftvc-empty"><h2 tabindex="-1">' . esc_html__( 'We couldn\'t find that', 'faith-tv-series' ) . '</h2><p>' . esc_html__( 'It may have been renamed or taken down.', 'faith-tv-series' ) . '</p><a class="ftvc-pill ftvc-pill--small" href="' . esc_url( self::link( 'home', '', '', $ctx ) ) . '" data-ftvc="home||">' . esc_html__( 'Back to the channel', 'faith-tv-series' ) . '</a></div>',
			'title' => __( 'Not found', 'faith-tv-series' ),
			'found' => false,
		);
	}

	/** The platform isn't answering and nothing is saved: visitors get a calm note, editors the reason. */
	private static function trouble( WP_Error $error ) {
		$note = current_user_can( 'edit_posts' ) ? '<p class="ftvc-empty__why">' . esc_html( $error->get_error_message() ) . '</p>' : '';
		return array(
			'html'  => '<div class="ftvc-empty"><h2 tabindex="-1">' . esc_html__( 'Our videos aren\'t loading right now', 'faith-tv-series' ) . '</h2><p>' . esc_html__( 'Please try again in a few minutes.', 'faith-tv-series' ) . '</p>' . $note . '</div>',
			'title' => __( 'Videos', 'faith-tv-series' ),
		);
	}

	/** The biggest picture a video has (banner and player). */
	private static function big( $video ) {
		return ! empty( $video['poster'] ) ? $video['poster'] : ( isset( $video['image'] ) ? (string) $video['image'] : '' );
	}

	private static function date( $raw ) {
		$t = $raw ? strtotime( (string) $raw ) : false;
		return $t ? wp_date( get_option( 'date_format' ), $t ) : '';
	}

	/** "1m", "38m", "1h 12m": a length the way the TV site writes it. */
	public static function mins( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds <= 0 ) {
			return '';
		}
		$h = intdiv( $seconds, 3600 );
		$m = (int) round( ( $seconds % 3600 ) / 60 );
		if ( 60 === $m ) {
			++$h;
			$m = 0;
		}
		/* translators: 1: hours, 2: minutes (a video's length, like 1h 12m) */
		return $h ? ( $m ? sprintf( __( '%1$dh %2$dm', 'faith-tv-series' ), $h, $m ) : sprintf( /* translators: %d: hours */ __( '%dh', 'faith-tv-series' ), $h ) ) : sprintf( /* translators: %d: minutes */ __( '%dm', 'faith-tv-series' ), max( 1, $m ) );
	}

	/** A picture address as a quoted CSS url() that can't end early (quotes and backslashes are encoded). */
	private static function css_url( $url ) {
		$url = esc_url_raw( (string) $url );
		return '' === $url ? 'none' : 'url("' . str_replace( array( '"', '\\', "\n", "\r" ), array( '%22', '%5C', '', '' ), $url ) . '")';
	}

	/** An http(s) address, or '' (for links taken from the platform). */
	private static function http_url( $url ) {
		$url = (string) $url;
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	/** Small line icons (no emoji, no icon font). */
	public static function icon( $name, $size = 22 ) {
		$paths = array(
			'play'   => '<path d="M8 5.5v13l11-6.5z" fill="currentColor"/>',
			'left'   => '<path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
			'right'  => '<path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
			'back'   => '<path d="M19 12H5M11 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
			'arrow'  => '<path d="M5 12h14M13 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>',
			'search' => '<circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="m20 20-4.8-4.8" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>',
			'menu'   => '<path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>',
			'share'  => '<path d="M12 3v12M7 8l5-5 5 5M5 13v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
			'tv'     => '<rect x="1.5" y="3" width="21" height="16" rx="3.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path class="ftvc-icon__accent" d="M10 7.5v7l6-3.5z"/>',
		);
		$size = (int) $size;
		return '<svg class="ftvc-icon" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">' . ( isset( $paths[ $name ] ) ? $paths[ $name ] : '' ) . '</svg>';
	}

	/* ---------- What the channel said about each category ---------- */

	private static function registry_key() {
		return 'ftvs_chcats_' . md5( FTVS_Catalog::identity() );
	}

	private static function registry() {
		$key = self::registry_key();
		if ( null === self::$registry || self::$registry_for !== $key ) {
			self::save_registry(); // what the previous church's listings taught, under its own name
			$saved              = get_option( $key, array() );
			self::$registry     = is_array( $saved ) ? $saved : array();
			self::$registry_for = $key;
		}
		return self::$registry;
	}

	/** @return array|null { t: title, d: description, i: picture, n: videos, s: series inside, p: parent } */
	private static function registry_get( $id ) {
		$all = self::registry();
		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] + array( 't' => '', 'd' => '', 'i' => '', 'n' => 0, 's' => 0, 'p' => '' ) : null;
	}

	/**
	 * Keep what a listing said about the categories in it ($parent '' when the listing is not "inside" anything).
	 * The first parent seen stays: a series can also sit in a Featured row.
	 */
	public static function remember( $parent, $cats ) {
		self::registry();
		foreach ( (array) $cats as $cat ) {
			if ( empty( $cat['id'] ) || ! is_string( $cat['id'] ) || '@' === $cat['id'][0] ) {
				continue;
			}
			$old = isset( self::$registry[ $cat['id'] ] ) ? self::$registry[ $cat['id'] ] : array();
			$new = array(
				't' => isset( $cat['title'] ) ? (string) $cat['title'] : ( isset( $old['t'] ) ? $old['t'] : '' ),
				'd' => isset( $cat['description'] ) ? substr( (string) $cat['description'], 0, 1500 ) : ( isset( $old['d'] ) ? $old['d'] : '' ),
				'i' => isset( $cat['image'] ) && '' !== $cat['image'] ? (string) $cat['image'] : ( isset( $old['i'] ) ? $old['i'] : '' ),
				'n' => isset( $cat['videos'] ) ? (int) $cat['videos'] : ( isset( $old['n'] ) ? $old['n'] : 0 ),
				's' => isset( $cat['subcategories'] ) ? (int) $cat['subcategories'] : ( isset( $old['s'] ) ? $old['s'] : 0 ),
				'p' => ! empty( $old['p'] ) ? $old['p'] : ( '' !== $parent && '@' !== $parent[0] && $parent !== $cat['id'] ? $parent : '' ),
			);
			if ( $new !== $old ) {
				self::$registry[ $cat['id'] ] = $new;
				self::$registry_dirty         = true;
			}
		}
	}

	public static function save_registry() {
		if ( ! self::$registry_dirty || '' === self::$registry_for ) {
			return;
		}
		self::$registry_dirty = false;
		if ( count( self::$registry ) > self::REGISTRY_MAX ) {
			self::$registry = array_slice( self::$registry, -self::REGISTRY_MAX, null, true );
		}
		update_option( self::$registry_for, self::$registry, false );
	}

	/** Forget everything (a different church was connected, or the admin refreshed). */
	public static function forget() {
		delete_option( self::registry_key() );
		self::$registry       = null;
		self::$registry_dirty = false;
		self::$registry_for   = '';
		self::$has_channel    = array();
	}

	/* ---------- Setting it up ---------- */

	/**
	 * A ready-made channel page: the whole channel, full width. It becomes the Watch page (tidy addresses and a
	 * page for every message) unless another Watch page is already published.
	 *
	 * @return int|WP_Error The new page's id (a draft).
	 */
	public static function create_page() {
		$content = serialize_block(
			array(
				'blockName'    => 'faith-tv/channel',
				'attrs'        => array( 'align' => 'full' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		// Named like the channel ("Faith TV", at /faith-tv/); WordPress adds -2 if that address is taken.
		$slug    = sanitize_title( self::name() );
		$id      = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => self::name(),
				'post_name'    => $slug,
				'post_content' => $content,
			),
			true
		);
		if ( ! is_wp_error( $id ) && ! FTVS_Watch::page_id() ) {
			FTVS_Settings::update( array( 'watch_page_id' => $id ) );
		}
		return $id;
	}
}
