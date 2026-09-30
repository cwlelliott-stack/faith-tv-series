<?php
/**
 * FTVS_Renderer: small helpers, then whole sections rendered from the built-in demo church
 * (no network needed; sample sections only show to people who can edit posts).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------- duration */

function test_renderer_duration() {
	assert_same( '', FTVS_Renderer::duration( 0 ) );
	assert_same( '', FTVS_Renderer::duration( -5 ) );
	assert_same( '', FTVS_Renderer::duration( '' ) );
	assert_same( '', FTVS_Renderer::duration( null ) );
	assert_same( '0:01', FTVS_Renderer::duration( 1 ) );
	assert_same( '0:59', FTVS_Renderer::duration( 59 ) );
	assert_same( '1:00', FTVS_Renderer::duration( 60 ) );
	assert_same( '1:05', FTVS_Renderer::duration( 65 ) );
	assert_same( '59:59', FTVS_Renderer::duration( 3599 ) );
	assert_same( '1:00:00', FTVS_Renderer::duration( 3600 ) );
	assert_same( '1:01:01', FTVS_Renderer::duration( 3661 ) );
	assert_same( '10:00:00', FTVS_Renderer::duration( 36000 ) );
	assert_same( '1:30', FTVS_Renderer::duration( '90' ), 'a numeric string works' );
	assert_same( '37:00', FTVS_Renderer::duration( 2220.542 ), 'seconds are cut, not rounded' );
}

/* ---------------------------------------------------------------- colors */

function test_renderer_contrast_ratio() {
	assert_near( 21, FTVS_Renderer::contrast( '#000000', '#FFFFFF' ), 0.001 );
	assert_near( 21, FTVS_Renderer::contrast( '#FFFFFF', '#000000' ), 0.001, 'order does not matter' );
	assert_near( 1, FTVS_Renderer::contrast( '#FFFFFF', '#FFFFFF' ), 0.001 );
	assert_near( 1, FTVS_Renderer::contrast( '#336699', '#336699' ), 0.001 );
	assert_near( FTVS_Renderer::contrast( '#FFF', '#000' ), FTVS_Renderer::contrast( '#FFFFFF', '#000000' ), 0.001, 'short hex works' );
}

function test_renderer_on_color_white_text_on_the_default_red() {
	assert_same( '#FFFFFF', FTVS_Renderer::on_color( '#C40D3C' ) );
	assert_true( FTVS_Renderer::contrast( '#C40D3C', '#FFFFFF' ) >= 4.5, 'the default accent passes WCAG AA with white text' );
}

function test_renderer_on_color_dark_text_on_light_colors() {
	assert_same( '#111114', FTVS_Renderer::on_color( '#FFF3B0' ), 'light yellow' );
	assert_same( '#111114', FTVS_Renderer::on_color( '#FFFFFF' ) );
	assert_same( '#111114', FTVS_Renderer::on_color( '#FFF' ) );
	assert_same( '#111114', FTVS_Renderer::on_color( '#FFD54F' ), 'amber' );
	assert_same( '#111114', FTVS_Renderer::on_color( '#7CFC00' ), 'lawn green' );
}

function test_renderer_on_color_white_text_on_dark_colors() {
	assert_same( '#FFFFFF', FTVS_Renderer::on_color( '#000000' ) );
	assert_same( '#FFFFFF', FTVS_Renderer::on_color( '#084073' ), 'navy' );
	assert_same( '#FFFFFF', FTVS_Renderer::on_color( '#1B5E20' ), 'dark green' );
}

function test_renderer_on_color_always_picks_a_readable_answer() {
	// Whatever the church color, the text color is one of the two, and it is the better of the two
	// unless white already meets AA.
	foreach ( array( '#C40D3C', '#FFF3B0', '#808080', '#0A84FF', '#FF9500', '#34C759', '#5856D6', '#FF2D55', '#8E8E93', '#FFCC00' ) as $hex ) {
		$on = FTVS_Renderer::on_color( $hex );
		assert_in_array( $on, array( '#FFFFFF', '#111114' ), $hex );
		$white = FTVS_Renderer::contrast( $hex, '#FFFFFF' );
		$dark  = FTVS_Renderer::contrast( $hex, '#111114' );
		if ( $white >= 4.5 ) {
			assert_same( '#FFFFFF', $on, $hex . ' white meets AA' );
		} else {
			assert_same( $white >= $dark ? '#FFFFFF' : '#111114', $on, $hex . ' picks the higher contrast' );
		}
	}
}

