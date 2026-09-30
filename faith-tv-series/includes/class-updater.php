<?php
/**
 * Updates through WordPress's normal "Update available" flow, from signed releases.
 *
 * The plugin header's "Update URI" points at github.com, so WordPress skips wordpress.org
 * for this plugin and asks us (update_plugins_github.com) instead. Each release (release.py)
 * attaches faith-tv-series.zip and latest.json:
 *
 *   { "payload": "<JSON: version, package, sha256, requires, requires_php, tested, notes, published, rollout>",
 *     "sig": "<Ed25519 signature of payload, base64>" }
 *
 * The signature is checked against the public key below and the zip against its SHA-256
 * before anything installs, so a tampered release or a compromised account can't push code
 * to churches. "rollout" (0-100) lets a release reach some sites first; 0 holds it back.
 * latest.json is fetched from the release download address, which isn't rate-limited like
 * GitHub's API (shared hosts with many sites used to hit that limit).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Updater {

	const REPO  = 'cwlelliott-stack/faith-tv-series';
	const ASSET = 'faith-tv-series.zip';
	const CACHE = 'ftvs_update_release';
	const SLUG  = 'faith-tv-series';
	// FaithStream's release signing key (the private half never leaves the release computer).
	const PUBLIC_KEY = 'CcbABBQtMxX8Z8nyYGEqke0ST9Saml3K/tQm1114KTA=';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_link' ), 10, 2 );
		add_action( 'admin_post_ftvs_check_updates', array( __CLASS__, 'check_now' ) );
	}

	/** Where the signed manifest lives. Filterable so a test server can stand in. */
	public static function manifest_url() {
		return apply_filters( 'ftvs_update_manifest', 'https://github.com/' . self::REPO . '/releases/latest/download/latest.json' );
	}

	/**
	 * Newest signed release.
	 *
	 * @return array|WP_Error { version, package, sha256, notes, url, published, requires, requires_php, tested, held }
	 */
	public static function latest( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE );
			if ( is_array( $cached ) && ( isset( $cached['error'] ) || isset( $cached['sha256'], $cached['held'] ) ) ) {
				return isset( $cached['error'] ) ? new WP_Error( 'ftvs_update', $cached['error'] ) : $cached;
			}
		}
		$response = wp_remote_get(
			self::manifest_url(),
			array(
				'timeout'     => 10,
				'redirection' => 5,
				'headers'     => array( 'User-Agent' => 'FaithTVSeries/' . FTVS_VERSION ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::fail( $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			return self::fail( 404 === $code ? __( 'No signed release found yet.', 'faith-tv-series' ) : sprintf( __( 'The update server answered with status %d.', 'faith-tv-series' ), $code ) );
		}
		$data = self::verify( wp_remote_retrieve_body( $response ) );
		if ( is_wp_error( $data ) ) {
			return self::fail( $data->get_error_message() );
		}
		set_site_transient( self::CACHE, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/**
	 * Checks the signature and reads the release.
	 *
	 * @return array|WP_Error
	 */
	public static function verify( $body ) {
		$outer = json_decode( (string) $body, true );
		if ( ! is_array( $outer ) || empty( $outer['payload'] ) || empty( $outer['sig'] ) || ! is_string( $outer['payload'] ) ) {
			return new WP_Error( 'ftvs_update', __( 'The update information could not be read.', 'faith-tv-series' ) );
		}
		$sig = base64_decode( (string) $outer['sig'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$key = base64_decode( self::public_key(), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) || false === $sig || false === $key ) {
			return new WP_Error( 'ftvs_update', __( 'This server cannot check update signatures.', 'faith-tv-series' ) );
		}
		try {
			$ok = sodium_crypto_sign_verify_detached( $sig, $outer['payload'], $key );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		if ( ! $ok ) {
			return new WP_Error( 'ftvs_update', __( 'The update\'s signature did not match. It was not offered.', 'faith-tv-series' ) );
		}
		$m = json_decode( $outer['payload'], true );
		if ( ! is_array( $m ) || empty( $m['version'] ) || ! preg_match( '/^\d+(\.\d+){0,3}$/', (string) $m['version'] ) || empty( $m['package'] ) || empty( $m['sha256'] ) || 0 !== strpos( (string) $m['package'], 'https://' ) ) {
			return new WP_Error( 'ftvs_update', __( 'The update information is incomplete.', 'faith-tv-series' ) );
		}
		$rollout = isset( $m['rollout'] ) ? max( 0, min( 100, (int) $m['rollout'] ) ) : 100;
		return array(
			'version'      => (string) $m['version'],
			'package'      => (string) $m['package'],
			'sha256'       => strtolower( (string) $m['sha256'] ),
			'notes'        => isset( $m['notes'] ) ? (string) $m['notes'] : '',
			'url'          => 'https://github.com/' . self::REPO . '/releases/tag/v' . $m['version'],
			'published'    => isset( $m['published'] ) ? (string) $m['published'] : '',
			'requires'     => isset( $m['requires'] ) ? (string) $m['requires'] : '6.0',
			'requires_php' => isset( $m['requires_php'] ) ? (string) $m['requires_php'] : '7.4',
			'tested'       => isset( $m['tested'] ) ? (string) $m['tested'] : get_bloginfo( 'version' ),
			// This site's place in a staged rollout (the same site always gets the same number).
			'held'         => self::bucket() >= $rollout,
		);
	}

	private static function public_key() {
		return (string) apply_filters( 'ftvs_update_public_key', self::PUBLIC_KEY );
	}

	/** 0-99, fixed per site. */
	private static function bucket() {
		return (int) ( sprintf( '%u', crc32( home_url() ) ) % 100 );
	}

	/** WordPress asks this during its update check (update_plugins_github.com). */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( FTVS_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::latest();
		if ( is_wp_error( $release ) || $release['held'] ) {
			return $update;
		}
		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $release['package'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested'       => $release['tested'],
		);
	}

	/** The "View details" window on the Plugins and Updates pages. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest();
		$notes   = is_wp_error( $release ) ? $release->get_error_message() : ( '' !== trim( $release['notes'] ) ? $release['notes'] : __( 'No notes for this release.', 'faith-tv-series' ) );
		$brand   = FTVS_Admin::brand();
		return (object) array(
			'name'          => 'Faith TV Series',
			'slug'          => self::SLUG,
			'version'       => is_wp_error( $release ) ? FTVS_VERSION : $release['version'],
			'author'        => '' !== $brand['name'] ? $brand['name'] : 'FaithStream',
			'homepage'      => $brand['url'],
			'requires'      => is_wp_error( $release ) ? '6.0' : $release['requires'],
			'requires_php'  => is_wp_error( $release ) ? '7.4' : $release['requires_php'],
			'tested'        => is_wp_error( $release ) ? get_bloginfo( 'version' ) : $release['tested'],
			'last_updated'  => is_wp_error( $release ) ? '' : $release['published'],
			'download_link' => is_wp_error( $release ) ? '' : $release['package'],
			'sections'      => array(
				'description' => esc_html__( 'Puts your church\'s videos live on your website: series, Sunday live, a searchable sermon library and a page for every message.', 'faith-tv-series' ),
				'changelog'   => '<pre style="white-space:pre-wrap">' . esc_html( $notes ) . '</pre>',
			),
		);
	}

	/** Downloads our release ourselves and refuses it unless its SHA-256 matches the signed manifest. */
	public static function download( $reply, $package, $upgrader ) {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		$release = self::latest();
		if ( is_wp_error( $release ) || $package !== $release['package'] ) {
			return $reply; // not ours
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! hash_equals( $release['sha256'], (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'ftvs_update', __( 'The downloaded update did not match its signature, so it was not installed.', 'faith-tv-series' ) );
		}
		return $file;
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

	/** Remember a failed check for an hour so pages don't keep asking. */
	private static function fail( $message ) {
		set_site_transient( self::CACHE, array( 'error' => $message ), HOUR_IN_SECONDS );
		return new WP_Error( 'ftvs_update', $message );
	}
}
