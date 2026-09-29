<?php
/**
 * Settings > Faith TV Series: the few settings, plus a list of every Faith TV
 * category with a ready-to-paste shortcode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Admin {

	const PAGE = 'faith-tv-series';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_ftvs_refresh', array( __CLASS__, 'refresh' ) );
	}

	public static function menu() {
		add_options_page(
			__( 'Faith TV Series', 'faith-tv-series' ),
			__( 'Faith TV Series', 'faith-tv-series' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
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

	public static function refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'faith-tv-series' ) );
		}
		check_admin_referer( 'ftvs_refresh' );
		FTVS_Gideo_Client::clear_cache();
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'ftvs_refreshed' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = FTVS_Settings::all();
		$name = FTVS_Settings::OPTION;
		$tree = FTVS_Gideo_Client::get_tree();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Faith TV Series', 'faith-tv-series' ); ?></h1>

			<?php if ( isset( $_GET['ftvs_refreshed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Done. The list below and every Faith TV section on the site now show the latest from Faith TV.', 'faith-tv-series' ); ?></p></div>
			<?php endif; ?>

			<p style="max-width:760px">
				<?php esc_html_e( 'Put any Faith TV category on a page. In Elementor, drag in the "Faith TV Series" widget and pick the category. Anywhere else, paste the shortcode from the list below. When the video team adds a new series on Faith TV, it shows up on the site by itself.', 'faith-tv-series' ); ?>
			</p>

			<h2><?php esc_html_e( 'Your Faith TV categories', 'faith-tv-series' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px">
				<input type="hidden" name="action" value="ftvs_refresh">
				<?php wp_nonce_field( 'ftvs_refresh' ); ?>
				<?php submit_button( __( 'Refresh from Faith TV now', 'faith-tv-series' ), 'secondary', 'submit', false ); ?>
				<span class="description" style="margin-left:8px">
					<?php
					/* translators: %d: minutes */
					printf( esc_html__( 'The site checks Faith TV for changes every %d minutes on its own.', 'faith-tv-series' ), (int) $s['cache_minutes'] );
					?>
				</span>
			</form>

			<?php if ( is_wp_error( $tree ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $tree->get_error_message() ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1100px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Category', 'faith-tv-series' ); ?></th>
							<th style="width:140px"><?php esc_html_e( 'Inside', 'faith-tv-series' ); ?></th>
							<th style="width:520px"><?php esc_html_e( 'Shortcode', 'faith-tv-series' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $tree as $row ) {
							self::tree_row( $row, false );
							foreach ( $row['children'] as $child ) {
								self::tree_row( $child, true );
							}
						}
						?>
					</tbody>
				</table>
				<p class="description" style="max-width:760px">
					<?php esc_html_e( 'Shortcode options: layout="showcase" (the default: big featured series that rotates), "coverflow" (3D carousel), "row" or "grid". title="FAITH MINI SERIES" and eyebrow="Everyday issues that challenge our faith" add a heading. autoplay="7" sets the rotation in seconds (0 = off). badge="New" marks the newest one (badge="" for none). limit="6" shows only the first six. play="faithtv" opens tv.faithtabernacle.com instead of playing on this site. theme="light" for light backgrounds. descriptions="yes" adds a short description under each card.', 'faith-tv-series' ); ?>
				</p>
			<?php endif; ?>

			<h2 style="margin-top:32px"><?php esc_html_e( 'Settings', 'faith-tv-series' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'ftvs' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ftvs-account"><?php esc_html_e( 'Gideo account ID', 'faith-tv-series' ); ?></label></th>
						<td><input id="ftvs-account" class="regular-text" name="<?php echo esc_attr( $name ); ?>[account_id]" value="<?php echo esc_attr( $s['account_id'] ); ?>">
							<p class="description"><?php esc_html_e( 'Faith Tabernacle is Faith-Tabernacle-1. Only change this if Gideo gives you a new one.', 'faith-tv-series' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ftvs-tv"><?php esc_html_e( 'Faith TV web address', 'faith-tv-series' ); ?></label></th>
						<td><input id="ftvs-tv" class="regular-text" type="url" name="<?php echo esc_attr( $name ); ?>[tv_url]" value="<?php echo esc_attr( $s['tv_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'Used for the "Watch on Faith TV" links.', 'faith-tv-series' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ftvs-cache"><?php esc_html_e( 'Check Faith TV every', 'faith-tv-series' ); ?></label></th>
						<td><input id="ftvs-cache" class="small-text" type="number" min="1" max="1440" name="<?php echo esc_attr( $name ); ?>[cache_minutes]" value="<?php echo esc_attr( $s['cache_minutes'] ); ?>"> <?php esc_html_e( 'minutes', 'faith-tv-series' ); ?></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<script>
		document.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-ftvs-copy]');
			if (!btn) return;
			var input = btn.previousElementSibling;
			input.select();
			var done = function () { btn.textContent = <?php echo wp_json_encode( __( 'Copied', 'faith-tv-series' ) ); ?>; setTimeout(function () { btn.textContent = <?php echo wp_json_encode( __( 'Copy', 'faith-tv-series' ) ); ?>; }, 1500); };
			if (navigator.clipboard) { navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); }); }
			else { document.execCommand('copy'); done(); }
		});
		</script>
		<?php
	}

	private static function tree_row( $cat, $indent ) {
		$inside = $cat['videos'] > 0
			/* translators: %d: number of episodes */
			? sprintf( _n( '%d episode', '%d episodes', $cat['videos'], 'faith-tv-series' ), $cat['videos'] )
			/* translators: %d: number of series */
			: sprintf( _n( '%d series', '%d series', $cat['subcategories'], 'faith-tv-series' ), $cat['subcategories'] );
		$code   = '[faith_tv_series category="' . $cat['id'] . '"]';
		?>
		<tr>
			<td style="<?php echo $indent ? 'padding-left:32px' : 'font-weight:600'; ?>"><?php echo esc_html( $cat['title'] ); ?></td>
			<td><?php echo esc_html( $inside ); ?></td>
			<td>
				<input type="text" readonly value="<?php echo esc_attr( $code ); ?>" style="width:400px;font-family:monospace;font-size:12px" onfocus="this.select()">
				<button type="button" class="button" data-ftvs-copy><?php esc_html_e( 'Copy', 'faith-tv-series' ); ?></button>
			</td>
		</tr>
		<?php
	}
}