/* ---------------------------------------------------------------- local_time */

function test_renderer_local_time_uses_the_site_time_zone() {
	ftvs_t_timezone( 'UTC' );
	assert_same( gmmktime( 0, 0, 0, 4, 5, 2026 ), FTVS_Renderer::local_time( '2026-04-05' ) );
	assert_same( gmmktime( 18, 0, 0, 4, 5, 2026 ), FTVS_Renderer::local_time( '2026-04-05 18:00' ) );

	ftvs_t_timezone( 'America/Chicago' ); // UTC-5 in April (daylight time)
	assert_same( gmmktime( 23, 0, 0, 4, 5, 2026 ), FTVS_Renderer::local_time( '2026-04-05 18:00' ) );
	assert_same( gmmktime( 5, 0, 0, 4, 5, 2026 ), FTVS_Renderer::local_time( '2026-04-05' ) );
	// In January the same zone is UTC-6.
	assert_same( gmmktime( 0, 0, 0, 1, 16, 2026 ), FTVS_Renderer::local_time( '2026-01-15 18:00' ) );
}

function test_renderer_local_time_returns_zero_for_garbage() {
	assert_same( 0, FTVS_Renderer::local_time( 'not a date' ) );
	assert_same( 0, FTVS_Renderer::local_time( '2026-13-45 99:99' ) );
}

/* ---------------------------------------------------------------- item_json / tagged */

function test_renderer_item_json_keeps_only_whitelisted_keys() {
	$json = FTVS_Renderer::item_json(
		array(
			'id'          => 123,
			'parent'      => 'series-1',
			'title'       => 'Part 1',
			'description' => '',
			'image'       => 'https://img.example.test/a.jpg',
			'poster'      => '',
			'length'      => 65,
			'added'       => '2026-01-01T10:00:00Z',
			'speaker'     => 'Pastor Sam',
			'scripture'   => 'John 3:16',
			'watch'       => '',
			'link'        => 'https://stream.example.test/watch/part-1',
			'series'      => 'Hope Rising',
			// None of these belong in the page:
			'hls'         => 'https://secret.example.test/stream.m3u8',
			'audio'       => 'https://secret.example.test/a.mp3',
			'tags'        => array( 'a', 'b' ),
			'live'        => true,
			'related'     => array( array( 'id' => 'x' ) ),
			'internal'    => 'nope',
		)
	);
	$out  = json_decode( $json, true );
	assert_same(
		array(
			'id'        => '123',
			'parent'    => 'series-1',
			'title'     => 'Part 1',
			'image'     => 'https://img.example.test/a.jpg',
			'length'    => 65,
			'added'     => '2026-01-01T10:00:00Z',
			'speaker'   => 'Pastor Sam',
			'scripture' => 'John 3:16',
			'link'      => 'https://stream.example.test/watch/part-1',
			'series'    => 'Hope Rising',
		),
		$out,
		'empty values, arrays and unknown keys are left out; the id is always a string'
	);
	assert_not_contains( 'secret.example.test', $json );
}

function test_renderer_item_json_drops_a_series_list() {
	$out = json_decode(
		FTVS_Renderer::item_json(
			array(
				'id'     => 'v',
				'series' => array( array( 'id' => 's', 'title' => 'S' ) ),
			)
		),
		true
	);
	assert_same( array( 'id' => 'v' ), $out, 'Faith Stream sends series as a list; only the string form is for the page' );
}

function test_renderer_tagged_adds_utm_parameters() {
	$url   = FTVS_Renderer::tagged(
		'https://example.org/visit',
		array(
			'id'     => 'the-way-home-1',
			'parent' => 'the-way-home',
		)
	);
	$parts = wp_parse_url( $url );
	parse_str( $parts['query'], $query );
	assert_same( 'example.org', $parts['host'] );
	assert_same( '/visit', $parts['path'] );
	assert_same(
		array(
			'utm_source'   => 'faith-tv',
			'utm_medium'   => 'website',
			'utm_campaign' => 'the-way-home',
			'utm_content'  => 'the-way-home-1',
		),
		$query
	);
}

