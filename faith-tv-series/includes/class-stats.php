<?php
/**
 * Counts plays on this website (and its embeds) without collecting anything about the people
 * watching: per day, how many plays and finishes, which videos and which pages. Shown on the
 * WordPress dashboard ("Faith Stream this week") and, if turned on, emailed every Monday.
 * Faith Stream churches also see these plays in Faith Stream's own reports.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Stats {

	const OPTION = 'ftvs_stats';
	const KEEP   = 60; // days
	const WEEKLY = 'ftvs_weekly_email';

	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard' ) );
		add_action( self::WEEKLY, array( __CLASS__, 'email' ) );
		if ( ! wp_next_scheduled( self::WEEKLY ) ) {
			$monday = new DateTimeImmutable( 'next monday 08:00', wp_timezone() );
			wp_schedule_event( $monday->getTimestamp(), 'weekly', self::WEEKLY );
		}
	}

	private static function data() {
		$d = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $d ) ? $d : array(),
			array(
				'days'   => array(),
				'titles' => array(),
			)
		);
	}

	/**
	 * @param string $event play | complete | error | live
	 * @param string $id    Video id (or live channel id).
	 * @param string $title Video title, as the page showed it.
	 * @param string $path  Page the video was watched on.
	 * @param bool   $embed Watched in an embed on another website.
	 */
	public static function record( $event, $id, $title, $path, $embed ) {
		if ( ! in_array( $event, array( 'play', 'complete', 'error', 'live' ), true ) ) {
			return;
		}
		$id = substr( preg_replace( '/[^A-Za-z0-9_-]/', '', $id ), 0, 128 );
		if ( '' === $id || ( 'live' !== $event && ! FTVS_Catalog::is_known( 'gideo' === FTVS_Catalog::source() ? strtolower( $id ) : $id ) ) ) {
			return;
		}
		$path = self::path( $path );
		$d    = self::data();
		$day  = wp_date( 'Y-m-d' );
		$row  = isset( $d['days'][ $day ] ) ? $d['days'][ $day ] : array( 'p' => 0, 'c' => 0, 'e' => 0, 'l' => 0, 'em' => 0, 'v' => array(), 'pg' => array() );
		switch ( $event ) {
			case 'play':
				++$row['p'];
				$row['v'][ $id ] = isset( $row['v'][ $id ] ) ? $row['v'][ $id ] + 1 : 1;
				if ( '' !== $path ) {
					$row['pg'][ $path ] = isset( $row['pg'][ $path ] ) ? $row['pg'][ $path ] + 1 : 1;
				}
				if ( $embed ) {
					++$row['em'];
				}
				break;
			case 'complete':
				++$row['c'];
				break;
			case 'error':
				++$row['e'];
				break;
			case 'live':
				++$row['l'];
				break;
		}
		// Keep the lists short: the 200 most-played videos and 100 pages per day.
		if ( count( $row['v'] ) > 200 ) {
			arsort( $row['v'] );
			$row['v'] = array_slice( $row['v'], 0, 200, true );
		}
		if ( count( $row['pg'] ) > 100 ) {
			arsort( $row['pg'] );
			$row['pg'] = array_slice( $row['pg'], 0, 100, true );
		}
		$d['days'][ $day ] = $row;
		if ( '' !== $title && 'play' === $event ) {
			$d['titles'][ $id ] = self::cut( sanitize_text_field( $title ), 120 );
			if ( count( $d['titles'] ) > 600 ) {
				$d['titles'] = array_slice( $d['titles'], -500, null, true );
			}
		}
		if ( count( $d['days'] ) > self::KEEP ) {
			ksort( $d['days'] );
			$d['days'] = array_slice( $d['days'], -self::KEEP, null, true );
		}
		update_option( self::OPTION, $d, false );
	}

	/** The first $n letters (not bytes, so accented letters stay whole). */
	public static function cut( $text, $n ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $n, 'UTF-8' ) : wp_html_excerpt( $text, $n );
	}

	/** Only the path on this site (no query strings, which can carry personal details). */
	private static function path( $raw ) {
		$raw  = (string) $raw;
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url  = wp_parse_url( $raw );
		if ( ! $url || ( ! empty( $url['host'] ) && $url['host'] !== $host ) ) {
			return ! empty( $url['host'] ) ? self::cut( sanitize_text_field( $url['host'] ), 80 ) : '';
		}
		return self::cut( '/' . ltrim( sanitize_text_field( isset( $url['path'] ) ? $url['path'] : '/' ), '/' ), 160 );
	}

	/**
	 * Totals for the last $days days, and the same number of days before that.
	 *
	 * @return array { plays, finished, errors, live, embeds, prev_plays, videos: [ [title, plays] ], pages: [ [path, plays] ], daily: [ date => plays ] }
	 */
	public static function summary( $days = 7 ) {
		$d      = self::data();
		$out    = array(
			'plays'      => 0,
			'finished'   => 0,
			'errors'     => 0,
			'live'       => 0,
			'embeds'     => 0,
			'prev_plays' => 0,
			'videos'     => array(),
			'pages'      => array(),
			'daily'      => array(),
		);
		$videos = array();
		$pages  = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day                  = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$row                  = isset( $d['days'][ $day ] ) ? $d['days'][ $day ] : null;
			$out['daily'][ $day ] = $row ? $row['p'] : 0;
			if ( ! $row ) {
				continue;
			}
			$out['plays']    += $row['p'];
			$out['finished'] += $row['c'];
			$out['errors']   += $row['e'];
			$out['live']     += $row['l'];
			$out['embeds']   += $row['em'];
			foreach ( $row['v'] as $id => $n ) {
				$videos[ $id ] = isset( $videos[ $id ] ) ? $videos[ $id ] + $n : $n;
			}
			foreach ( $row['pg'] as $path => $n ) {
				$pages[ $path ] = isset( $pages[ $path ] ) ? $pages[ $path ] + $n : $n;
			}
		}
		for ( $i = 2 * $days - 1; $i >= $days; $i-- ) {
			$day = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			if ( isset( $d['days'][ $day ] ) ) {
				$out['prev_plays'] += $d['days'][ $day ]['p'];
			}
		}
		arsort( $videos );
		arsort( $pages );
		foreach ( array_slice( $videos, 0, 5, true ) as $id => $n ) {
			$out['videos'][] = array( isset( $d['titles'][ $id ] ) ? $d['titles'][ $id ] : $id, $n );
		}
		foreach ( array_slice( $pages, 0, 5, true ) as $path => $n ) {
			$out['pages'][] = array( $path, $n );
		}
		return $out;
	}

	public static function dashboard() {
		if ( current_user_can( 'manage_options' ) && FTVS_Settings::get( 'count_plays' ) && FTVS_Catalog::connected() ) {
			wp_add_dashboard_widget( 'ftvs_stats', __( 'Faith Stream: videos this week', 'faith-tv-series' ), array( __CLASS__, 'widget' ) );
		}
	}

	public static function widget() {
		$s = self::summary( 7 );
		?>
		<div class="ftvs-dash">
			<div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px">
				<div><strong style="font-size:28px;line-height:1.1;display:block"><?php echo esc_html( number_format_i18n( $s['plays'] ) ); ?></strong><span><?php esc_html_e( 'plays', 'faith-tv-series' ); ?></span>
					<?php if ( $s['prev_plays'] ) : ?>
						<span style="color:#646970">
							<?php
							$change = (int) round( 100 * ( $s['plays'] - $s['prev_plays'] ) / $s['prev_plays'] );
							/* translators: %s: percent change, like +12% */
							printf( esc_html__( '(%s vs. the week before)', 'faith-tv-series' ), esc_html( ( $change >= 0 ? '+' : '' ) . $change . '%' ) );
							?>
						</span>
					<?php endif; ?>
				</div>
				<div><strong style="font-size:28px;line-height:1.1;display:block"><?php echo esc_html( number_format_i18n( $s['finished'] ) ); ?></strong><span><?php esc_html_e( 'watched to the end', 'faith-tv-series' ); ?></span></div>
				<?php if ( $s['live'] ) : ?>
					<div><strong style="font-size:28px;line-height:1.1;display:block"><?php echo esc_html( number_format_i18n( $s['live'] ) ); ?></strong><span><?php esc_html_e( 'joined live', 'faith-tv-series' ); ?></span></div>
				<?php endif; ?>
			</div>
			<?php if ( $s['videos'] ) : ?>
				<p style="margin:12px 0 4px"><strong><?php esc_html_e( 'Most watched', 'faith-tv-series' ); ?></strong></p>
				<ol style="margin:0 0 0 18px">
					<?php foreach ( $s['videos'] as $v ) : ?>
						<li><?php echo esc_html( $v[0] ); ?> <span style="color:#646970">(<?php echo esc_html( number_format_i18n( $v[1] ) ); ?>)</span></li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
			<?php if ( $s['pages'] ) : ?>
				<p style="margin:12px 0 4px"><strong><?php esc_html_e( 'Where people watched', 'faith-tv-series' ); ?></strong></p>
				<ul style="margin:0">
					<?php foreach ( $s['pages'] as $p ) : ?>
						<li><code><?php echo esc_html( $p[0] ); ?></code> <span style="color:#646970">(<?php echo esc_html( number_format_i18n( $p[1] ) ); ?>)</span></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! $s['plays'] ) : ?>
				<p><?php esc_html_e( 'No plays on the website yet this week. Plays are counted without collecting anything about the people watching.', 'faith-tv-series' ); ?></p>
			<?php endif; ?>
			<?php if ( $s['errors'] ) : ?>
				<p style="color:#b32d2e">
					<?php
					/* translators: %d: number of failed plays */
					printf( esc_html( _n( '%d play could not start. See Faith Stream > Health.', '%d plays could not start. See Faith Stream > Health.', $s['errors'], 'faith-tv-series' ) ), (int) $s['errors'] );
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Monday morning: last week's numbers to the site's admin address (off unless turned on). */
	public static function email() {
		if ( ! FTVS_Settings::get( 'stats_email' ) || ! FTVS_Catalog::connected() || FTVS_Catalog::is_demo() ) {
			return;
		}
		$s    = self::summary( 7 );
		$site = get_bloginfo( 'name' );
		/* translators: %s: site name */
		$subject = sprintf( __( '%s: your videos last week', 'faith-tv-series' ), $site );
		/* translators: 1: plays, 2: watched to the end */
		$body = sprintf( __( "Plays on the website: %1\$s\nWatched to the end: %2\$s\n", 'faith-tv-series' ), number_format_i18n( $s['plays'] ), number_format_i18n( $s['finished'] ) );
		if ( $s['live'] ) {
			/* translators: %s: number of people */
			$body .= sprintf( __( "Joined the live service: %s\n", 'faith-tv-series' ), number_format_i18n( $s['live'] ) );
		}
		if ( $s['videos'] ) {
			$body .= "\n" . __( 'Most watched:', 'faith-tv-series' ) . "\n";
			foreach ( $s['videos'] as $i => $v ) {
				$body .= ( $i + 1 ) . '. ' . $v[0] . ' (' . $v[1] . ")\n";
			}
		}
		$body .= "\n" . admin_url( 'index.php' ) . "\n";
		wp_mail( get_option( 'admin_email' ), $subject, $body );
	}
}
