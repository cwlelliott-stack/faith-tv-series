<?php
/**
 * FTVS_Watch (a page for every message) and the sitemap that lists those pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Sets a private static of FTVS_Watch and puts it back when the test ends. */
function ftvs_t_watch_static( $name, $value, $reset ) {
	$property = new ReflectionProperty( 'FTVS_Watch', $name );
	if ( PHP_VERSION_ID < 80100 ) {
		$property->setAccessible( true );
	}
	$property->setValue( null, $value ); // (null, value): the one-argument form of a static is deprecated in PHP 8.3
	ftvs_t_cleanup(
		function () use ( $property, $reset ) {
			$property->setValue( null, $reset );
		}
	);
}

function ftvs_t_watch_video( $extra = array() ) {
	return array_merge(
		array(
			'id'          => 'the-way-home-1',
			'parent'      => 'the-way-home',
			'series'      => 'The Way Home',
			'title'       => 'Coming Home',
			'description' => 'A short talk about coming home.',
			'image'       => 'https://img.example.test/card.jpg',
			'poster'      => 'https://img.example.test/big.jpg',
			'length'      => 1815,
			'added'       => '2026-09-20T16:00:00Z',
			'live'        => false,
			'speaker'     => 'Pastor Sam',
			'scripture'   => 'Luke 15:11-32',
			'tags'        => array(),
		),
		$extra
	);
}

/** The JSON-LD block a page head printed. */
function ftvs_t_json_ld( $html ) {
	preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m );
	return isset( $m[1] ) ? json_decode( $m[1], true ) : null;
}

function ftvs_t_head_of( $video ) {
	ftvs_t_watch_static( 'video', $video, null );
	ob_start();
	FTVS_Watch::head();
	return ob_get_clean();
}

/* ---------------------------------------------------------------- the Watch page and its addresses */

function test_watch_page_id_needs_a_published_page() {
	$page = ftvs_t_page();
	assert_same( 0, FTVS_Watch::page_id(), 'nothing chosen' );
	ftvs_t_settings( array( 'watch_page_id' => $page->ID ) );
	assert_same( $page->ID, FTVS_Watch::page_id() );
	ftvs_t_settings( array( 'watch_page_id' => 999999999 ) );
	assert_same( 0, FTVS_Watch::page_id(), 'a page that was deleted' );
	$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1 ) );
	if ( $posts ) {
		ftvs_t_settings( array( 'watch_page_id' => $posts[0]->ID ) );
		assert_same( 0, FTVS_Watch::page_id(), 'a blog post is not a page' );
	}
}

function test_watch_url_with_pretty_permalinks() {
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'watch_page_id' => $page->ID ) );
	ftvs_t_permalinks( '/%postname%/' );
	$url = FTVS_Watch::url( 'the-way-home-1' );
	assert_matches( '#^' . preg_quote( home_url(), '#' ) . '/' . preg_quote( get_page_uri( $page ), '#' ) . '/the-way-home-1/?$#', $url );
	assert_contains( '/a%20b', FTVS_Watch::url( 'a b' ), 'ids are encoded' );
}

function test_watch_url_with_plain_permalinks() {
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'watch_page_id' => $page->ID ) );
	ftvs_t_permalinks( '' );
	$url = FTVS_Watch::url( 'the-way-home-1' );
	assert_same( home_url( '/' ) . '?page_id=' . $page->ID . '&ftvs_video=the-way-home-1', $url );
}

function test_watch_url_needs_a_watch_page_and_a_real_id() {
	assert_same( '', FTVS_Watch::url( 'the-way-home-1' ), 'no Watch page chosen' );
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'watch_page_id' => $page->ID ) );
	assert_same( '', FTVS_Watch::url( '' ) );
	assert_same( '', FTVS_Watch::url( 123 ) );
	assert_same( '', FTVS_Watch::url( array( 'x' ) ) );
	assert_same( '', FTVS_Watch::url( null ) );
}

