<?php
/**
 * FTVS_Settings: the sanitizers that guard the one options row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------- size_value / color_value */

function test_settings_size_value_bare_number_becomes_pixels() {
	assert_same( '40px', FTVS_Settings::size_value( '40' ) );
	assert_same( '2.5px', FTVS_Settings::size_value( '2.5' ) );
	assert_same( '40px', FTVS_Settings::size_value( '  40PX ' ), 'trimmed and lower-cased' );
	assert_same( '40px', FTVS_Settings::size_value( 40 ), 'an integer works too' );
}

function test_settings_size_value_accepts_css_units() {
	foreach ( array( '12px', '2.5rem', '1.25em', '5vw', '50vh', '80%' ) as $ok ) {
		assert_same( $ok, FTVS_Settings::size_value( $ok ), $ok );
	}
}

function test_settings_size_value_accepts_clamp_min_max() {
	assert_same( 'clamp(1rem, 2vw + 1rem, 3rem)', FTVS_Settings::size_value( 'clamp(1rem, 2vw + 1rem, 3rem)' ) );
	assert_same( 'min(4vw,40px)', FTVS_Settings::size_value( 'MIN(4vw,40px)' ), 'lower-cased' );
	assert_same( 'max(1rem, 3vw)', FTVS_Settings::size_value( 'max(1rem, 3vw)' ) );
	assert_same( 'clamp(' . str_repeat( '1', 80 ) . ')', FTVS_Settings::size_value( 'clamp(' . str_repeat( '1', 80 ) . ')' ), '80 characters inside is the limit' );
	assert_same( '', FTVS_Settings::size_value( 'clamp(' . str_repeat( '1', 81 ) . ')' ), '81 is too long' );
}

function test_settings_size_value_rejects_everything_else() {
	$bad = array( '', 'abc', '40 px', '4000', '1.234', '-5', '40px;color:red', '40px}', 'calc(1px + 2px)', 'url(x)', 'expression(alert(1))', 'clamp(1px, url(x), 2px)', 'clamp(1px;x:y)', 'min()' );
	foreach ( $bad as $value ) {
		assert_same( '', FTVS_Settings::size_value( $value ), 'should be dropped: ' . $value );
	}
}

function test_settings_color_value_accepts_hex_in_upper_case() {
	assert_same( '#FFF', FTVS_Settings::color_value( '#fff' ) );
	assert_same( '#C40D3C', FTVS_Settings::color_value( '#c40d3c' ) );
	assert_same( '#ABCD', FTVS_Settings::color_value( '#abcd' ), '4 digits (with alpha)' );
	assert_same( '#11223344', FTVS_Settings::color_value( '#11223344' ), '8 digits (with alpha)' );
	assert_same( '#FFF', FTVS_Settings::color_value( "  #fff\n" ), 'trimmed' );
}

function test_settings_color_value_accepts_rgb_and_hsl_in_lower_case() {
	assert_same( 'rgb(1, 2, 3)', FTVS_Settings::color_value( 'rgb(1, 2, 3)' ) );
	assert_same( 'rgba(0,0,0,.5)', FTVS_Settings::color_value( 'RGBA(0,0,0,.5)' ) );
	assert_same( 'hsl(120, 50%, 50%)', FTVS_Settings::color_value( 'HSL(120, 50%, 50%)' ) );
	assert_same( 'rgb(0 0 0 / 50%)', FTVS_Settings::color_value( 'rgb(0 0 0 / 50%)' ) );
}

function test_settings_color_value_rejects_everything_else() {
	$bad = array( '', 'red', '#ggg', '#12345', '#1234567', 'rgb(1,2,3);color:red', 'url(x)', 'var(--x)', 'rgb(1,2,3', 'rgb(1,2,3)}', 'transparent' );
	foreach ( $bad as $value ) {
		assert_same( '', FTVS_Settings::color_value( $value ), 'should be dropped: ' . $value );
	}
}

/* ---------------------------------------------------------------- text_styles / text_css_vars */

