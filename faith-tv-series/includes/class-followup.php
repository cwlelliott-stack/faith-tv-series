<?php
/**
 * Follow-up: "Remind me" sign-ups (Sunday live, new series) and "new video" notices, handed to
 * the church's own follow-up system (a GoHighLevel inbound webhook, Zapier, Make, a Faith
 * Connections form endpoint...). Nothing is kept here except sign-ups that couldn't be
 * delivered yet, which are retried every hour and dropped after a day.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_Followup {

	const RETRY = 'ftvs_followup_retry';
	const CRON  = 'ftvs_followup_retry';

	public static function init() {
		add_action( 'ftvs_new_videos', array( __CLASS__, 'new_videos' ) );
		add_action( self::CRON, array( __CLASS__, 'retry' ) );
	}

	/** Default wording for the texting consent line (the church can change it). */
	public static function consent_text() {
		$custom = trim( (string) FTVS_Settings::get( 'remind_consent' ) );
		if ( '' !== $custom ) {
			return $custom;
		}
		$church = (string) FTVS_Settings::get( 'church_name' );
		/* translators: %s: church name */
		return sprintf( __( 'I agree to get text reminders from %s. Message and data rates may apply. Reply STOP to stop.', 'faith-tv-series' ), '' !== $church ? $church : get_bloginfo( 'name' ) );
	}

	/**
	 * @param array $in email, phone, sms (consent), kind (live|series), video, title, page, hp (honeypot).
	 * @return true|WP_Error
	 */
	public static function remind( $in ) {
		$in = is_array( $in ) ? $in : array();
		if ( ! FTVS_Settings::get( 'remind' ) || '' === (string) FTVS_Settings::get( 'remind_webhook' ) ) {
			return new WP_Error( 'ftvs_off', __( 'Reminders are not turned on.', 'faith-tv-series' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $in['hp'] ) ) {
			return true; // a bot filled the hidden box; pretend it worked
		}
		$email = isset( $in['email'] ) ? sanitize_email( $in['email'] ) : '';
		$phone = isset( $in['phone'] ) ? preg_replace( '/[^0-9+]/', '', (string) $in['phone'] ) : '';
		$sms   = ! empty( $in['sms'] );
		if ( '' === $email && strlen( $phone ) < 10 ) {
			return new WP_Error( 'ftvs_contact', __( 'Type an email address or a mobile number.', 'faith-tv-series' ), array( 'status' => 400 ) );
		}
		if ( '' !== $phone && ! $sms ) {
			return new WP_Error( 'ftvs_consent', __( 'Check the box to agree to text reminders, or leave the number empty.', 'faith-tv-series' ), array( 'status' => 400 ) );
		}
		$payload = array(
			'source'       => 'faith-tv-website',
			'kind'         => isset( $in['kind'] ) && 'live' === $in['kind'] ? 'live' : 'series',
			'email'        => $email,
			'phone'        => $phone,
			'sms_consent'  => '' !== $phone && $sms,
			'consent_text' => '' !== $phone ? self::consent_text() : '',
			'video_id'     => isset( $in['video'] ) ? substr( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $in['video'] ), 0, 128 ) : '',
			'video_title'  => isset( $in['title'] ) ? FTVS_Stats::cut( sanitize_text_field( $in['title'] ), 160 ) : '',
			'page'         => isset( $in['page'] ) ? esc_url_raw( $in['page'] ) : '',
			'site'         => home_url( '/' ),
			'created_at'   => gmdate( 'c' ),
		);
		if ( ! self::send( (string) FTVS_Settings::get( 'remind_webhook' ), $payload ) ) {
			self::later( $payload );
		}
		return true;
	}

	/** New videos appeared on the channel: tell the church's follow-up system (e.g. to text "New series: ..."). */
	public static function new_videos( $videos ) {
		$url = (string) FTVS_Settings::get( 'new_webhook' );
		if ( '' === $url || FTVS_Catalog::is_demo() ) {
			return;
		}
		$list = array();
		foreach ( array_slice( $videos, 0, 20 ) as $v ) {
			$list[] = array(
				'id'      => $v['id'],
				'title'   => $v['title'],
				'speaker' => $v['speaker'],
				'image'   => $v['image'],
				'added'   => $v['added'],
				'url'     => '' !== FTVS_Watch::url( $v['id'] ) ? FTVS_Watch::url( $v['id'] ) : FTVS_Catalog::video_link( $v['id'], $v['parent'] ),
			);
		}
		self::send(
			$url,
			array(
				'source' => 'faith-tv-website',
				'event'  => 'new_videos',
				'videos' => $list,
				'site'   => home_url( '/' ),
			)
		);
	}

	private static function send( $url, $payload ) {
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return false;
		}
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	private static function later( $payload ) {
		$queue   = get_option( self::RETRY, array() );
		$queue   = is_array( $queue ) ? array_slice( $queue, -99 ) : array();
		$queue[] = $payload;
		update_option( self::RETRY, $queue, false );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::CRON );
		}
	}

	public static function retry() {
		$queue = get_option( self::RETRY, array() );
		update_option( self::RETRY, array(), false );
		$url = (string) FTVS_Settings::get( 'remind_webhook' );
		foreach ( is_array( $queue ) ? $queue : array() as $payload ) {
			if ( strtotime( $payload['created_at'] ) < time() - DAY_IN_SECONDS ) {
				continue; // too old to be useful; don't keep people's details around
			}
			if ( ! self::send( $url, $payload ) ) {
				self::later( $payload );
			}
		}
	}

	public static function pending() {
		$queue = get_option( self::RETRY, array() );
		return is_array( $queue ) ? count( $queue ) : 0;
	}
}