function test_watch_registers_its_query_variable_and_shortcode() {
	assert_same( array( 'a', 'ftvs_video' ), FTVS_Watch::query_vars( array( 'a' ) ) );
	assert_true( shortcode_exists( 'faith_tv_watch' ) );
	assert_same( '', FTVS_Watch::shortcode(), 'no message being shown: the shortcode prints nothing' );
}

/* ---------------------------------------------------------------- what search engines and link previews read */

function test_watch_head_prints_canonical_link_social_tags_and_structured_data() {
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'watch_page_id' => $page->ID, 'church_name' => 'Grace Church' ) );
	ftvs_t_permalinks( '/%postname%/' );
	$video = ftvs_t_watch_video();
	$html  = ftvs_t_head_of( $video );
	$url   = FTVS_Watch::url( $video['id'] );

	assert_contains( '<link rel="canonical" href="' . esc_url( $url ) . '">', $html );
	assert_contains( '<meta name="description" content="A short talk about coming home.">', $html );
	assert_contains( '<meta property="og:type" content="video.other">', $html );
	assert_contains( '<meta property="og:title" content="Coming Home">', $html );
	assert_contains( '<meta property="og:url" content="' . esc_attr( $url ) . '">', $html );
	assert_contains( '<meta property="og:image" content="https://img.example.test/big.jpg">', $html, 'the big picture for link previews' );
	assert_contains( '<meta name="twitter:card" content="summary_large_image">', $html );
	assert_contains( '<meta name="twitter:image" content="https://img.example.test/big.jpg">', $html );

	$ld = ftvs_t_json_ld( $html );
	assert_same( 'https://schema.org', $ld['@context'] );
	assert_same( 'VideoObject', $ld['@type'] );
	assert_same( 'Coming Home', $ld['name'] );
	assert_same( 'A short talk about coming home.', $ld['description'] );
	assert_same( array( 'https://img.example.test/big.jpg' ), $ld['thumbnailUrl'] );
	assert_same( '2026-09-20T16:00:00+00:00', $ld['uploadDate'] );
	assert_same( $url, $ld['url'] );
	assert_same( 'PT30M15S', $ld['duration'] );
	assert_same( 'Grace Church', $ld['publisher']['name'] );
}

function test_watch_head_falls_back_to_the_card_picture_and_a_made_up_description() {
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'watch_page_id' => $page->ID, 'church_name' => 'Grace Church' ) );
	$html = ftvs_t_head_of( ftvs_t_watch_video( array( 'description' => '', 'poster' => '', 'length' => 0 ) ) );
	assert_contains( '<meta name="description" content="Watch Coming Home from Grace Church.">', $html );
	assert_contains( '<meta property="og:image" content="https://img.example.test/card.jpg">', $html );
	$ld = ftvs_t_json_ld( $html );
	assert_same( 'Watch Coming Home from Grace Church.', $ld['description'] );
	assert_false( isset( $ld['duration'] ), 'no length known: no duration' );
}

function test_watch_head_trims_a_long_description() {
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'watch_page_id' => $page->ID ) );
	$long = implode( ' ', array_fill( 0, 80, 'word' ) );
	$html = ftvs_t_head_of( ftvs_t_watch_video( array( 'description' => $long ) ) );
	assert_contains( '<meta name="description" content="' . implode( ' ', array_fill( 0, 40, 'word' ) ) . '…">', $html );
}

function test_watch_head_describes_a_video_over_an_hour_long() {
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'watch_page_id' => $page->ID ) );
	$ld = ftvs_t_json_ld( ftvs_t_head_of( ftvs_t_watch_video( array( 'length' => 3661 ) ) ) );
	assert_same( 'PT61M1S', $ld['duration'], 'ISO 8601 allows minutes above 59' );
}

function test_watch_head_escapes_titles_and_cannot_be_closed_early() {
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'watch_page_id' => $page->ID ) );
	$title = '"><script>alert(1)</script> & Co';
	$html  = ftvs_t_head_of( ftvs_t_watch_video( array( 'title' => $title, 'description' => '</script><img src=x onerror=alert(2)>' ) ) );
	assert_not_contains( '<script>alert(1)', $html );
	assert_not_contains( '<img src=x', $html );
	assert_contains( 'content="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co"', $html );
	assert_same( 1, substr_count( $html, '</script>' ), 'the structured data block ends where the plugin ended it' );
	$ld = ftvs_t_json_ld( $html );
	assert_same( $title, $ld['name'], 'and reads back as the original text' );
}

