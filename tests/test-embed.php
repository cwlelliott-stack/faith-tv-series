<?php
/**
 * FTVS_Embed: signed addresses for iframes on other websites. Only addresses made by the admin's
 * embed builder carry options; an address edited by hand falls back to the plain default look.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FTVS_T_Die extends Exception {
}

/** The signature of an embed query, made the way the plugin makes it. */
function ftvs_t_embed_sign( $query, $legacy = false ) {
	return ftvs_t_private( 'FTVS_Embed', 'sign', array( $query, $legacy ) );
}

/** The ftvs_* parameters of an embed address as PHP would see them in $_GET. */
function ftvs_t_embed_params( $url ) {
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
	return $params;
}

/**
 * What FTVS_Embed::maybe_serve() checks: the parameters it knows about, cleaned up the way it
 * cleans them, must sign to the address's ftvs_sig.
 */
function ftvs_t_embed_verifies( $params ) {
	$query = array( 'ftvs_embed' => sanitize_text_field( isset( $params['ftvs_embed'] ) ? $params['ftvs_embed'] : '' ) );
	foreach ( ftvs_t_private( 'FTVS_Embed', 'option_keys' ) as $key ) {
		if ( isset( $params[ 'ftvs_' . $key ] ) ) {
			$query[ 'ftvs_' . $key ] = sanitize_text_field( $params[ 'ftvs_' . $key ] );
		}
	}
	$sig = isset( $params['ftvs_sig'] ) ? (string) $params['ftvs_sig'] : '';
	return '' !== $sig && hash_equals( ftvs_t_embed_sign( $query ), $sig );
}

/* ---------------------------------------------------------------- building addresses */

function test_embed_url_shape() {
	$url   = FTVS_Embed::url(
		array(
			'category' => 'kids-rock',
			'layout'   => 'row',
			'theme'    => 'dark',
		)
	);
	$parts = wp_parse_url( $url );
	assert_same( wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $parts['host'], 'on the church\'s own site' );
	$params = ftvs_t_embed_params( $url );
	assert_same( 'kids-rock', $params['ftvs_embed'] );
	assert_same( 'row', $params['ftvs_layout'] );
	assert_same( 'dark', $params['ftvs_theme'] );
	assert_matches( '/^[0-9a-f]{20}$/', $params['ftvs_sig'], 'a 20 character signature' );
	assert_count( 4, $params );
}

function test_embed_url_leaves_out_empty_and_default_options() {
	$params = ftvs_t_embed_params(
		FTVS_Embed::url(
			array(
				'category' => 'kids-rock',
				'kind'     => 'category',
				'limit'    => '0',
				'title'    => '   ',
				'eyebrow'  => '',
				'layout'   => '',
				'bogus'    => 'not an option',
			)
		)
	);
	assert_same( array( 'ftvs_embed', 'ftvs_sig' ), array_keys( $params ) );

	$params = ftvs_t_embed_params( FTVS_Embed::url( array( 'category' => 'kids-rock', 'limit' => '6', 'kind' => 'library' ) ) );
	assert_same( '6', $params['ftvs_limit'] );
	assert_same( 'library', $params['ftvs_kind'] );
}

function test_embed_url_without_a_category_uses_the_kind() {
	foreach ( array( 'live', 'library', 'video' ) as $kind ) {
		$params = ftvs_t_embed_params( FTVS_Embed::url( array( 'kind' => $kind ) ) );
		assert_same( $kind, $params['ftvs_embed'], $kind );
		assert_same( $kind, $params['ftvs_kind'], $kind );
		assert_true( ftvs_t_embed_verifies( $params ), $kind );
	}
	$params = ftvs_t_embed_params( FTVS_Embed::url( array() ) );
	assert_same( '', $params['ftvs_embed'] );
}

