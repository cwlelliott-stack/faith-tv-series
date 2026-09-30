<?php
/**
 * The plugin as a whole: version numbers, requirements, what is loaded, and the translations.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ftvs_t_readme() {
	return (string) file_get_contents( FTVS_DIR . 'readme.txt' ); // phpcs:ignore
}

/* ---------------------------------------------------------------- version and requirements */

function test_plugin_version_matches_in_the_header_the_constant_and_the_readme() {
	$header = get_file_data( FTVS_FILE, array( 'version' => 'Version' ) );
	preg_match( '/^Stable tag:\s*(\S+)/mi', ftvs_t_readme(), $stable );
	assert_matches( '/^\d+(\.\d+){1,3}$/', FTVS_VERSION );
	assert_same( FTVS_VERSION, $header['version'], 'plugin header Version' );
	assert_same( FTVS_VERSION, $stable[1], 'readme.txt Stable tag' );
}

function test_plugin_readme_and_header_agree_on_requirements() {
	$header = get_file_data(
		FTVS_FILE,
		array(
			'php' => 'Requires PHP',
			'wp'  => 'Requires at least',
		)
	);
	preg_match( '/^Requires PHP:\s*(\S+)/mi', ftvs_t_readme(), $php );
	preg_match( '/^Requires at least:\s*(\S+)/mi', ftvs_t_readme(), $wp );
	assert_same( $header['php'], $php[1] );
	assert_same( $header['wp'], $wp[1] );
	assert_true( version_compare( PHP_VERSION, $header['php'], '>=' ), 'this site meets the PHP requirement' );
	assert_true( version_compare( get_bloginfo( 'version' ), $header['wp'], '>=' ), 'and the WordPress one' );
}

function test_plugin_readme_tested_up_to_is_a_version() {
	preg_match( '/^Tested up to:\s*(\S+)/mi', ftvs_t_readme(), $tested );
	assert_matches( '/^\d+\.\d+(\.\d+)?$/', $tested[1] );
}

function test_plugin_header() {
	$data = get_file_data(
		FTVS_FILE,
		array(
			'name'   => 'Plugin Name',
			'domain' => 'Text Domain',
			'path'   => 'Domain Path',
			'update' => 'Update URI',
			'author' => 'Author',
		)
	);
	assert_same( 'Faith TV Series', $data['name'] );
	assert_same( 'faith-tv-series', $data['domain'] );
	assert_same( basename( dirname( FTVS_FILE ) ), $data['domain'], 'the text domain is the plugin folder name, as WordPress expects' );
	assert_same( '/languages', $data['path'] );
	assert_true( is_dir( FTVS_DIR . 'languages' ) );
	assert_not_same( '', $data['update'] );
	assert_not_same( '', $data['author'] );
}

/* ---------------------------------------------------------------- what gets loaded */

function test_plugin_loads_every_class() {
	foreach ( array( 'Settings', 'Cache', 'Purge', 'Catalog', 'Gideo_Client', 'FaithStream_Client', 'YouTube_Client', 'Demo_Client', 'Manual', 'Watch', 'Live', 'Stats', 'Followup', 'Health', 'Renderer', 'Rest', 'Admin', 'Embed', 'Podcast', 'Blocks' ) as $class ) {
		assert_true( class_exists( 'FTVS_' . $class ), 'FTVS_' . $class );
	}
	assert_true( defined( 'FTVS_DIR' ) && is_dir( FTVS_DIR . 'includes' ) );
}

function test_plugin_hooks_are_in_place() {
	assert_true( false !== has_action( 'rest_api_init', array( 'FTVS_Rest', 'register_routes' ) ) );
	assert_true( false !== has_action( 'ftvs_gone', array( 'FTVS_Catalog', 'on_gone' ) ) );
	assert_true( false !== has_action( 'ftvs_cache_changed', array( 'FTVS_Catalog', 'on_changed' ) ) );
	assert_true( false !== has_action( FTVS_Cache::CRON, array( 'FTVS_Cache', 'run_queue' ) ) );
	assert_true( false !== has_action( 'ftvs_new_videos', array( 'FTVS_Followup', 'new_videos' ) ) );
	assert_true( false !== has_action( 'wp_footer', array( 'FTVS_Live', 'bar' ) ) );
	assert_true( false !== has_filter( 'the_content', array( 'FTVS_Watch', 'content' ) ) );
}

function test_plugin_registers_the_frontend_assets() {
	assert_true( wp_style_is( 'faith-tv-series', 'registered' ) );
	assert_true( wp_script_is( 'faith-tv-series', 'registered' ) );
	foreach ( array( 'assets/faith-tv-series.js', 'assets/faith-tv-series.css', 'assets/embed.js', 'assets/admin.js', 'assets/blocks.js', 'assets/vendor/hls.min.js' ) as $file ) {
		assert_true( is_readable( FTVS_DIR . $file ), $file );
	}
}