function test_watch_head_says_nothing_when_no_message_is_shown() {
	ob_start();
	FTVS_Watch::head();
	assert_same( '', ob_get_clean() );
}

function test_watch_document_title_is_the_messages_title() {
	assert_same( array( 'title' => 'Watch', 'site' => 'Grace' ), FTVS_Watch::document_title( array( 'title' => 'Watch', 'site' => 'Grace' ) ) );
	ftvs_t_watch_static( 'video', ftvs_t_watch_video(), null );
	assert_same( array( 'title' => 'Coming Home', 'site' => 'Grace' ), FTVS_Watch::document_title( array( 'title' => 'Watch', 'site' => 'Grace' ) ) );
}

function test_watch_page_title_is_swapped_only_inside_the_loop_of_the_watch_page() {
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'watch_page_id' => $page->ID ) );
	ftvs_t_watch_static( 'video', ftvs_t_watch_video(), null );
	ftvs_t_watch_static( 'titled', false, false );
	assert_same( 'Watch', FTVS_Watch::page_title( 'Watch', $page->ID ), 'outside the loop (menus, widgets) it stays' );

	global $wp_query;
	$was = $wp_query->in_the_loop;
	ftvs_t_cleanup(
		function () use ( $was ) {
			$GLOBALS['wp_query']->in_the_loop = $was;
		}
	);
	$wp_query->in_the_loop = true;
	assert_same( 'Watch', FTVS_Watch::page_title( 'Watch', 999999999 ), 'another post\'s title stays' );
	assert_same( 'Coming Home', FTVS_Watch::page_title( 'Watch', $page->ID ) );
	$html = FTVS_Watch::render( ftvs_t_watch_video( array( 'parent' => '' ) ) );
	assert_not_contains( '<h1', $html, 'the theme already printed the title, so it is not repeated' );
}

/* ---------------------------------------------------------------- the message view */

function test_watch_render_shows_the_message() {
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'theme' => 'light', 'share' => 1, 'watch_page_id' => $page->ID ) );
	$html = FTVS_Watch::render( ftvs_t_watch_video( array( 'parent' => '' ) ) );
	assert_contains( 'ftvs ftvs--light', $html );
	assert_contains( 'data-layout="watch"', $html );
	assert_contains( '<h1 class="ftvs-feature__title ftvs-watch__title">Coming Home</h1>', $html );
	assert_contains( 'The Way Home', $html, 'the series name' );
	assert_contains( 'Pastor Sam', $html );
	assert_contains( 'Luke 15:11-32', $html );
	assert_contains( '30:15', $html, 'the length' );
	assert_contains( 'A short talk about coming home.', $html );
	assert_contains( 'data-ftvs-share="' . esc_attr( FTVS_Watch::url( 'the-way-home-1' ) ) . '"', $html );
	assert_same( array( 'the-way-home-1', 'the-way-home-1' ), ftvs_t_card_ids( $html ), 'the picture and the "Watch now" button both open the player' );
	assert_contains( 'src="https://img.example.test/big.jpg"', $html );
}

function test_watch_render_leaves_out_share_when_switched_off_and_escapes_the_description() {
	ftvs_t_settings( array( 'share' => 0 ) );
	$html = FTVS_Watch::render( ftvs_t_watch_video( array( 'parent' => '', 'description' => "Line one\n\n<b>Line</b> two <script>x()</script>" ) ) );
	assert_not_contains( 'data-ftvs-share', $html );
	assert_not_contains( '<script>', $html );
	assert_contains( '&lt;b&gt;Line&lt;/b&gt; two', $html );
	assert_contains( '<p>Line one</p>', $html, 'paragraphs from blank lines' );
}

