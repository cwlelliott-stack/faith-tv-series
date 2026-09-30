<?php
/**
 * The Faith Stream admin menu: Church (connect), Videos, Look & feel, Player, Live, Embed,
 * Health, Updates.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Admin {

	const PAGE  = 'faith-stream';
	const PAGES = array(
		'faith-stream'         => 'church',
		'faith-stream-videos'  => 'videos',
		'faith-stream-look'    => 'look',
		'faith-stream-player'  => 'player',
		'faith-stream-live'    => 'live',
		'faith-stream-embed'   => 'embed',
		'faith-stream-health'  => 'health',
		'faith-stream-updates' => 'updates',
	);
	// Tabs that need a connected church.
	const NEEDS_CHURCH = array( 'videos', 'look', 'player', 'live', 'embed' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'privacy_text' ) );
		// WordPress refuses unknown pages before admin_init runs; this fires just before that refusal.
		add_action( 'admin_page_access_denied', array( __CLASS__, 'redirect_old_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'refresh', 'lookup', 'connect', 'demo', 'watch_page', 'secret', 'purge', 'recheck' ) as $action ) {
			add_action( 'admin_post_ftvs_' . $action, array( __CLASS__, 'do_' . $action ) );
		}
		add_action( 'wp_ajax_ftvs_preview_url', array( __CLASS__, 'ajax_preview_url' ) );
	}

	public static function url( $page = self::PAGE, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Who the plugin says it's from. Agencies can use their own name and link:
	 * define( 'FTVS_BRAND_NAME', 'Your Agency' ); define( 'FTVS_BRAND_URL', 'https://...' );
	 * (FTVS_BRAND_NAME '' hides "Powered by" everywhere), or the ftvs_brand filter.
	 */
	public static function brand() {
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$brand = array(
			'name'    => defined( 'FTVS_BRAND_NAME' ) ? (string) FTVS_BRAND_NAME : 'FAITHSTREAM',
			'url'     => defined( 'FTVS_BRAND_URL' ) ? (string) FTVS_BRAND_URL : add_query_arg( array( 'ref' => 'wp', 'host' => $host ), 'https://faithstream.video/' ),
			'support' => defined( 'FTVS_SUPPORT_URL' ) ? (string) FTVS_SUPPORT_URL : 'https://faithstream.video/',
		);
		return apply_filters( 'ftvs_brand', $brand );
	}

	public static function menu() {
		add_menu_page( __( 'Faith Stream', 'faith-tv-series' ), __( 'Faith Stream', 'faith-tv-series' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ), self::menu_icon(), 58 );
		foreach ( self::tab_names() as $slug => $name ) {
			add_submenu_page( self::PAGE, $name, $name, 'manage_options', $slug, array( __CLASS__, 'page' ) );
		}
		if ( FTVS_Settings::extra_sources() ) {
			add_submenu_page( self::PAGE, __( 'Build a series', 'faith-tv-series' ), __( 'Build a series', 'faith-tv-series' ), 'edit_pages', 'edit.php?post_type=' . FTVS_Manual::TYPE );
		}
	}

	private static function tab_names() {
		$tabs = array(
			'faith-stream'         => __( 'Church', 'faith-tv-series' ),
			'faith-stream-videos'  => __( 'Videos', 'faith-tv-series' ),
			'faith-stream-look'    => __( 'Look & feel', 'faith-tv-series' ),
			'faith-stream-player'  => __( 'Player', 'faith-tv-series' ),
			'faith-stream-live'    => __( 'Sunday live', 'faith-tv-series' ),
			'faith-stream-embed'   => __( 'Embed', 'faith-tv-series' ),
			'faith-stream-health'  => __( 'Health', 'faith-tv-series' ),
			'faith-stream-updates' => __( 'Updates', 'faith-tv-series' ),
		);
		if ( ! FTVS_Settings::direct_edition() ) {
			unset( $tabs['faith-stream-updates'] ); // WordPress.org updates that edition
		}
		return $tabs;
	}

	/** Suggested wording for the site's privacy policy (Settings > Privacy > Policy guide). */
	public static function privacy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$lines = array(
			__( 'Our videos are shown from our video platform (Faith Stream, Gideo, YouTube or Vimeo) and its delivery network. When you watch, your browser loads the video and its pictures from there, which, like any website, sees your internet address.', 'faith-tv-series' ),
			__( 'If we count plays in Faith Stream, your browser also tells Faith Stream when a video starts, how long it plays and whether it finishes, with the address of the page (not your name). Faith Stream keeps only a scrambled form of your internet address that changes every day.', 'faith-tv-series' ),
			__( 'If you choose "Count me present" while watching our live service, the email or first name and password of your church account (or the email and name you type) go to Faith Stream, which checks you in to our attendance. Your browser keeps a sign-in code until you press "Not you?".', 'faith-tv-series' ),
			__( 'If you ask to be reminded, the email address or mobile number you type (and your agreement to receive texts) goes to our follow-up system. "Continue watching" and your volume are remembered only in your own browser.', 'faith-tv-series' ),
		);
		wp_add_privacy_policy_content( __( 'Faith TV Series', 'faith-tv-series' ), '<p>' . implode( '</p><p>', array_map( 'esc_html', $lines ) ) . '</p>' );
	}

	public static function register_settings() {
		register_setting(
			'ftvs',
			FTVS_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'FTVS_Settings', 'sanitize' ),
				'default'           => FTVS_Settings::defaults(),
			)
		);
	}

	/** The old Settings > Faith TV Series page lives here now. */
	public static function redirect_old_page() {
		if ( isset( $_GET['page'] ) && 'faith-tv-series' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	public static function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( self::PAGES[ $page ] ) ) {
			return;
		}
		// The brand fonts come from Google Fonts in the direct edition only (the wordpress.org
		// edition, which has no self-updater, uses system fonts: no outside calls).
		if ( class_exists( 'FTVS_Updater' ) ) {
			wp_enqueue_style( 'ftvs-admin-font', 'https://fonts.googleapis.com/css2?family=Poppins:wght@500;700;800&family=Roboto:wght@700;800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_enqueue_style( 'ftvs-admin', FTVS_URL . 'assets/admin.css', array(), FTVS_VERSION );
		wp_style_add_data( 'ftvs-admin', 'rtl', 'replace' );
		wp_enqueue_script( 'ftvs-admin', FTVS_URL . 'assets/admin.js', array(), FTVS_VERSION, true );
		wp_localize_script(
			'ftvs-admin',
			'FTVS_ADMIN',
			array(
				'copied'   => __( 'Copied', 'faith-tv-series' ),
				'lowText'  => __( 'Hard to read: the words on buttons will be switched to dark so they stay readable.', 'faith-tv-series' ),
				'okText'   => '',
			)
		);
		if ( in_array( $page, array( 'faith-stream-embed', 'faith-stream-look' ), true ) ) {
			// Same helper other websites load, so the preview frame grows to fit.
			wp_enqueue_script( 'ftvs-embed-host', FTVS_URL . 'assets/embed.js', array(), FTVS_VERSION, true );
		}
	}

	/* ---------- Actions ---------- */

	/** "Refresh from your channel": fetch everything now, and clear page caches. */
	public static function do_refresh() {
		self::guard( 'ftvs_refresh' );
		FTVS_Cache::clear();
		FTVS_Health::warm();
		FTVS_Purge::now();
		wp_safe_redirect( add_query_arg( 'ftvs_refreshed', '1', wp_get_referer() ? wp_get_referer() : self::url( 'faith-stream-videos' ) ) );
		exit;
	}

	/** The platforms the connect wizard looks churches up on (Gideo is the fallback). */
	private static function lookup_sources() {
		return FTVS_Settings::extra_sources() ? array( 'faithstream', 'youtube' ) : array( 'faithstream' );
	}

	/** Step 2 of connecting: find the church, keep it aside until the admin confirms. */
	public static function do_lookup() {
		self::guard( 'ftvs_lookup' );
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in guard() above.
		$source = isset( $post['source'] ) && in_array( $post['source'], self::lookup_sources(), true ) ? $post['source'] : 'gideo';
		$field  = function ( $key ) use ( $post ) {
			return isset( $post[ $key ] ) ? sanitize_text_field( $post[ $key ] ) : '';
		};
		if ( 'faithstream' === $source ) {
			$found = FTVS_FaithStream_Client::lookup( $field( 'fs_url' ), $field( 'fs_tenant' ) );
			$input = array( 'fs_url' => $field( 'fs_url' ), 'fs_tenant' => $field( 'fs_tenant' ) );
		} elseif ( 'youtube' === $source ) {
			$links = isset( $post['yt'] ) ? sanitize_textarea_field( $post['yt'] ) : '';
			$found = FTVS_YouTube_Client::lookup( $links, $field( 'yt_key' ) );
			$input = array( 'yt' => $links );
		} elseif ( ! empty( $post['by_account'] ) ) {
			$found = FTVS_Gideo_Client::lookup_account( $field( 'account_id' ) );
			$input = array( 'account_id' => $field( 'account_id' ) );
		} else {
			$found = FTVS_Gideo_Client::lookup_domain( $field( 'tv' ) );
			$input = array( 'tv' => $field( 'tv' ) );
		}
		set_transient(
			self::pending_key(),
			is_wp_error( $found ) ? array( 'error' => $found->get_error_message(), 'input' => $input ) : array( 'found' => $found, 'input' => $input ),
			HOUR_IN_SECONDS
		);
		wp_safe_redirect( self::url( self::PAGE, array( 'connect' => 1, 'step' => 2, 'source' => $source ) ) );
		exit;
	}

	/** "Yes, this is my church." */
	public static function do_connect() {
		self::guard( 'ftvs_connect' );
		$pending = get_transient( self::pending_key() );
		if ( empty( $pending['found'] ) ) {
			wp_safe_redirect( self::url( self::PAGE, array( 'connect' => 1 ) ) );
			exit;
		}
		$found  = $pending['found'];
		$fields = array( 'source', 'account_id', 'tv_url', 'fs_url', 'fs_tenant', 'yt_channel', 'church_name', 'church_logo' );
		$save   = array_fill_keys( $fields, '' );
		foreach ( $fields as $key ) {
			if ( isset( $found[ $key ] ) ) {
				$save[ $key ] = $found[ $key ];
			}
		}
		$save['yt_playlists'] = isset( $found['yt_playlists'] ) ? $found['yt_playlists'] : array();
		if ( ! empty( $found['yt_key'] ) ) {
			$save['yt_key'] = $found['yt_key'];
		} else {
			$save['yt_key_remove'] = 1;
		}
		$save['features'] = isset( $found['features'] ) ? $found['features'] : null;
		// The church's own color and light/dark look, unless the admin says no.
		if ( ! empty( $_POST['use_colors'] ) && ! empty( $found['colors']['primary'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$save['accent'] = $found['colors']['primary'];
			$save['theme']  = $found['colors']['scheme'];
		}
		FTVS_Settings::update( $save );
		FTVS_Cache::clear();
		delete_transient( self::pending_key() );
		FTVS_Health::warm_soon();
		wp_safe_redirect( self::url( self::PAGE, array( 'connect' => 1, 'step' => 3 ) ) );
		exit;
	}

	/** "Just looking? Try it with sample videos." Editors see them; visitors never do. */
	public static function do_demo() {
		self::guard( 'ftvs_demo' );
		FTVS_Settings::update(
			array(
				'source'      => 'demo',
				'church_name' => __( 'Sample Church', 'faith-tv-series' ),
				'church_logo' => '',
			)
		);
		FTVS_Cache::clear();
		wp_safe_redirect( self::url( self::PAGE, array( 'connect' => 1, 'step' => 3 ) ) );
		exit;
	}

	public static function do_watch_page() {
		self::guard( 'ftvs_watch_page', 'publish_pages' );
		$id = FTVS_Blocks::create_watch_page();
		if ( is_wp_error( $id ) ) {
			wp_die( esc_html( $id->get_error_message() ) );
		}
		wp_safe_redirect( get_edit_post_link( $id, 'raw' ) );
		exit;
	}

	public static function do_secret() {
		self::guard( 'ftvs_secret' );
		update_option( 'ftvs_refresh_secret', wp_generate_password( 48, false ), false );
		wp_safe_redirect( self::url( self::PAGE, array( 'ftvs_secret' => 1 ) ) . '#ftvs-instant' );
		exit;
	}

	public static function do_purge() {
		self::guard( 'ftvs_purge' );
		FTVS_Purge::now();
		wp_safe_redirect( self::url( 'faith-stream-health', array( 'ftvs_purged' => 1 ) ) );
		exit;
	}

	public static function do_recheck() {
		self::guard( 'ftvs_recheck' );
		FTVS_Health::where_used( true );
		FTVS_Health::check_used( true );
		wp_safe_redirect( self::url( 'faith-stream-health', array( 'ftvs_rechecked' => 1 ) ) );
		exit;
	}

	/** Look & feel: a signed preview address carrying the settings being tried (not saved yet). */
	public static function ajax_preview_url() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'ftvs_preview_url' );
		$post = wp_unslash( $_POST );
		$look = isset( $post[ FTVS_Settings::OPTION ] ) && is_array( $post[ FTVS_Settings::OPTION ] ) ? $post[ FTVS_Settings::OPTION ] : array();
		$args = array(
			'category' => isset( $post['preview_category'] ) ? sanitize_text_field( $post['preview_category'] ) : '',
			'preview'  => 1,
		);
		foreach ( array( 'accent', 'layout', 'mobile_layout', 'theme', 'style', 'font', 'label', 'badge', 'powered_by' ) as $key ) {
			if ( isset( $look[ $key ] ) ) {
				$args[ 'look_' . $key ] = sanitize_text_field( $look[ $key ] );
			}
		}
		foreach ( FTVS_Settings::TEXT_PARTS as $part ) {
			foreach ( array( 'size', 'color' ) as $what ) {
				if ( ! empty( $look['text'][ $part ][ $what ] ) ) {
					$args[ $part . '_' . $what ] = sanitize_text_field( $look['text'][ $part ][ $what ] );
				}
			}
		}
		wp_send_json_success( array( 'url' => FTVS_Embed::url( $args ) ) );
	}

	private static function guard( $action, $cap = 'manage_options' ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'faith-tv-series' ) );
		}
		check_admin_referer( $action );
	}

	private static function pending_key() {
		return 'ftvs_pending_' . get_current_user_id();
	}

	private static function post_button( $action, $label, $class = 'button ftvs-btn-secondary', $extra = array() ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ftvs-inline-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php foreach ( $extra as $k => $v ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
			<?php endforeach; ?>
			<?php wp_nonce_field( $action ); ?>
			<button class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	/* ---------- Page ---------- */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : self::PAGE; // phpcs:ignore WordPress.Security.NonceVerification
		$tab  = isset( self::PAGES[ $page ] ) ? self::PAGES[ $page ] : 'church';
		if ( ! FTVS_Catalog::connected() && in_array( $tab, self::NEEDS_CHURCH, true ) ) {
			$tab = 'church';
		}
		$tree = FTVS_Catalog::connected() ? FTVS_Catalog::get_tree() : array();
		echo '<div class="wrap ftvs-admin">';
		self::header( $tab, $tree );
		echo '<hr class="wp-header-end">';
		settings_errors();
		self::notices();
		echo '<div class="ftvs-panel">';
		switch ( $tab ) {
			case 'videos':
				self::videos( $tree );
				break;
			case 'look':
				self::look( $tree );
				break;
			case 'player':
				self::player();
				break;
			case 'live':
				self::live();
				break;
			case 'embed':
				self::embed( $tree );
				break;
			case 'health':
				self::health();
				break;
			case 'updates':
				self::updates();
				break;
			default:
				self::church( $tree );
		}
		echo '</div>';
		self::powered();
		echo '</div>';
	}

	private static function header( $tab, $tree ) {
		$s         = FTVS_Settings::all();
		$connected = FTVS_Catalog::connected();
		?>
		<header class="ftvs-brand">
			<span class="ftvs-logo"><?php echo self::gear( 50 ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="ftvs-logo__word"><strong>FAITHSTREAM</strong><span><?php esc_html_e( 'a division of FaithWorks Image Consulting', 'faith-tv-series' ); ?></span></span></span>
			<div class="ftvs-product">
				<h1><?php esc_html_e( 'Video for WordPress', 'faith-tv-series' ); ?></h1>
				<p><?php esc_html_e( 'Your church\'s series and sermons, live on your website.', 'faith-tv-series' ); ?></p>
			</div>
			<div class="ftvs-conn">
				<?php if ( $connected ) : ?>
					<span class="ftvs-avatar" style="<?php echo esc_attr( self::bg( $s['church_logo'] ) ); ?>"></span>
					<span><strong><?php echo esc_html( '' !== $s['church_name'] ? $s['church_name'] : __( 'Your church', 'faith-tv-series' ) ); ?></strong>
					<small><span class="ftvs-dot<?php echo self::failing() ? ' is-off' : ''; ?>"></span><?php echo esc_html( self::source_name() ); ?></small></span>
				<?php else : ?>
					<span class="ftvs-avatar is-empty">?</span>
					<span><strong><?php esc_html_e( 'No church yet', 'faith-tv-series' ); ?></strong><small><span class="ftvs-dot is-off"></span><?php esc_html_e( 'Not connected', 'faith-tv-series' ); ?></small></span>
				<?php endif; ?>
			</div>
			<span class="ftvs-ver">v<?php echo esc_html( FTVS_VERSION ); ?></span>
		</header>
		<nav class="ftvs-tabs" aria-label="<?php esc_attr_e( 'Faith Stream', 'faith-tv-series' ); ?>">
			<?php foreach ( self::tab_names() as $slug => $name ) : ?>
				<?php
				if ( 'updates' === self::PAGES[ $slug ] && ! class_exists( 'FTVS_Updater' ) ) {
					continue;
				}
				$is  = self::PAGES[ $slug ] === $tab;
				$off = ! $connected && in_array( self::PAGES[ $slug ], self::NEEDS_CHURCH, true );
				?>
				<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="<?php echo esc_attr( trim( ( $is ? 'is-current' : '' ) . ( $off ? ' is-off' : '' ) ) ); ?>"<?php echo $is ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $name ); ?>
					<?php if ( 'videos' === self::PAGES[ $slug ] && $connected && is_array( $tree ) ) : ?>
						<span class="ftvs-count"><?php echo (int) count( $tree ); ?></span>
					<?php elseif ( 'health' === self::PAGES[ $slug ] && self::problems() ) : ?>
						<span class="ftvs-count is-alert"><?php echo (int) self::problems(); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/** Has the channel been failing for a while? */
	private static function failing() {
		$h = FTVS_Cache::health();
		return $h['fails'] && $h['failing_at'] && time() - $h['failing_at'] > 30 * MINUTE_IN_SECONDS;
	}

	/** How many things need attention (for the Health tab's count). */
	private static function problems() {
		$n      = self::failing() ? 1 : 0;
		$status = get_option( 'ftvs_used_status', array() );
		foreach ( isset( $status['rows'] ) ? $status['rows'] : array() as $row ) {
			if ( empty( $row['ok'] ) && 'publish' === $row['status'] ) {
				++$n;
			}
		}
		return $n;
	}

	private static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification
		$messages = array(
			'ftvs_refreshed' => __( 'Done. Every video section on the site now shows the latest from your channel, and page caches were cleared.', 'faith-tv-series' ),
			'ftvs_checked'   => __( 'Checked for a new version. The result is below.', 'faith-tv-series' ),
			'ftvs_purged'    => __( 'Page caches were asked to clear.', 'faith-tv-series' ),
			'ftvs_rechecked' => __( 'Checked every page with a video section.', 'faith-tv-series' ),
			'ftvs_secret'    => __( 'New secret made. Paste it into Faith Stream again.', 'faith-tv-series' ),
		);
		foreach ( $messages as $key => $text ) {
			if ( isset( $_GET[ $key ] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
			}
		}
		// phpcs:enable
		if ( FTVS_Catalog::is_demo() ) {
			echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'You are trying the plugin with sample videos.', 'faith-tv-series' ) . '</strong> ' . esc_html__( 'Only people who can edit the site see video sections until you connect your church.', 'faith-tv-series' ) . ' <a href="' . esc_url( self::url( self::PAGE, array( 'connect' => 1 ) ) ) . '">' . esc_html__( 'Connect your church', 'faith-tv-series' ) . '</a></p></div>';
		}
	}

	/* ---------- Church ---------- */

	private static function church( $tree ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( ! FTVS_Catalog::connected() || isset( $_GET['connect'] ) ) {
			self::wizard();
			return;
		}
		$s      = FTVS_Settings::all();
		$rows   = is_wp_error( $tree ) ? array() : $tree;
		$series = 0;
		foreach ( $rows as $row ) {
			$series += count( $row['children'] );
		}
		$h = FTVS_Cache::health();
		?>
		<div class="ftvs-card ftvs-pad">
			<div class="ftvs-church">
				<span class="ftvs-logobox" style="<?php echo esc_attr( self::bg( $s['church_logo'] ) ); ?>"></span>
				<div>
					<span class="ftvs-chip is-ok"><?php echo FTVS_Catalog::is_demo() ? esc_html__( 'Sample videos', 'faith-tv-series' ) : esc_html__( 'Connected', 'faith-tv-series' ); ?></span>
					<h2><?php echo esc_html( '' !== $s['church_name'] ? $s['church_name'] : __( 'Your church', 'faith-tv-series' ) ); ?></h2>
					<dl class="ftvs-dl">
						<dt><?php esc_html_e( 'Videos come from', 'faith-tv-series' ); ?></dt>
						<dd><?php echo esc_html( self::source_name() ); ?>
							<?php if ( FTVS_Catalog::channel_url() ) : ?>
								&middot; <a href="<?php echo esc_url( FTVS_Catalog::channel_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( FTVS_Catalog::channel_host() ); ?></a>
							<?php endif; ?>
						</dd>
						<?php if ( in_array( $s['source'], array( 'gideo', 'faithstream' ), true ) ) : ?>
							<dt><?php echo 'gideo' === $s['source'] ? esc_html__( 'Gideo account', 'faith-tv-series' ) : esc_html__( 'Church ID', 'faith-tv-series' ); ?></dt>
							<dd><code><?php echo esc_html( 'gideo' === $s['source'] ? $s['account_id'] : $s['fs_tenant'] ); ?></code></dd>
						<?php endif; ?>
						<dt><?php esc_html_e( 'Last checked', 'faith-tv-series' ); ?></dt>
						<dd>
							<?php
							if ( self::failing() ) {
								/* translators: %s: how long */
								echo '<span class="ftvs-bad">' . esc_html( sprintf( __( 'Not answering for %s. Visitors see the last list the site saved.', 'faith-tv-series' ), human_time_diff( $h['failing_at'] ) ) ) . '</span>';
							} elseif ( $h['last_ok'] ) {
								/* translators: 1: how long ago, 2: minutes */
								printf( esc_html__( '%1$s ago. Checks every %2$d minutes, in the background.', 'faith-tv-series' ), esc_html( human_time_diff( $h['last_ok'] ) ), (int) $s['cache_minutes'] );
							} else {
								esc_html_e( 'Not yet.', 'faith-tv-series' );
							}
							?>
						</dd>
					</dl>
				</div>
				<div class="ftvs-stack">
					<a class="button ftvs-btn-secondary" href="<?php echo esc_url( self::url( self::PAGE, array( 'connect' => 1 ) ) ); ?>"><?php echo FTVS_Catalog::is_demo() ? esc_html__( 'Connect your church', 'faith-tv-series' ) : esc_html__( 'Connect a different church', 'faith-tv-series' ); ?></a>
					<?php self::refresh_button(); ?>
				</div>
			</div>
			<?php if ( is_wp_error( $tree ) ) : ?>
				<p class="ftvs-msg"><?php echo esc_html( $tree->get_error_message() ); ?></p>
			<?php else : ?>
				<div class="ftvs-facts">
					<div><strong><?php echo (int) count( $rows ); ?></strong><span><?php esc_html_e( 'Rows on your channel', 'faith-tv-series' ); ?></span></div>
					<div><strong><?php echo (int) $series; ?></strong><span><?php esc_html_e( 'Series', 'faith-tv-series' ); ?></span></div>
					<div><strong><?php echo esc_html( self::layout_name( FTVS_Settings::get( 'layout' ) ) ); ?></strong><span><?php esc_html_e( 'Layout on computers', 'faith-tv-series' ); ?></span></div>
					<div><strong><?php echo esc_html( self::layout_name( FTVS_Settings::get( 'mobile_layout' ) ) ); ?></strong><span><?php esc_html_e( 'Layout on phones', 'faith-tv-series' ); ?></span></div>
				</div>
			<?php endif; ?>
		</div>

		<?php self::watch_card(); ?>
		<?php if ( 'faithstream' === $s['source'] ) : ?>
			<?php self::instant_card(); ?>
		<?php endif; ?>

		<div class="ftvs-card ftvs-pad ftvs-gap">
			<h3 class="ftvs-h2"><?php esc_html_e( 'Advanced', 'faith-tv-series' ); ?></h3>
			<form method="post" action="options.php" class="ftvs-inline">
				<?php settings_fields( 'ftvs' ); ?>
				<label for="ftvs-cache"><?php esc_html_e( 'Check for new videos every', 'faith-tv-series' ); ?></label>
				<input id="ftvs-cache" class="small-text" type="number" min="1" max="1440" name="<?php echo esc_attr( FTVS_Settings::OPTION ); ?>[cache_minutes]" value="<?php echo esc_attr( $s['cache_minutes'] ); ?>">
				<span><?php esc_html_e( 'minutes', 'faith-tv-series' ); ?></span>
				<?php if ( 'youtube' === $s['source'] ) : ?>
					<label for="ftvs-yt-key" style="margin-left:18px"><?php esc_html_e( 'YouTube API key (optional)', 'faith-tv-series' ); ?></label>
					<input id="ftvs-yt-key" class="ftvs-input" style="max-width:280px" type="password" autocomplete="off" name="<?php echo esc_attr( FTVS_Settings::OPTION ); ?>[yt_key]" placeholder="<?php echo '' !== $s['yt_key'] ? esc_attr__( 'Saved (hidden)', 'faith-tv-series' ) : ''; ?>">
				<?php endif; ?>
				<?php submit_button( __( 'Save', 'faith-tv-series' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( 'youtube' === $s['source'] ) : ?>
				<p class="ftvs-hint"><?php esc_html_e( 'Without a key, each playlist shows its newest 15 videos. With a free YouTube Data API key from Google Cloud, whole playlists show, and if you connected a channel with no playlists picked, every playlist on it becomes a series.', 'faith-tv-series' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Watch page (a page for every message) and the podcast feed. */
	private static function watch_card() {
		$s     = FTVS_Settings::all();
		$page  = FTVS_Watch::page_id();
		$name  = FTVS_Settings::OPTION;
		$pages = get_pages( array( 'post_status' => 'publish,draft' ) );
		?>
		<div class="ftvs-card ftvs-pad ftvs-gap">
			<h3 class="ftvs-h2"><?php esc_html_e( 'Watch page and a page for every message', 'faith-tv-series' ); ?></h3>
			<p class="ftvs-lead"><?php esc_html_e( 'Pick the page where people watch (or let us make one). Every message then also gets its own page under it, like yourchurch.com/watch/message-name, that shows up in Google and looks right when someone texts or posts the link.', 'faith-tv-series' ); ?></p>
			<form method="post" action="options.php" class="ftvs-inline">
				<?php settings_fields( 'ftvs' ); ?>
				<label for="ftvs-watch-page" class="screen-reader-text"><?php esc_html_e( 'Watch page', 'faith-tv-series' ); ?></label>
				<select id="ftvs-watch-page" class="ftvs-input" name="<?php echo esc_attr( $name ); ?>[watch_page_id]">
					<option value="0"><?php esc_html_e( '- No Watch page -', 'faith-tv-series' ); ?></option>
					<?php foreach ( $pages as $p ) : ?>
						<option value="<?php echo esc_attr( $p->ID ); ?>"<?php selected( (int) $s['watch_page_id'], $p->ID ); ?>><?php echo esc_html( $p->post_title . ( 'draft' === $p->post_status ? ' (' . __( 'draft', 'faith-tv-series' ) . ')' : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Save', 'faith-tv-series' ), 'secondary', 'submit', false ); ?>
			</form>
			<p class="ftvs-inline" style="margin-top:12px">
				<?php self::post_button( 'ftvs_watch_page', __( 'Create my Watch page', 'faith-tv-series' ), 'button button-primary ftvs-btn-primary' ); ?>
				<span class="ftvs-hint"><?php esc_html_e( 'Makes a draft page with Sunday live, the newest messages, your series and a sermon search, then opens it so you can look it over and publish.', 'faith-tv-series' ); ?></span>
			</p>
			<?php if ( $page ) : ?>
				<p class="ftvs-msg is-ok">
					<?php
					$lib   = FTVS_Catalog::library();
					$first = ! is_wp_error( $lib ) && $lib ? $lib[0] : null;
					/* translators: %s: page address */
					printf( esc_html__( 'Message pages are on: %s', 'faith-tv-series' ), '<a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener">' . esc_html( get_permalink( $page ) ) . '</a>' );
					if ( $first ) {
						echo '<br>' . esc_html__( 'For example:', 'faith-tv-series' ) . ' <a href="' . esc_url( FTVS_Watch::url( $first['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html( $first['title'] ) . '</a>';
					}
					?>
				</p>
			<?php elseif ( $s['watch_page_id'] ) : ?>
				<p class="ftvs-msg"><?php esc_html_e( 'Publish the Watch page to turn on message pages.', 'faith-tv-series' ); ?></p>
			<?php endif; ?>

			<h3 class="ftvs-h3" style="margin-top:26px"><?php esc_html_e( 'Podcast', 'faith-tv-series' ); ?></h3>
			<form method="post" action="options.php">
				<?php settings_fields( 'ftvs' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[podcast]" value="0">
				<label class="ftvs-switch"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[podcast]" value="1"<?php checked( ! empty( $s['podcast'] ) ); ?>><span class="ftvs-switch__track"></span><span><?php esc_html_e( 'Publish a podcast feed of the messages', 'faith-tv-series' ); ?></span></label>
				<div class="ftvs-grid2">
					<label><span><?php esc_html_e( 'Podcast name', 'faith-tv-series' ); ?></span><input class="ftvs-input" name="<?php echo esc_attr( $name ); ?>[podcast_title]" value="<?php echo esc_attr( $s['podcast_title'] ); ?>" placeholder="<?php echo esc_attr( $s['church_name'] ); ?>"></label>
					<label><span><?php esc_html_e( 'Author', 'faith-tv-series' ); ?></span><input class="ftvs-input" name="<?php echo esc_attr( $name ); ?>[podcast_author]" value="<?php echo esc_attr( $s['podcast_author'] ); ?>" placeholder="<?php echo esc_attr( $s['church_name'] ); ?>"></label>
					<label><span><?php esc_html_e( 'Square picture (address, 1400 to 3000 pixels)', 'faith-tv-series' ); ?></span><input class="ftvs-input" name="<?php echo esc_attr( $name ); ?>[podcast_image]" value="<?php echo esc_attr( $s['podcast_image'] ); ?>" placeholder="https://"></label>
				</div>
				<?php submit_button( __( 'Save', 'faith-tv-series' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( ! empty( $s['podcast'] ) ) : ?>
				<?php $n = count( FTVS_Podcast::episodes( 5 ) ); ?>
				<p class="ftvs-inline"><span><?php esc_html_e( 'Feed address for Apple Podcasts and Spotify:', 'faith-tv-series' ); ?></span> <code><?php echo esc_html( FTVS_Podcast::url() ); ?></code> <button type="button" class="button ftvs-btn-secondary" data-copy="<?php echo esc_attr( FTVS_Podcast::url() ); ?>"><?php esc_html_e( 'Copy', 'faith-tv-series' ); ?></button></p>
				<?php if ( ! $n ) : ?>
					<p class="ftvs-msg"><?php echo 'faithstream' === $s['source'] ? esc_html__( 'No messages have audio yet. Turn on "Make an audio file of each new video" in Faith Stream\'s Settings; new messages will then appear in the feed.', 'faith-tv-series' ) : esc_html__( 'Podcasts need an audio file of each message, which Faith Stream makes. Your current platform does not provide one.', 'faith-tv-series' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Faith Stream tells the site the moment something is published (a signed ping). */
	private static function instant_card() {
		$secret = (string) get_option( 'ftvs_refresh_secret', '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 48, false );
			update_option( 'ftvs_refresh_secret', $secret, false );
		}
		$ping = get_option( 'ftvs_last_ping', array() );
		$url  = rest_url( 'faith-tv/v1/refresh' );
		?>
		<div class="ftvs-card ftvs-pad ftvs-gap" id="ftvs-instant">
			<h3 class="ftvs-h2"><?php esc_html_e( 'Instant updates from Faith Stream', 'faith-tv-series' ); ?></h3>
			<p class="ftvs-lead"><?php esc_html_e( 'So a new or removed video shows on the website within seconds (instead of at the next check), and so people watching here can be counted present, Faith Stream needs to know this website. One click fills it in there; you check it and press Save.', 'faith-tv-series' ); ?></p>
			<?php
			$home   = wp_parse_url( home_url() );
			$origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
			// The secret travels in the #fragment, which browsers never send to a server.
			$setup  = add_query_arg( 'tenant', FTVS_FaithStream_Client::tenant(), FTVS_FaithStream_Client::base() . '/admin/settings/website' )
				. '#refresh_url=' . rawurlencode( $url ) . '&secret=' . rawurlencode( $secret ) . '&origin=' . rawurlencode( $origin );
			?>
			<p><a class="button button-primary ftvs-btn-primary" href="<?php echo esc_url( $setup ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Set it up in Faith Stream', 'faith-tv-series' ); ?></a>
				<span class="ftvs-hint"><?php esc_html_e( 'Or copy these two into Faith Stream: Settings > Website & media.', 'faith-tv-series' ); ?></span></p>
			<div class="ftvs-grid2">
				<label><span><?php esc_html_e( 'Refresh address', 'faith-tv-series' ); ?></span><span class="ftvs-code"><input readonly value="<?php echo esc_attr( $url ); ?>"><button type="button" class="button ftvs-btn-secondary" data-copy="<?php echo esc_attr( $url ); ?>"><?php esc_html_e( 'Copy', 'faith-tv-series' ); ?></button></span></label>
				<label><span><?php esc_html_e( 'Secret', 'faith-tv-series' ); ?></span><span class="ftvs-code"><input readonly type="password" value="<?php echo esc_attr( $secret ); ?>" onfocus="this.type='text'" onblur="this.type='password'"><button type="button" class="button ftvs-btn-secondary" data-copy="<?php echo esc_attr( $secret ); ?>"><?php esc_html_e( 'Copy', 'faith-tv-series' ); ?></button></span></label>
			</div>
			<p class="ftvs-inline">
				<span class="ftvs-hint">
					<?php
					if ( ! empty( $ping['t'] ) ) {
						/* translators: %s: how long ago */
						printf( esc_html__( 'Last update from Faith Stream: %s ago.', 'faith-tv-series' ), esc_html( human_time_diff( $ping['t'] ) ) );
					} else {
						esc_html_e( 'No update received yet. Faith Stream\'s "Send a test ping" button checks it.', 'faith-tv-series' );
					}
					?>
				</span>
				<?php self::post_button( 'ftvs_secret', __( 'Make a new secret', 'faith-tv-series' ), 'button-link' ); ?>
			</p>
		</div>
		<?php
	}

	private static function wizard() {
		// phpcs:disable WordPress.Security.NonceVerification
		$step   = isset( $_GET['step'] ) ? max( 1, min( 3, absint( $_GET['step'] ) ) ) : 1;
		$source = '';
		if ( isset( $_GET['source'] ) ) {
			$source = in_array( $_GET['source'], self::lookup_sources(), true ) ? sanitize_key( $_GET['source'] ) : 'gideo';
		}
		$pick = isset( $_GET['pick'] ) ? sanitize_text_field( wp_unslash( $_GET['pick'] ) ) : '';
		// phpcs:enable
		if ( 2 === $step && '' === $source ) {
			$step = 1;
		}
		if ( 3 === $step && ! FTVS_Catalog::connected() ) {
			$step = 1;
		}
		$was_connected = FTVS_Catalog::connected() && 3 !== $step;
		$pending       = 2 === $step ? get_transient( self::pending_key() ) : false;
		$steps         = array( __( 'Where your videos live', 'faith-tv-series' ), __( 'Find your church', 'faith-tv-series' ), __( 'Pick what to show', 'faith-tv-series' ) );
		$brand         = self::brand();
		?>
		<div class="ftvs-card ftvs-pad">
			<h2 class="ftvs-h2"><?php echo $was_connected ? esc_html__( 'Connect a different church', 'faith-tv-series' ) : esc_html__( 'Connect your church', 'faith-tv-series' ); ?></h2>
			<p class="ftvs-lead"><?php esc_html_e( 'Three quick steps. Videos stay where they already live; nothing is copied or uploaded.', 'faith-tv-series' ); ?></p>
			<ol class="ftvs-steps">
				<?php foreach ( $steps as $i => $name ) : ?>
					<li class="<?php echo esc_attr( $i + 1 < $step ? 'is-done' : ( $i + 1 === $step ? 'is-on' : '' ) ); ?>"><?php echo esc_html( $name ); ?></li>
				<?php endforeach; ?>
			</ol>

			<?php if ( 1 === $step ) : ?>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
					<input type="hidden" name="connect" value="1">
					<input type="hidden" name="step" value="2">
					<div class="ftvs-choices<?php echo FTVS_Settings::extra_sources() ? ' ftvs-choices--3' : ''; ?>">
						<button type="submit" name="source" value="faithstream" class="ftvs-choice">
							<span class="ftvs-choice__ic"><?php echo self::gear( 38 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<span><strong><?php esc_html_e( 'Faith Stream', 'faith-tv-series' ); ?></strong><span class="ftvs-choice__p"><?php esc_html_e( 'Your church streams and keeps its video library on Faith Stream. Sunday live, instant updates and play counts work automatically.', 'faith-tv-series' ); ?></span></span>
						</button>
						<button type="submit" name="source" value="gideo" class="ftvs-choice">
							<span class="ftvs-choice__ic is-gideo"><svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><rect x="2.5" y="4.5" width="19" height="13" rx="1.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 21h8M12 17.5V21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M10 8.5v5l4.5-2.5z" fill="currentColor"/></svg></span>
							<span><strong><?php esc_html_e( 'A Gideo TV channel', 'faith-tv-series' ); ?></strong><span class="ftvs-choice__p"><?php esc_html_e( 'Your church has a TV website and apps on Gideo, for example tv.yourchurch.com.', 'faith-tv-series' ); ?></span></span>
						</button>
						<?php if ( FTVS_Settings::extra_sources() ) : ?>
						<button type="submit" name="source" value="youtube" class="ftvs-choice">
							<span class="ftvs-choice__ic is-gideo"><svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="4" fill="none" stroke="currentColor" stroke-width="2"/><path d="M10 9v6l5-3z" fill="currentColor"/></svg></span>
							<span><strong><?php esc_html_e( 'YouTube', 'faith-tv-series' ); ?></strong><span class="ftvs-choice__p"><?php esc_html_e( 'Your videos are on YouTube. Each playlist becomes a series, and new uploads appear by themselves.', 'faith-tv-series' ); ?></span></span>
						</button>
						<?php endif; ?>
					</div>
				</form>
				<div class="ftvs-alt">
					<div>
						<strong><?php esc_html_e( 'Just looking?', 'faith-tv-series' ); ?></strong>
						<span class="ftvs-muted"><?php esc_html_e( 'See every layout on your own site with sample videos. Only people who can edit the site see them.', 'faith-tv-series' ); ?></span>
					</div>
					<?php self::post_button( 'ftvs_demo', __( 'Try it with sample videos', 'faith-tv-series' ) ); ?>
				</div>
				<div class="ftvs-alt">
					<div>
						<strong><?php esc_html_e( 'No video platform yet?', 'faith-tv-series' ); ?></strong>
						<?php if ( FTVS_Settings::extra_sources() ) : ?>
							<span class="ftvs-muted"><?php esc_html_e( 'You can build series by hand from any video links (Faith Stream > Build a series), or talk to us about hosting your videos and Sunday stream.', 'faith-tv-series' ); ?></span>
						<?php else : ?>
							<span class="ftvs-muted"><?php esc_html_e( 'Talk to us about hosting your videos and Sunday stream on Faith Stream.', 'faith-tv-series' ); ?></span>
						<?php endif; ?>
					</div>
					<span class="ftvs-inline">
						<?php if ( FTVS_Settings::extra_sources() ) : ?>
							<a class="button ftvs-btn-secondary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . FTVS_Manual::TYPE ) ); ?>"><?php esc_html_e( 'Build a series', 'faith-tv-series' ); ?></a>
						<?php endif; ?>
						<a class="ftvs-link" href="<?php echo esc_url( add_query_arg( array( 'intent' => 'start', 'site' => home_url() ), $brand['url'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Talk to us', 'faith-tv-series' ); ?></a>
					</span>
				</div>
			<?php elseif ( 2 === $step ) : ?>
				<?php self::find_form( $source, $pending ); ?>
			<?php else : ?>
				<?php self::pick_step( $pick ); ?>
			<?php endif; ?>

			<div class="ftvs-wizard-foot">
				<?php if ( 2 === $step ) : ?>
					<a class="ftvs-link" href="<?php echo esc_url( self::url( self::PAGE, array( 'connect' => 1 ) ) ); ?>"><?php esc_html_e( 'Back', 'faith-tv-series' ); ?></a>
				<?php endif; ?>
				<?php if ( $was_connected ) : ?>
					<a class="ftvs-link" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'faith-tv-series' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function find_form( $source, $pending ) {
		$input = isset( $pending['input'] ) ? $pending['input'] : array();
		$found = isset( $pending['found'] ) && $pending['found']['source'] === $source ? $pending['found'] : null;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ftvs_lookup">
			<input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>">
			<?php wp_nonce_field( 'ftvs_lookup' ); ?>
			<?php if ( 'gideo' === $source ) : ?>
				<div class="ftvs-field">
					<label for="ftvs-tv"><?php esc_html_e( 'Your church\'s TV website', 'faith-tv-series' ); ?></label>
					<div class="ftvs-inline">
						<input class="ftvs-input" id="ftvs-tv" name="tv" placeholder="tv.yourchurch.com" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( isset( $input['tv'] ) ? $input['tv'] : '' ); ?>">
						<button class="button button-primary button-hero ftvs-btn-primary"><?php esc_html_e( 'Find my church', 'faith-tv-series' ); ?></button>
					</div>
					<span class="ftvs-hint"><?php esc_html_e( 'The address people use to watch your church online. We look it up with Gideo and fill in the rest.', 'faith-tv-series' ); ?></span>
				</div>
				<details class="ftvs-advanced"<?php echo isset( $input['account_id'] ) ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'I have a Gideo account ID instead', 'faith-tv-series' ); ?></summary>
					<div class="ftvs-inline" style="margin-top:10px">
						<input class="ftvs-input" name="account_id" placeholder="Your-Church-1" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( isset( $input['account_id'] ) ? $input['account_id'] : '' ); ?>">
						<button class="button ftvs-btn-secondary" name="by_account" value="1"><?php esc_html_e( 'Use this ID', 'faith-tv-series' ); ?></button>
					</div>
				</details>
			<?php elseif ( 'youtube' === $source ) : ?>
				<div class="ftvs-field">
					<label for="ftvs-yt"><?php esc_html_e( 'Your playlist links, or your channel link', 'faith-tv-series' ); ?></label>
					<textarea class="ftvs-input" id="ftvs-yt" name="yt" rows="4" style="max-width:640px;padding:8px 12px" placeholder="https://www.youtube.com/playlist?list=...&#10;https://www.youtube.com/@yourchurch"><?php echo esc_textarea( isset( $input['yt'] ) ? $input['yt'] : '' ); ?></textarea>
					<span class="ftvs-hint"><?php esc_html_e( 'One per line. Each playlist becomes a series; a channel link adds "Latest videos".', 'faith-tv-series' ); ?></span>
				</div>
				<details class="ftvs-advanced">
					<summary><?php esc_html_e( 'I have a YouTube API key (optional)', 'faith-tv-series' ); ?></summary>
					<div style="margin-top:10px"><input class="ftvs-input" name="yt_key" type="password" autocomplete="off" placeholder="AIza..."><p class="ftvs-hint"><?php esc_html_e( 'Without one, each playlist shows its newest 15 videos.', 'faith-tv-series' ); ?></p></div>
				</details>
				<p><button class="button button-primary button-hero ftvs-btn-primary"><?php esc_html_e( 'Find my videos', 'faith-tv-series' ); ?></button></p>
			<?php else : ?>
				<div class="ftvs-field">
					<label for="ftvs-fs-url"><?php esc_html_e( 'Your Faith Stream address', 'faith-tv-series' ); ?></label>
					<div class="ftvs-inline">
						<input class="ftvs-input" id="ftvs-fs-url" name="fs_url" placeholder="stream.yourchurch.com" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( isset( $input['fs_url'] ) ? $input['fs_url'] : '' ); ?>">
						<button class="button button-primary button-hero ftvs-btn-primary"><?php esc_html_e( 'Find my church', 'faith-tv-series' ); ?></button>
					</div>
					<span class="ftvs-hint"><?php esc_html_e( 'The address people use to watch, as it appears in the browser. We work out the rest.', 'faith-tv-series' ); ?></span>
				</div>
				<details class="ftvs-advanced"<?php echo ! empty( $input['fs_tenant'] ) ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'I have a church ID', 'faith-tv-series' ); ?></summary>
					<div class="ftvs-inline" style="margin-top:10px">
						<input class="ftvs-input" name="fs_tenant" placeholder="yourchurch" autocomplete="off" spellcheck="false" style="max-width:240px" value="<?php echo esc_attr( isset( $input['fs_tenant'] ) ? $input['fs_tenant'] : '' ); ?>">
						<span class="ftvs-hint"><?php esc_html_e( 'Only needed with the main Faith Stream address. Faith Stream shows it under Settings.', 'faith-tv-series' ); ?></span>
					</div>
				</details>
			<?php endif; ?>
		</form>

		<?php if ( ! empty( $pending['error'] ) ) : ?>
			<p class="ftvs-msg"><?php echo esc_html( $pending['error'] ); ?></p>
		<?php elseif ( $found ) : ?>
			<?php
			$names  = wp_list_pluck( array_slice( $found['rows'], 0, 4 ), 'title' );
			$thumbs = array_slice( array_filter( wp_list_pluck( $found['rows'], 'image' ) ), 0, 6 );
			$color  = ! empty( $found['colors']['primary'] ) ? $found['colors']['primary'] : '';
			?>
			<div class="ftvs-found">
				<span class="ftvs-logobox" style="<?php echo esc_attr( self::bg( $found['church_logo'] ) ); ?>"></span>
				<div>
					<span class="ftvs-chip is-ok"><?php esc_html_e( 'Found it', 'faith-tv-series' ); ?></span>
					<h3><?php echo esc_html( $found['church_name'] ); ?></h3>
					<p class="ftvs-muted">
						<?php
						/* translators: 1: number of rows, 2: some row names */
						printf( esc_html( _n( '%1$d row on your channel: %2$s', '%1$d rows on your channel: %2$s', count( $found['rows'] ), 'faith-tv-series' ) ), count( $found['rows'] ), esc_html( implode( ', ', $names ) . ( count( $found['rows'] ) > 4 ? ', …' : '' ) ) );
						?>
					</p>
					<div class="ftvs-thumbs">
						<?php foreach ( $thumbs as $src ) : ?>
							<img src="<?php echo esc_url( $src ); ?>" alt="">
						<?php endforeach; ?>
					</div>
				</div>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ftvs-confirm">
				<input type="hidden" name="action" value="ftvs_connect">
				<?php wp_nonce_field( 'ftvs_connect' ); ?>
				<?php if ( '' !== $color ) : ?>
					<label class="ftvs-check" style="margin-bottom:12px"><input type="checkbox" name="use_colors" value="1" checked> <span class="ftvs-swatch-dot" style="background:<?php echo esc_attr( $color ); ?>"></span>
						<?php
						/* translators: %s: color like #C40D3C */
						printf( esc_html__( 'Use our church color (%s) and our light or dark look', 'faith-tv-series' ), esc_html( $color ) );
						?>
					</label>
				<?php endif; ?>
				<button class="button button-primary button-hero ftvs-btn-primary"><?php esc_html_e( 'Yes, this is my church', 'faith-tv-series' ); ?></button>
			</form>
		<?php endif; ?>
		<?php
	}

	private static function pick_step( $pick ) {
		$tree = FTVS_Catalog::get_tree();
		$rows = is_wp_error( $tree ) ? array() : $tree;
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( $candidate['id'] === $pick ) {
				$row = $candidate;
			}
		}
		if ( $row ) {
			$code = '[faith_tv_series category="' . $row['id'] . '"]';
			?>
			<p class="ftvs-msg is-ok"><strong>
				<?php
				/* translators: %s: church name */
				printf( esc_html__( 'You are connected to %s.', 'faith-tv-series' ), esc_html( FTVS_Settings::get( 'church_name' ) ) );
				?>
			</strong>
				<?php
				/* translators: %s: category name */
				printf( esc_html__( '%s is ready to go on your site.', 'faith-tv-series' ), esc_html( $row['title'] ) );
				?>
			</p>
			<div class="ftvs-alt ftvs-alt--strong">
				<div>
					<strong><?php esc_html_e( 'The fastest way: a ready-made Watch page', 'faith-tv-series' ); ?></strong>
					<span class="ftvs-muted"><?php esc_html_e( 'Sunday live, the newest messages, your series and a sermon search, on one page. You look it over, then press Publish.', 'faith-tv-series' ); ?></span>
				</div>
				<?php self::post_button( 'ftvs_watch_page', __( 'Create my Watch page', 'faith-tv-series' ), 'button button-primary ftvs-btn-primary' ); ?>
			</div>
			<div class="ftvs-done">
				<div class="ftvs-how">
					<h4><?php esc_html_e( 'With Elementor', 'faith-tv-series' ); ?></h4>
					<ol>
						<li><?php esc_html_e( 'Edit a page with Elementor.', 'faith-tv-series' ); ?></li>
						<li><?php esc_html_e( 'Search the widgets for "Faith TV" and drag it in.', 'faith-tv-series' ); ?></li>
						<li>
							<?php
							/* translators: %s: category name */
							printf( esc_html__( 'Pick %s. Done.', 'faith-tv-series' ), '<strong>' . esc_html( $row['title'] ) . '</strong>' );
							?>
						</li>
					</ol>
				</div>
				<div class="ftvs-how">
					<h4><?php esc_html_e( 'With the block editor', 'faith-tv-series' ); ?></h4>
					<ol>
						<li><?php esc_html_e( 'Edit a page and press + to add a block.', 'faith-tv-series' ); ?></li>
						<li><?php esc_html_e( 'Search "Faith TV" (or "Sunday Live", "Sermon Library").', 'faith-tv-series' ); ?></li>
						<li>
							<?php
							/* translators: %s: category name */
							printf( esc_html__( 'Pick %s in the sidebar.', 'faith-tv-series' ), '<strong>' . esc_html( $row['title'] ) . '</strong>' );
							?>
						</li>
					</ol>
				</div>
				<div class="ftvs-how">
					<h4><?php esc_html_e( 'Anywhere else', 'faith-tv-series' ); ?></h4>
					<p class="ftvs-muted"><?php esc_html_e( 'Paste this shortcode into a page or post:', 'faith-tv-series' ); ?></p>
					<div class="ftvs-code"><input readonly value="<?php echo esc_attr( $code ); ?>"><button type="button" class="button ftvs-btn-secondary" data-copy="<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Copy', 'faith-tv-series' ); ?></button></div>
				</div>
			</div>
			<p class="ftvs-inline" style="margin-top:18px">
				<a class="button ftvs-btn-secondary" href="<?php echo esc_url( self::url( 'faith-stream-videos' ) ); ?>"><?php esc_html_e( 'See all your videos', 'faith-tv-series' ); ?></a>
				<a class="ftvs-link" href="<?php echo esc_url( self::url( 'faith-stream-look' ) ); ?>"><?php esc_html_e( 'Set your church color', 'faith-tv-series' ); ?></a>
				<a class="ftvs-link" href="<?php echo esc_url( self::url( 'faith-stream-live' ) ); ?>"><?php esc_html_e( 'Set up Sunday live', 'faith-tv-series' ); ?></a>
			</p>
			<?php
			return;
		}
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="connect" value="1">
			<input type="hidden" name="step" value="3">
			<p class="ftvs-lead"><?php esc_html_e( 'Pick the category to put on your site first. You can add more anywhere later.', 'faith-tv-series' ); ?></p>
			<?php if ( is_wp_error( $tree ) ) : ?>
				<p class="ftvs-msg"><?php echo esc_html( $tree->get_error_message() ); ?></p>
			<?php endif; ?>
			<div class="ftvs-picks">
				<?php foreach ( $rows as $candidate ) : ?>
					<label class="ftvs-pick">
						<input type="radio" name="pick" value="<?php echo esc_attr( $candidate['id'] ); ?>" required>
						<span class="ftvs-pick__art" style="<?php echo esc_attr( self::bg( $candidate['image'] ) ); ?>"></span>
						<span class="ftvs-pick__txt"><strong><?php echo esc_html( $candidate['title'] ); ?></strong><span><?php echo esc_html( self::inside( $candidate ) ); ?></span></span>
					</label>
				<?php endforeach; ?>
			</div>
			<p style="margin-top:20px"><button class="button button-primary button-hero ftvs-btn-primary"><?php esc_html_e( 'Finish', 'faith-tv-series' ); ?></button></p>
		</form>
		<?php
	}

	/* ---------- Videos ---------- */

	private static function videos( $tree ) {
		?>
		<h2 class="ftvs-h2"><?php esc_html_e( 'Your videos', 'faith-tv-series' ); ?></h2>
		<p class="ftvs-lead"><?php esc_html_e( 'Everything on your channel. Put any of these on a page with the Elementor widget or block "Faith TV Series", or copy its shortcode.', 'faith-tv-series' ); ?></p>
		<div class="ftvs-toolbar">
			<input class="ftvs-input" type="search" data-ftvs-filter placeholder="<?php esc_attr_e( 'Search', 'faith-tv-series' ); ?>" aria-label="<?php esc_attr_e( 'Search categories', 'faith-tv-series' ); ?>">
			<span class="ftvs-grow"></span>
			<?php if ( FTVS_Settings::extra_sources() ) : ?>
				<a class="button ftvs-btn-secondary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . FTVS_Manual::TYPE ) ); ?>"><?php esc_html_e( 'Build a series', 'faith-tv-series' ); ?></a>
			<?php endif; ?>
			<?php self::refresh_button(); ?>
		</div>
		<?php if ( is_wp_error( $tree ) ) : ?>
			<p class="ftvs-msg"><?php echo esc_html( $tree->get_error_message() ); ?></p>
			<?php
			return;
		endif;
		$auto = array(
			FTVS_Catalog::NEWEST   => array( __( 'Newest messages (automatic)', 'faith-tv-series' ), __( 'Always the latest messages from the whole channel.', 'faith-tv-series' ) ),
			FTVS_Catalog::FEATURED => array( __( 'What we feature (automatic)', 'faith-tv-series' ), 'faithstream' === FTVS_Catalog::source() ? __( 'Whatever you mark as featured on your Faith Stream home page.', 'faith-tv-series' ) : __( 'Your channel\'s first row.', 'faith-tv-series' ) ),
		);
		?>
		<div class="ftvs-rows">
			<?php foreach ( $auto as $id => $info ) : ?>
				<?php $code = '[faith_tv_series category="' . $id . '"]'; ?>
				<div class="ftvs-row" data-ftvs-row="<?php echo esc_attr( strtolower( $info[0] ) ); ?>">
					<span class="ftvs-row__art ftvs-row__art--auto" aria-hidden="true"><?php echo self::gear( 34, '#fff' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
					<div>
						<h3><?php echo esc_html( $info[0] ); ?></h3>
						<span class="ftvs-muted"><?php echo esc_html( $info[1] ); ?></span>
					</div>
					<div class="ftvs-row__actions">
						<button type="button" class="button ftvs-btn-secondary" data-copy="<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Copy shortcode', 'faith-tv-series' ); ?></button>
					</div>
				</div>
			<?php endforeach; ?>
			<?php foreach ( $tree as $row ) : ?>
				<?php $code = '[faith_tv_series category="' . $row['id'] . '"]'; ?>
				<div class="ftvs-row" data-ftvs-row="<?php echo esc_attr( strtolower( $row['title'] . ' ' . implode( ' ', wp_list_pluck( $row['children'], 'title' ) ) ) ); ?>">
					<span class="ftvs-row__art" style="<?php echo esc_attr( self::bg( $row['image'] ) ); ?>"></span>
					<div>
						<h3><?php echo esc_html( $row['title'] ); ?></h3>
						<span class="ftvs-muted"><?php echo esc_html( self::inside( $row ) ); ?></span>
						<?php if ( $row['children'] ) : ?>
							<div class="ftvs-kids">
								<?php foreach ( $row['children'] as $child ) : ?>
									<button type="button" data-copy="<?php echo esc_attr( '[faith_tv_series category="' . $child['id'] . '"]' ); ?>" title="<?php esc_attr_e( 'Copy this series\' shortcode', 'faith-tv-series' ); ?>"><?php echo esc_html( $child['title'] ); ?></button>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
					<div class="ftvs-row__actions">
						<button type="button" class="button ftvs-btn-secondary" data-copy="<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Copy shortcode', 'faith-tv-series' ); ?></button>
						<a class="ftvs-link" href="<?php echo esc_url( self::url( 'faith-stream-embed', array( 'category' => $row['id'] ) ) ); ?>"><?php esc_html_e( 'Get embed code', 'faith-tv-series' ); ?></a>
						<?php if ( FTVS_Catalog::category_link( $row['id'] ) ) : ?>
							<a class="ftvs-link" href="<?php echo esc_url( FTVS_Catalog::category_link( $row['id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open on your channel', 'faith-tv-series' ); ?></a>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="ftvs-muted ftvs-small"><?php esc_html_e( 'Tip: click a series name to copy its own shortcode. Shortcode options: layout="showcase|coverflow|list|row|grid|library", mobile_layout="auto|same|list|coverflow|row", title="...", eyebrow="...", limit="6", play="faithtv", theme="light", video="<video id>" (one video), from="2026-03-01" until="2026-04-06" otherwise="<category>" (show only between dates), next="off" or next_label="..." next_url="..." (the button after watching). Also [faith_tv_live] for Sunday live and [faith_tv_library] for the sermon library.', 'faith-tv-series' ); ?></p>
		<?php
	}

	/* ---------- Look & feel ---------- */

	private static function look( $tree ) {
		$s        = FTVS_Settings::all();
		$name     = FTVS_Settings::OPTION;
		$colors   = array(
			'#C40D3C' => __( 'Crimson', 'faith-tv-series' ),
			'#084073' => __( 'Navy', 'faith-tv-series' ),
			'#423BA3' => __( 'Purple', 'faith-tv-series' ),
			'#08777F' => __( 'Teal', 'faith-tv-series' ),
			'#15803D' => __( 'Green', 'faith-tv-series' ),
			'#111114' => __( 'Black', 'faith-tv-series' ),
		);
		$layouts  = array(
			'showcase'  => __( 'Showcase', 'faith-tv-series' ),
			'coverflow' => __( '3D carousel', 'faith-tv-series' ),
			'list'      => __( 'Featured + list', 'faith-tv-series' ),
			'row'       => __( 'Row', 'faith-tv-series' ),
			'grid'      => __( 'Grid', 'faith-tv-series' ),
		);
		$mlayouts = array(
			'auto'      => __( 'Automatic', 'faith-tv-series' ),
			'same'      => __( 'Same as computers', 'faith-tv-series' ),
			'list'      => __( 'Featured + list', 'faith-tv-series' ),
			'coverflow' => __( 'Swipe', 'faith-tv-series' ),
			'row'       => __( 'Row', 'faith-tv-series' ),
		);
		$custom   = ! isset( $colors[ strtoupper( $s['accent'] ) ] );
		$locked   = FTVS_Settings::direct_edition() && ! FTVS_Settings::feature( 'hide_powered_by' );
		$brand    = self::brand();
		$preview  = self::preview_categories( $tree );
		?>
		<h2 class="ftvs-h2"><?php esc_html_e( 'Look & feel', 'faith-tv-series' ); ?></h2>
		<p class="ftvs-lead"><?php esc_html_e( 'Defaults for every video section on your site. Each section can still pick its own layout.', 'faith-tv-series' ); ?></p>
		<div class="ftvs-look">
			<form method="post" action="options.php" class="ftvs-card ftvs-pad" data-ftvs-look data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ftvs_preview_url' ) ); ?>">
				<?php settings_fields( 'ftvs' ); ?>
				<fieldset class="ftvs-field">
					<legend><?php esc_html_e( 'Church color', 'faith-tv-series' ); ?></legend>
					<div class="ftvs-swatches">
						<?php foreach ( $colors as $hex => $label ) : ?>
							<label class="ftvs-swatch" title="<?php echo esc_attr( $label ); ?>" style="--c:<?php echo esc_attr( $hex ); ?>"><input type="radio" name="<?php echo esc_attr( $name ); ?>[accent]" value="<?php echo esc_attr( $hex ); ?>"<?php checked( strtoupper( $s['accent'] ), $hex ); ?>><span class="screen-reader-text"><?php echo esc_html( $label ); ?></span></label>
						<?php endforeach; ?>
						<label class="ftvs-swatch is-custom" title="<?php esc_attr_e( 'Your own color', 'faith-tv-series' ); ?>" style="--c:<?php echo esc_attr( $s['accent'] ); ?>">
							<input type="radio" name="<?php echo esc_attr( $name ); ?>[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>" data-ftvs-custom-radio<?php checked( $custom ); ?>>
							<input type="color" value="<?php echo esc_attr( $s['accent'] ); ?>" data-ftvs-custom aria-label="<?php esc_attr_e( 'Your own color', 'faith-tv-series' ); ?>">
						</label>
					</div>
					<span class="ftvs-hint" data-ftvs-contrast><?php esc_html_e( 'Buttons, badges and the stripe behind the artwork.', 'faith-tv-series' ); ?></span>
				</fieldset>
				<fieldset class="ftvs-field">
					<legend><?php esc_html_e( 'Style', 'faith-tv-series' ); ?></legend>
					<div class="ftvs-seg"><?php self::radios( $name . '[style]', array( 'bold' => __( 'Bold', 'faith-tv-series' ), 'soft' => __( 'Soft', 'faith-tv-series' ), 'minimal' => __( 'Minimal', 'faith-tv-series' ) ), $s['style'] ); ?></div>
					<span class="ftvs-hint"><?php esc_html_e( 'Bold: square corners and heavy capitals. Soft: rounded corners, normal letters. Minimal: quiet and light.', 'faith-tv-series' ); ?></span>
				</fieldset>
				<fieldset class="ftvs-field">
					<legend><?php esc_html_e( 'Background', 'faith-tv-series' ); ?></legend>
					<div class="ftvs-seg"><?php self::radios( $name . '[theme]', array( 'dark' => __( 'Dark', 'faith-tv-series' ), 'light' => __( 'Light', 'faith-tv-series' ) ), $s['theme'] ); ?></div>
				</fieldset>
				<fieldset class="ftvs-field">
					<legend><?php esc_html_e( 'Font', 'faith-tv-series' ); ?></legend>
					<div class="ftvs-seg"><?php self::radios( $name . '[font]', array( 'brand' => __( 'Built-in', 'faith-tv-series' ), 'inherit' => __( 'Same as my website', 'faith-tv-series' ) ), $s['font'] ); ?></div>
				</fieldset>
				<fieldset class="ftvs-field">
					<legend><?php esc_html_e( 'Layout on computers', 'faith-tv-series' ); ?></legend>
					<div class="ftvs-seg"><?php self::radios( $name . '[layout]', $layouts, $s['layout'] ); ?></div>
				</fieldset>
				<fieldset class="ftvs-field">
					<legend><?php esc_html_e( 'Layout on phones', 'faith-tv-series' ); ?></legend>
					<div class="ftvs-seg"><?php self::radios( $name . '[mobile_layout]', $mlayouts, $s['mobile_layout'] ); ?></div>
					<span class="ftvs-hint"><?php esc_html_e( 'Automatic: the newest series big, the rest as a list.', 'faith-tv-series' ); ?></span>
				</fieldset>
				<div class="ftvs-field">
					<label for="ftvs-label"><?php esc_html_e( 'Label over each series', 'faith-tv-series' ); ?></label>
					<input class="ftvs-input" id="ftvs-label" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $s['label'] ); ?>" placeholder="<?php esc_attr_e( 'The category\'s name', 'faith-tv-series' ); ?>">
					<span class="ftvs-hint"><?php esc_html_e( 'Leave empty to use each category\'s name.', 'faith-tv-series' ); ?></span>
				</div>
				<div class="ftvs-field">
					<label for="ftvs-badge"><?php esc_html_e( 'Badge on the newest', 'faith-tv-series' ); ?></label>
					<input class="ftvs-input" id="ftvs-badge" name="<?php echo esc_attr( $name ); ?>[badge]" value="<?php echo esc_attr( $s['badge'] ); ?>" style="max-width:180px">
					<span class="ftvs-hint"><?php esc_html_e( 'Leave empty for no badge.', 'faith-tv-series' ); ?></span>
				</div>
				<?php if ( '' !== $brand['name'] ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[powered_by]" value="<?php echo $locked ? '1' : '0'; ?>">
					<label class="ftvs-switch"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[powered_by]" value="1"<?php checked( ! empty( $s['powered_by'] ) ); ?><?php disabled( $locked ); ?>><span class="ftvs-switch__track"></span><span>
						<?php
						/* translators: %s: brand name */
						printf( esc_html__( 'Show "Powered by %s" under the player', 'faith-tv-series' ), esc_html( $brand['name'] ) );
						?>
					</span></label>
					<?php if ( $locked ) : ?>
						<p class="ftvs-hint"><?php esc_html_e( 'Included with your church\'s plan. Plans that remove it are on Faith Stream.', 'faith-tv-series' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
				<fieldset class="ftvs-field" style="margin-top:18px">
					<legend><?php esc_html_e( 'Text sizes and colors', 'faith-tv-series' ); ?></legend>
					<?php self::text_table( $name . '[text]', (array) $s['text'] ); ?>
					<span class="ftvs-hint"><?php esc_html_e( 'For every section on the site. Leave a box empty for the built-in look. Each Elementor widget can still change its own under Style.', 'faith-tv-series' ); ?></span>
				</fieldset>
				<?php submit_button( __( 'Save', 'faith-tv-series' ), 'primary ftvs-btn-primary' ); ?>
			</form>
			<div class="ftvs-look__preview">
				<div class="ftvs-preview-head"><strong><?php esc_html_e( 'Preview', 'faith-tv-series' ); ?></strong>
					<select class="ftvs-input ftvs-input--small" data-ftvs-pv-cat aria-label="<?php esc_attr_e( 'Preview with', 'faith-tv-series' ); ?>">
						<?php foreach ( $preview as $id => $title ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $title ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="ftvs-seg is-small" role="group"><button type="button" data-ftvs-pv="desk" aria-pressed="true"><?php esc_html_e( 'Computer', 'faith-tv-series' ); ?></button><button type="button" data-ftvs-pv="phone" aria-pressed="false"><?php esc_html_e( 'Phone', 'faith-tv-series' ); ?></button></span>
				</div>
				<div class="ftvs-embed-stage ftvs-look__stage" data-ftvs-pv-stage>
					<iframe title="<?php esc_attr_e( 'Preview', 'faith-tv-series' ); ?>" data-ftvs-embed data-ftvs-pv-frame style="height:640px"></iframe>
				</div>
				<p class="ftvs-hint"><?php esc_html_e( 'The real section with your real videos, before you save. Nothing changes on the site until you press Save.', 'faith-tv-series' ); ?></p>
			</div>
		</div>
		<?php
	}

	/** Categories to preview Look & feel with: a row with series first. */
	private static function preview_categories( $tree ) {
		$out = array();
		foreach ( is_wp_error( $tree ) ? array() : $tree as $row ) {
			if ( $row['children'] ) {
				$out[ $row['id'] ] = $row['title'];
			}
		}
		foreach ( is_wp_error( $tree ) ? array() : $tree as $row ) {
			if ( ! isset( $out[ $row['id'] ] ) ) {
				$out[ $row['id'] ] = $row['title'];
			}
		}
		$out[ FTVS_Catalog::NEWEST ] = __( 'Newest messages', 'faith-tv-series' );
		return $out;
	}

	private static function radios( $field, $options, $current ) {
		foreach ( $options as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s" value="%2$s"%3$s><span>%4$s</span></label>',
				esc_attr( $field ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
	}

	private static function toggle( $key, $label, $hint = '' ) {
		$name = FTVS_Settings::OPTION;
		?>
		<input type="hidden" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="0">
		<label class="ftvs-switch"><input type="checkbox" name="<?php echo esc_attr( $name . '[' . $key . ']' ); ?>" value="1"<?php checked( ! empty( FTVS_Settings::get( $key ) ) ); ?>><span class="ftvs-switch__track"></span><span><?php echo esc_html( $label ); ?></span></label>
		<?php if ( '' !== $hint ) : ?>
			<p class="ftvs-hint ftvs-hint--switch"><?php echo esc_html( $hint ); ?></p>
		<?php endif; ?>
		<?php
	}

	/* ---------- Player ---------- */

	private static function player() {
		$s     = FTVS_Settings::all();
		$name  = FTVS_Settings::OPTION;
		$steps = array_pad( (array) $s['next_steps'], 3, array( 'label' => '', 'url' => '' ) );
		?>
		<h2 class="ftvs-h2"><?php esc_html_e( 'Player', 'faith-tv-series' ); ?></h2>
		<p class="ftvs-lead"><?php esc_html_e( 'What happens while and after someone watches.', 'faith-tv-series' ); ?></p>
		<form method="post" action="options.php" class="ftvs-card ftvs-pad ftvs-narrow">
			<?php settings_fields( 'ftvs' ); ?>
			<h3 class="ftvs-h3" style="margin-top:0"><?php esc_html_e( 'A next step after watching', 'faith-tv-series' ); ?></h3>
			<p class="ftvs-hint"><?php esc_html_e( 'Up to three buttons under the player and when a message ends: Plan a visit, I\'m new, Prayer, Give, or any link. Each link carries which message led there (utm_source=faith-tv, utm_content=<video>), so your forms and follow-up can see it.', 'faith-tv-series' ); ?></p>
			<div class="ftvs-field">
				<label for="ftvs-next-title"><?php esc_html_e( 'Heading', 'faith-tv-series' ); ?></label>
				<input class="ftvs-input" id="ftvs-next-title" name="<?php echo esc_attr( $name ); ?>[next_title]" value="<?php echo esc_attr( $s['next_title'] ); ?>" placeholder="<?php esc_attr_e( 'Take a next step', 'faith-tv-series' ); ?>">
			</div>
			<table class="ftvs-text-table ftvs-steps-table">
				<thead><tr><th><?php esc_html_e( 'Button', 'faith-tv-series' ); ?></th><th><?php esc_html_e( 'Goes to', 'faith-tv-series' ); ?></th></tr></thead>
				<tbody>
					<?php
					$examples = array( __( 'Plan a visit', 'faith-tv-series' ), __( 'Prayer request', 'faith-tv-series' ), __( 'Give', 'faith-tv-series' ) );
					foreach ( $steps as $i => $step ) :
						?>
						<tr>
							<td><input class="ftvs-input" name="<?php echo esc_attr( $name . '[next_steps][' . $i . '][label]' ); ?>" value="<?php echo esc_attr( $step['label'] ); ?>" placeholder="<?php echo esc_attr( $examples[ $i ] ); ?>" aria-label="<?php esc_attr_e( 'Button words', 'faith-tv-series' ); ?>"></td>
							<td><input class="ftvs-input" name="<?php echo esc_attr( $name . '[next_steps][' . $i . '][url]' ); ?>" value="<?php echo esc_attr( $step['url'] ); ?>" placeholder="https://" aria-label="<?php esc_attr_e( 'Link', 'faith-tv-series' ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3 class="ftvs-h3"><?php esc_html_e( 'A button for one series', 'faith-tv-series' ); ?></h3>
			<p class="ftvs-hint"><?php esc_html_e( 'Shown first, only with that series. For example "Sign up for the marriage retreat" after the marriage series.', 'faith-tv-series' ); ?></p>
			<?php
			$options = array();
			$tree    = FTVS_Catalog::get_tree();
			foreach ( is_wp_error( $tree ) ? array() : $tree as $row ) {
				$options[ $row['id'] ] = $row['title'];
				foreach ( $row['children'] as $child ) {
					$options[ $child['id'] ] = $row['title'] . ' / ' . $child['title'];
				}
			}
			$rows = array();
			foreach ( (array) $s['series_steps'] as $id => $step ) {
				$rows[] = array( 'series' => $id, 'label' => $step['label'], 'url' => $step['url'] );
			}
			$rows[] = array( 'series' => '', 'label' => '', 'url' => '' );
			$rows[] = array( 'series' => '', 'label' => '', 'url' => '' );
			?>
			<table class="ftvs-text-table ftvs-steps-table">
				<thead><tr><th><?php esc_html_e( 'Series', 'faith-tv-series' ); ?></th><th><?php esc_html_e( 'Button', 'faith-tv-series' ); ?></th><th><?php esc_html_e( 'Goes to', 'faith-tv-series' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $i => $row ) : ?>
						<tr>
							<td>
								<select class="ftvs-input" name="<?php echo esc_attr( $name . '[series_steps][' . $i . '][series]' ); ?>" aria-label="<?php esc_attr_e( 'Series', 'faith-tv-series' ); ?>">
									<option value=""><?php esc_html_e( '- Pick a series -', 'faith-tv-series' ); ?></option>
									<?php foreach ( $options as $id => $title ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>"<?php selected( $row['series'], $id ); ?>><?php echo esc_html( $title ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><input class="ftvs-input" name="<?php echo esc_attr( $name . '[series_steps][' . $i . '][label]' ); ?>" value="<?php echo esc_attr( $row['label'] ); ?>" aria-label="<?php esc_attr_e( 'Button words', 'faith-tv-series' ); ?>"></td>
							<td><input class="ftvs-input" name="<?php echo esc_attr( $name . '[series_steps][' . $i . '][url]' ); ?>" value="<?php echo esc_attr( $row['url'] ); ?>" placeholder="https://" aria-label="<?php esc_attr_e( 'Link', 'faith-tv-series' ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3 class="ftvs-h3"><?php esc_html_e( 'While watching', 'faith-tv-series' ); ?></h3>
			<?php self::toggle( 'upnext', __( 'Play the next episode after a short countdown, and suggest more at the end', 'faith-tv-series' ) ); ?>
			<?php self::toggle( 'resume', __( 'Pick up where people left off (remembered on their own device, nothing is sent anywhere)', 'faith-tv-series' ) ); ?>
			<?php self::toggle( 'share', __( 'Show a Share button for each message', 'faith-tv-series' ) ); ?>

			<h3 class="ftvs-h3"><?php esc_html_e( 'Counting plays', 'faith-tv-series' ); ?></h3>
			<?php self::toggle( 'count_plays', __( 'Count plays for the WordPress dashboard (no names, no addresses)', 'faith-tv-series' ) ); ?>
			<?php if ( 'faithstream' === $s['source'] ) : ?>
				<?php self::toggle( 'report_plays', __( 'Also count them in Faith Stream\'s reports and Faith Central\'s "who is watching"', 'faith-tv-series' ), __( 'Viewers stay anonymous. Faith Stream stores only a daily-scrambled form of the visitor\'s internet address.', 'faith-tv-series' ) ); ?>
			<?php endif; ?>
			<?php self::toggle( 'stats_email', __( 'Email last week\'s numbers to the site admin every Monday', 'faith-tv-series' ) ); ?>

			<h3 class="ftvs-h3"><?php esc_html_e( 'Follow-up', 'faith-tv-series' ); ?></h3>
			<?php self::toggle( 'remind', __( 'Show "Remind me" on the Sunday Live section', 'faith-tv-series' ), __( 'Visitors leave an email or mobile number; it goes straight to your follow-up system below. Texting needs their agreement, which the form asks for.', 'faith-tv-series' ) ); ?>
			<div class="ftvs-field">
				<label for="ftvs-remind-hook"><?php esc_html_e( 'Send sign-ups to (webhook address)', 'faith-tv-series' ); ?></label>
				<input class="ftvs-input" id="ftvs-remind-hook" name="<?php echo esc_attr( $name ); ?>[remind_webhook]" value="<?php echo esc_attr( $s['remind_webhook'] ); ?>" placeholder="https://services.leadconnectorhq.com/hooks/...">
				<span class="ftvs-hint"><?php esc_html_e( 'For example a GoHighLevel "Inbound webhook" workflow trigger, Zapier or Make.', 'faith-tv-series' ); ?></span>
			</div>
			<div class="ftvs-field">
				<label for="ftvs-consent"><?php esc_html_e( 'Texting agreement wording', 'faith-tv-series' ); ?></label>
				<textarea class="ftvs-input" id="ftvs-consent" rows="2" style="max-width:640px;padding:8px 12px" name="<?php echo esc_attr( $name ); ?>[remind_consent]" placeholder="<?php echo esc_attr( FTVS_Followup::consent_text() ); ?>"><?php echo esc_textarea( $s['remind_consent'] ); ?></textarea>
			</div>
			<div class="ftvs-field">
				<label for="ftvs-new-hook"><?php esc_html_e( 'When a new video appears, tell (webhook address, optional)', 'faith-tv-series' ); ?></label>
				<input class="ftvs-input" id="ftvs-new-hook" name="<?php echo esc_attr( $name ); ?>[new_webhook]" value="<?php echo esc_attr( $s['new_webhook'] ); ?>" placeholder="https://">
				<span class="ftvs-hint"><?php esc_html_e( 'Sends the new video\'s title, picture and link, so a workflow can text or email "New series: ...".', 'faith-tv-series' ); ?></span>
			</div>
			<?php if ( FTVS_Followup::pending() ) : ?>
				<p class="ftvs-msg">
					<?php
					/* translators: %d: number of sign-ups */
					printf( esc_html( _n( '%d sign-up could not be delivered yet; it is retried every hour for a day.', '%d sign-ups could not be delivered yet; they are retried every hour for a day.', FTVS_Followup::pending(), 'faith-tv-series' ) ), (int) FTVS_Followup::pending() );
					?>
				</p>
			<?php endif; ?>
			<?php submit_button( __( 'Save', 'faith-tv-series' ), 'primary ftvs-btn-primary' ); ?>
		</form>
		<?php
	}

	/* ---------- Sunday live ---------- */

	private static function live() {
		$s        = FTVS_Settings::all();
		$name     = FTVS_Settings::OPTION;
		$services = array_pad( (array) $s['services'], 4, array( 'day' => '', 'time' => '' ) );
		$days     = array( __( 'Sunday', 'faith-tv-series' ), __( 'Monday', 'faith-tv-series' ), __( 'Tuesday', 'faith-tv-series' ), __( 'Wednesday', 'faith-tv-series' ), __( 'Thursday', 'faith-tv-series' ), __( 'Friday', 'faith-tv-series' ), __( 'Saturday', 'faith-tv-series' ) );
		$channels = array();
		if ( FTVS_Catalog::has_live() ) {
			$list = call_user_func( array( FTVS_Catalog::client(), 'get_live' ) );
			$channels = is_wp_error( $list ) ? array() : $list;
		}
		$state = FTVS_Live::state();
		?>
		<h2 class="ftvs-h2"><?php esc_html_e( 'Sunday live', 'faith-tv-series' ); ?></h2>
		<p class="ftvs-lead"><?php esc_html_e( 'The Sunday Live section counts down to the next service, switches to the live stream when it starts, and shows the replay afterward. Nobody has to edit the page on Sunday morning.', 'faith-tv-series' ); ?></p>
		<p class="ftvs-msg is-ok">
			<strong><?php esc_html_e( 'Right now:', 'faith-tv-series' ); ?></strong>
			<?php
			if ( 'live' === $state['status'] ) {
				esc_html_e( 'live.', 'faith-tv-series' );
			} elseif ( '' !== $state['next_label'] ) {
				/* translators: %s: day and time */
				printf( esc_html__( 'next service %s.', 'faith-tv-series' ), esc_html( $state['next_label'] ) );
			} else {
				esc_html_e( 'no service times yet.', 'faith-tv-series' );
			}
			if ( $state['replay'] ) {
				/* translators: %s: video title */
				echo ' ' . esc_html( sprintf( __( 'The replay shown: %s.', 'faith-tv-series' ), $state['replay']['title'] ) );
			}
			?>
		</p>
		<form method="post" action="options.php" class="ftvs-card ftvs-pad ftvs-narrow">
			<?php settings_fields( 'ftvs' ); ?>
			<h3 class="ftvs-h3" style="margin-top:0"><?php esc_html_e( 'Service times', 'faith-tv-series' ); ?></h3>
			<table class="ftvs-text-table">
				<tbody>
					<?php foreach ( $services as $i => $svc ) : ?>
						<tr>
							<td>
								<select class="ftvs-input" name="<?php echo esc_attr( $name . '[services][' . $i . '][day]' ); ?>" aria-label="<?php esc_attr_e( 'Day', 'faith-tv-series' ); ?>">
									<option value=""><?php esc_html_e( '- Day -', 'faith-tv-series' ); ?></option>
									<?php foreach ( $days as $d => $label ) : ?>
										<option value="<?php echo esc_attr( $d ); ?>"<?php selected( '' !== $svc['day'] && (int) $svc['day'] === $d ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><input class="ftvs-input" type="time" name="<?php echo esc_attr( $name . '[services][' . $i . '][time]' ); ?>" value="<?php echo esc_attr( $svc['time'] ); ?>" aria-label="<?php esc_attr_e( 'Time', 'faith-tv-series' ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="ftvs-inline">
				<label for="ftvs-svc-len"><?php esc_html_e( 'A service lasts about', 'faith-tv-series' ); ?></label>
				<input id="ftvs-svc-len" class="small-text" type="number" min="15" max="360" name="<?php echo esc_attr( $name ); ?>[service_length]" value="<?php echo esc_attr( $s['service_length'] ); ?>">
				<span><?php esc_html_e( 'minutes', 'faith-tv-series' ); ?></span>
				<span class="ftvs-hint">
					<?php
					/* translators: %s: time zone */
					printf( esc_html__( 'Times are in your site\'s time zone (%s, under Settings > General).', 'faith-tv-series' ), esc_html( wp_timezone_string() ) );
					?>
				</span>
			</p>

			<?php if ( $channels ) : ?>
				<h3 class="ftvs-h3"><?php esc_html_e( 'Which Faith Stream channel', 'faith-tv-series' ); ?></h3>
				<select class="ftvs-input" name="<?php echo esc_attr( $name ); ?>[live_channel]">
					<option value=""><?php esc_html_e( 'Whichever channel is live', 'faith-tv-series' ); ?></option>
					<?php foreach ( $channels as $ch ) : ?>
						<option value="<?php echo esc_attr( $ch['id'] ); ?>"<?php selected( $s['live_channel'], $ch['id'] ); ?>><?php echo esc_html( $ch['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="ftvs-hint"><?php esc_html_e( 'Faith Stream tells the site when the stream starts, and the replay appears when the recording is ready.', 'faith-tv-series' ); ?></p>
			<?php else : ?>
				<h3 class="ftvs-h3"><?php esc_html_e( 'Your live stream link', 'faith-tv-series' ); ?></h3>
				<input class="ftvs-input" name="<?php echo esc_attr( $name ); ?>[live_url]" value="<?php echo esc_attr( $s['live_url'] ); ?>" placeholder="https://www.youtube.com/@yourchurch/live" style="max-width:560px">
				<p class="ftvs-hint"><?php esc_html_e( 'A YouTube live link (youtube.com/channel/UC.../live works every week), a Vimeo event, or the embed address from Boxcast, Resi, Church Online or similar. It shows during service times.', 'faith-tv-series' ); ?></p>
			<?php endif; ?>

			<?php if ( 'faithstream' === $s['source'] ) : ?>
				<h3 class="ftvs-h3"><?php esc_html_e( 'Count people watching online', 'faith-tv-series' ); ?></h3>
				<?php self::toggle( 'checkin', __( 'Let people watching the live service on the website be counted present', 'faith-tv-series' ), __( 'They sign in once with their church account (or email), and after the minutes set in Faith Stream they are checked in on Faith Connections, like on the Faith Stream app. Needs Faith Connections turned on in Faith Stream, a live channel that counts for attendance, and this website added in Faith Stream: Settings > Website & media.', 'faith-tv-series' ) ); ?>
			<?php endif; ?>

			<h3 class="ftvs-h3"><?php esc_html_e( 'A "We\'re live" bar on every page', 'faith-tv-series' ); ?></h3>
			<?php self::toggle( 'live_bar', __( 'Show a slim bar at the top of every page while the service is live', 'faith-tv-series' ) ); ?>
			<div class="ftvs-field">
				<label for="ftvs-live-page"><?php esc_html_e( 'The bar takes people to', 'faith-tv-series' ); ?></label>
				<input class="ftvs-input" id="ftvs-live-page" name="<?php echo esc_attr( $name ); ?>[live_page]" value="<?php echo esc_attr( $s['live_page'] ); ?>" placeholder="<?php echo esc_attr( FTVS_Watch::page_id() ? get_permalink( FTVS_Watch::page_id() ) : home_url( '/watch/' ) ); ?>">
				<span class="ftvs-hint"><?php esc_html_e( 'Empty: your Watch page, or the stream opens right where they are.', 'faith-tv-series' ); ?></span>
			</div>
			<?php submit_button( __( 'Save', 'faith-tv-series' ), 'primary ftvs-btn-primary' ); ?>
		</form>
		<div class="ftvs-card ftvs-pad ftvs-narrow ftvs-gap">
			<h3 class="ftvs-h3" style="margin-top:0"><?php esc_html_e( 'Put it on a page', 'faith-tv-series' ); ?></h3>
			<p><?php esc_html_e( 'Elementor: the "Sunday Live" widget. Block editor: the "Sunday Live" block. Anywhere else:', 'faith-tv-series' ); ?></p>
			<div class="ftvs-code"><input readonly value="[faith_tv_live]"><button type="button" class="button ftvs-btn-secondary" data-copy="[faith_tv_live]"><?php esc_html_e( 'Copy', 'faith-tv-series' ); ?></button></div>
		</div>
		<?php
	}

	/* ---------- Embed ---------- */

	private static function embed( $tree ) {
		$rows = is_wp_error( $tree ) ? array() : $tree;
		// phpcs:ignore WordPress.Security.NonceVerification
		$want = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';
		$pick = '';
		foreach ( $rows as $row ) {
			if ( $row['id'] === $want || in_array( $want, wp_list_pluck( $row['children'], 'id' ), true ) ) {
				$pick = $want;
			}
		}
		if ( '' === $pick ) {
			foreach ( $rows as $row ) {
				if ( '' === $pick && $row['children'] ) {
					$pick = $row['id'];
				}
			}
		}
		if ( '' === $pick && $rows ) {
			$pick = $rows[0]['id'];
		}
		$args    = array(
			'category' => $pick,
			'layout'   => 'row',
			'theme'    => 'dark',
			'bg'       => '',
			'open'     => '',
		);
		$layouts = array(
			'row'       => __( 'Sliding row', 'faith-tv-series' ),
			'grid'      => __( 'Grid', 'faith-tv-series' ),
			'list'      => __( 'Featured + list', 'faith-tv-series' ),
			'showcase'  => __( 'Showcase', 'faith-tv-series' ),
			'coverflow' => __( '3D carousel', 'faith-tv-series' ),
		);
		$heights = array();
		foreach ( array_keys( $layouts ) as $layout ) {
			$heights[ $layout ] = FTVS_Embed::start_height( $layout );
		}
		$heights['live']    = FTVS_Embed::start_height( 'live' );
		$heights['video']   = FTVS_Embed::start_height( 'video' );
		$heights['library'] = FTVS_Embed::start_height( 'library' );
		?>
		<h2 class="ftvs-h2"><?php esc_html_e( 'Embed on another website', 'faith-tv-series' ); ?></h2>
		<p class="ftvs-lead"><?php esc_html_e( 'Put a video section on any website that accepts HTML: Faith Central, a landing page, a partner church\'s site. It stays up to date by itself. Pick what to show, then copy the code.', 'faith-tv-series' ); ?></p>
		<div class="ftvs-embed-builder" data-ftvs-embed data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ftvs_embed_code' ) ); ?>" data-heights="<?php echo esc_attr( wp_json_encode( $heights ) ); ?>">
			<form class="ftvs-card ftvs-pad" data-ftvs-embed-form onsubmit="return false">
				<fieldset class="ftvs-field">
					<legend><?php esc_html_e( 'Kind', 'faith-tv-series' ); ?></legend>
					<div class="ftvs-seg"><?php self::radios( 'kind', array( 'category' => __( 'A category', 'faith-tv-series' ), 'video' => __( 'One video', 'faith-tv-series' ), 'live' => __( 'Sunday live', 'faith-tv-series' ), 'library' => __( 'Sermon library', 'faith-tv-series' ) ), 'category' ); ?></div>
				</fieldset>
				<div class="ftvs-field" data-ftvs-kind="category library">
					<label for="ftvs-e-cat"><?php esc_html_e( 'What to show', 'faith-tv-series' ); ?></label>
					<select id="ftvs-e-cat" name="category" class="ftvs-input">
						<option value="<?php echo esc_attr( FTVS_Catalog::NEWEST ); ?>"><?php esc_html_e( 'Newest messages (automatic)', 'faith-tv-series' ); ?></option>
						<?php foreach ( $rows as $row ) : ?>
							<?php if ( $row['children'] ) : ?>
								<optgroup label="<?php echo esc_attr( $row['title'] ); ?>">
									<option value="<?php echo esc_attr( $row['id'] ); ?>"<?php selected( $pick, $row['id'] ); ?>>
										<?php
										/* translators: %s: row name */
										printf( esc_html__( 'All of %s', 'faith-tv-series' ), esc_html( $row['title'] ) );
										?>
									</option>
									<?php foreach ( $row['children'] as $child ) : ?>
										<option value="<?php echo esc_attr( $child['id'] ); ?>"<?php selected( $pick, $child['id'] ); ?>><?php echo esc_html( $child['title'] ); ?></option>
									<?php endforeach; ?>
								</optgroup>
							<?php else : ?>
								<option value="<?php echo esc_attr( $row['id'] ); ?>"<?php selected( $pick, $row['id'] ); ?>><?php echo esc_html( $row['title'] ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="ftvs-field" data-ftvs-kind="video" hidden>
					<label for="ftvs-e-video"><?php esc_html_e( 'Video ID', 'faith-tv-series' ); ?></label>
					<input id="ftvs-e-video" class="ftvs-input" name="video" placeholder="forgiveness-part-2">
					<span class="ftvs-hint"><?php esc_html_e( 'The last part of the video\'s address on your channel.', 'faith-tv-series' ); ?></span>
				</div>
				<fieldset class="ftvs-field" data-ftvs-kind="category">
					<legend><?php esc_html_e( 'Layout', 'faith-tv-series' ); ?></legend>
					<div class="ftvs-seg"><?php self::radios( 'layout', $layouts, 'row' ); ?></div>
				</fieldset>
				<fieldset class="ftvs-field">
					<legend><?php esc_html_e( 'Colors', 'faith-tv-series' ); ?></legend>
					<div class="ftvs-seg"><?php self::radios( 'theme', array( 'dark' => __( 'Dark', 'faith-tv-series' ), 'light' => __( 'Light', 'faith-tv-series' ) ), 'dark' ); ?></div>
					<label class="ftvs-check"><input type="checkbox" name="bg" value="clear"> <?php esc_html_e( 'See-through background (uses the other website\'s background)', 'faith-tv-series' ); ?></label>
				</fieldset>
				<div class="ftvs-field">
					<label for="ftvs-e-title"><?php esc_html_e( 'Heading (optional)', 'faith-tv-series' ); ?></label>
					<input id="ftvs-e-title" class="ftvs-input" name="title" placeholder="<?php esc_attr_e( 'Our series', 'faith-tv-series' ); ?>">
				</div>
				<div class="ftvs-field">
					<label for="ftvs-e-eyebrow"><?php esc_html_e( 'Small line above it (optional)', 'faith-tv-series' ); ?></label>
					<input id="ftvs-e-eyebrow" class="ftvs-input" name="eyebrow" placeholder="<?php esc_attr_e( 'New every week', 'faith-tv-series' ); ?>">
				</div>
				<div class="ftvs-field" data-ftvs-kind="category">
					<label for="ftvs-e-limit"><?php esc_html_e( 'How many to show', 'faith-tv-series' ); ?></label>
					<input id="ftvs-e-limit" class="small-text" type="number" min="0" max="50" name="limit" value="0">
					<span class="ftvs-hint"><?php esc_html_e( '0 shows all of them.', 'faith-tv-series' ); ?></span>
				</div>
				<fieldset class="ftvs-field" data-ftvs-kind="category video library">
					<legend><?php esc_html_e( 'When someone clicks', 'faith-tv-series' ); ?></legend>
					<div class="ftvs-seg"><?php self::radios( 'open', array( '' => __( 'Play inside the embed', 'faith-tv-series' ), 'channel' => __( 'Open your channel', 'faith-tv-series' ) ), '' ); ?></div>
				</fieldset>
				<details class="ftvs-advanced">
					<summary><?php esc_html_e( 'Text sizes and colors', 'faith-tv-series' ); ?></summary>
					<?php self::text_table( '', array() ); ?>
				</details>
			</form>
			<div class="ftvs-embed-out">
				<div class="ftvs-preview-head"><strong><?php esc_html_e( 'Preview', 'faith-tv-series' ); ?></strong><a class="ftvs-link" href="<?php echo esc_url( FTVS_Embed::url( $args ) ); ?>" target="_blank" rel="noopener" data-ftvs-embed-open><?php esc_html_e( 'Open in a new tab', 'faith-tv-series' ); ?></a></div>
				<div class="ftvs-embed-stage"><iframe title="<?php esc_attr_e( 'Embed preview', 'faith-tv-series' ); ?>" data-ftvs-embed data-ftvs-embed-preview src="<?php echo esc_url( FTVS_Embed::url( $args ) ); ?>" style="height:<?php echo (int) FTVS_Embed::start_height( 'row' ); ?>px"></iframe></div>
				<div class="ftvs-field" style="margin-top:18px">
					<label for="ftvs-e-code"><?php esc_html_e( 'Embed code', 'faith-tv-series' ); ?></label>
					<textarea id="ftvs-e-code" class="ftvs-code-box" rows="5" readonly data-ftvs-embed-code><?php echo esc_textarea( FTVS_Embed::code( $args ) ); ?></textarea>
					<div class="ftvs-inline"><button type="button" class="button button-primary ftvs-btn-primary" data-copy-from="#ftvs-e-code"><?php esc_html_e( 'Copy embed code', 'faith-tv-series' ); ?></button>
					<span class="ftvs-hint"><?php esc_html_e( 'Paste it where the other website lets you add HTML or an "embed" block. The second line lets the frame grow to fit; if a site only allows the first line, it still works at a fixed height.', 'faith-tv-series' ); ?></span></div>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------- Health ---------- */

	private static function health() {
		$h      = FTVS_Cache::health();
		$status = get_option( 'ftvs_used_status', array() );
		if ( empty( $status['rows'] ) || empty( $status['t'] ) || time() - $status['t'] > HOUR_IN_SECONDS ) {
			$status = array(
				't'    => time(),
				'rows' => FTVS_Health::check_used(),
			);
			update_option( 'ftvs_used_status', $status, false );
		}
		$stats = FTVS_Stats::summary( 7 );
		$purge = get_option( 'ftvs_last_purge', array() );
		?>
		<h2 class="ftvs-h2"><?php esc_html_e( 'Health', 'faith-tv-series' ); ?></h2>
		<p class="ftvs-lead"><?php esc_html_e( 'Whether the video sections on your site are working, and where they are.', 'faith-tv-series' ); ?></p>
		<div class="ftvs-facts ftvs-facts--cards">
			<div class="<?php echo self::failing() ? 'is-bad' : ''; ?>">
				<strong><?php echo self::failing() ? esc_html__( 'Not answering', 'faith-tv-series' ) : ( $h['last_ok'] ? esc_html( human_time_diff( $h['last_ok'] ) ) : '-' ); ?></strong>
				<span><?php echo self::failing() ? esc_html( $h['error'] ) : esc_html__( 'since the last good check of your channel', 'faith-tv-series' ); ?></span>
			</div>
			<div class="<?php echo self::problems() ? 'is-bad' : ''; ?>">
				<strong><?php echo (int) count( $status['rows'] ); ?></strong>
				<span>
					<?php
					$bad = self::problems() - ( self::failing() ? 1 : 0 );
					/* translators: %d: sections with a problem */
					echo $bad ? esc_html( sprintf( _n( 'video sections, %d needs attention', 'video sections, %d need attention', $bad, 'faith-tv-series' ), $bad ) ) : esc_html__( 'video sections on your site, all working', 'faith-tv-series' );
					?>
				</span>
			</div>
			<div class="<?php echo $stats['errors'] ? 'is-bad' : ''; ?>">
				<strong><?php echo esc_html( number_format_i18n( $stats['plays'] ) ); ?></strong>
				<span>
					<?php
					/* translators: %d: failed plays */
					echo $stats['errors'] ? esc_html( sprintf( _n( 'plays this week, %d failed to start', 'plays this week, %d failed to start', $stats['errors'], 'faith-tv-series' ), $stats['errors'] ) ) : esc_html__( 'plays this week', 'faith-tv-series' );
					?>
				</span>
			</div>
		</div>

		<div class="ftvs-card ftvs-pad ftvs-gap">
			<div class="ftvs-inline" style="justify-content:space-between">
				<h3 class="ftvs-h2" style="margin:0"><?php esc_html_e( 'Where it\'s used', 'faith-tv-series' ); ?></h3>
				<span class="ftvs-inline">
					<?php self::post_button( 'ftvs_recheck', __( 'Check all pages now', 'faith-tv-series' ) ); ?>
					<?php self::refresh_button(); ?>
				</span>
			</div>
			<?php if ( ! $status['rows'] ) : ?>
				<p class="ftvs-muted"><?php esc_html_e( 'No page has a video section yet.', 'faith-tv-series' ); ?></p>
			<?php else : ?>
				<table class="widefat striped ftvs-used">
					<thead><tr><th><?php esc_html_e( 'Page', 'faith-tv-series' ); ?></th><th><?php esc_html_e( 'Section', 'faith-tv-series' ); ?></th><th><?php esc_html_e( 'Shows', 'faith-tv-series' ); ?></th><th><?php esc_html_e( 'Status', 'faith-tv-series' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $status['rows'] as $row ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( '' !== $row['title'] ? $row['title'] : __( '(no title)', 'faith-tv-series' ) ); ?></a><?php echo 'publish' !== $row['status'] ? ' <span class="ftvs-muted">(' . esc_html( $row['status'] ) . ')</span>' : ''; ?>
									<?php $edit = get_edit_post_link( (int) $row['post'], 'raw' ); // built here: the list itself may come from WP-Cron, where there is no user ?>
									<?php if ( $edit ) : ?>
										<br><a class="ftvs-small-link" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Edit', 'faith-tv-series' ); ?></a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( self::section_name( $row ) ); ?></td>
								<td>
									<code><?php echo esc_html( '' !== $row['video'] ? 'video: ' . $row['video'] : ( '' !== $row['category'] ? $row['category'] : '-' ) ); ?></code>
									<?php if ( '' !== $row['from'] || '' !== $row['until'] ) : ?>
										<br><span class="ftvs-muted">
											<?php
											/* translators: 1: start date, 2: end date, 3: what shows otherwise */
											echo esc_html( sprintf( __( 'Dated: %1$s to %2$s; otherwise %3$s', 'faith-tv-series' ), '' !== $row['from'] ? substr( $row['from'], 0, 10 ) : '...', '' !== $row['until'] ? substr( $row['until'], 0, 10 ) : '...', '' !== $row['otherwise'] ? $row['otherwise'] : __( 'nothing', 'faith-tv-series' ) ) );
											?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo $row['ok'] ? '<span class="ftvs-chip is-ok">' . esc_html__( 'Working', 'faith-tv-series' ) . '</span>' : '<span class="ftvs-bad">' . esc_html( $row['problem'] ) . '</span>'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="ftvs-hint">
					<?php
					/* translators: %s: how long ago */
					printf( esc_html__( 'Checked %s ago. Pages are checked every hour in the background.', 'faith-tv-series' ), esc_html( human_time_diff( $status['t'] ) ) );
					?>
				</p>
			<?php endif; ?>
		</div>

		<div class="ftvs-look ftvs-gap">
			<div class="ftvs-card ftvs-pad">
				<h3 class="ftvs-h2"><?php esc_html_e( 'Emails and caches', 'faith-tv-series' ); ?></h3>
				<form method="post" action="options.php">
					<?php settings_fields( 'ftvs' ); ?>
					<?php self::toggle( 'alert_email', __( 'Email the site admin if videos stop showing (at most once a day)', 'faith-tv-series' ) ); ?>
					<?php submit_button( __( 'Save', 'faith-tv-series' ), 'secondary', 'submit', false ); ?>
				</form>
				<p class="ftvs-muted" style="margin-top:16px">
					<?php
					if ( ! empty( $purge['t'] ) ) {
						/* translators: 1: how long ago, 2: cache names */
						printf( esc_html__( 'Page caches last cleared %1$s ago (%2$s). This happens by itself when your channel changes.', 'faith-tv-series' ), esc_html( human_time_diff( $purge['t'] ) ), esc_html( $purge['caches'] ? implode( ', ', $purge['caches'] ) : __( 'no cache plugin found', 'faith-tv-series' ) ) );
					} else {
						esc_html_e( 'Page caches are cleared by themselves when your channel changes.', 'faith-tv-series' );
					}
					?>
				</p>
				<?php self::post_button( 'ftvs_purge', __( 'Clear page caches now', 'faith-tv-series' ) ); ?>
			</div>
			<div class="ftvs-card ftvs-pad">
				<h3 class="ftvs-h2"><?php esc_html_e( 'For support', 'faith-tv-series' ); ?></h3>
				<p class="ftvs-muted"><?php esc_html_e( 'Everything support needs, in one paste. It has no passwords or visitor details.', 'faith-tv-series' ); ?></p>
				<textarea id="ftvs-diag" class="ftvs-code-box" rows="8" readonly><?php echo esc_textarea( FTVS_Health::diagnostics() ); ?></textarea>
				<p><button type="button" class="button ftvs-btn-secondary" data-copy-from="#ftvs-diag"><?php esc_html_e( 'Copy diagnostics', 'faith-tv-series' ); ?></button>
					<a class="ftvs-link" href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"><?php esc_html_e( 'WordPress Site Health', 'faith-tv-series' ); ?></a></p>
			</div>
		</div>
		<?php
	}

	private static function section_name( $row ) {
		$kinds = array(
			'shortcode' => __( 'Shortcode', 'faith-tv-series' ),
			'block'     => __( 'Block', 'faith-tv-series' ),
			'elementor' => __( 'Elementor', 'faith-tv-series' ),
		);
		$types = array(
			'series'  => __( 'Videos', 'faith-tv-series' ),
			'live'    => __( 'Sunday live', 'faith-tv-series' ),
			'library' => __( 'Sermon library', 'faith-tv-series' ),
		);
		return ( isset( $types[ $row['type'] ] ) ? $types[ $row['type'] ] : $row['type'] ) . ' (' . ( isset( $kinds[ $row['kind'] ] ) ? $kinds[ $row['kind'] ] : $row['kind'] ) . ( '' !== $row['layout'] ? ', ' . self::layout_name( $row['layout'] ) : '' ) . ')';
	}

	/* ---------- Updates ---------- */

	private static function updates() {
		if ( class_exists( 'FTVS_Updater' ) ) {
			FTVS_Updater::tab(); // the direct edition only
		}
	}

	/* ---------- Bits ---------- */

	/**
	 * Size + color boxes for each part of a section.
	 *
	 * @param string $prefix Field name prefix: 'ftvs_settings[text]' posts part[size]; '' posts part_size (embed builder).
	 * @param array  $values part => { size, color }.
	 */
	private static function text_table( $prefix, $values ) {
		$parts = array(
			'heading' => array( __( 'Heading', 'faith-tv-series' ), '44' ),
			'eyebrow' => array( __( 'Small line above the heading', 'faith-tv-series' ), '14' ),
			'series'  => array( __( 'Big series title', 'faith-tv-series' ), '64' ),
			'card'    => array( __( 'Series names on cards', 'faith-tv-series' ), '16' ),
			'text'    => array( __( 'Descriptions', 'faith-tv-series' ), '16' ),
			'meta'    => array( __( 'Episode counts and labels', 'faith-tv-series' ), '12' ),
		);
		?>
		<table class="ftvs-text-table">
			<thead><tr><th><?php esc_html_e( 'Words', 'faith-tv-series' ); ?></th><th><?php esc_html_e( 'Size', 'faith-tv-series' ); ?></th><th><?php esc_html_e( 'Color', 'faith-tv-series' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $parts as $part => $info ) : ?>
					<?php
					$size  = isset( $values[ $part ]['size'] ) ? $values[ $part ]['size'] : '';
					$color = isset( $values[ $part ]['color'] ) ? $values[ $part ]['color'] : '';
					$sname = '' === $prefix ? $part . '_size' : $prefix . '[' . $part . '][size]';
					$cname = '' === $prefix ? $part . '_color' : $prefix . '[' . $part . '][color]';
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $info[0] ); ?></th>
						<td><input class="ftvs-input ftvs-text-size" name="<?php echo esc_attr( $sname ); ?>" value="<?php echo esc_attr( $size ); ?>" placeholder="<?php echo esc_attr( $info[1] ); ?>" inputmode="decimal" aria-label="<?php echo esc_attr( $info[0] . ' ' . __( 'size', 'faith-tv-series' ) ); ?>" data-ftvs-text="<?php echo esc_attr( $part ); ?>-size"></td>
						<td class="ftvs-text-color">
							<input type="color" value="<?php echo esc_attr( preg_match( '/^#[0-9a-f]{6}$/i', $color ) ? $color : '#ffffff' ); ?>" data-ftvs-pick aria-label="<?php echo esc_attr( $info[0] . ' ' . __( 'color picker', 'faith-tv-series' ) ); ?>">
							<input class="ftvs-input" name="<?php echo esc_attr( $cname ); ?>" value="<?php echo esc_attr( $color ); ?>" placeholder="<?php esc_attr_e( 'Built-in', 'faith-tv-series' ); ?>" aria-label="<?php echo esc_attr( $info[0] . ' ' . __( 'color', 'faith-tv-series' ) ); ?>" data-ftvs-text="<?php echo esc_attr( $part ); ?>-color">
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** background-image for a style attribute; quotes and parentheses in the URL are encoded so it can't escape the CSS. */
	private static function bg( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$url = str_replace( array( '"', "'", '(', ')', '\\', ' ' ), array( '%22', '%27', '%28', '%29', '%5C', '%20' ), $url );
		return 'background-image:url("' . $url . '")';
	}

	private static function refresh_button() {
		self::post_button( 'ftvs_refresh', __( 'Refresh from your channel', 'faith-tv-series' ) );
	}

	private static function powered() {
		$brand = self::brand();
		if ( '' === $brand['name'] ) {
			return;
		}
		echo '<div class="ftvs-powered">' . self::gear( 18 ) . esc_html__( 'Powered by', 'faith-tv-series' ) . ' <a href="' . esc_url( $brand['url'] ) . '" target="_blank" rel="noopener"><b>' . esc_html( $brand['name'] ) . '</b></a> <span>&middot; <a href="' . esc_url( $brand['support'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Help', 'faith-tv-series' ) . '</a></span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function source_name() {
		$names = array(
			'faithstream' => __( 'Faith Stream', 'faith-tv-series' ),
			'gideo'       => __( 'Gideo channel', 'faith-tv-series' ),
			'youtube'     => __( 'YouTube', 'faith-tv-series' ),
			'demo'        => __( 'Sample videos', 'faith-tv-series' ),
		);
		$source = FTVS_Catalog::source();
		return isset( $names[ $source ] ) ? $names[ $source ] : __( 'Series you built', 'faith-tv-series' );
	}

	private static function layout_name( $value ) {
		$names = array(
			'auto'      => __( 'Automatic', 'faith-tv-series' ),
			'same'      => __( 'Same', 'faith-tv-series' ),
			'showcase'  => __( 'Showcase', 'faith-tv-series' ),
			'coverflow' => __( '3D carousel', 'faith-tv-series' ),
			'list'      => __( 'Featured + list', 'faith-tv-series' ),
			'row'       => __( 'Row', 'faith-tv-series' ),
			'grid'      => __( 'Grid', 'faith-tv-series' ),
			'library'   => __( 'Sermon library', 'faith-tv-series' ),
		);
		return isset( $names[ $value ] ) ? $names[ $value ] : (string) $value;
	}

	private static function inside( $cat ) {
		if ( ! empty( $cat['children'] ) ) {
			/* translators: %d: number of series */
			return sprintf( _n( '%d series inside', '%d series inside', count( $cat['children'] ), 'faith-tv-series' ), count( $cat['children'] ) );
		}
		/* translators: %d: number of videos */
		return sprintf( _n( '%d video', '%d videos', $cat['videos'], 'faith-tv-series' ), $cat['videos'] );
	}

	/** The FaithStream gear: ring with sound bars and a play arrow. */
	public static function gear( $size, $fill = 'currentColor' ) {
		$size = (int) $size;
		return '<svg viewBox="0 0 120 120" width="' . $size . '" height="' . $size . '" fill="' . esc_attr( $fill ) . '" aria-hidden="true" focusable="false">'
			. '<path fill-rule="evenodd" d="' . self::GEAR . '"/>'
			. '<circle cx="60" cy="60" r="34" fill="none" stroke="' . esc_attr( $fill ) . '" stroke-width="4"/>'
			. '<rect x="35" y="53" width="5" height="14" rx="2.5"/><rect x="43" y="46" width="5" height="28" rx="2.5"/><rect x="51" y="40" width="5" height="40" rx="2.5"/>'
			. '<path d="M60 40.5 Q60 38 62.4 39.4 L84.6 57.6 Q87.5 60 84.6 62.4 L62.4 80.6 Q60 82 60 79.5 Z"/></svg>';
	}

	private static function menu_icon() {
		return 'data:image/svg+xml;base64,' . base64_encode( self::gear( 20, '#a7aaad' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	const GEAR = 'M108.0 60.6 L117.9 62.7 L117.6 66.4 L107.5 66.9 L106.2 73.0 L115.3 77.6 L114.0 81.1 L104.1 78.9 L101.3 84.5 L108.8 91.3 L106.7 94.3 L97.7 89.7 L93.5 94.4 L99.0 102.9 L96.3 105.3 L88.7 98.5 L83.5 101.9 L86.6 111.5 L83.3 113.1 L77.8 104.6 L71.8 106.5 L72.4 116.7 L68.8 117.3 L65.6 107.7 L59.4 108.0 L57.3 117.9 L53.6 117.6 L53.1 107.5 L47.0 106.2 L42.4 115.3 L38.9 114.0 L41.1 104.1 L35.5 101.3 L28.7 108.8 L25.7 106.7 L30.3 97.7 L25.6 93.5 L17.1 99.0 L14.7 96.3 L21.5 88.7 L18.1 83.5 L8.5 86.6 L6.9 83.3 L15.4 77.8 L13.5 71.8 L3.3 72.4 L2.7 68.8 L12.3 65.6 L12.0 59.4 L2.1 57.3 L2.4 53.6 L12.5 53.1 L13.8 47.0 L4.7 42.4 L6.0 38.9 L15.9 41.1 L18.7 35.5 L11.2 28.7 L13.3 25.7 L22.3 30.3 L26.5 25.6 L21.0 17.1 L23.7 14.7 L31.3 21.5 L36.5 18.1 L33.4 8.5 L36.7 6.9 L42.2 15.4 L48.2 13.5 L47.6 3.3 L51.2 2.7 L54.4 12.3 L60.6 12.0 L62.7 2.1 L66.4 2.4 L66.9 12.5 L73.0 13.8 L77.6 4.7 L81.1 6.0 L78.9 15.9 L84.5 18.7 L91.3 11.2 L94.3 13.3 L89.7 22.3 L94.4 26.5 L102.9 21.0 L105.3 23.7 L98.5 31.3 L101.9 36.5 L111.5 33.4 L113.1 36.7 L104.6 42.2 L106.5 48.2 L116.7 47.6 L117.3 51.2 L107.7 54.4Z M100 60 A40 40 0 1 0 20 60 A40 40 0 1 0 100 60Z';
}