function test_embed_url_carries_text_and_look_options() {
	$params = ftvs_t_embed_params(
		FTVS_Embed::url(
			array(
				'category'      => 'kids-rock',
				'heading_size'  => '40',
				'heading_color' => '#ffffff',
				'meta_size'     => '12px',
				'look_accent'   => '#123456',
				'look_style'    => 'soft',
				'preview'       => '1',
			)
		)
	);
	assert_same( '40', $params['ftvs_heading_size'] );
	assert_same( '#ffffff', $params['ftvs_heading_color'] );
	assert_same( '12px', $params['ftvs_meta_size'] );
	assert_same( '#123456', $params['ftvs_look_accent'] );
	assert_same( 'soft', $params['ftvs_look_style'] );
	assert_same( '1', $params['ftvs_preview'] );
	assert_true( ftvs_t_embed_verifies( $params ) );
}

/* ---------------------------------------------------------------- the signature */

function test_embed_url_round_trips() {
	$cases = array(
		array( 'category' => '@newest' ),
		array(
			'category' => 'kids-rock',
			'layout'   => 'coverflow',
			'theme'    => 'light',
			'bg'       => 'clear',
			'open'     => 'channel',
			'limit'    => '8',
		),
		array(
			'category' => 'demo-hope-rising',
			'title'    => 'Watch & Grow: 100% live, "today" + more',
			'eyebrow'  => 'Caf' . "\xc3\xa9" . ' Sunday / Domingo',
		),
		array(
			'kind'  => 'video',
			'video' => 'outrageous-part-4',
		),
		array(
			'category'      => 'faith-tv-mini-series-2',
			'heading_color' => 'rgba(255, 255, 255, .8)',
			'look_accent'   => '#C40D3C',
		),
	);
	foreach ( $cases as $i => $args ) {
		$params = ftvs_t_embed_params( FTVS_Embed::url( $args ) );
		assert_true( ftvs_t_embed_verifies( $params ), 'case ' . $i . ': ' . wp_json_encode( $args ) );
		foreach ( $args as $key => $value ) {
			if ( 'category' === $key || 'kind' === $key && 'category' === $value ) {
				continue;
			}
			assert_same( (string) $value, $params[ 'ftvs_' . $key ], 'case ' . $i . ' ' . $key . ' arrives as it was typed' );
		}
	}
}

function test_embed_editing_a_parameter_breaks_the_signature() {
	$params = ftvs_t_embed_params(
		FTVS_Embed::url(
			array(
				'category' => 'kids-rock',
				'layout'   => 'row',
				'theme'    => 'dark',
				'title'    => 'Kids',
			)
		)
	);
	assert_true( ftvs_t_embed_verifies( $params ) );

	foreach ( array( 'ftvs_embed' => 'elevate', 'ftvs_layout' => 'grid', 'ftvs_theme' => 'light', 'ftvs_title' => 'Make it say something else' ) as $key => $new ) {
		$edited         = $params;
		$edited[ $key ] = $new;
		assert_false( ftvs_t_embed_verifies( $edited ), 'changing ' . $key );
	}
	$removed = $params;
	unset( $removed['ftvs_layout'] );
	assert_false( ftvs_t_embed_verifies( $removed ), 'removing an option' );

	$added               = $params;
	$added['ftvs_bg']    = 'clear';
	assert_false( ftvs_t_embed_verifies( $added ), 'adding an option' );

	$added               = $params;
	$added['ftvs_limit'] = '1';
	assert_false( ftvs_t_embed_verifies( $added ), 'adding a limit' );

	$forged             = $params;
	$forged['ftvs_sig'] = str_repeat( '0', 20 );
	assert_false( ftvs_t_embed_verifies( $forged ), 'a made-up signature' );

	$missing = $params;
	unset( $missing['ftvs_sig'] );
	assert_false( ftvs_t_embed_verifies( $missing ), 'no signature at all' );

	$empty             = $params;
	$empty['ftvs_sig'] = '';
	assert_false( ftvs_t_embed_verifies( $empty ), 'an empty signature' );
}

function test_embed_parameters_that_are_not_options_are_not_signed() {
	$params = ftvs_t_embed_params( FTVS_Embed::url( array( 'category' => 'kids-rock' ) ) );
	$params['utm_source'] = 'newsletter';
	$params['fbclid']     = 'abc';
	assert_true( ftvs_t_embed_verifies( $params ), 'tracking parameters added by other sites do not matter' );
}