function test_watch_render_adds_the_churchs_next_steps() {
	ftvs_t_settings(
		array(
			'next_steps' => array(
				array(
					'label' => 'Plan a visit',
					'url'   => 'https://example.org/visit',
				),
			),
		)
	);
	$html = FTVS_Watch::render( ftvs_t_watch_video( array( 'parent' => '' ) ) );
	assert_contains( 'Plan a visit', $html );
	assert_contains( 'utm_campaign', $html );
}

function test_watch_render_lists_more_from_the_same_series() {
	ftvs_t_demo();
	ftvs_t_admin();
	FTVS_Catalog::library();
	$video = FTVS_Catalog::find_video( 'demo-hope-rising-2' );
	$html  = FTVS_Watch::render( $video );
	assert_contains( 'More from Hope Rising', $html );
	assert_same( array( 'demo-hope-rising-2', 'demo-hope-rising-2', 'demo-hope-rising-1', 'demo-hope-rising-2', 'demo-hope-rising-3', 'demo-hope-rising-4' ), ftvs_t_card_ids( $html ), 'the message (picture and button), then its series in a row' );
}

/* ---------------------------------------------------------------- serving a message page */

/** Makes the main query look like a visit to the Watch page at .../<video id>/ . */
function ftvs_t_watch_request( $page, $video_id ) {
	global $wp_query, $wp_the_query;
	$saved = array( $wp_query, $wp_the_query );
	ftvs_t_cleanup(
		function () use ( $saved ) {
			$GLOBALS['wp_query']     = $saved[0];
			$GLOBALS['wp_the_query'] = $saved[1];
			if ( false === has_action( 'wp_head', 'rel_canonical' ) ) {
				add_action( 'wp_head', 'rel_canonical' );
			}
			$GLOBALS['post'] = null;
		}
	);
	foreach ( array( 'wpseo_title', 'wpseo_metadesc', 'wpseo_canonical', 'wpseo_opengraph_image', 'rank_math/frontend/title' ) as $hook ) {
		ftvs_t_guard_hook( $hook );
	}
	$query = new WP_Query( array( 'page_id' => $page->ID ) );
	$query->set( FTVS_Watch::VAR, $video_id );
	$GLOBALS['wp_query']     = $query;
	$GLOBALS['wp_the_query'] = $query;
	return $query;
}

function test_watch_template_redirect_shows_a_known_message() {
	$page = ftvs_t_page();
	ftvs_t_demo( array( 'watch_page_id' => $page->ID ) );
	ftvs_t_admin();
	FTVS_Catalog::library();
	ftvs_t_watch_static( 'video', null, null );
	$query = ftvs_t_watch_request( $page, 'demo-hope-rising-1' );
	FTVS_Watch::template_redirect();
	assert_false( $query->is_404() );
	assert_same( 'Hope Rising, Part 1', FTVS_Watch::document_title( array( 'title' => 'Watch' ) )['title'], 'the page now speaks for the message' );
	assert_false( has_action( 'wp_head', 'rel_canonical' ), 'WordPress\'s own canonical link (the Watch page) is removed; the message prints its own' );
}

function test_watch_template_redirect_unknown_messages_are_a_plain_404() {
	$page = ftvs_t_page();
	ftvs_t_demo( array( 'watch_page_id' => $page->ID ) );
	ftvs_t_admin();
	FTVS_Catalog::library();
	ftvs_t_watch_static( 'video', null, null );
	$query = ftvs_t_watch_request( $page, 'demo-never-heard-of-it-9' );
	FTVS_Watch::template_redirect();
	assert_true( $query->is_404() );
	assert_same( 'Watch', FTVS_Watch::document_title( array( 'title' => 'Watch' ) )['title'] );
}

function test_watch_template_redirect_sample_messages_are_for_editors_only() {
	$page = ftvs_t_page();
	ftvs_t_demo( array( 'watch_page_id' => $page->ID ) );
	FTVS_Catalog::library();
	ftvs_t_watch_static( 'video', null, null );
	$query = ftvs_t_watch_request( $page, 'demo-hope-rising-1' );
	FTVS_Watch::template_redirect();
	assert_true( $query->is_404(), 'a visitor gets a 404 for a sample video' );
}