function test_renderer_tagged_encodes_values_and_keeps_existing_parameters() {
	$url = FTVS_Renderer::tagged(
		'https://example.org/visit?ref=bulletin&x=a%20b',
		array(
			'id'     => 'video & more/1',
			'parent' => '',
		)
	);
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	assert_same( 'bulletin', $query['ref'] );
	assert_same( 'a b', $query['x'] );
	assert_same( 'video', $query['utm_campaign'], 'a video without a series is tagged "video"' );
	assert_same( 'video & more/1', $query['utm_content'], 'the id survives the round trip' );
	assert_not_contains( ' ', $url );
}

function test_renderer_next_steps_html() {
	ftvs_t_settings(
		array(
			'next_title' => 'Go deeper',
			'next_steps' => array(
				array(
					'label' => 'Plan a visit',
					'url'   => 'https://example.org/visit',
				),
				array(
					'label' => 'Give <now>',
					'url'   => 'https://example.org/give',
				),
			),
		)
	);
	$html = FTVS_Renderer::next_steps_html(
		array(
			'id'     => 'v1',
			'parent' => 's1',
		)
	);
	assert_contains( 'Go deeper', $html );
	assert_contains( 'data-ftvs-next-step', $html );
	assert_contains( 'utm_source=faith-tv', $html );
	assert_contains( 'utm_content=v1', $html );
	assert_contains( 'Give &lt;now&gt;', $html, 'labels are escaped' );
	assert_same( 2, substr_count( $html, 'data-ftvs-next-step' ), 'one button per step' );

	ftvs_t_settings( array( 'next_steps' => array() ) );
	assert_same( '', FTVS_Renderer::next_steps_html( array( 'id' => 'v1' ) ), 'no steps, no box' );
}

/* ---------------------------------------------------------------- text sizes/colors, site css */

function test_renderer_text_vars_from_shortcode_attributes() {
	$css = FTVS_Renderer::text_vars(
		array(
			'heading_size'  => '48',
			'heading_color' => '#fff',
			'meta_size'     => 'huge',
			'card_color'    => 'red',
		)
	);
	assert_same( '--ftvs-heading-size:48px;--ftvs-heading-color:#FFF;', $css );
	assert_same( '', FTVS_Renderer::text_vars( array() ) );
}

function test_renderer_site_css_uses_accent_and_picks_readable_text() {
	ftvs_t_settings( array( 'accent' => '#C40D3C' ) );
	$css = FTVS_Renderer::site_css();
	assert_contains( '--ftvs-accent:#C40D3C', $css );
	assert_contains( '--ftvs-on-accent:#FFFFFF', $css );

	ftvs_t_settings( array( 'accent' => '#FFF3B0' ) );
	assert_contains( '--ftvs-on-accent:#111114', FTVS_Renderer::site_css() );

	ftvs_t_settings( array( 'accent' => 'javascript:evil' ) );
	assert_not_contains( '--ftvs-accent', FTVS_Renderer::site_css(), 'an accent that is not a hex color is not printed' );
}

function test_renderer_site_css_text_styles_and_font() {
	ftvs_t_settings(
		array(
			'text' => array(
				'heading' => array(
					'size'  => '40px',
					'color' => '#FFF',
				),
			),
			'font' => 'inherit',
		)
	);
	$css = FTVS_Renderer::site_css();
	assert_contains( '.ftvs{--ftvs-heading-size:40px;--ftvs-heading-color:#FFF;}', $css );
	assert_contains( '--ftvs-font:inherit', $css );

	ftvs_t_settings( array( 'font' => 'brand' ) );
	assert_not_contains( '--ftvs-font', FTVS_Renderer::site_css() );
	assert_not_contains( '.ftvs{', FTVS_Renderer::site_css(), 'no text styles, no rule' );
}

function test_renderer_style_class() {
	foreach ( array( 'bold', 'soft', 'minimal' ) as $style ) {
		ftvs_t_settings( array( 'style' => $style ) );
		assert_same( 'ftvs--style-' . $style, FTVS_Renderer::style_class() );
	}
	ftvs_t_settings( array( 'style' => 'neon' ) );
	assert_same( 'ftvs--style-bold', FTVS_Renderer::style_class(), 'an unknown stored style falls back to bold' );
}

/* ---------------------------------------------------------------- render(): @newest */