function test_settings_text_styles_keeps_only_valid_parts_and_values() {
	$out = FTVS_Settings::text_styles(
		array(
			'heading' => array(
				'size'  => '40',
				'color' => '#fff',
			),
			'card'    => array(
				'size'  => 'expression(x)',
				'color' => 'red',
			),
			'meta'    => array( 'size' => '12px' ),
			'bogus'   => array(
				'size'  => '10px',
				'color' => '#000',
			),
		)
	);
	assert_same(
		array(
			'heading' => array(
				'size'  => '40px',
				'color' => '#FFF',
			),
			'meta'    => array(
				'size'  => '12px',
				'color' => '',
			),
		),
		$out,
		'card (both invalid) and bogus (not a part) are dropped; a half-valid part keeps the valid half'
	);
}

function test_settings_text_styles_empty_input() {
	assert_same( array(), FTVS_Settings::text_styles( array() ) );
}

function test_settings_text_css_vars_builds_custom_properties_in_part_order() {
	$css = FTVS_Settings::text_css_vars(
		array(
			'meta'    => array(
				'size'  => '12px',
				'color' => '',
			),
			'heading' => array(
				'size'  => '40px',
				'color' => '#FFF',
			),
		)
	);
	assert_same( '--ftvs-heading-size:40px;--ftvs-heading-color:#FFF;--ftvs-meta-size:12px;', $css );
}

function test_settings_text_css_vars_revalidates_whatever_it_is_given() {
	$css = FTVS_Settings::text_css_vars(
		array(
			'heading' => array(
				'size'  => '40px;background:url(//evil.example/x)',
				'color' => '#fff',
			),
			'text'    => array(
				'size'  => '18',
				'color' => 'red;position:fixed',
			),
		)
	);
	assert_same( '--ftvs-heading-color:#FFF;--ftvs-text-size:18px;', $css, 'injected CSS never gets through' );
	assert_same( '', FTVS_Settings::text_css_vars( 'not an array' ) );
	assert_same( '', FTVS_Settings::text_css_vars( array() ) );
}

/* ---------------------------------------------------------------- next_steps / services */

function test_settings_next_steps_sanitizes_and_caps_at_three() {
	$steps = FTVS_Settings::next_steps(
		array(
			array(
				'label' => ' Plan a visit ',
				'url'   => ' https://example.org/visit ',
			),
			array(
				'label' => '<b>Give</b>',
				'url'   => 'https://example.org/give',
			),
			array(
				'label' => 'Bad link',
				'url'   => 'javascript:alert(1)',
			),
			array(
				'label' => '',
				'url'   => 'https://example.org/no-label',
			),
			array(
				'label' => 'No address',
				'url'   => '',
			),
			array(
				'label' => 'Third',
				'url'   => 'https://example.org/third',
			),
			array(
				'label' => 'Fourth',
				'url'   => 'https://example.org/fourth',
			),
		)
	);
	assert_same(
		array(
			array(
				'label' => 'Plan a visit',
				'url'   => 'https://example.org/visit',
			),
			array(
				'label' => 'Give',
				'url'   => 'https://example.org/give',
			),
			array(
				'label' => 'Third',
				'url'   => 'https://example.org/third',
			),
		),
		$steps
	);
}

function test_settings_next_steps_ignores_junk() {
	assert_same( array(), FTVS_Settings::next_steps( array() ) );
	assert_same( array(), FTVS_Settings::next_steps( 'nope' ) );
	assert_same( array(), FTVS_Settings::next_steps( array( 'a', 5, null ) ) );
}

function test_settings_services_normalizes_times_and_drops_bad_rows() {
	$out = FTVS_Settings::services(
		array(
			array(
				'day'  => '0',
				'time' => '9:05',
			),
			array(
				'day'  => 3,
				'time' => '19:00',
			),
			array(
				'day'  => 6,
				'time' => ' 23:59 ',
			),
			array(
				'day'  => 7,
				'time' => '10:00',
			),
			array(
				'day'  => '',
				'time' => '10:00',
			),
			array(
				'day'  => -1,
				'time' => '10:00',
			),
			array(
				'day'  => 1,
				'time' => '24:00',
			),
			array(
				'day'  => 1,
				'time' => '10:60',
			),
			array(
				'day'  => 1,
				'time' => '10.30',
			),
			array(
				'day'  => 1,
				'time' => '',
			),
			array( 'time' => '10:00' ),
		)
	);
	assert_same(
		array(
			array(
				'day'  => 0,
				'time' => '09:05',
			),
			array(
				'day'  => 3,
				'time' => '19:00',
			),
			array(
				'day'  => 6,
				'time' => '23:59',
			),
		),
		$out
	);
}

