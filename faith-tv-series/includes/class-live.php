<?php
/**
 * Sunday live: what the Live block and the "We're live" bar show right now.
 *
 *   before the service  "Next service: Sunday 10:30" with a countdown (and the last replay)
 *   during              the live stream (Faith Stream says so, or it's service time with a pasted link)
 *   after               the recording, or the newest message
 *
 * Faith Stream churches get this automatically. Churches on other platforms paste their live
 * link (YouTube Live, Vimeo, Boxcast, Resi, any embeddable player or .m3u8) and enter their
 * service times under Faith Stream > Church > Sunday live.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Live {

	// Show the stream a little before the service starts (people arrive early).
	const EARLY = 10 * MINUTE_IN_SECONDS;

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'bar' ) );
	}

	/**
	 * @return array { status: live|idle, title, image, next, next_label, ends, viewers, chat, link,
	 *                 play: { kind: hls|embed, src, id } | null, replay: video|null }
	 */
	public static function state( $want = '' ) {
		$now     = time();
		$channel = self::channel( $want );
		$next    = self::next_service( $now );
		$out     = array(
			'status'     => 'idle',
			'title'      => '',
			'image'      => '',
			'next'       => $next ? gmdate( 'c', $next ) : '',
			'next_label' => $next ? self::label( $next ) : '',
			'viewers'    => 0,
			'chat'       => '',
			'link'       => '',
			'play'       => null,
			'replay'     => null,
		);
		if ( $channel ) {
			$out['title'] = $channel['title'];
			$out['image'] = $channel['image'];
			$out['link']  = $channel['link'];
			if ( 'live' === $channel['status'] && '' !== $channel['hls'] ) {
				$out['status']  = 'live';
				$out['viewers'] = $channel['viewers'];
				$out['chat']    = $channel['chat'] ? $channel['link'] : '';
				$out['play']    = array(
					'kind' => 'hls',
					'src'  => $channel['hls'],
					'id'   => $channel['id'],
				);
			}
			$sched = $channel['scheduled'] ? strtotime( $channel['scheduled'] ) : false;
			if ( $sched && $sched > $now && ( ! $next || $sched < $next ) ) {
				$out['next']       = gmdate( 'c', $sched );
				$out['next_label'] = self::label( $sched );
			}
			$out['replay'] = $channel['replay'];
		}
		if ( 'live' !== $out['status'] && '' !== (string) FTVS_Settings::get( 'live_url' ) && self::in_service( $now ) ) {
			$play = self::manual_player( (string) FTVS_Settings::get( 'live_url' ) );
			if ( $play ) {
				$out['status'] = 'live';
				$out['play']   = $play;
				$out['link']   = (string) FTVS_Settings::get( 'live_url' );
			}
		}
		if ( ! $out['replay'] && FTVS_Catalog::connected() ) {
			$newest = FTVS_Catalog::newest( 1 );
			if ( ! is_wp_error( $newest ) && $newest['videos'] ) {
				$out['replay'] = $newest['videos'][0];
			}
		}
		if ( $out['replay'] ) {
			$out['replay']['watch'] = FTVS_Watch::url( $out['replay']['id'] );
		}
		if ( '' === $out['title'] ) {
			$out['title'] = __( 'Sunday service', 'faith-tv-series' );
		}
		return $out;
	}

	/** The Faith Stream channel to watch: the one picked, else whichever is live, else the first. */
	private static function channel( $want = '' ) {
		if ( ! FTVS_Catalog::has_live() ) {
			return null;
		}
		$list = call_user_func( array( FTVS_Catalog::client(), 'get_live' ) );
		if ( is_wp_error( $list ) || ! $list ) {
			return null;
		}
		$want = '' !== $want ? $want : (string) FTVS_Settings::get( 'live_channel' );
		foreach ( $list as $ch ) {
			if ( '' !== $want && $ch['id'] === $want ) {
				return $ch;
			}
		}
		foreach ( $list as $ch ) {
			if ( 'live' === $ch['status'] ) {
				return $ch;
			}
		}
		return $list[0];
	}

	/** Start of the next (or current) service as a Unix time, or 0 when no times are set. */
	public static function next_service( $now = null ) {
		$now      = null === $now ? time() : $now;
		$services = (array) FTVS_Settings::get( 'services' );
		if ( ! $services ) {
			return 0;
		}
		$tz     = wp_timezone();
		$length = 60 * (int) FTVS_Settings::get( 'service_length' );
		$best   = 0;
		foreach ( $services as $svc ) {
			list( $h, $m ) = array_map( 'intval', explode( ':', $svc['time'] ) );
			for ( $week = 0; $week <= 1; $week++ ) {
				$day  = new DateTimeImmutable( '@' . $now );
				$day  = $day->setTimezone( $tz );
				$diff = ( (int) $svc['day'] - (int) $day->format( 'w' ) + 7 ) % 7 + 7 * $week;
				$at   = $day->setTime( $h, $m )->modify( '+' . $diff . ' days' )->getTimestamp();
				// A service that started but hasn't ended yet still counts as "now".
				if ( $at + $length > $now ) {
					$best = $best ? min( $best, $at ) : $at;
					break;
				}
			}
		}
		return $best;
	}

	private static function in_service( $now ) {
		$start = self::next_service( $now );
		return $start && $now >= $start - self::EARLY && $now < $start + 60 * (int) FTVS_Settings::get( 'service_length' );
	}

	/** "Sunday 10:30 AM" in the site's own time zone and format. */
	private static function label( $t ) {
		return wp_date( 'l ' . get_option( 'time_format' ), $t );
	}

	/**
	 * How to play a pasted live link.
	 *
	 * @return array|null { kind: hls|embed, src }
	 */
	public static function manual_player( $url ) {
		$url = esc_url_raw( $url );
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return null;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '/\.m3u8$/i', $path ) ) {
			return array( 'kind' => 'hls', 'src' => $url, 'id' => '' );
		}
		if ( preg_match( '/(^|\.)youtube\.com$|(^|\.)youtu\.be$/', $host ) ) {
			if ( preg_match( '#(?:v=|/live/|youtu\.be/|/embed/)([A-Za-z0-9_-]{11})#', $url, $m ) ) {
				return array( 'kind' => 'embed', 'src' => FTVS_YouTube_Client::embed_url( $m[1] ), 'id' => '' );
			}
			if ( preg_match( '#/channel/(UC[A-Za-z0-9_-]{22})#', $url, $m ) ) {
				return array( 'kind' => 'embed', 'src' => 'https://www.youtube-nocookie.com/embed/live_stream?autoplay=1&channel=' . $m[1], 'id' => '' );
			}
			return null;
		}
		if ( preg_match( '#vimeo\.com/event/(\d+)#', $url, $m ) ) {
			return array( 'kind' => 'embed', 'src' => 'https://vimeo.com/event/' . $m[1] . '/embed?autoplay=1', 'id' => '' );
		}
		if ( preg_match( '#vimeo\.com/(\d+)#', $url, $m ) ) {
			return array( 'kind' => 'embed', 'src' => 'https://player.vimeo.com/video/' . $m[1] . '?autoplay=1', 'id' => '' );
		}
		// Boxcast, Resi, Church Online and others hand out an embeddable player address.
		return array( 'kind' => 'embed', 'src' => $url, 'id' => '' );
	}

	/** The optional "We're live" bar at the top of every page. It asks every minute (not cached with the page). */
	public static function bar() {
		if ( ! FTVS_Settings::get( 'live_bar' ) || is_admin() || FTVS_Catalog::is_demo() || isset( $_GET['ftvs_embed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! FTVS_Catalog::has_live() && '' === (string) FTVS_Settings::get( 'live_url' ) ) {
			return;
		}
		wp_enqueue_style( 'faith-tv-series' );
		wp_enqueue_script( 'faith-tv-series' );
		$page = (string) FTVS_Settings::get( 'live_page' );
		echo '<div class="ftvs-livebar" data-ftvs-livebar data-href="' . esc_url( '' !== $page ? $page : ( FTVS_Watch::page_id() ? get_permalink( FTVS_Watch::page_id() ) : '' ) ) . '" hidden></div>';
		// The script and style were enqueued late; print them here if the footer already passed them.
		if ( did_action( 'wp_print_footer_scripts' ) ) {
			wp_print_styles( array( 'faith-tv-series' ) );
			wp_print_scripts( array( 'faith-tv-series' ) );
		}
	}
}