function test_renderer_render_newest_from_the_demo_church() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'category'      => '@newest',
			'layout'        => 'row',
			'mobile_layout' => 'same',
		)
	);
	assert_contains( 'data-ftvs', $html );
	assert_contains( 'data-category="@newest"', $html );
	assert_contains( 'data-layout="row"', $html );
	assert_contains( 'ftvs--row', $html );
	assert_contains( 'Sample videos.', $html, 'editors are told these are samples' );
	assert_not_contains( 'ftvs-only-mobile', $html, 'same layout on phones: one copy of the section' );

	$ids = ftvs_t_card_ids( $html );
	// "Newest" shows the 24 newest messages: all of the demo's 24, minus any slots taken by series built by hand.
	assert_count( 24 - min( 24, count( FTVS_Manual::library() ) ), $ids, 'the demo messages are listed' );
	assert_same( array( 'demo-hope-rising-1', 'demo-kids-1', 'demo-kids-2', 'demo-hope-rising-2' ), array_slice( $ids, 0, 4 ), 'newest first; part 2 is a week older' );
	assert_contains( 'Hope Rising, Part 1', $html );
}

function test_renderer_render_respects_limit() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'category'      => '@newest',
			'layout'        => 'grid',
			'mobile_layout' => 'same',
			'limit'         => 5,
		)
	);
	assert_same( 5, substr_count( $html, 'class="ftvs__item"' ), 'five cards, whatever the site also has' );

	$html = FTVS_Renderer::render(
		array(
			'category'      => 'demo-hope-rising',
			'layout'        => 'grid',
			'mobile_layout' => 'same',
			'limit'         => 2,
		)
	);
	assert_same( array( 'demo-hope-rising-1', 'demo-hope-rising-2' ), ftvs_t_card_ids( $html ) );
}

function test_renderer_render_featured_lists_the_first_row_of_series() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'category'      => '@featured',
			'layout'        => 'grid',
			'mobile_layout' => 'same',
		)
	);
	assert_contains( 'data-kind="category"', $html );
	assert_contains( 'Hope Rising', $html );
	assert_contains( '4 episodes', $html );
	assert_count( 6, ftvs_t_card_ids( $html ) );
}

/* ---------------------------------------------------------------- render(): categories */

function test_renderer_render_a_series_by_id() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'category'      => 'demo-hope-rising',
			'layout'        => 'row',
			'mobile_layout' => 'same',
		)
	);
	assert_same( array( 'demo-hope-rising-1', 'demo-hope-rising-2', 'demo-hope-rising-3', 'demo-hope-rising-4' ), ftvs_t_card_ids( $html ) );
	assert_contains( 'data-category="demo-hope-rising"', $html );
	assert_contains( 'data-label="Hope Rising"', $html, 'with no label set, the series name is used' );
	assert_contains( 'data-kind="video"', $html );
	assert_contains( 'Hope Rising, Part 4', $html );
}

function test_renderer_render_a_series_by_name() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'category'      => '  hope   RISING ',
			'layout'        => 'row',
			'mobile_layout' => 'same',
		)
	);
	assert_contains( 'data-category="demo-hope-rising"', $html, 'names match ignoring case and spacing' );
	assert_count( 4, ftvs_t_card_ids( $html ) );
}

function test_renderer_render_a_row_of_series_shows_episode_counts() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'category'      => 'Sermon Series',
			'layout'        => 'grid',
			'mobile_layout' => 'same',
		)
	);
	assert_contains( 'data-kind="category"', $html );
	assert_contains( '4 episodes', $html );
	assert_contains( '5 episodes', $html );
	assert_count( 6, ftvs_t_card_ids( $html ) );
}

function test_renderer_render_label_badge_and_heading() {
	ftvs_t_demo( array( 'label' => 'Site label' ) );
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'category'      => 'demo-hope-rising',
			'layout'        => 'showcase',
			'mobile_layout' => 'same',
			'label'         => 'Our label',
			'badge'         => 'Fresh',
			'eyebrow'       => 'This <em>week</em>',
			'title'         => 'Watch & grow',
		)
	);
	assert_contains( 'data-label="Our label"', $html, 'the shortcode label beats the site label' );
	assert_contains( 'Fresh', $html );
	assert_not_contains( '>New<', $html, 'the badge attribute replaces the site badge' );
	assert_contains( 'This &lt;em&gt;week&lt;/em&gt;', $html, 'eyebrow is escaped' );
	assert_contains( 'Watch &amp; grow', $html );
	assert_contains( 'ftvs__head', $html );

	$html = FTVS_Renderer::render(
		array(
			'category'      => 'demo-hope-rising',
			'layout'        => 'row',
			'mobile_layout' => 'same',
		)
	);
	assert_contains( 'data-label="Site label"', $html, 'with no label of its own the section uses the site label' );
}