function test_settings_services_caps_at_fourteen() {
	$many = array();
	for ( $i = 0; $i < 20; $i++ ) {
		$many[] = array(
			'day'  => $i % 7,
			'time' => '10:00',
		);
	}
	assert_count( 14, FTVS_Settings::services( $many ) );
	assert_same( array(), FTVS_Settings::services( array() ) );
}

/* ---------------------------------------------------------------- sanitize */

function test_settings_sanitize_source_whitelist() {
	foreach ( array( 'gideo', 'faithstream', 'youtube', 'demo' ) as $source ) {
		assert_same( $source, FTVS_Settings::sanitize( array( 'source' => $source ) )['source'], $source );
	}
	assert_same( '', FTVS_Settings::sanitize( array( 'source' => 'evil' ) )['source'] );
	assert_same( '', FTVS_Settings::sanitize( array( 'source' => 'Demo' ) )['source'], 'case sensitive' );
	assert_same( '', FTVS_Settings::sanitize( array( 'source' => array( 'demo' ) ) )['source'] );
	assert_same( '', FTVS_Settings::sanitize( array( 'source' => '' ) )['source'], 'an empty source disconnects' );
}

function test_settings_sanitize_keeps_fields_the_form_did_not_send() {
	ftvs_t_settings(
		array(
			'source'      => 'youtube',
			'church_name' => 'Grace',
			'accent'      => '#123456',
		)
	);
	$out = FTVS_Settings::sanitize( array( 'theme' => 'light' ) );
	assert_same( 'youtube', $out['source'] );
	assert_same( 'Grace', $out['church_name'] );
	assert_same( '#123456', $out['accent'] );
	assert_same( 'light', $out['theme'] );
	assert_same( FTVS_Settings::all(), FTVS_Settings::sanitize( array() ), 'nothing sent, nothing changes' );
	assert_same( FTVS_Settings::all(), FTVS_Settings::sanitize( 'not an array' ) );
}

function test_settings_sanitize_theme_style_font() {
	assert_same( 'light', FTVS_Settings::sanitize( array( 'theme' => 'light' ) )['theme'] );
	assert_same( 'dark', FTVS_Settings::sanitize( array( 'theme' => 'dark' ) )['theme'] );
	assert_same( 'dark', FTVS_Settings::sanitize( array( 'theme' => 'purple' ) )['theme'], 'anything else is dark' );

	foreach ( FTVS_Settings::STYLES as $style ) {
		assert_same( $style, FTVS_Settings::sanitize( array( 'style' => $style ) )['style'] );
	}
	ftvs_t_settings( array( 'style' => 'soft' ) );
	assert_same( 'soft', FTVS_Settings::sanitize( array( 'style' => 'neon' ) )['style'], 'an unknown style keeps the saved one' );

	assert_same( 'inherit', FTVS_Settings::sanitize( array( 'font' => 'inherit' ) )['font'] );
	assert_same( 'brand', FTVS_Settings::sanitize( array( 'font' => 'Comic Sans' ) )['font'], 'anything else is the brand font' );
}

function test_settings_sanitize_layouts() {
	foreach ( FTVS_Settings::LAYOUTS as $layout ) {
		assert_same( $layout, FTVS_Settings::sanitize( array( 'layout' => $layout ) )['layout'] );
	}
	foreach ( FTVS_Settings::MLAYOUTS as $layout ) {
		assert_same( $layout, FTVS_Settings::sanitize( array( 'mobile_layout' => $layout ) )['mobile_layout'] );
	}
	ftvs_t_settings(
		array(
			'layout'        => 'grid',
			'mobile_layout' => 'row',
		)
	);
	$out = FTVS_Settings::sanitize(
		array(
			'layout'        => 'carousel',
			'mobile_layout' => 'sideways',
		)
	);
	assert_same( 'grid', $out['layout'], 'unknown layouts are ignored' );
	assert_same( 'row', $out['mobile_layout'] );
}

