<?php
/**
 * A podcast feed of the church's messages at /feed/faith-tv/, for Apple Podcasts, Spotify and
 * any podcast app. Each episode is the message's audio (Faith Stream makes an audio file of
 * each new video when "audio for a podcast" is on in its Settings) and links to its page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Podcast {

	const FEED  = 'faith-tv';
	const CACHE = 'ftvs_podcast_xml';
	// The last feed that was built from a good answer: served while the channel can't be read.
	const LAST = 'ftvs_podcast_last';

	/** The podcast app's "only if changed" question, kept for render() (WordPress's own answer is about posts). */
	private static $if_none_match = '';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'ftvs_new_videos', array( __CLASS__, 'forget' ) );
		add_action( 'update_option_' . FTVS_Settings::OPTION, array( __CLASS__, 'reset' ) );
		add_action( 'parse_request', array( __CLASS__, 'own_validators' ) );
	}

	/**
	 * WordPress answers "304 Not Modified" for any feed when no post changed, before render() runs. Episodes
	 * come from the channel, not posts, so that answer would hide new ones: take the question away from
	 * WordPress and answer it in render() from the feed itself.
	 */
	public static function own_validators( $wp ) {
		if ( isset( $wp->query_vars['feed'] ) && self::FEED === $wp->query_vars['feed'] ) {
			self::$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
			unset( $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		}
	}

	public static function register() {
		add_feed( self::FEED, array( __CLASS__, 'render' ) );
		if ( get_option( 'ftvs_feed_ver' ) !== '1' ) {
			update_option( 'ftvs_feed_ver', '1', true );
			delete_option( 'rewrite_rules' ); // rebuilt when this request is parsed, with every plugin's rules
		}
	}

	public static function url() {
		return get_feed_link( self::FEED );
	}

	public static function forget() {
		delete_transient( self::CACHE );
	}

	/** Settings changed (maybe another church): the old feed is not even a fallback any more. */
	public static function reset() {
		delete_transient( self::CACHE );
		delete_option( self::LAST );
	}

	/** Hourly, from FTVS_Health::warm(): build the feed in the background, so a podcast app never waits. */
	public static function rebuild() {
		if ( ! FTVS_Settings::get( 'podcast' ) || ! FTVS_Catalog::connected() || FTVS_Catalog::is_demo() ) {
			return;
		}
		$xml = self::build();
		if ( null !== $xml ) {
			set_transient( self::CACHE, $xml, 2 * HOUR_IN_SECONDS );
			update_option( self::LAST, $xml, false );
		}
	}

	/** @return array Episodes that have audio: video shape + 'audio'. */
	public static function episodes( $max = 100 ) {
		$library = FTVS_Catalog::library();
		if ( is_wp_error( $library ) ) {
			return array();
		}
		$out   = array();
		$tries = 0;
		foreach ( $library as $video ) {
			// No audio on the ten newest messages: the channel doesn't make audio (yet), so stop asking.
			if ( count( $out ) >= $max || $tries >= $max + 20 || ( ! $out && $tries >= 10 ) ) {
				break;
			}
			$audio = isset( $video['audio'] ) ? (string) $video['audio'] : '';
			if ( '' === $audio ) {
				// Lists don't always carry the audio address; the video's own details do.
				++$tries;
				$details = FTVS_Catalog::get_video( $video['id'] );
				$audio   = is_wp_error( $details ) || empty( $details['audio'] ) ? '' : $details['audio'];
			}
			if ( '' !== $audio ) {
				$video['audio'] = $audio;
				$out[]          = $video;
			}
		}
		return $out;
	}

	public static function render() {
		if ( ! FTVS_Settings::get( 'podcast' ) || ! FTVS_Catalog::connected() || FTVS_Catalog::is_demo() ) {
			status_header( 404 );
			exit;
		}
		$xml = get_transient( self::CACHE );
		if ( false === $xml ) {
			$xml = self::build();
			if ( null === $xml ) {
				// The channel can't be read right now: the last good feed, never an empty one.
				$xml = (string) get_option( self::LAST, '' );
				if ( '' === $xml ) {
					status_header( 503 );
					header( 'Retry-After: 600' );
					exit;
				}
			} else {
				set_transient( self::CACHE, $xml, HOUR_IN_SECONDS );
				update_option( self::LAST, $xml, false );
			}
		}
		$etag = '"' . md5( $xml ) . '"';
		if ( function_exists( 'header_remove' ) ) {
			header_remove( 'Last-Modified' ); // WordPress's, about posts
		}
		header( 'ETag: ' . $etag );
		if ( '' !== self::$if_none_match && in_array( $etag, array_map( 'trim', explode( ',', self::$if_none_match ) ), true ) ) {
			status_header( 304 );
			exit;
		}
		header( 'Content-Type: application/rss+xml; charset=UTF-8' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_xml below.
		exit;
	}

	/** The audio file's type from its address (Faith Stream makes .m4a). */
	private static function audio_type( $url ) {
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '.mp3' === substr( $path, -4 ) ) {
			return 'audio/mpeg';
		}
		if ( '.aac' === substr( $path, -4 ) ) {
			return 'audio/aac';
		}
		return 'audio/mp4';
	}

	/** @return string|null The feed, or null when the channel couldn't be read (so nothing empty is saved). */
	private static function build() {
		if ( is_wp_error( FTVS_Catalog::library() ) ) {
			return null;
		}
		$s      = FTVS_Settings::all();
		$church = '' !== $s['church_name'] ? $s['church_name'] : get_bloginfo( 'name' );
		$title  = '' !== $s['podcast_title'] ? $s['podcast_title'] : $church;
		$author = '' !== $s['podcast_author'] ? $s['podcast_author'] : $church;
		$image  = '' !== $s['podcast_image'] ? $s['podcast_image'] : $s['church_logo'];
		$link   = FTVS_Watch::page_id() ? get_permalink( FTVS_Watch::page_id() ) : home_url( '/' );
		$x      = function ( $v ) {
			return function_exists( 'esc_xml' ) ? esc_xml( (string) $v ) : htmlspecialchars( (string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		};
		$out    = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n<channel>\n"
			. '<title>' . $x( $title ) . "</title>\n"
			. '<link>' . $x( $link ) . "</link>\n"
			. '<atom:link href="' . $x( self::url() ) . '" rel="self" type="application/rss+xml"/>' . "\n"
			. '<language>' . $x( str_replace( '_', '-', get_locale() ) ) . "</language>\n"
			/* translators: %s: church name */
			. '<description>' . $x( sprintf( __( 'Messages from %s.', 'faith-tv-series' ), $church ) ) . "</description>\n"
			. '<itunes:author>' . $x( $author ) . "</itunes:author>\n"
			. '<itunes:category text="Religion &amp; Spirituality"><itunes:category text="Christianity"/></itunes:category>' . "\n"
			. "<itunes:explicit>false</itunes:explicit>\n";
		if ( '' !== $image ) {
			$out .= '<itunes:image href="' . $x( $image ) . '"/>' . "\n";
		}
		foreach ( self::episodes() as $v ) {
			$page  = FTVS_Watch::url( $v['id'] );
			$t     = $v['added'] ? strtotime( $v['added'] ) : false;
			$desc  = '' !== $v['description'] ? $v['description'] : $v['title'];
			$out  .= "<item>\n"
				. '<title>' . $x( $v['title'] ) . "</title>\n"
				. '<guid isPermaLink="false">' . $x( 'faith-tv:' . $v['id'] ) . "</guid>\n"
				. ( '' !== $page ? '<link>' . $x( $page ) . "</link>\n" : '' )
				. ( $t ? '<pubDate>' . $x( gmdate( DATE_RSS, $t ) ) . "</pubDate>\n" : '' )
				. '<description>' . $x( $desc ) . "</description>\n"
				. '<enclosure url="' . $x( $v['audio'] ) . '" length="0" type="' . $x( self::audio_type( $v['audio'] ) ) . '"/>' . "\n"
				. ( $v['length'] ? '<itunes:duration>' . (int) $v['length'] . "</itunes:duration>\n" : '' )
				. ( '' !== $v['image'] ? '<itunes:image href="' . $x( $v['image'] ) . '"/>' . "\n" : '' )
				. ( '' !== $v['speaker'] ? '<itunes:author>' . $x( $v['speaker'] ) . "</itunes:author>\n" : '' )
				. "</item>\n";
		}
		return $out . "</channel>\n</rss>\n";
	}
}