function test_watch_template_redirect_ignores_other_pages_and_plain_visits() {
	$page = ftvs_t_page();
	ftvs_t_demo( array( 'watch_page_id' => $page->ID ) );
	ftvs_t_admin();
	FTVS_Catalog::library();
	ftvs_t_watch_static( 'video', null, null );
	$query = ftvs_t_watch_request( $page, '' );
	FTVS_Watch::template_redirect();
	assert_false( $query->is_404(), 'the Watch page itself, with no message chosen' );
	assert_same( 'Watch', FTVS_Watch::document_title( array( 'title' => 'Watch' ) )['title'] );
}

function test_watch_content_becomes_the_message_on_the_watch_page_only() {
	$page = ftvs_t_page();
	ftvs_t_demo( array( 'watch_page_id' => $page->ID ) );
	ftvs_t_admin();
	FTVS_Catalog::library();
	ftvs_t_watch_static( 'video', FTVS_Catalog::find_video( 'demo-hope-rising-1' ), null );
	$query = ftvs_t_watch_request( $page, 'demo-hope-rising-1' );
	$GLOBALS['post'] = $page;
	setup_postdata( $page );
	assert_same( 'The page', FTVS_Watch::content( 'The page' ), 'not in the loop (a widget, a menu): the page\'s own text' );
	$query->in_the_loop = true;
	$html               = FTVS_Watch::content( 'The page' );
	assert_contains( 'Hope Rising, Part 1', $html );
	assert_contains( 'data-layout="watch"', $html );
	assert_not_contains( 'The page', $html, 'the page\'s own text is replaced' );
}

/* ---------------------------------------------------------------- sitemap */

function ftvs_t_sitemap() {
	if ( ! class_exists( 'WP_Sitemaps_Provider' ) ) {
		require_once ABSPATH . WPINC . '/sitemaps/class-wp-sitemaps-provider.php';
	}
	require_once FTVS_DIR . 'includes/class-sitemap.php';
	return new FTVS_Sitemap();
}

function test_sitemap_lists_a_page_for_every_message() {
	$page = ftvs_t_page();
	ftvs_t_demo( array( 'watch_page_id' => $page->ID ) );
	ftvs_t_permalinks( '/%postname%/' );
	$sitemap = ftvs_t_sitemap();
	$urls    = $sitemap->get_url_list( 1 );
	$locs    = wp_list_pluck( $urls, 'loc' );
	assert_true( count( $urls ) >= 24 );
	assert_contains( '/demo-hope-rising-1', implode( ' ', $locs ) );
	assert_matches( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $urls[0]['lastmod'], 'ISO 8601 dates' );
	assert_same( array(), $sitemap->get_url_list( 2 ), 'nothing on a second page' );
	assert_same( 1, $sitemap->get_max_num_pages() );
	assert_same( 1000, FTVS_Sitemap::PER_PAGE );
}

function test_sitemap_is_empty_without_a_watch_page_or_a_church() {
	ftvs_t_demo();
	assert_same( array(), ftvs_t_sitemap()->get_url_list( 1 ), 'no Watch page: no message pages to list' );
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'watch_page_id' => $page->ID ) );
	assert_same( array(), ftvs_t_sitemap()->get_url_list( 1 ), 'no church connected' );
	assert_same( 1, ftvs_t_sitemap()->get_max_num_pages(), 'at least one (empty) page' );
}

function test_watch_page_title_is_plain_text() {
	$page = ftvs_t_page();
	ftvs_t_settings( array( 'watch_page_id' => $page->ID ) );
	ftvs_t_watch_static( 'video', ftvs_t_watch_video( array( 'title' => 'Grace <img src=x onerror=alert(1)>' ) ), null );
	ftvs_t_watch_static( 'titled', false, false );
	global $wp_query;
	$was = $wp_query->in_the_loop;
	ftvs_t_cleanup(
		function () use ( $was ) {
			$GLOBALS['wp_query']->in_the_loop = $was;
		}
	);
	$wp_query->in_the_loop = true;
	assert_same( 'Grace &lt;img src=x onerror=alert(1)&gt;', FTVS_Watch::page_title( 'Watch', $page->ID ), 'themes print the title as HTML' );
}