function test_settings_sanitize_toggles() {
	$keys = array( 'powered_by', 'share', 'resume', 'upnext', 'count_plays', 'report_plays', 'stats_email', 'live_bar', 'podcast', 'remind', 'alert_email' );
	foreach ( $keys as $key ) {
		// Off. (powered_by can be switched off here because the default plan, features unknown, allows it.)
		foreach ( array( '0', 0, '', false ) as $off ) {
			assert_same( 0, FTVS_Settings::sanitize( array( $key => $off ) )[ $key ], $key . ' off with ' . var_export( $off, true ) ); // phpcs:ignore
		}
		foreach ( array( '1', 1, 'on', true, 'yes' ) as $on ) {
			assert_same( 1, FTVS_Settings::sanitize( array( $key => $on ) )[ $key ], $key . ' on with ' . var_export( $on, true ) ); // phpcs:ignore
		}
	}
	ftvs_t_settings( array( 'share' => 0 ) );
	assert_same( 0, FTVS_Settings::sanitize( array( 'resume' => 1 ) )['share'], 'a toggle that was not sent keeps its value' );
}

function test_settings_sanitize_powered_by_is_locked_without_the_feature() {
	// Plan unknown (null): removing the badge is allowed.
	ftvs_t_settings( array( 'features' => null ) );
	assert_same( 0, FTVS_Settings::sanitize( array( 'powered_by' => 0 ) )['powered_by'] );

	// Plan includes hide_powered_by.
	ftvs_t_settings( array( 'features' => array( 'hide_powered_by' ) ) );
	assert_same( 0, FTVS_Settings::sanitize( array( 'powered_by' => 0 ) )['powered_by'] );

	// Plan lists features but not that one: the badge stays on.
	ftvs_t_settings( array( 'features' => array( 'live', 'library' ) ) );
	assert_same( 1, FTVS_Settings::sanitize( array( 'powered_by' => 0 ) )['powered_by'] );

	// An empty list is still a list.
	ftvs_t_settings( array( 'features' => array() ) );
	assert_same( 1, FTVS_Settings::sanitize( array( 'powered_by' => 0 ) )['powered_by'] );
}

function test_settings_sanitize_powered_by_lock_uses_the_features_sent_in_the_same_save() {
	ftvs_t_settings( array( 'features' => null ) );
	$out = FTVS_Settings::sanitize(
		array(
			'features'   => array( 'live' ),
			'powered_by' => 0,
		)
	);
	assert_same( array( 'live' ), $out['features'] );
	assert_same( 1, $out['powered_by'] );

	$out = FTVS_Settings::sanitize(
		array(
			'features'   => array( 'Hide_Powered_By', '' ),
			'powered_by' => 0,
		)
	);
	assert_same( array( 'hide_powered_by' ), $out['features'], 'keys are sanitized and empties dropped' );
	assert_same( 0, $out['powered_by'] );
}

function test_settings_sanitize_powered_by_comes_back_when_the_plan_loses_the_feature() {
	ftvs_t_settings(
		array(
			'features'   => array( 'hide_powered_by' ),
			'powered_by' => 0,
		)
	);
	$out = FTVS_Settings::sanitize( array( 'features' => array( 'live' ) ) );
	assert_same( 1, $out['powered_by'], 'the badge is forced back on even though this save did not mention it' );
}

function test_settings_sanitize_features_null_means_unknown() {
	ftvs_t_settings( array( 'features' => array( 'live' ) ) );
	assert_same( null, FTVS_Settings::sanitize( array( 'features' => null ) )['features'] );
	assert_same( array( 'live' ), FTVS_Settings::sanitize( array( 'theme' => 'light' ) )['features'], 'not sent keeps the list' );
}