function test_renderer_render_text_size_and_color_per_section() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'category'      => 'demo-hope-rising',
			'layout'        => 'row',
			'mobile_layout' => 'same',
			'heading_size'  => '48',
			'heading_color' => '#abc',
			'card_color'    => 'expression(evil)',
		)
	);
	assert_contains( '--ftvs-heading-size:48px;--ftvs-heading-color:#ABC;', $html );
	assert_not_contains( 'evil', $html );
}

function test_renderer_render_theme_from_site_or_shortcode() {
	ftvs_t_demo( array( 'theme' => 'light' ) );
	ftvs_t_admin();
	$args = array(
		'category'      => 'demo-hope-rising',
		'layout'        => 'row',
		'mobile_layout' => 'same',
	);
	assert_contains( 'ftvs--light', FTVS_Renderer::render( $args ) );
	assert_contains( 'ftvs--dark', FTVS_Renderer::render( $args + array( 'theme' => 'dark' ) ) );
	assert_contains( 'ftvs--dark', FTVS_Renderer::render( $args + array( 'theme' => 'purple' ) ), 'anything that is not "light" is dark' );
}

/* ---------------------------------------------------------------- render(): layouts */

function test_renderer_render_each_layout_has_its_own_root_class() {
	ftvs_t_demo();
	ftvs_t_admin();
	foreach ( FTVS_Renderer::LAYOUTS as $layout ) {
		if ( 'library' === $layout ) {
			continue;
		}
		$html = FTVS_Renderer::render(
			array(
				'category'      => 'demo-hope-rising',
				'layout'        => $layout,
				'mobile_layout' => 'same',
			)
		);
		assert_contains( 'ftvs--' . $layout, $html, $layout );
		assert_contains( 'data-layout="' . $layout . '"', $html, $layout );
		assert_contains( 'Hope Rising, Part 1', $html, $layout );
	}
}

function test_renderer_render_uses_the_site_layout_when_the_section_has_none() {
	ftvs_t_demo( array( 'layout' => 'grid' ) );
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'category'      => 'demo-hope-rising',
			'mobile_layout' => 'same',
		)
	);
	assert_contains( 'data-layout="grid"', $html );
	$html = FTVS_Renderer::render(
		array(
			'category'      => 'demo-hope-rising',
			'layout'        => 'nonsense',
			'mobile_layout' => 'same',
		)
	);
	assert_contains( 'data-layout="grid"', $html, 'an unknown layout falls back to the site layout' );
}

function test_renderer_render_sends_phones_a_different_layout_by_default() {
	ftvs_t_demo( array( 'layout' => 'showcase' ) ); // mobile_layout "auto"
	ftvs_t_admin();
	$html = FTVS_Renderer::render( array( 'category' => 'demo-hope-rising' ) );
	assert_contains( 'ftvs-switch', $html );
	assert_contains( 'ftvs-only-desktop', $html );
	assert_contains( 'ftvs-only-mobile', $html );
	assert_contains( 'data-layout="showcase"', $html );
	assert_contains( 'data-layout="list"', $html, 'the auto phone layout for the showcase is the featured list' );

	$html = FTVS_Renderer::render(
		array(
			'category'      => 'demo-hope-rising',
			'mobile_layout' => 'grid',
		)
	);
	assert_contains( 'data-layout="grid"', $html, 'a section can pick its own phone layout' );

	ftvs_t_demo( array( 'layout' => 'row' ) );
	$html = FTVS_Renderer::render( array( 'category' => 'demo-hope-rising' ) );
	assert_not_contains( 'ftvs-only-mobile', $html, 'a row is already fine on a phone' );
}

function test_renderer_render_library_layout_goes_to_the_library() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render( array( 'layout' => 'library' ) );
	assert_contains( 'ftvs--library', $html );
	assert_contains( 'data-ftvs-lib-list', $html );
}

