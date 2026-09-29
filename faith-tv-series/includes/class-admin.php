<?php
/**
 * The Faith Stream admin menu: Church (connect), Videos, Look & feel, Updates.
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
		'faith-stream-embed'   => 'embed',
		'faith-stream-updates' => 'updates',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_old_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ftvs_refresh', array( __CLASS__, 'refresh' ) );
		add_action( 'admin_post_ftvs_lookup', array( __CLASS__, 'lookup' ) );
		add_action( 'admin_post_ftvs_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_ftvs_autoupdate', array( __CLASS__, 'toggle_autoupdate' ) );
	}

	public static function url( $page = self::PAGE, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Faith Stream', 'faith-tv-series' ), __( 'Faith Stream', 'faith-tv-series' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ), self::menu_icon(), 58 );
		add_submenu_page( self::PAGE, __( 'Church', 'faith-tv-series' ), __( 'Church', 'faith-tv-series' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
		add_submenu_page( self::PAGE, __( 'Videos', 'faith-tv-series' ), __( 'Videos', 'faith-tv-series' ), 'manage_options', 'faith-stream-videos', array( __CLASS__, 'page' ) );
		add_submenu_page( self::PAGE, __( 'Look & feel', 'faith-tv-series' ), __( 'Look & feel', 'faith-tv-series' ), 'manage_options', 'faith-stream-look', array( __CLASS__, 'page' ) );
		add_submenu_page( self::PAGE, __( 'Embed', 'faith-tv-series' ), __( 'Embed', 'faith-tv-series' ), 'manage_options', 'faith-stream-embed', array( __CLASS__, 'page' ) );
		add_submenu_page( self::PAGE, __( 'Updates', 'faith-tv-series' ), __( 'Updates', 'faith-tv-series' ), 'manage_options', 'faith-stream-updates', array( __CLASS__, 'page' ) );
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
		wp_enqueue_style( 'ftvs-admin-font', 'https://fonts.googleapis.com/css2?family=Poppins:wght@500;700;800&family=Roboto:wght@700;800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'ftvs-admin', FTVS_URL . 'assets/admin.css', array(), FTVS_VERSION );
		wp_enqueue_script( 'ftvs-admin', FTVS_URL . 'assets/admin.js', array(), FTVS_VERSION, true );
		if ( 'faith-stream-embed' === $page ) {
			// Same helper other websites load, so the preview frame grows to fit.
			wp_enqueue_script( 'ftvs-embed-host', FTVS_URL . 'assets/embed.js', array(), FTVS_VERSION, true );
		}
	}

	/* ---------- Actions ---------- */

	public static function refresh() {
		self::guard( 'ftvs_refresh' );
		FTVS_Cache::clear();
		wp_safe_redirect( self::url( 'faith-stream-videos', array( 'ftvs_refreshed' => '1' ) ) );
		exit;
	}

	/** Step 2 of connecting: find the church, keep it aside until the admin confirms. */
	public static function lookup() {
		self::guard( 'ftvs_lookup' );
		$post   = wp_unslash( $_POST );
		$source = isset( $post['source'] ) && 'faithstream' === $post['source'] ? 'faithstream' : 'gideo';
		$field  = function ( $key ) use ( $post ) {
			return isset( $post[ $key ] ) ? sanitize_text_field( $post[ $key ] ) : '';
		};
		if ( 'faithstream' === $source ) {
			$found = FTVS_FaithStream_Client::lookup( $field( 'fs_url' ), $field( 'fs_tenant' ) );
			$input = array( 'fs_url' => $field( 'fs_url' ), 'fs_tenant' => $field( 'fs_tenant' ) );
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
	public static function connect() {
		self::guard( 'ftvs_connect' );
		$pending = get_transient( self::pending_key() );
		if ( empty( $pending['found'] ) ) {
			wp_safe_redirect( self::url( self::PAGE, array( 'connect' => 1 ) ) );
			exit;
		}
		$found  = $pending['found'];
		$fields = array( 'source', 'account_id', 'tv_url', 'fs_url', 'fs_tenant', 'church_name', 'church_logo' );
		$save   = array_fill_keys( $fields, '' );
		foreach ( $fields as $key ) {
			if ( isset( $found[ $key ] ) ) {
				$save[ $key ] = $found[ $key ];
			}
		}
		FTVS_Settings::update( $save );
		FTVS_Cache::clear();
		delete_transient( self::pending_key() );
		wp_safe_redirect( self::url( self::PAGE, array( 'connect' => 1, 'step' => 3 ) ) );
		exit;
	}

	/** Same switch as "Enable auto-updates" on the Plugins page. */
	public static function toggle_autoupdate() {
		self::guard( 'ftvs_autoupdate', 'update_plugins' );
		$file = plugin_basename( FTVS_FILE );
		$list = (array) get_site_option( 'auto_update_plugins', array() );
		$list = ! empty( $_POST['enable'] ) ? array_values( array_unique( array_merge( $list, array( $file ) ) ) ) : array_values( array_diff( $list, array( $file ) ) );
		update_site_option( 'auto_update_plugins', $list );
		wp_safe_redirect( self::url( 'faith-stream-updates' ) );
		exit;
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

	/* ---------- Page ---------- */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : self::PAGE; // phpcs:ignore WordPress.Security.NonceVerification
		$tab  = isset( self::PAGES[ $page ] ) ? self::PAGES[ $page ] : 'church';
		if ( ! FTVS_Catalog::connected() && in_array( $tab, array( 'videos', 'look', 'embed' ), true ) ) {
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
			case 'embed':
				self::embed( $tree );
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
		$tabs      = array(
			'faith-stream'         => __( 'Church', 'faith-tv-series' ),
			'faith-stream-videos'  => __( 'Videos', 'faith-tv-series' ),
			'faith-stream-look'    => __( 'Look & feel', 'faith-tv-series' ),
			'faith-stream-embed'   => __( 'Embed', 'faith-tv-series' ),
			'faith-stream-updates' => __( 'Updates', 'faith-tv-series' ),
		);
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
					<small><span class="ftvs-dot"></span><?php echo esc_html( self::source_name() ); ?></small></span>
				<?php else : ?>
					<span class="ftvs-avatar is-empty">?</span>
					<span><strong><?php esc_html_e( 'No church yet', 'faith-tv-series' ); ?></strong><small><span class="ftvs-dot is-off"></span><?php esc_html_e( 'Not connected', 'faith-tv-series' ); ?></small></span>
				<?php endif; ?>
			</div>
			<span class="ftvs-ver">v<?php echo esc_html( FTVS_VERSION ); ?></span>
		</header>
		<nav class="ftvs-tabs" aria-label="<?php esc_attr_e( 'Faith Stream', 'faith-tv-series' ); ?>">
			<?php foreach ( $tabs as $slug => $name ) : ?>
				<?php
				$is  = self::PAGES[ $slug ] === $tab;
				$off = ! $connected && in_array( self::PAGES[ $slug ], array( 'videos', 'look', 'embed' ), true );
				?>
				<a href="<?php echo esc_url( self::url( $slug ) ); ?>" class="<?php echo esc_attr( trim( ( $is ? 'is-current' : '' ) . ( $off ? ' is-off' : '' ) ) ); ?>"<?php echo $is ? ' aria-current="page"' : ''; ?>>
					<?php echo esc_html( $name ); ?>
					<?php if ( 'videos' === self::PAGES[ $slug ] && $connected && is_array( $tree ) ) : ?>
						<span class="ftvs-count"><?php echo (int) count( $tree ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	private static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification
		$messages = array(
			'ftvs_refreshed' => __( 'Done. Every video section on the site now shows the latest from your channel.', 'faith-tv-series' ),
			'ftvs_checked'   => __( 'Checked for a new version. The result is below.', 'faith-tv-series' ),
		);
		foreach ( $messages as $key => $text ) {
			if ( isset( $_GET[ $key ] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
			}
		}
		// phpcs:enable
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
		?>
		<div class="ftvs-card ftvs-pad">
			<div class="ftvs-church">
				<span class="ftvs-logobox" style="<?php echo esc_attr( self::bg( $s['church_logo'] ) ); ?>"></span>
				<div>
					<span class="ftvs-chip is-ok"><?php esc_html_e( 'Connected', 'faith-tv-series' ); ?></span>
					<h2><?php echo esc_html( '' !== $s['church_name'] ? $s['church_name'] : __( 'Your church', 'faith-tv-series' ) ); ?></h2>
					<dl class="ftvs-dl">
						<dt><?php esc_html_e( 'Videos come from', 'faith-tv-series' ); ?></dt>
						<dd><?php echo esc_html( self::source_name() ); ?>
							<?php if ( FTVS_Catalog::channel_url() ) : ?>
								&middot; <a href="<?php echo esc_url( FTVS_Catalog::channel_url() ); ?>" target="_blank" rel="noopener"><?php echo esc_html( FTVS_Catalog::channel_host() ); ?></a>
							<?php endif; ?>
						</dd>
						<dt><?php echo 'gideo' === $s['source'] ? esc_html__( 'Gideo account', 'faith-tv-series' ) : esc_html__( 'Church ID', 'faith-tv-series' ); ?></dt>
						<dd><code><?php echo esc_html( 'gideo' === $s['source'] ? $s['account_id'] : $s['fs_tenant'] ); ?></code></dd>
						<dt><?php esc_html_e( 'Checks for new videos', 'faith-tv-series' ); ?></dt>
						<dd>
							<?php
							/* translators: %d: minutes */
							printf( esc_html__( 'Every %d minutes', 'faith-tv-series' ), (int) $s['cache_minutes'] );
							?>
						</dd>
					</dl>
				</div>
				<div class="ftvs-stack">
					<a class="button ftvs-btn-secondary" href="<?php echo esc_url( self::url( self::PAGE, array( 'connect' => 1 ) ) ); ?>"><?php esc_html_e( 'Connect a different church', 'faith-tv-series' ); ?></a>
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
			<details class="ftvs-advanced">
				<summary><?php esc_html_e( 'Advanced', 'faith-tv-series' ); ?></summary>
				<form method="post" action="options.php" class="ftvs-inline">
					<?php settings_fields( 'ftvs' ); ?>
					<label for="ftvs-cache"><?php esc_html_e( 'Check for new videos every', 'faith-tv-series' ); ?></label>
					<input id="ftvs-cache" class="small-text" type="number" min="1" max="1440" name="<?php echo esc_attr( FTVS_Settings::OPTION ); ?>[cache_minutes]" value="<?php echo esc_attr( $s['cache_minutes'] ); ?>">
					<span><?php esc_html_e( 'minutes', 'faith-tv-series' ); ?></span>
					<?php submit_button( __( 'Save', 'faith-tv-series' ), 'secondary', 'submit', false ); ?>
				</form>
			</details>
		</div>
		<?php
	}

	private static function wizard() {
		// phpcs:disable WordPress.Security.NonceVerification
		$step   = isset( $_GET['step'] ) ? max( 1, min( 3, absint( $_GET['step'] ) ) ) : 1;
		$source = '';
		if ( isset( $_GET['source'] ) ) {
			$source = 'faithstream' === $_GET['source'] ? 'faithstream' : 'gideo';
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
					<div class="ftvs-choices">
						<button type="submit" name="source" value="gideo" class="ftvs-choice">
							<span class="ftvs-choice__ic is-gideo"><svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><rect x="2.5" y="4.5" width="19" height="13" rx="1.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 21h8M12 17.5V21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M10 8.5v5l4.5-2.5z" fill="currentColor"/></svg></span>
							<span><strong><?php esc_html_e( 'A Gideo TV channel', 'faith-tv-series' ); ?></strong><span class="ftvs-choice__p"><?php esc_html_e( 'Your church has a TV website and apps on Gideo, for example tv.yourchurch.com.', 'faith-tv-series' ); ?></span><span class="ftvs-chip"><?php esc_html_e( 'Most churches today', 'faith-tv-series' ); ?></span></span>
						</button>
						<button type="submit" name="source" value="faithstream" class="ftvs-choice">
							<span class="ftvs-choice__ic"><?php echo self::gear( 38 ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
							<span><strong><?php esc_html_e( 'Faith Stream', 'faith-tv-series' ); ?></strong><span class="ftvs-choice__p"><?php esc_html_e( 'Your church streams and keeps its video library on Faith Stream.', 'faith-tv-series' ); ?></span><span class="ftvs-chip is-new"><?php esc_html_e( 'New', 'faith-tv-series' ); ?></span></span>
						</button>
					</div>
				</form>
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
			<?php else : ?>
				<div class="ftvs-field">
					<label for="ftvs-fs-url"><?php esc_html_e( 'Your Faith Stream address', 'faith-tv-series' ); ?></label>
					<input class="ftvs-input" id="ftvs-fs-url" name="fs_url" placeholder="stream.yourchurch.com" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( isset( $input['fs_url'] ) ? $input['fs_url'] : '' ); ?>">
				</div>
				<div class="ftvs-field">
					<label for="ftvs-fs-id"><?php esc_html_e( 'Church ID', 'faith-tv-series' ); ?></label>
					<div class="ftvs-inline">
						<input class="ftvs-input" id="ftvs-fs-id" name="fs_tenant" placeholder="yourchurch" autocomplete="off" spellcheck="false" style="max-width:240px" value="<?php echo esc_attr( isset( $input['fs_tenant'] ) ? $input['fs_tenant'] : '' ); ?>">
						<button class="button button-primary button-hero ftvs-btn-primary"><?php esc_html_e( 'Find my church', 'faith-tv-series' ); ?></button>
					</div>
					<span class="ftvs-hint"><?php esc_html_e( 'Faith Stream shows both under Settings in your church\'s admin.', 'faith-tv-series' ); ?></span>
				</div>
			<?php endif; ?>
		</form>

		<?php if ( ! empty( $pending['error'] ) ) : ?>
			<p class="ftvs-msg"><?php echo esc_html( $pending['error'] ); ?></p>
		<?php elseif ( $found ) : ?>
			<?php
			$names  = wp_list_pluck( array_slice( $found['rows'], 0, 4 ), 'title' );
			$thumbs = array_slice( array_filter( wp_list_pluck( $found['rows'], 'image' ) ), 0, 6 );
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
					<h4><?php esc_html_e( 'Anywhere else', 'faith-tv-series' ); ?></h4>
					<p class="ftvs-muted"><?php esc_html_e( 'Paste this shortcode into a page or post:', 'faith-tv-series' ); ?></p>
					<div class="ftvs-code"><input readonly value="<?php echo esc_attr( $code ); ?>"><button type="button" class="button ftvs-btn-secondary" data-copy="<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Copy', 'faith-tv-series' ); ?></button></div>
				</div>
			</div>
			<p class="ftvs-inline" style="margin-top:18px">
				<a class="button button-primary ftvs-btn-primary" href="<?php echo esc_url( self::url( 'faith-stream-videos' ) ); ?>"><?php esc_html_e( 'See all your videos', 'faith-tv-series' ); ?></a>
				<a class="ftvs-link" href="<?php echo esc_url( self::url( 'faith-stream-look' ) ); ?>"><?php esc_html_e( 'Set your church color', 'faith-tv-series' ); ?></a>
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
		<p class="ftvs-lead"><?php esc_html_e( 'Everything on your channel. Put any of these on a page with the Elementor widget "Faith TV Series", or copy its shortcode.', 'faith-tv-series' ); ?></p>
		<div class="ftvs-toolbar">
			<input class="ftvs-input" type="search" data-ftvs-filter placeholder="<?php esc_attr_e( 'Search', 'faith-tv-series' ); ?>" aria-label="<?php esc_attr_e( 'Search categories', 'faith-tv-series' ); ?>">
			<span class="ftvs-grow"></span>
			<?php self::refresh_button(); ?>
		</div>
		<?php if ( is_wp_error( $tree ) ) : ?>
			<p class="ftvs-msg"><?php echo esc_html( $tree->get_error_message() ); ?></p>
			<?php
			return;
		endif;
		?>
		<div class="ftvs-rows">
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
						<a class="ftvs-link" href="<?php echo esc_url( FTVS_Catalog::category_link( $row['id'] ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open on your channel', 'faith-tv-series' ); ?></a>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="ftvs-muted ftvs-small"><?php esc_html_e( 'Tip: click a series name to copy its own shortcode. Shortcode options: layout="showcase|coverflow|list|row|grid", mobile_layout="auto|same|list|coverflow|row", title="...", eyebrow="...", limit="6", play="faithtv", theme="light".', 'faith-tv-series' ); ?></p>
		<?php
	}

	/* ---------- Look & feel ---------- */

	private static function look( $tree ) {
		$s        = FTVS_Settings::all();
		$name     = FTVS_Settings::OPTION;
		$preview  = self::preview_data( $tree );
		$colors   = array(
			'#C40D3C' => __( 'Crimson', 'faith-tv-series' ),
			'#084073' => __( 'Navy', 'faith-tv-series' ),
			'#423BA3' => __( 'Purple', 'faith-tv-series' ),
			'#0A919C' => __( 'Teal', 'faith-tv-series' ),
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
		?>
		<h2 class="ftvs-h2"><?php esc_html_e( 'Look & feel', 'faith-tv-series' ); ?></h2>
		<p class="ftvs-lead"><?php esc_html_e( 'Defaults for every video section on your site. Each section can still pick its own layout.', 'faith-tv-series' ); ?></p>
		<div class="ftvs-look">
			<form method="post" action="options.php" class="ftvs-card ftvs-pad" data-ftvs-look>
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
					<span class="ftvs-hint"><?php esc_html_e( 'Buttons, badges and the stripe behind the artwork.', 'faith-tv-series' ); ?></span>
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
					<input class="ftvs-input" id="ftvs-label" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $s['label'] ); ?>" placeholder="<?php echo esc_attr( $preview['category'] ); ?>" data-ftvs-in="label">
					<span class="ftvs-hint"><?php esc_html_e( 'Leave empty to use each category\'s name.', 'faith-tv-series' ); ?></span>
				</div>
				<div class="ftvs-field">
					<label for="ftvs-badge"><?php esc_html_e( 'Badge on the newest', 'faith-tv-series' ); ?></label>
					<input class="ftvs-input" id="ftvs-badge" name="<?php echo esc_attr( $name ); ?>[badge]" value="<?php echo esc_attr( $s['badge'] ); ?>" style="max-width:180px" data-ftvs-in="badge">
					<span class="ftvs-hint"><?php esc_html_e( 'Leave empty for no badge.', 'faith-tv-series' ); ?></span>
				</div>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[powered_by]" value="0">
				<label class="ftvs-switch"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[powered_by]" value="1"<?php checked( ! empty( $s['powered_by'] ) ); ?> data-ftvs-in="powered"><span class="ftvs-switch__track"></span><span><?php esc_html_e( 'Show "Powered by FaithStream" under the player', 'faith-tv-series' ); ?></span></label>
				<fieldset class="ftvs-field">
					<legend><?php esc_html_e( 'Text sizes and colors', 'faith-tv-series' ); ?></legend>
					<?php self::text_table( $name . '[text]', (array) $s['text'] ); ?>
					<span class="ftvs-hint"><?php esc_html_e( 'For every section on the site. Leave a box empty for the built-in look. Each Elementor widget can still change its own under Style.', 'faith-tv-series' ); ?></span>
				</fieldset>
				<?php submit_button( __( 'Save', 'faith-tv-series' ), 'primary ftvs-btn-primary' ); ?>
			</form>
			<div>
				<div class="ftvs-preview-head"><strong><?php esc_html_e( 'Preview', 'faith-tv-series' ); ?></strong>
					<span class="ftvs-seg is-small" role="group"><button type="button" data-ftvs-pv="desk" aria-pressed="true"><?php esc_html_e( 'Computer', 'faith-tv-series' ); ?></button><button type="button" data-ftvs-pv="phone" aria-pressed="false"><?php esc_html_e( 'Phone', 'faith-tv-series' ); ?></button></span>
				</div>
				<div class="ftvs-stage">
					<div class="ftvs-mini" data-ftvs-mini style="--acc:<?php echo esc_attr( $s['accent'] ); ?>" data-category="<?php echo esc_attr( $preview['category'] ); ?>">
						<div class="ftvs-mini__k"><?php echo esc_html( $preview['category'] ); ?></div>
						<div class="ftvs-mini__show">
							<div>
								<span class="ftvs-mini__badge" data-ftvs-out="badge"><?php echo esc_html( $s['badge'] ); ?></span><span class="ftvs-mini__lab" data-ftvs-out="label"><?php echo esc_html( '' !== $s['label'] ? $s['label'] : $preview['category'] ); ?></span>
								<h4><?php echo esc_html( $preview['title'] ); ?></h4>
								<div class="ftvs-mini__eps"><?php echo esc_html( $preview['meta'] ); ?></div>
								<span class="ftvs-mini__go"><?php esc_html_e( 'Watch the series', 'faith-tv-series' ); ?></span>
							</div>
							<div class="ftvs-mini__frame" style="<?php echo esc_attr( self::bg( $preview['image'] ) ); ?>"><i></i></div>
						</div>
						<div class="ftvs-mini__powered" data-ftvs-out="powered">Powered by <b>FAITHSTREAM</b></div>
					</div>
				</div>
			</div>
		</div>
		<?php
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

	/** A real series from the channel for the Look & feel preview. */
	private static function preview_data( $tree ) {
		$data = array(
			'category' => __( 'Mini Series', 'faith-tv-series' ),
			'title'    => __( 'Series name', 'faith-tv-series' ),
			'meta'     => '',
			'image'    => '',
		);
		if ( is_wp_error( $tree ) || ! $tree ) {
			return $data;
		}
		$row = null;
		foreach ( $tree as $candidate ) {
			if ( $candidate['children'] && preg_match( '/mini.?series/i', $candidate['title'] ) ) {
				$row = $candidate;
				break;
			}
			if ( ! $row && $candidate['children'] ) {
				$row = $candidate;
			}
		}
		$row   = $row ? $row : $tree[0];
		$first = $row['children'] ? $row['children'][0] : $row;
		return array(
			'category' => $row['title'],
			'title'    => $first['title'],
			/* translators: %d: number of episodes */
			'meta'     => $first['videos'] ? sprintf( _n( '%d episode', '%d episodes', $first['videos'], 'faith-tv-series' ), $first['videos'] ) : '',
			'image'    => $first['image'],
		);
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
				if ( '' === $pick && preg_match( '/mini.?series/i', $row['title'] ) ) {
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
		?>
		<h2 class="ftvs-h2"><?php esc_html_e( 'Embed on another website', 'faith-tv-series' ); ?></h2>
		<p class="ftvs-lead"><?php esc_html_e( 'Put a video section on any website that accepts HTML: Faith Central, a landing page, a partner church\'s site. It stays up to date by itself. Pick what to show, then copy the code.', 'faith-tv-series' ); ?></p>
		<div class="ftvs-embed-builder" data-ftvs-embed data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ftvs_embed_code' ) ); ?>" data-heights="<?php echo esc_attr( wp_json_encode( $heights ) ); ?>">
			<form class="ftvs-card ftvs-pad" data-ftvs-embed-form onsubmit="return false">
				<div class="ftvs-field">
					<label for="ftvs-e-cat"><?php esc_html_e( 'What to show', 'faith-tv-series' ); ?></label>
					<select id="ftvs-e-cat" name="category" class="ftvs-input">
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
				<fieldset class="ftvs-field">
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
					<input id="ftvs-e-title" class="ftvs-input" name="title" placeholder="<?php esc_attr_e( 'Faith Mini Series', 'faith-tv-series' ); ?>">
				</div>
				<div class="ftvs-field">
					<label for="ftvs-e-eyebrow"><?php esc_html_e( 'Small line above it (optional)', 'faith-tv-series' ); ?></label>
					<input id="ftvs-e-eyebrow" class="ftvs-input" name="eyebrow" placeholder="<?php esc_attr_e( 'Everyday issues that challenge our faith', 'faith-tv-series' ); ?>">
				</div>
				<div class="ftvs-field">
					<label for="ftvs-e-limit"><?php esc_html_e( 'How many to show', 'faith-tv-series' ); ?></label>
					<input id="ftvs-e-limit" class="small-text" type="number" min="0" max="50" name="limit" value="0">
					<span class="ftvs-hint"><?php esc_html_e( '0 shows all of them.', 'faith-tv-series' ); ?></span>
				</div>
				<fieldset class="ftvs-field">
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

	/* ---------- Updates ---------- */

	private static function updates() {
		$release = FTVS_Updater::latest();
		$s       = FTVS_Settings::all();
		$auto    = in_array( plugin_basename( FTVS_FILE ), (array) get_site_option( 'auto_update_plugins', array() ), true );
		$newer   = ! is_wp_error( $release ) && version_compare( $release['version'], FTVS_VERSION, '>' );
		?>
		<div class="ftvs-card ftvs-pad ftvs-narrow">
			<h2 class="ftvs-h2"><?php esc_html_e( 'Updates', 'faith-tv-series' ); ?></h2>
			<p class="ftvs-lead"><?php esc_html_e( 'New versions come from FaithStream and install like any WordPress plugin update.', 'faith-tv-series' ); ?></p>
			<dl class="ftvs-dl">
				<dt><?php esc_html_e( 'Installed', 'faith-tv-series' ); ?></dt>
				<dd><strong><?php echo esc_html( FTVS_VERSION ); ?></strong></dd>
				<dt><?php esc_html_e( 'Newest', 'faith-tv-series' ); ?></dt>
				<dd>
					<?php if ( is_wp_error( $release ) ) : ?>
						<?php echo esc_html( $release->get_error_message() ); ?>
					<?php else : ?>
						<strong><?php echo esc_html( $release['version'] ); ?></strong>
						<?php if ( $release['published'] ) : ?>
							<span class="ftvs-muted">(<?php echo esc_html( mysql2date( get_option( 'date_format' ), $release['published'] ) ); ?>)</span>
						<?php endif; ?>
						<?php if ( $newer ) : ?>
							<span class="ftvs-chip is-new"><?php esc_html_e( 'Ready to install', 'faith-tv-series' ); ?></span>
						<?php else : ?>
							<span class="ftvs-chip is-ok"><?php esc_html_e( 'You\'re up to date', 'faith-tv-series' ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</dd>
			</dl>
			<div class="ftvs-inline" style="margin:20px 0">
				<?php if ( $newer ) : ?>
					<a class="button button-primary ftvs-btn-primary" href="<?php echo esc_url( admin_url( 'plugins.php?plugin_status=upgrade' ) ); ?>"><?php esc_html_e( 'Go to Plugins to update', 'faith-tv-series' ); ?></a>
				<?php endif; ?>
				<a class="button ftvs-btn-secondary" href="<?php echo esc_url( FTVS_Updater::check_url() ); ?>"><?php esc_html_e( 'Check for updates now', 'faith-tv-series' ); ?></a>
				<?php if ( current_user_can( 'update_plugins' ) && function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ftvs-inline-form">
						<input type="hidden" name="action" value="ftvs_autoupdate">
						<input type="hidden" name="enable" value="<?php echo $auto ? '0' : '1'; ?>">
						<?php wp_nonce_field( 'ftvs_autoupdate' ); ?>
						<button class="ftvs-switch ftvs-switch--button" aria-pressed="<?php echo $auto ? 'true' : 'false'; ?>"><span class="ftvs-switch__track"></span><span><?php esc_html_e( 'Install updates automatically', 'faith-tv-series' ); ?></span></button>
					</form>
				<?php endif; ?>
			</div>
			<?php if ( ! is_wp_error( $release ) && '' !== trim( $release['notes'] ) ) : ?>
				<h3 class="ftvs-h3">
					<?php
					/* translators: %s: version number */
					printf( esc_html__( 'What\'s new in %s', 'faith-tv-series' ), esc_html( $release['version'] ) );
					?>
				</h3>
				<div class="ftvs-notes"><?php echo wp_kses_post( wpautop( esc_html( $release['notes'] ) ) ); ?></div>
			<?php endif; ?>
			<details class="ftvs-advanced">
				<summary><?php esc_html_e( 'Advanced: private repository token', 'faith-tv-series' ); ?></summary>
				<form method="post" action="options.php">
					<?php settings_fields( 'ftvs' ); ?>
					<p class="ftvs-muted"><?php esc_html_e( 'Only needed if the plugin\'s GitHub repository is ever made private: a fine-grained token with read-only access to that one repository ("Contents: Read").', 'faith-tv-series' ); ?></p>
					<div class="ftvs-inline">
						<input class="ftvs-input" type="password" autocomplete="new-password" data-lpignore="true" name="<?php echo esc_attr( FTVS_Settings::OPTION ); ?>[update_token]" value="" placeholder="<?php echo '' !== $s['update_token'] ? esc_attr__( 'Saved (hidden)', 'faith-tv-series' ) : ''; ?>">
						<?php if ( '' !== $s['update_token'] ) : ?>
							<label><input type="checkbox" name="<?php echo esc_attr( FTVS_Settings::OPTION ); ?>[update_token_remove]" value="1"> <?php esc_html_e( 'Remove the saved token', 'faith-tv-series' ); ?></label>
						<?php endif; ?>
						<?php submit_button( __( 'Save', 'faith-tv-series' ), 'secondary', 'submit', false ); ?>
					</div>
				</form>
			</details>
		</div>
		<?php
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
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ftvs-inline-form">
			<input type="hidden" name="action" value="ftvs_refresh">
			<?php wp_nonce_field( 'ftvs_refresh' ); ?>
			<button class="button ftvs-btn-secondary"><?php esc_html_e( 'Refresh from your channel', 'faith-tv-series' ); ?></button>
		</form>
		<?php
	}

	private static function powered() {
		echo '<div class="ftvs-powered">' . self::gear( 18 ) . esc_html__( 'Powered by', 'faith-tv-series' ) . ' <b>FAITHSTREAM</b> <span>&middot; faithstream.video</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function source_name() {
		return 'faithstream' === FTVS_Catalog::source() ? __( 'Faith Stream', 'faith-tv-series' ) : __( 'Gideo channel', 'faith-tv-series' );
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