function test_settings_feature_helper() {
	assert_true( FTVS_Settings::feature( 'anything', null ), 'unknown plan: everything is included' );
	assert_true( FTVS_Settings::feature( 'live', array( 'live' ) ) );
	assert_false( FTVS_Settings::feature( 'library', array( 'live' ) ) );
	assert_false( FTVS_Settings::feature( 'live', array() ) );
	ftvs_t_settings( array( 'features' => array( 'live' ) ) );
	assert_true( FTVS_Settings::feature( 'live' ), 'without the second argument it reads the saved list' );
	assert_false( FTVS_Settings::feature( 'library' ) );
}

function test_settings_sanitize_text_fields_and_urls() {
	$out = FTVS_Settings::sanitize(
		array(
			'church_name'  => '  <b>Grace</b> Church ',
			'fs_url'       => ' https://stream.example.test/ ',
			'tv_url'       => 'javascript:alert(1)',
			'church_logo'  => 'https://cdn.example.test/logo.png/',
			'fs_tenant'    => 'My Church!',
			'account_id'   => 'abc 123!',
			'badge'        => '<i>New</i>',
			'live_channel' => 'main',
		)
	);
	assert_same( 'Grace Church', $out['church_name'] );
	assert_same( 'https://stream.example.test', $out['fs_url'], 'no trailing slash' );
	assert_same( '', $out['tv_url'], 'javascript: addresses are dropped' );
	assert_same( 'https://cdn.example.test/logo.png', $out['church_logo'] );
	assert_same( 'mychurch', $out['fs_tenant'] );
	assert_same( 'abc123', $out['account_id'] );
	assert_same( 'New', $out['badge'] );
}

function test_settings_sanitize_accent_color() {
	assert_same( '#ABCDEF', FTVS_Settings::sanitize( array( 'accent' => '#abcdef' ) )['accent'] );
	assert_same( '#ABC', FTVS_Settings::sanitize( array( 'accent' => '#abc' ) )['accent'] );
	ftvs_t_settings( array( 'accent' => '#123456' ) );
	assert_same( '#123456', FTVS_Settings::sanitize( array( 'accent' => 'red' ) )['accent'], 'not a hex color: keeps the saved one' );
	assert_same( '#123456', FTVS_Settings::sanitize( array( 'accent' => '#12345' ) )['accent'] );
}

function test_settings_sanitize_numbers_are_clamped() {
	assert_same( 15, FTVS_Settings::sanitize( array( 'service_length' => 5 ) )['service_length'] );
	assert_same( 360, FTVS_Settings::sanitize( array( 'service_length' => 9999 ) )['service_length'] );
	assert_same( 90, FTVS_Settings::sanitize( array( 'service_length' => '90' ) )['service_length'] );
	assert_same( 1, FTVS_Settings::sanitize( array( 'cache_minutes' => 0 ) )['cache_minutes'] );
	assert_same( 1440, FTVS_Settings::sanitize( array( 'cache_minutes' => 999999 ) )['cache_minutes'] );
	assert_same( 15, FTVS_Settings::sanitize( array( 'cache_minutes' => '15' ) )['cache_minutes'] );
	assert_same( 0, FTVS_Settings::sanitize( array( 'watch_page_id' => 'abc' ) )['watch_page_id'] );
	assert_same( 12, FTVS_Settings::sanitize( array( 'watch_page_id' => '12' ) )['watch_page_id'] );
}

function test_settings_sanitize_youtube_fields() {
	$out = FTVS_Settings::sanitize(
		array(
			'yt_channel'   => '@Grace Church<x>!',
			'yt_playlists' => array( 'PLrEnWoR732-BHrPp_Pm8_VleD68f9s14-', 'not a playlist', 'dQw4w9WgXcQ', 'UUabcdefghijklmnop' ),
			'yt_key'       => ' AIza SyABC_-1! ',
		)
	);
	assert_same( '@GraceChurchx', $out['yt_channel'] );
	assert_same( array( 'PLrEnWoR732-BHrPp_Pm8_VleD68f9s14-', 'UUabcdefghijklmnop' ), $out['yt_playlists'] );
	assert_same( 'AIzaSyABC_-1', $out['yt_key'] );

	ftvs_t_settings( array( 'yt_key' => 'KEEPME' ) );
	assert_same( 'KEEPME', FTVS_Settings::sanitize( array( 'yt_key' => '   ' ) )['yt_key'], 'an empty box keeps the saved key' );
	assert_same( '', FTVS_Settings::sanitize( array( 'yt_key_remove' => '1' ) )['yt_key'] );
}