function test_renderer_render_one_video() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render( array( 'video' => 'demo-hope-rising-2' ) );
	assert_contains( 'data-layout="player"', $html );
	assert_contains( 'ftvs--player', $html );
	assert_contains( 'Hope Rising, Part 2', $html );
	assert_same( array( 'demo-hope-rising-2' ), ftvs_t_card_ids( $html ) );
}

function test_renderer_render_one_video_that_does_not_exist() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render( array( 'video' => 'demo-no-such-thing-9' ) );
	assert_contains( 'ftvs-notice', $html );
	assert_contains( 'No video on your channel has the ID', $html );
}

/* ---------------------------------------------------------------- render(): who sees what */

function test_renderer_sample_sections_are_hidden_from_visitors() {
	ftvs_t_demo();
	$html = FTVS_Renderer::render( array( 'category' => '@newest' ) );
	assert_same( '<!-- Faith TV Series: sample videos are shown only to editors -->', $html );
	assert_same( '', FTVS_Renderer::library( array() ), 'the library too' );
	assert_same( '', FTVS_Renderer::live( array() ), 'and the live block' );
}

function test_renderer_problems_are_a_comment_for_visitors_and_a_note_for_editors() {
	// No church connected (the default settings).
	$html = FTVS_Renderer::render( array( 'category' => '@newest' ) );
	assert_matches( '/^<!-- Faith TV Series: .*-->$/s', $html, 'visitors get an HTML comment only' );
	assert_not_contains( 'ftvs-notice', $html );

	ftvs_t_admin();
	$html = FTVS_Renderer::render( array( 'category' => '@newest' ) );
	assert_contains( 'ftvs-notice', $html );
	assert_contains( 'No church is connected yet', $html );
	assert_contains( 'Only people who can edit this page see this note.', $html );
}

function test_renderer_unknown_category_name_explains_itself_to_editors() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render( array( 'category' => 'Nothing Like This' ) );
	assert_contains( 'ftvs-notice', $html );
	assert_contains( 'No category on your channel is named &quot;Nothing Like This&quot;', str_replace( '"', '&quot;', $html ) );
}

function test_renderer_problem_escapes_the_message() {
	ftvs_t_admin();
	$html = FTVS_Renderer::problem( new WP_Error( 'x', 'bad <script>alert(1)</script> & worse' ) );
	assert_not_contains( '<script>', $html );
	assert_contains( '&lt;script&gt;', $html );
}

/* ---------------------------------------------------------------- render(): scheduling */

function ftvs_t_days( $n ) {
	return gmdate( 'Y-m-d', time() + $n * DAY_IN_SECONDS );
}

function test_renderer_schedule_before_from_shows_nothing() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render( array( 'category' => 'demo-hope-rising', 'from' => ftvs_t_days( 30 ) ) );
	assert_same( '<!-- Faith TV Series: this section is scheduled for other dates -->', $html );
}

function test_renderer_schedule_after_until_shows_nothing() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render( array( 'category' => 'demo-hope-rising', 'until' => ftvs_t_days( -30 ) ) );
	assert_same( '<!-- Faith TV Series: this section is scheduled for other dates -->', $html );
}

function test_renderer_schedule_inside_the_window_shows_the_section() {
	ftvs_t_demo();
	ftvs_t_admin();
	$args = array(
		'category'      => 'demo-hope-rising',
		'layout'        => 'row',
		'mobile_layout' => 'same',
	);
	$both = FTVS_Renderer::render( $args + array( 'from' => ftvs_t_days( -30 ), 'until' => ftvs_t_days( 30 ) ) );
	assert_contains( 'data-category="demo-hope-rising"', $both );
	assert_contains( 'data-category="demo-hope-rising"', FTVS_Renderer::render( $args + array( 'from' => ftvs_t_days( -1 ) ) ), 'only a start date, already past' );
	assert_contains( 'data-category="demo-hope-rising"', FTVS_Renderer::render( $args + array( 'until' => ftvs_t_days( 2 ) ) ), 'only an end date, still ahead' );
}

function test_renderer_schedule_ignores_dates_it_cannot_read() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'category'      => 'demo-hope-rising',
			'layout'        => 'row',
			'mobile_layout' => 'same',
			'from'          => 'sometime soon',
			'until'         => '31st of never',
		)
	);
	assert_contains( 'data-category="demo-hope-rising"', $html, 'a date that cannot be read is no limit at all' );
}