function test_embed_signature_ignores_parameter_order() {
	$a = ftvs_t_embed_sign(
		array(
			'ftvs_embed'  => 'x',
			'ftvs_layout' => 'row',
			'ftvs_theme'  => 'dark',
		)
	);
	$b = ftvs_t_embed_sign(
		array(
			'ftvs_theme'  => 'dark',
			'ftvs_embed'  => 'x',
			'ftvs_layout' => 'row',
		)
	);
	assert_same( $a, $b );
	assert_same(
		$a,
		ftvs_t_embed_sign(
			array(
				'ftvs_embed'  => 'x',
				'ftvs_layout' => 'row',
				'ftvs_theme'  => 'dark',
				'ftvs_sig'    => 'whatever was there',
			)
		),
		'an existing signature is not part of what is signed'
	);
}

function test_embed_signature_depends_on_this_sites_secret() {
	$query  = array(
		'ftvs_embed'  => 'x',
		'ftvs_layout' => 'row',
	);
	$secret = 'first-secret-for-tests';
	ftvs_t_add_filter(
		'pre_option_ftvs_embed_secret',
		function () use ( &$secret ) {
			return $secret;
		},
		20,
		0
	);
	$one = ftvs_t_embed_sign( $query );
	assert_same( substr( hash_hmac( 'sha256', http_build_query( $query ), 'first-secret-for-tests' ), 0, 20 ), $one, 'HMAC-SHA256 of the sorted query, first 20 characters' );
	$params = ftvs_t_embed_params( FTVS_Embed::url( array( 'category' => 'x', 'layout' => 'row' ) ) );
	assert_true( ftvs_t_embed_verifies( $params ) );

	$secret = 'second-secret-for-tests';
	assert_not_same( $one, ftvs_t_embed_sign( $query ) );
	assert_false( ftvs_t_embed_verifies( $params ), 'a new secret retires every old address' );
}

function test_embed_secret_is_made_on_first_use_and_kept() {
	$saved = array();
	ftvs_t_add_filter(
		'pre_option_ftvs_embed_secret',
		function () {
			return ''; // "no secret saved yet"
		},
		20,
		0
	);
	ftvs_t_add_filter(
		'pre_update_option_ftvs_embed_secret',
		function ( $value, $old ) use ( &$saved ) {
			$saved[] = $value;
			return $old; // "unchanged": nothing is written to the database
		},
		10,
		3
	);
	$query = array( 'ftvs_embed' => 'x' );
	$sig   = ftvs_t_embed_sign( $query );
	assert_count( 1, $saved, 'a secret was made and saved' );
	assert_true( is_string( $saved[0] ) && strlen( $saved[0] ) >= 32, 'a long random secret' );
	assert_same( substr( hash_hmac( 'sha256', http_build_query( $query ), $saved[0] ), 0, 20 ), $sig, 'and used to sign' );
}

function test_embed_legacy_signatures_from_version_1_2_use_the_auth_salt() {
	$query = array(
		'ftvs_embed'  => 'x',
		'ftvs_layout' => 'row',
	);
	assert_not_same( ftvs_t_embed_sign( $query ), ftvs_t_embed_sign( $query, true ) );
	$expected = substr( hash_hmac( 'sha256', http_build_query( $query ), wp_salt( 'auth' ) ), 0, 20 );
	assert_same( $expected, ftvs_t_embed_sign( $query, true ), 'addresses made by 1.2 (keyed with the auth salt) still verify' );
}

/* ---------------------------------------------------------------- the code to paste */