function test_settings_sanitize_update_token() {
	assert_same( 'ghp_abc123', FTVS_Settings::sanitize( array( 'update_token' => ' ghp_abc-123! ' ) )['update_token'] );
	ftvs_t_settings( array( 'update_token' => 'ghp_keep' ) );
	$out = FTVS_Settings::sanitize( array( 'update_token' => '' ) );
	assert_same( 'ghp_keep', $out['update_token'], 'the token is never shown again, so an empty box keeps it' );
	$out = FTVS_Settings::sanitize( array( 'update_token_remove' => '1' ) );
	assert_same( '', $out['update_token'] );
	assert_false( array_key_exists( 'update_token_remove', $out ), 'the helper flag is not stored' );
}

function test_settings_sanitize_changing_the_update_token_forgets_the_cached_release() {
	set_site_transient( FTVS_Updater::CACHE, array( 'version' => '9.9.9' ), 60 );
	FTVS_Settings::sanitize( array( 'theme' => 'light' ) );
	assert_true( is_array( get_site_transient( FTVS_Updater::CACHE ) ), 'an unrelated save leaves the cached release alone' );
	FTVS_Settings::sanitize( array( 'update_token' => 'ghp_newtoken' ) );
	assert_false( get_site_transient( FTVS_Updater::CACHE ), 'a new token clears it' );
}

function test_settings_sanitize_next_steps_services_and_text_go_through_their_sanitizers() {
	$out = FTVS_Settings::sanitize(
		array(
			'next_steps' => array(
				array(
					'label' => 'Visit',
					'url'   => 'https://example.org/v',
				),
				array(
					'label' => 'Bad',
					'url'   => 'javascript:x',
				),
			),
			'services'   => array(
				array(
					'day'  => 0,
					'time' => '9:30',
				),
				array(
					'day'  => 9,
					'time' => '9:30',
				),
			),
			'text'       => array(
				'heading' => array(
					'size'  => '40',
					'color' => 'nope',
				),
			),
		)
	);
	assert_count( 1, $out['next_steps'] );
	assert_same(
		array(
			array(
				'day'  => 0,
				'time' => '09:30',
			),
		),
		$out['services']
	);
	assert_same(
		array(
			'heading' => array(
				'size'  => '40px',
				'color' => '',
			),
		),
		$out['text']
	);
	// Non-array values for these keys are ignored rather than wiping the saved list.
	ftvs_t_settings( array( 'next_steps' => array( array( 'label' => 'Keep', 'url' => 'https://example.org/k' ) ) ) );
	assert_count( 1, FTVS_Settings::sanitize( array( 'next_steps' => 'oops' ) )['next_steps'] );
}

/* ---------------------------------------------------------------- constants and migration */

function test_settings_layout_lists_agree_across_classes() {
	assert_same( FTVS_Settings::LAYOUTS, FTVS_Renderer::LAYOUTS, 'the settings and the renderer accept the same layouts' );
	foreach ( FTVS_Settings::LAYOUTS as $layout ) {
		assert_in_array( $layout, FTVS_Settings::MLAYOUTS );
	}
	assert_in_array( 'auto', FTVS_Settings::MLAYOUTS );
	assert_in_array( 'same', FTVS_Settings::MLAYOUTS );
	$defaults = FTVS_Settings::defaults();
	assert_in_array( $defaults['layout'], FTVS_Settings::LAYOUTS );
	assert_in_array( $defaults['mobile_layout'], FTVS_Settings::MLAYOUTS );
	assert_in_array( $defaults['style'], FTVS_Settings::STYLES );
	assert_same( '#C40D3C', $defaults['accent'] );
	$sources = array_keys( FTVS_Catalog::sources() );
	sort( $sources );
	$allowed = FTVS_Settings::SOURCES;
	sort( $allowed );
	assert_same( $allowed, $sources, 'every source the settings allow has a client, and the other way round' );
}

