<?php
/**
 * Updates from GitHub releases, through WordPress's normal "Update available" flow.
 *
 * The plugin header's "Update URI" points at github.com, so WordPress skips
 * wordpress.org for this plugin and asks us (update_plugins_github.com) instead.
 * A release is a tag like v1.2.0 with faith-tv-series.zip attached (release.py makes both).
 * If the repository is private, a read-only GitHub token saved in the settings is used.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Updater {

	const REPO  = 'cwlelliott-stack/faith-tv-series';
	const ASSET = 'faith-tv-series.zip';
	const CACHE = 'ftvs_update_release';
	const SLUG  = 'faith-tv-series';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_link' ), 10, 2 );
		add_action( 'admin_post_ftvs_check_updates', array( __CLASS__, 'check_now' ) );
	}

	/** GitHub API address for the repository. Filterable so a test server can stand in. */
	public static function api() {
		return untrailingslashit( apply_filters( 'ftvs_update_api', 'https://api.github.com/repos/' . self::REPO ) );
	}

	/**
	 * Newest release on GitHub.
	 *
	 * @return array|WP_Error { version, package, notes, url, published }
	 */
	public static function latest( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE );
			if ( is_array( $cached ) ) {
				return isset( $cached['error'] ) ? new WP_Error( 'ftvs_update', $cached['error'] ) : $cached;
			}
		}

		$response = wp_remote_get(
			self::api() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => self::headers( 'application/vnd.github+json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::fail( $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return self::fail( '' === self::token()
				? __( 'No release found. If the GitHub repository is private, save an access token below.', 'faith-tv-series' )
				: __( 'No release found, or the access token cannot read this repository.', 'faith-tv-series' ) );
		}
		if ( 401 === $code || 403 === $code ) {
			return self::fail( __( 'GitHub refused the request. Check the access token.', 'faith-tv-series' ) );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return self::fail( sprintf( __( 'GitHub answered with status %d.', 'faith-tv-series' ), $code ) );
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		$version = ltrim( isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '', 'vV' );
		$asset   = null;
		foreach ( isset( $release['assets'] ) ? (array) $release['assets'] : array() as $candidate ) {
			if ( isset( $candidate['name'] ) && self::ASSET === $candidate['name'] ) {
				$asset = $candidate;
				break;
			}
		}
		if ( ! preg_match( '/^\d+(\.\d+){0,3}$/', $version ) || ! $asset ) {
			return self::fail( __( 'The newest GitHub release is missing faith-tv-series.zip or a version tag like v1.2.0.', 'faith-tv-series' ) );
		}

		$data = array(
			'version'   => $version,
			// A private repository only serves the file through the API, with the token.
			'package'   => '' !== self::token() ? $asset['url'] : $asset['browser_download_url'],
			'notes'     => isset( $release['body'] ) ? (string) $release['body'] : '',
			'url'       => isset( $release['html_url'] ) ? (string) $release['html_url'] : 'https://github.com/' . self::REPO,
			'published' => isset( $release['published_at'] ) ? (string) $release['published_at'] : '',
		);
		set_site_transient( self::CACHE, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/** WordPress asks this during its update check (update_plugins_github.com). */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( FTVS_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::latest();
		if ( is_wp_error( $release ) ) {
			return $update;
		}
		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $release['package'],
			'requires'     => '6.0',
			'requires_php' => '7.4',
			'tested'       => get_bloginfo( 'version' ),
		);
	}

	/** The "View details" window on the Plugins and Updates pages. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest();
		$notes   = is_wp_error( $release ) ? $release->get_error_message() : ( '' !== trim( $release['notes'] ) ? $release['notes'] : __( 'No notes for this release.', 'faith-tv-series' ) );
		return (object) array(
			'name'          => 'Faith TV Series',
			'slug'          => self::SLUG,
			'version'       => is_wp_error( $release ) ? FTVS_VERSION : $release['version'],
			'author'        => 'Faith Tabernacle',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => is_wp_error( $release ) ? '' : $release['published'],
			'download_link' => is_wp_error( $release ) ? '' : $release['package'],
			'sections'      => array(
				'description' => esc_html__( 'Shows a Faith TV (Gideo) category, like the Mini Series, live on the church website, with an on-page player.', 'faith-tv-series' ),
				'changelog'   => '<pre style="white-space:pre-wrap">' . esc_html( $notes ) . '</pre>',
			),
		);
	}

	/**
	 * Private repositories: fetch the release file with the token ourselves. GitHub answers
	 * with a redirect to a signed download address, which must be fetched without the token.
	 */
	public static function download( $reply, $package, $upgrader ) {
		if ( false !== $reply || ! is_string( $package ) || 0 !== strpos( $package, self::api() . '/releases/assets/' ) ) {
			return $reply;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 60,
				'redirection' => 0,
				'headers'     => self::headers( 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			return $location ? download_url( $location, 300 ) : new WP_Error( 'ftvs_update', __( 'GitHub did not say where the file is.', 'faith-tv-series' ) );
		}
		if ( 200 === $code ) {
			$file = wp_tempnam( self::ASSET );
			if ( ! $file || false === file_put_contents( $file, wp_remote_retrieve_body( $response ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new WP_Error( 'ftvs_update', __( 'Could not save the update file.', 'faith-tv-series' ) );
			}
			return $file;
		}
		/* translators: %d: HTTP status code */
		return new WP_Error( 'ftvs_update', sprintf( __( 'GitHub answered with status %d when downloading the update.', 'faith-tv-series' ), $code ) );
	}

	/** After any update, look again next time instead of trusting the cached answer. */
	public static function forget() {
		delete_site_transient( self::CACHE );
	}

	public static function row_link( $links, $file ) {
		if ( plugin_basename( FTVS_FILE ) === $file && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( self::check_url() ) . '">' . esc_html__( 'Check for updates', 'faith-tv-series' ) . '</a>';
		}
		return $links;
	}

	public static function check_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=ftvs_check_updates' ), 'ftvs_check_updates' );
	}

	public static function check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'faith-tv-series' ) );
		}
		check_admin_referer( 'ftvs_check_updates' );
		self::forget();
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( FTVS_Admin::url( 'faith-stream-updates', array( 'ftvs_checked' => '1' ) ) );
		exit;
	}

	private static function token() {
		return (string) FTVS_Settings::get( 'update_token' );
	}

	private static function headers( $accept ) {
		$headers = array(
			'Accept'               => $accept,
			'User-Agent'           => 'FaithTVSeries/' . FTVS_VERSION,
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( '' !== self::token() ) {
			$headers['Authorization'] = 'Bearer ' . self::token();
		}
		return $headers;
	}

	/** Remember a failed check for an hour so pages don't keep asking GitHub. */
	private static function fail( $message ) {
		set_site_transient( self::CACHE, array( 'error' => $message ), HOUR_IN_SECONDS );
		return new WP_Error( 'ftvs_update', $message );
	}
}