function test_plugin_frontend_script_gets_its_settings_before_it_runs() {
	$inline = wp_scripts()->get_data( 'faith-tv-series', 'before' );
	$js     = is_array( $inline ) ? implode( "\n", $inline ) : '';
	assert_contains( 'window.FTVS_CONFIG = ', $js );
	preg_match( '/window\.FTVS_CONFIG = (\{.*\});/s', $js, $m );
	$config = json_decode( $m[1], true );
	assert_true( is_array( $config ), 'the config is valid JSON' );
	assert_same( esc_url_raw( rest_url( 'faith-tv/v1/' ) ), $config['rest'] );
	foreach ( array( 'hls', 'powered', 'brand', 'share', 'resume', 'upnext', 'count', 'next', 'strings' ) as $key ) {
		assert_has_key( $key, $config );
	}
	assert_true( count( $config['strings'] ) > 40, 'the player\'s words are all passed along for translation' );
}

/* ---------------------------------------------------------------- translations */

function ftvs_t_lang_files( $extension ) {
	$files = glob( FTVS_DIR . 'languages/*.' . $extension );
	sort( $files );
	return $files;
}

function ftvs_t_placeholders( $text ) {
	preg_match_all( '/%(?:\d+\$)?[-+0 #]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', str_replace( '%%', '', (string) $text ), $m );
	$found = $m[0];
	sort( $found );
	return $found;
}

function ftvs_t_locale_of( $file ) {
	return preg_replace( '/^faith-tv-series-|\.(mo|po)$/', '', basename( $file ) );
}

function test_translations_are_shipped() {
	assert_true( is_readable( FTVS_DIR . 'languages/faith-tv-series.pot' ), 'the template' );
	assert_true( count( ftvs_t_lang_files( 'mo' ) ) >= 1, 'compiled translations' );
	foreach ( ftvs_t_lang_files( 'po' ) as $po ) {
		assert_true( is_readable( preg_replace( '/\.po$/', '.mo', $po ) ), basename( $po ) . ' has its compiled .mo' );
	}
}

function test_translations_mo_files_load_and_name_their_language() {
	require_once ABSPATH . WPINC . '/pomo/mo.php';
	foreach ( ftvs_t_lang_files( 'mo' ) as $file ) {
		$mo = new MO();
		assert_true( $mo->import_from_file( $file ), basename( $file ) . ' can be read' );
		assert_true( count( $mo->entries ) > 300, basename( $file ) . ' has translations (' . count( $mo->entries ) . ')' );
		assert_same( ftvs_t_locale_of( $file ), $mo->headers['Language'], basename( $file ) . ' names its own language' );
		assert_matches( '/nplurals=\d+;/', $mo->headers['Plural-Forms'], basename( $file ) . ' says how plurals work' );
		assert_matches( '/charset=UTF-8/i', $mo->headers['Content-Type'] );
	}
}

function test_translations_mo_matches_po() {
	require_once ABSPATH . WPINC . '/pomo/mo.php';
	require_once ABSPATH . WPINC . '/pomo/po.php';
	foreach ( ftvs_t_lang_files( 'po' ) as $po_file ) {
		$po = new PO();
		assert_true( $po->import_from_file( $po_file ), basename( $po_file ) . ' parses' );
		$mo = new MO();
		$mo->import_from_file( preg_replace( '/\.po$/', '.mo', $po_file ) );
		foreach ( $po->entries as $key => $entry ) {
			$translated = array_filter( $entry->translations, 'strlen' );
			$fuzzy      = in_array( 'fuzzy', (array) $entry->flags, true );
			if ( '' === $entry->singular || $fuzzy || ! $translated ) {
				continue;
			}
			assert_has_key( $key, $mo->entries, basename( $po_file ) . ': "' . $entry->singular . '" is translated in the .po but missing from the .mo (recompile it)' );
			assert_same( $entry->translations, $mo->entries[ $key ]->translations, basename( $po_file ) . ': ' . $entry->singular );
		}
	}
}

function test_translations_keep_the_placeholders() {
	require_once ABSPATH . WPINC . '/pomo/po.php';
	foreach ( ftvs_t_lang_files( 'po' ) as $po_file ) {
		$po = new PO();
		$po->import_from_file( $po_file );
		foreach ( $po->entries as $entry ) {
			if ( '' === $entry->singular ) {
				continue;
			}
			foreach ( $entry->translations as $n => $translation ) {
				if ( '' === $translation ) {
					continue;
				}
				$source = ( $n > 0 && $entry->is_plural ) ? $entry->plural : $entry->singular;
				$want   = ftvs_t_placeholders( $source );
				$have   = ftvs_t_placeholders( $translation );
				if ( 0 === $n && $entry->is_plural && ! $have ) {
					continue; // "Un episodio" for the one-episode form is fine: the number is not needed
				}
				assert_same( $want, $have, basename( $po_file ) . ': the %s/%d placeholders of "' . $source . '" must survive in "' . $translation . '"' );
			}
		}
	}
}