function test_settings_sources_have_working_client_classes() {
	foreach ( FTVS_Catalog::sources() as $source => $class ) {
		assert_true( class_exists( $class ), $source . ' -> ' . $class );
		assert_true( method_exists( $class, 'get_children' ), $class . '::get_children' );
		assert_true( method_exists( $class, 'is_id' ), $class . '::is_id' );
		assert_true( method_exists( $class, 'get_video' ), $class . '::get_video' );
	}
}

/** Runs maybe_migrate() against the settings the test set, capturing what it would save instead of saving it. */
function ftvs_t_migrated( $saved ) {
	$captured = array( 'value' => null );
	ftvs_t_settings( array(), false );
	$GLOBALS['ftvs_t_settings'] = $saved;
	ftvs_t_add_filter(
		'pre_update_option_' . FTVS_Settings::OPTION,
		function ( $value, $old ) use ( &$captured ) {
			$captured['value'] = $value;
			return $old; // "unchanged": WordPress skips the database write
		},
		10,
		3
	);
	FTVS_Settings::maybe_migrate();
	return $captured['value'];
}

function test_settings_migrate_1_1_site_with_faith_tabernacle_channel() {
	$out = ftvs_t_migrated( array( 'account_id' => 'Faith-Tabernacle-1' ) );
	assert_true( is_array( $out ), 'settings saved with the 1.1 form (no source) get migrated' );
	assert_same( 'gideo', $out['source'] );
	assert_same( 'Faith Tabernacle', $out['church_name'] );
	assert_same( 'https://tv.faithtabernacle.com/webtv-assets/logo.png', $out['church_logo'] );
}

function test_settings_migrate_1_1_site_with_another_account() {
	$out = ftvs_t_migrated( array( 'account_id' => 'Some-Other-Church' ) );
	assert_same( 'gideo', $out['source'] );
	assert_false( isset( $out['church_name'] ), 'only Faith Tabernacle gets its name filled in' );
}

function test_settings_migrate_1_1_site_without_account_stays_unconnected() {
	$out = ftvs_t_migrated( array( 'accent' => '#123456' ) );
	assert_same( '', $out['source'] );
}

function test_settings_migrate_leaves_current_settings_alone() {
	$out = ftvs_t_migrated(
		array(
			'source'     => 'youtube',
			'account_id' => 'Faith-Tabernacle-1',
		)
	);
	assert_same( null, $out, 'settings that already have a source are not rewritten' );
}

function test_settings_series_steps_from_form_rows_and_saved_maps() {
	$rows = FTVS_Settings::series_steps(
		array(
			array( 'series' => 'marriage', 'label' => 'Marriage retreat', 'url' => 'https://example.org/retreat' ),
			array( 'series' => '', 'label' => 'No series', 'url' => 'https://example.org/x' ),
			array( 'series' => 'kids-rock', 'label' => '', 'url' => 'https://example.org/kids' ),
			array( 'series' => 'bad<id>', 'label' => 'Odd id', 'url' => 'https://example.org/odd' ),
		)
	);
	assert_same( array( 'marriage', 'badid' ), array_keys( $rows ), 'rows without a series or a label are dropped; ids are cleaned' );
	assert_same( 'Marriage retreat', $rows['marriage']['label'] );
	$again = FTVS_Settings::series_steps( $rows );
	assert_same( $rows, $again, 'a saved map passes through unchanged' );
	assert_same( array(), FTVS_Settings::series_steps( array( array( 'series' => 'x', 'label' => 'Bad', 'url' => 'javascript:alert(1)' ) ) ) );
}

function test_settings_checkin_is_on_by_default_and_a_toggle() {
	assert_same( 1, FTVS_Settings::defaults()['checkin'] );
	ftvs_t_settings( array() );
	$out = FTVS_Settings::sanitize( array( 'checkin' => '0' ) );
	assert_same( 0, $out['checkin'] );
}
