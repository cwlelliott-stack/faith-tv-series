<?php
/**
 * Caching for everything read from the church's video platform.
 *
 * Every good answer is also kept as a backup. When an answer goes stale, visitors get the
 * backup right away and WP-Cron fetches a fresh one in the background, so nobody waits on
 * the platform. If the platform is down the site keeps showing the last list it got, unless
 * the platform says the thing is gone (then it disappears from the site too).
 *
 * Fetchers are given as array( class, method, args ) so WP-Cron can run them later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Cache {

	const ERROR_TTL = 5 * MINUTE_IN_SECONDS;
	// After the platform times out or refuses the connection, don't try again for a while.
	const DOWN_TTL = 3 * MINUTE_IN_SECONDS;
	const LOCK_TTL = 90;
	// A list that comes back empty replaces a full saved one only once it has stayed empty this long.
	const EMPTY_WAIT = 30 * MINUTE_IN_SECONDS;
	const QUEUE    = 'ftvs_refresh_queue';
	const HEALTH   = 'ftvs_health';
	const CRON     = 'ftvs_refresh_stale';

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'run_queue' ) );
	}

	/**
	 * @param string $key What is being cached (unique within the connected church).
	 * @param int    $ttl Seconds a good answer counts as fresh.
	 * @param array  $job array( class, method, args ): fetches the data or returns a WP_Error.
	 * @return mixed|WP_Error
	 */
	public static function remember( $key, $ttl, $job ) {
		$hash = self::hash( $key );
		$hit  = get_transient( self::transient( $hash ) );
		if ( false !== $hit ) {
			return is_array( $hit ) && isset( $hit['__error'] ) ? new WP_Error( isset( $hit['__code'] ) ? $hit['__code'] : 'ftvs_upstream', $hit['__error'] ) : $hit;
		}
		$backup = self::backup( $hash );
		if ( null !== $backup && self::can_serve_stale( $backup, $ttl ) ) {
			// Stale-while-revalidate: this visitor gets the saved copy; a fresh one comes in the background.
			set_transient( self::transient( $hash ), $backup['d'], min( $ttl, MINUTE_IN_SECONDS ) );
			self::queue( $key, $ttl, $job );
			return $backup['d'];
		}
		return self::refresh( $key, $ttl, $job );
	}

	/** Fetches now (the caller waits), stores the answer, and falls back to the backup on failure. */
	public static function refresh( $key, $ttl, $job ) {
		$hash   = self::hash( $key );
		$backup = self::backup( $hash );
		$fallback = function ( WP_Error $error ) use ( $hash, $backup ) {
			if ( null !== $backup ) {
				set_transient( self::transient( $hash ), $backup['d'], self::ERROR_TTL );
				return $backup['d'];
			}
			set_transient(
				self::transient( $hash ),
				array(
					'__error' => $error->get_error_message(),
					'__code'  => $error->get_error_code(),
				),
				self::ERROR_TTL
			);
			return $error;
		};

		if ( get_transient( self::down_key() ) ) {
			return $fallback( new WP_Error( 'ftvs_down', __( 'Your video platform is not answering right now.', 'faith-tv-series' ) ) );
		}
		$lock = 'ftvs_lock_' . $hash;
		if ( null !== $backup && get_transient( $lock ) ) {
			return $backup['d']; // someone else is already fetching this
		}
		set_transient( $lock, 1, self::LOCK_TTL );
		$data = call_user_func_array( array( $job[0], $job[1] ), isset( $job[2] ) ? (array) $job[2] : array() );
		delete_transient( $lock );

		if ( is_wp_error( $data ) ) {
			self::note_error( $key, $data );
			if ( 'ftvs_gone' === $data->get_error_code() ) {
				// Removed on purpose: stop showing the saved copy too.
				delete_option( 'ftvs_bk_' . $hash );
				set_transient(
					self::transient( $hash ),
					array(
						'__error' => $data->get_error_message(),
						'__code'  => 'ftvs_gone',
					),
					self::ERROR_TTL
				);
				do_action( 'ftvs_gone', $key );
				return $data;
			}
			if ( 'http_request_failed' === $data->get_error_code() ) {
				set_transient( self::down_key(), 1, self::DOWN_TTL );
			}
			return $fallback( $data );
		}

		// A platform hiccup (maintenance, a half-started server) can answer "nothing" for a list that has
		// videos: keep showing the saved list until the empty answer has lasted a while.
		if ( null !== $backup && self::emptied( $backup['d'], $data ) ) {
			$since = $backup['e'] ? $backup['e'] : time();
			if ( time() - $since < self::EMPTY_WAIT ) {
				if ( ! $backup['e'] ) {
					update_option(
						'ftvs_bk_' . $hash,
						array(
							'__ftvs' => 2,
							't'      => $backup['t'],
							'd'      => $backup['d'],
							'e'      => $since,
						),
						false
					);
				}
				set_transient( self::transient( $hash ), $backup['d'], self::ERROR_TTL );
				return $backup['d'];
			}
		}

		set_transient( self::transient( $hash ), $data, $ttl );
		// Search answers get no outage copy: every different search would leave a row behind forever.
		if ( 0 !== strpos( $key, 's_' ) ) {
			update_option(
				'ftvs_bk_' . $hash,
				array(
					'__ftvs' => 2,
					't'      => time(),
					'd'      => $data,
				),
				false
			);
		}
		self::note_ok();
		if ( null === $backup || md5( serialize( $backup['d'] ) ) !== md5( serialize( $data ) ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			do_action( 'ftvs_cache_changed', $key, $data, null === $backup ? null : $backup['d'] );
		}
		return $data;
	}

	/** The saved copy of a key, or null. */
	public static function peek( $key ) {
		$backup = self::backup( self::hash( $key ) );
		return null === $backup ? null : $backup['d'];
	}

	/**
	 * Makes every section fetch fresh answers (backups stay as the outage fallback).
	 * Bumping a generation number works with any object cache, not just the options table.
	 */
	/**
	 * Everything counts as stale, but visitors keep getting the saved copies while fresh ones are fetched in the
	 * background (a ping from Faith Stream, an update of the plugin). clear() is for "Refresh from your channel".
	 */
	public static function expire() {
		update_option( 'ftvs_cache_gen', (int) get_option( 'ftvs_cache_gen', 1 ) + 1, true );
		delete_transient( self::down_key() );
	}

	public static function clear() {
		update_option( 'ftvs_cache_gen', (int) get_option( 'ftvs_cache_gen', 1 ) + 1, true );
		update_option( 'ftvs_cleared_at', time(), true );
		delete_transient( self::down_key() );
	}

	/** WP-Cron: fetch everything that went stale while visitors were served the saved copy. */
	public static function run_queue() {
		$queue = get_option( self::QUEUE, array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			return;
		}
		update_option( self::QUEUE, array(), false );
		$start = time();
		foreach ( $queue as $key => $entry ) {
			if ( time() - $start > 25 ) {
				// Out of time: put the rest back for the next run.
				self::queue( $key, $entry['ttl'], $entry['job'] );
				continue;
			}
			if ( isset( $entry['id'] ) && $entry['id'] !== FTVS_Catalog::identity() ) {
				continue; // queued for a church that is no longer connected
			}
			self::refresh( $key, $entry['ttl'], $entry['job'] );
		}
	}

	/** Connection health for the admin: last good answer, last error, and how long the site has been on saved copies. */
	public static function health() {
		$h = get_option( self::HEALTH, array() );
		return wp_parse_args(
			is_array( $h ) ? $h : array(),
			array(
				'last_ok'    => 0,
				'last_error' => 0,
				'error'      => '',
				'error_key'  => '',
				'fails'      => 0,
				'failing_at' => 0,
				'id'         => '',
			)
		);
	}

	private static function note_ok() {
		$h = self::health();
		if ( $h['fails'] || time() - $h['last_ok'] > 60 || FTVS_Catalog::identity() !== $h['id'] ) {
			$h['last_ok']    = time();
			$h['fails']      = 0;
			$h['failing_at'] = 0;
			$h['id']         = FTVS_Catalog::identity();
			update_option( self::HEALTH, $h, false );
		}
	}

	private static function note_error( $key, WP_Error $error ) {
		if ( 'ftvs_gone' === $error->get_error_code() ) {
			return; // a removed video is not a connection problem
		}
		$h               = self::health();
		$h['last_error'] = time();
		$h['error']      = $error->get_error_message();
		$h['error_key']  = (string) $key;
		$h['fails']      = (int) $h['fails'] + 1;
		$h['failing_at'] = $h['failing_at'] ? $h['failing_at'] : time();
		$h['id']         = FTVS_Catalog::identity();
		update_option( self::HEALTH, $h, false );
	}

	private static function can_serve_stale( $backup, $ttl ) {
		if ( $backup['t'] <= (int) get_option( 'ftvs_cleared_at', 0 ) ) {
			return false; // saved before "Refresh from your channel": fetch now
		}
		// Only while the copy is fairly recent; after that (e.g. WP-Cron not running) fetch inline. Quick-changing
		// answers (the live status) are never more than three lifetimes old.
		return time() - $backup['t'] < ( $ttl < MINUTE_IN_SECONDS ? 3 * $ttl : max( 3 * $ttl, 30 * MINUTE_IN_SECONDS ) );
	}

	private static function queue( $key, $ttl, $job ) {
		$queue = get_option( self::QUEUE, array() );
		$queue = is_array( $queue ) ? $queue : array();
		if ( ! isset( $queue[ $key ] ) ) {
			$queue[ $key ] = array(
				'ttl' => $ttl,
				'job' => $job,
				'id'  => FTVS_Catalog::identity(),
			);
			update_option( self::QUEUE, $queue, false );
		}
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time(), self::CRON );
		}
	}

	private static function backup( $hash ) {
		$raw = get_option( 'ftvs_bk_' . $hash, null );
		if ( null === $raw || false === $raw ) {
			return null;
		}
		if ( is_array( $raw ) && isset( $raw['__ftvs'], $raw['d'] ) ) {
			return array(
				't' => (int) $raw['t'],
				'd' => $raw['d'],
				'e' => isset( $raw['e'] ) ? (int) $raw['e'] : 0, // when an empty answer first came in instead
			);
		}
		// Saved by 1.2 or earlier: its answers have other shapes (a video was only its stream address), so it is
		// never shown. The next good answer replaces it.
		return null;
	}

	/** Whether a list that had something now has nothing (answers that aren't lists never count). */
	private static function emptied( $old, $new ) {
		return 0 === self::items( $new ) && self::items( $old ) > 0;
	}

	/** How many things a list answer holds, or -1 when it isn't a list (a stream address, the live status). */
	private static function items( $data ) {
		if ( ! is_array( $data ) ) {
			return -1;
		}
		if ( isset( $data['rows'] ) || isset( $data['videos'] ) || isset( $data['categories'] ) ) {
			$n = 0;
			foreach ( array( 'rows', 'videos', 'categories' ) as $part ) {
				$n += isset( $data[ $part ] ) && is_array( $data[ $part ] ) ? count( $data[ $part ] ) : 0;
			}
			return $n;
		}
		return array_values( $data ) === $data ? count( $data ) : -1;
	}

	private static function hash( $key ) {
		return md5( FTVS_Catalog::identity() . '|' . $key );
	}

	private static function transient( $hash ) {
		return 'ftvs_' . (int) get_option( 'ftvs_cache_gen', 1 ) . '_' . $hash;
	}

	private static function down_key() {
		return 'ftvs_down_' . md5( FTVS_Catalog::identity() );
	}
}