function test_renderer_schedule_otherwise_shows_another_category() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'category'      => 'demo-hope-rising',
			'layout'        => 'row',
			'mobile_layout' => 'same',
			'from'          => ftvs_t_days( 30 ),
			'otherwise'     => 'demo-rooted',
		)
	);
	assert_contains( 'data-category="demo-rooted"', $html );
	assert_not_contains( 'demo-hope-rising', $html );
	assert_count( 5, ftvs_t_card_ids( $html ) );

	$html = FTVS_Renderer::render(
		array(
			'category'      => 'demo-hope-rising',
			'layout'        => 'row',
			'mobile_layout' => 'same',
			'until'         => ftvs_t_days( -30 ),
			'otherwise'     => '@newest',
		)
	);
	assert_contains( 'data-category="@newest"', $html, 'the otherwise category can be an automatic one' );
}

function test_renderer_schedule_otherwise_replaces_a_single_video_too() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::render(
		array(
			'video'         => 'demo-hope-rising-1',
			'from'          => ftvs_t_days( 30 ),
			'otherwise'     => 'demo-rooted',
			'layout'        => 'row',
			'mobile_layout' => 'same',
		)
	);
	assert_not_contains( 'data-layout="player"', $html );
	assert_contains( 'data-category="demo-rooted"', $html );
}

function test_renderer_schedule_editors_can_preview_a_date() {
	ftvs_t_demo();
	ftvs_t_admin();
	$args = array(
		'category'      => 'demo-hope-rising',
		'layout'        => 'row',
		'mobile_layout' => 'same',
		'from'          => '2020-01-01',
		'until'         => '2020-02-01',
	);
	assert_contains( 'scheduled for other dates', FTVS_Renderer::render( $args ), 'in the past: hidden' );
	$_GET['ftvs_asof'] = '2020-01-15';
	assert_contains( 'data-category="demo-hope-rising"', FTVS_Renderer::render( $args ), '?ftvs_asof= shows the section as it will look on that day' );
	$_GET['ftvs_asof'] = '2020-03-01';
	assert_contains( 'scheduled for other dates', FTVS_Renderer::render( $args ) );
}

function test_renderer_schedule_visitors_cannot_use_asof() {
	// Nothing connected: a visitor asking for a date must not see the section as of that date.
	$_GET['ftvs_asof'] = '2020-01-15';
	$html              = FTVS_Renderer::render(
		array(
			'category' => '@newest',
			'from'     => '2020-01-01',
			'until'    => '2020-02-01',
		)
	);
	assert_contains( 'scheduled for other dates', $html );
}

/* ---------------------------------------------------------------- shortcodes */

function test_renderer_shortcodes_are_registered() {
	foreach ( array( 'faith_tv_series', 'faithstream', 'faith_tv_live', 'faith_tv_library' ) as $tag ) {
		assert_true( shortcode_exists( $tag ), $tag );
	}
}

function test_renderer_shortcode_faith_tv_series_end_to_end() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = do_shortcode( '[faith_tv_series category="demo-hope-rising" layout="grid" mobile_layout="same" limit="2" title="Hi there"]' );
	assert_same( array( 'demo-hope-rising-1', 'demo-hope-rising-2' ), ftvs_t_card_ids( $html ) );
	assert_contains( 'data-layout="grid"', $html );
	assert_contains( 'Hi there', $html );
}

/* ---------------------------------------------------------------- [faithstream] alias */

function test_renderer_faithstream_alias_maps_layout_names() {
	ftvs_t_demo();
	ftvs_t_admin();
	$map = array(
		'carousel' => 'coverflow',
		'slider'   => 'coverflow',
		'grid'     => 'grid',
		'row'      => 'row',
		'list'     => 'list',
		'hero'     => 'showcase',
		'showcase' => 'showcase',
		'CAROUSEL' => 'coverflow',
	);
	foreach ( $map as $from => $to ) {
		$html = FTVS_Renderer::faithstream(
			array(
				'category'      => 'demo-hope-rising',
				'layout'        => $from,
				'mobile_layout' => 'same',
			)
		);
		assert_contains( 'data-layout="' . $to . '"', $html, $from . ' -> ' . $to );
		assert_contains( 'ftvs--' . $to, $html, $from . ' -> ' . $to );
	}
}

function test_renderer_faithstream_alias_carousel_is_coverflow_through_do_shortcode() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = do_shortcode( '[faithstream category="demo-hope-rising" layout="carousel" mobile_layout="same"]' );
	assert_contains( 'data-layout="coverflow"', $html );
	assert_contains( 'ftvs-cf', $html, 'the 3D carousel markup' );
}