function test_embed_code_is_an_iframe_plus_the_helper_script() {
	$code = FTVS_Embed::code(
		array(
			'category' => 'kids-rock',
			'layout'   => 'row',
			'title'    => 'Kids "Rock" & more',
		)
	);
	assert_matches( '/^<iframe src="[^"]+" title="[^"]*" data-ftvs-embed loading="lazy"/', $code );
	assert_contains( 'title="Kids &quot;Rock&quot; &amp; more"', $code, 'the title is escaped' );
	assert_contains( 'allow="autoplay; fullscreen; picture-in-picture; web-share"', $code );
	assert_contains( 'allowfullscreen', $code );
	assert_contains( 'height:' . FTVS_Embed::start_height( 'row' ) . 'px', $code );
	assert_contains( '<script src="' . FTVS_URL . 'assets/embed.js" async></script>', $code );
	preg_match( '/<iframe src="([^"]+)"/', $code, $m );
	assert_true( ftvs_t_embed_verifies( ftvs_t_embed_params( html_entity_decode( $m[1] ) ) ), 'the address in the code is the signed one' );
}

function test_embed_code_escapes_a_title_with_markup() {
	$code = FTVS_Embed::code(
		array(
			'category' => 'kids-rock',
			'title'    => '"><script>alert(1)</script>',
		)
	);
	assert_not_contains( '<script>alert', $code );
	assert_contains( 'title="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $code );
}

function test_embed_code_defaults_title_and_height() {
	$code = FTVS_Embed::code( array( 'category' => 'kids-rock' ) );
	assert_contains( 'title="Videos"', $code );
	assert_contains( 'height:700px', $code, 'unknown layout: a general height' );
	assert_contains( 'height:' . FTVS_Embed::start_height( 'live' ) . 'px', FTVS_Embed::code( array( 'kind' => 'live' ) ) );
	assert_contains( 'height:' . FTVS_Embed::start_height( 'library' ) . 'px', FTVS_Embed::code( array( 'kind' => 'library', 'layout' => 'row' ) ), 'the kind beats the layout' );
	assert_contains( 'height:' . FTVS_Embed::start_height( 'video' ) . 'px', FTVS_Embed::code( array( 'kind' => 'video', 'video' => 'x' ) ) );
}

function test_embed_start_height() {
	$expected = array(
		'showcase'  => 760,
		'coverflow' => 640,
		'list'      => 900,
		'row'       => 380,
		'grid'      => 720,
		'video'     => 520,
		'live'      => 520,
		'library'   => 900,
	);
	foreach ( $expected as $layout => $height ) {
		assert_same( $height, FTVS_Embed::start_height( $layout ), $layout );
	}
	assert_same( 700, FTVS_Embed::start_height( '' ) );
	assert_same( 700, FTVS_Embed::start_height( 'nonsense' ) );
}

function test_embed_known_options() {
	$keys = ftvs_t_private( 'FTVS_Embed', 'option_keys' );
	foreach ( FTVS_Embed::OPTIONS as $option ) {
		assert_in_array( $option, $keys );
	}
	foreach ( FTVS_Settings::TEXT_PARTS as $part ) {
		assert_in_array( $part . '_size', $keys );
		assert_in_array( $part . '_color', $keys );
	}
	foreach ( FTVS_Embed::LOOK as $look ) {
		assert_in_array( 'look_' . $look, $keys );
	}
	assert_same( array_values( array_unique( $keys ) ), $keys, 'no option listed twice' );
}

/* ---------------------------------------------------------------- the builder's ajax endpoint */

/** Runs FTVS_Embed::ajax_code() with wp_die() turned into an exception; returns { json, died }. */
function ftvs_t_embed_ajax( $post ) {
	ftvs_t_add_filter( 'wp_doing_ajax', '__return_true' );
	ftvs_t_add_filter(
		'wp_die_ajax_handler',
		function () {
			return function ( $message ) {
				throw new FTVS_T_Die( (string) $message );
			};
		}
	);
	$_POST    = $post;
	$_REQUEST = $post;
	$died     = null;
	ob_start();
	try {
		FTVS_Embed::ajax_code();
	} catch ( FTVS_T_Die $e ) {
		$died = $e->getMessage();
	}
	$out = ob_get_clean();
	return array(
		'json' => json_decode( $out, true ),
		'died' => $died,
	);
}

function test_embed_ajax_signs_addresses_for_administrators() {
	ftvs_t_admin();
	$result = ftvs_t_embed_ajax(
		array(
			'_ajax_nonce'  => wp_create_nonce( 'ftvs_embed_code' ),
			'category'     => 'kids-rock',
			'layout'       => 'row',
			'title'        => '<b>Kids</b> Rock',
			'heading_size' => '40',
			'preview'      => '1',
			'bogus'        => 'ignored',
		)
	);
	assert_true( $result['json']['success'] );
	$url    = $result['json']['data']['url'];
	$params = ftvs_t_embed_params( $url );
	assert_same( 'kids-rock', $params['ftvs_embed'] );
	assert_same( 'Kids Rock', $params['ftvs_title'], 'tags are stripped before signing' );
	assert_same( '40', $params['ftvs_heading_size'] );
	assert_false( isset( $params['ftvs_bogus'] ) );
	assert_false( isset( $params['ftvs_preview'] ), 'the preview flag is not something a browser page can ask for' );
	assert_true( ftvs_t_embed_verifies( $params ) );
	assert_contains( '<iframe', $result['json']['data']['code'] );
}

function test_embed_ajax_refuses_everyone_else() {
	// Signed in with a good nonce, but not allowed to manage options (an editor, say).
	ftvs_t_admin();
	$nonce = wp_create_nonce( 'ftvs_embed_code' );
	ftvs_t_add_filter(
		'user_has_cap',
		function ( $caps ) {
			unset( $caps['manage_options'] );
			return $caps;
		}
	);
	$result = ftvs_t_embed_ajax(
		array(
			'_ajax_nonce' => $nonce,
			'category'    => 'kids-rock',
		)
	);
	assert_false( $result['json']['success'], 'someone without manage_options cannot sign addresses' );
	assert_false( isset( $result['json']['data']['url'] ) );

	wp_set_current_user( 0 );
	$result = ftvs_t_embed_ajax( array( 'category' => 'kids-rock' ) );
	assert_false( $result['json']['success'], 'a visitor cannot either' );
}

function test_embed_ajax_needs_a_valid_nonce() {
	ftvs_t_admin();
	$result = ftvs_t_embed_ajax( array( 'category' => 'kids-rock' ) );
	assert_same( '-1', $result['died'], 'no nonce: the request is refused' );
	assert_same( null, $result['json'] );

	$result = ftvs_t_embed_ajax(
		array(
			'_ajax_nonce' => 'nonsense',
			'category'    => 'kids-rock',
		)
	);
	assert_same( '-1', $result['died'] );
}

/* ---------------------------------------------------------------- serving */

function test_embed_maybe_serve_does_nothing_without_the_parameter() {
	unset( $_GET['ftvs_embed'] );
	ob_start();
	$result = FTVS_Embed::maybe_serve();
	$out    = ob_get_clean();
	assert_same( null, $result );
	assert_same( '', $out );
}

function test_embed_preview_look_tries_settings_without_saving_them() {
	ftvs_t_guard_hook( 'option_' . FTVS_Settings::OPTION );
	ftvs_t_settings(
		array(
			'accent' => '#C40D3C',
			'theme'  => 'dark',
			'layout' => 'grid',
			'text'   => array(),
		)
	);
	ftvs_t_private(
		'FTVS_Embed',
		'preview_look',
		array(
			array(
				'ftvs_look_accent'  => '#123456',
				'ftvs_look_theme'   => 'light',
				'ftvs_look_layout'  => 'carousel',
				'ftvs_look_bogus'   => 'x',
				'ftvs_heading_size' => '40',
				'ftvs_heading_color' => 'nonsense',
			),
		)
	);
	assert_same( '#123456', FTVS_Settings::get( 'accent' ) );
	assert_same( 'light', FTVS_Settings::get( 'theme' ) );
	assert_same( 'grid', FTVS_Settings::get( 'layout' ), 'an invalid layout in the preview leaves the saved one' );
	assert_same(
		array(
			'heading' => array(
				'size'  => '40px',
				'color' => '',
			),
		),
		FTVS_Settings::get( 'text' )
	);
	assert_contains( '--ftvs-accent:#123456', FTVS_Renderer::site_css() );
}