function test_translations_are_complete_enough_to_ship() {
	require_once ABSPATH . WPINC . '/pomo/po.php';
	foreach ( ftvs_t_lang_files( 'po' ) as $po_file ) {
		$po    = new PO();
		$po->import_from_file( $po_file );
		$total = 0;
		$done  = 0;
		foreach ( $po->entries as $entry ) {
			if ( '' === $entry->singular ) {
				continue;
			}
			++$total;
			if ( array_filter( $entry->translations, 'strlen' ) ) {
				++$done;
			}
		}
		assert_true( $total > 400, 'the template has strings (' . $total . ')' );
		assert_true( $done / $total >= 0.95, basename( $po_file ) . ': ' . $done . ' of ' . $total . ' strings translated' );
	}
}

function test_translations_template_covers_every_string_in_the_source() {
	require_once ABSPATH . WPINC . '/pomo/po.php';
	$pot = new PO();
	assert_true( $pot->import_from_file( FTVS_DIR . 'languages/faith-tv-series.pot' ) );
	$known = array();
	foreach ( $pot->entries as $entry ) {
		$known[ $entry->singular ] = true;
		if ( $entry->is_plural ) {
			$known[ $entry->plural ] = true;
		}
	}
	$one    = <<<'RE'
~\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'faith-tv-series'~s
RE;
	$plural = <<<'RE'
~\b_n\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'((?:[^'\\]|\\.)*)'\s*,\s*[^,]+,\s*'faith-tv-series'~s
RE;
	$missing = array();
	$files   = glob( FTVS_DIR . 'includes/*.php' );
	$files[] = FTVS_FILE;
	foreach ( $files as $file ) {
		$source = (string) file_get_contents( $file ); // phpcs:ignore
		$found  = array();
		if ( preg_match_all( $one, $source, $m ) ) {
			$found = array_merge( $found, $m[1] );
		}
		if ( preg_match_all( $plural, $source, $m ) ) {
			$found = array_merge( $found, $m[1], $m[2] );
		}
		foreach ( $found as $text ) {
			$text = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $text );
			if ( ! isset( $known[ $text ] ) ) {
				$missing[ $text ] = basename( $file );
			}
		}
	}
	assert_same( array(), $missing, 'strings in the code that faith-tv-series.pot does not list (regenerate the template)' );
}

function test_translations_json_for_the_block_editor() {
	$files = ftvs_t_lang_files( 'json' );
	assert_true( count( $files ) >= 1, 'a JSON file for the editor script' );
	foreach ( $files as $file ) {
		$name = basename( $file );
		assert_matches( '/^faith-tv-series-([a-z]{2,3}_[A-Z]{2})-([0-9a-f]{32})\.json$/', $name );
		preg_match( '/^faith-tv-series-([a-z]{2,3}_[A-Z]{2})-([0-9a-f]{32})\.json$/', $name, $m );
		assert_same( md5( 'assets/blocks.js' ), $m[2], $name . ': WordPress looks the file up by the md5 of the script\'s path inside the plugin' );
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore
		assert_true( is_array( $data ), $name . ' is valid JSON' );
		assert_same( 'assets/blocks.js', $data['source'] );
		assert_same( 'messages', $data['domain'] );
		assert_same( $m[1], $data['locale_data']['messages']['']['lang'] );
		assert_true( count( $data['locale_data']['messages'] ) > 10 );
	}
	assert_true( is_readable( FTVS_DIR . 'assets/blocks.js' ), 'the script the JSON belongs to' );
}

function test_translations_show_up_when_the_site_language_is_spanish() {
	$mo = FTVS_DIR . 'languages/faith-tv-series-es_ES.mo';
	if ( ! is_readable( $mo ) ) {
		ftvs_skip( 'no Spanish translation shipped' );
	}
	ftvs_t_cleanup(
		function () {
			unload_textdomain( 'faith-tv-series' );
		}
	);
	unload_textdomain( 'faith-tv-series' );
	assert_true( load_textdomain( 'faith-tv-series', $mo ) );
	assert_same( 'Vitrina', __( 'Showcase', 'faith-tv-series' ) );
	assert_not_same( 'Watch now', __( 'Watch now', 'faith-tv-series' ), 'the buttons on the site are translated' );
	assert_same( '1 episodio', sprintf( _n( '%d episode', '%d episodes', 1, 'faith-tv-series' ), 1 ) );
	assert_same( '3 episodios', sprintf( _n( '%d episode', '%d episodes', 3, 'faith-tv-series' ), 3 ) );
}