function test_renderer_faithstream_alias_unknown_or_missing_layout_uses_the_site_layout() {
	ftvs_t_demo( array( 'layout' => 'grid' ) );
	ftvs_t_admin();
	foreach ( array( array( 'layout' => 'weird' ), array() ) as $extra ) {
		$html = FTVS_Renderer::faithstream(
			$extra + array(
				'category'      => 'demo-hope-rising',
				'mobile_layout' => 'same',
			)
		);
		assert_contains( 'data-layout="grid"', $html );
	}
}

function test_renderer_faithstream_alias_passes_other_attributes_through() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::faithstream(
		array(
			'category'      => 'demo-hope-rising',
			'layout'        => 'grid',
			'mobile_layout' => 'same',
			'limit'         => '1',
			'theme'         => 'light',
			'bogus'         => 'ignored',
		)
	);
	assert_count( 1, ftvs_t_card_ids( $html ) );
	assert_contains( 'ftvs--light', $html );
	assert_not_contains( 'ignored', $html );
}

function test_renderer_faithstream_alias_library_and_live() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::faithstream( array( 'library' => 'all' ) );
	assert_contains( 'ftvs--library', $html );
	assert_contains( 'data-category=""', $html, 'library="all" is the whole channel' );

	$html = FTVS_Renderer::faithstream( array( 'library' => 'demo-kids' ) );
	assert_contains( 'data-category="demo-kids"', $html );
	assert_contains( 'data-total="2"', $html, 'only the messages in that category' );

	// Live with nothing set up: the editor is told what to do.
	$html = FTVS_Renderer::faithstream( array( 'live' => 'main' ) );
	assert_contains( 'Add your service times or live link', $html );
}

function test_renderer_faithstream_alias_tolerates_no_attributes() {
	ftvs_t_admin();
	$html = FTVS_Renderer::faithstream( '' ); // WordPress passes "" for a bare [faithstream]
	assert_contains( 'Pick a category.', $html );
}

/* ---------------------------------------------------------------- library */

function test_renderer_library_lists_every_message_newest_first() {
	ftvs_t_demo();
	ftvs_t_admin();
	$total = 0;
	foreach ( FTVS_Demo_Client::get_children( 'demo-sermon-series' )['categories'] as $series ) {
		$total += $series['videos'];
	}
	$total += FTVS_Demo_Client::get_children( '' )['categories'][1]['videos'];

	$total += count( FTVS_Manual::library() ); // series built by hand on this site, if any

	$html = FTVS_Renderer::library( array() );
	assert_contains( 'data-total="' . $total . '"', $html );
	assert_contains( 'data-per="24"', $html );
	$ids = ftvs_t_card_ids( $html );
	assert_same( 'demo-hope-rising-1', $ids[0] );
	assert_true( count( $ids ) <= 24 );
	assert_contains( 'name="speaker"', $html, 'more than one speaker: the speaker filter shows' );
	assert_contains( 'data-ftvs-lib-form', $html );
}

function test_renderer_library_can_be_limited_to_a_category() {
	ftvs_t_demo();
	ftvs_t_admin();
	$html = FTVS_Renderer::library( array( 'category' => 'demo-rooted' ) );
	assert_contains( 'data-total="5"', $html );
	assert_contains( 'data-category="demo-rooted"', $html );
	assert_same( array( 'demo-rooted-1', 'demo-rooted-2', 'demo-rooted-3', 'demo-rooted-4', 'demo-rooted-5' ), ftvs_t_card_ids( $html ), 'newest first (the demo dates each part a week apart)' );
}

function test_renderer_library_page_size_is_clamped() {
	ftvs_t_demo();
	ftvs_t_admin();
	assert_contains( 'data-per="6"', FTVS_Renderer::library( array( 'per' => 1 ) ) );
	assert_contains( 'data-per="48"', FTVS_Renderer::library( array( 'per' => 500 ) ) );
	assert_contains( 'data-per="10"', FTVS_Renderer::library( array( 'per' => 10 ) ) );
	assert_contains( 'data-ftvs-lib-more', FTVS_Renderer::library( array( 'per' => 6 ) ), 'more messages than fit on a page: a "Load more" button' );
}
