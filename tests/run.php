<?php
/**
 * A tiny test runner for the Faith TV Series plugin. No Composer, no PHPUnit: it runs inside
 * WordPress with WP-CLI, loads every tests/test-*.php, runs the functions named test_*, prints
 * PASS/FAIL for each and a summary, and exits with 1 if anything failed.
 *
 *   wp eval-file tests/run.php              # everything
 *   wp eval-file tests/run.php cache        # only tests whose name or file contains "cache"
 *
 * (bash tests/run-docker.sh does this against the README's local Docker site.)
 *
 * A test is a function with no arguments that calls assert_* functions (tests/helpers.php).
 * Tests never touch the network and never save settings; see helpers.php. Statuses:
 *
 *   PASS    ok
 *   FAIL    an assertion failed, or the plugin raised a PHP warning/notice, or it tried to
 *           reach the network without a stub
 *   ERROR   the test (or the plugin) threw an exception
 *   SKIP    the test decided it cannot run here (ftvs_skip)
 *   XFAIL   failed, and is marked as a known plugin bug with ftvs_known_bug() (not a failure)
 *   XPASS   marked as a known bug but passes: the bug seems fixed, remove the marker
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this through WP-CLI: wp eval-file tests/run.php\n" );
	exit( 1 );
}

function ftvs_t_halt( $code ) {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::halt( $code );
	}
	exit( $code );
}

if ( ! class_exists( 'FTVS_Settings' ) || ! defined( 'FTVS_VERSION' ) ) {
	echo "The Faith TV Series plugin is not active on this site. Activate it first (wp plugin activate faith-tv-series).\n";
	ftvs_t_halt( 1 );
}

error_reporting( E_ALL ); // phpcs:ignore
require_once __DIR__ . '/helpers.php';

/** PHP warnings and notices raised by the plugin (or a test) fail the test; other code's are ignored. */
function ftvs_t_error_handler( $errno, $errstr, $errfile, $errline ) {
	if ( ! ( error_reporting() & $errno ) ) {
		return true;
	}
	$file   = str_replace( '\\', '/', (string) $errfile );
	$plugin = str_replace( '\\', '/', FTVS_DIR );
	if ( 0 === strpos( $file, $plugin ) || false !== strpos( $file, '/tests/test-' ) ) {
		$GLOBALS['ftvs_t_php_notices'][] = $errstr . ' (' . basename( $file ) . ':' . $errline . ')';
	}
	return true;
}

/** @return array { 0: status, 1: message } */
function ftvs_t_execute( $name ) {
	ftvs_t_reset();
	$GLOBALS['ftvs_t_php_notices'] = array();
	$level                         = ob_get_level();
	$status                        = 'PASS';
	$message                       = '';
	set_error_handler( 'ftvs_t_error_handler' ); // phpcs:ignore
	ob_start();
	try {
		call_user_func( $name );
	} catch ( FTVS_Test_Skipped $e ) {
		$status  = 'SKIP';
		$message = $e->getMessage();
	} catch ( FTVS_Assertion_Failed $e ) {
		$status  = 'FAIL';
		$message = $e->getMessage();
	} catch ( Throwable $e ) {
		$status  = 'ERROR';
		$message = get_class( $e ) . ': ' . $e->getMessage() . ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
	}
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
	restore_error_handler();
	if ( 'PASS' === $status && $GLOBALS['ftvs_t_php_notices'] ) {
		$status  = 'FAIL';
		$message = 'PHP notice/warning: ' . implode( "\n  PHP notice/warning: ", array_unique( $GLOBALS['ftvs_t_php_notices'] ) );
	}
	if ( 'PASS' === $status && $GLOBALS['ftvs_t_http_blocked'] ) {
		$status  = 'FAIL';
		$message = 'request that no stub answered (tests must not use the network): ' . implode( ', ', array_unique( $GLOBALS['ftvs_t_http_blocked'] ) );
	}
	ftvs_t_reset();
	return array( $status, $message );
}

/* ---- find the tests ---- */

$ftvs_filter = getenv( 'FTVS_TEST_FILTER' ) ? (string) getenv( 'FTVS_TEST_FILTER' ) : '';
if ( isset( $args ) && is_array( $args ) && isset( $args[0] ) ) {
	$ftvs_filter = (string) $args[0];
}
$ftvs_strict = '' !== (string) getenv( 'FTVS_STRICT' ) && '0' !== (string) getenv( 'FTVS_STRICT' );
$ftvs_ci     = '' !== (string) getenv( 'GITHUB_ACTIONS' );

// YouTube and series built by hand are switched off on real sites for now; their code is still tested.
add_filter( 'ftvs_extra_sources', '__return_true' );
if ( ! post_type_exists( FTVS_Manual::TYPE ) ) {
	FTVS_Manual::register();
}

$ftvs_files = glob( __DIR__ . '/test-*.php' );
sort( $ftvs_files );
$ftvs_suite = array(); // file => test function names
foreach ( $ftvs_files as $ftvs_file ) {
	$ftvs_before = get_defined_functions()['user'];
	require $ftvs_file;
	$ftvs_new = array_diff( get_defined_functions()['user'], $ftvs_before );
	foreach ( $ftvs_new as $ftvs_fn ) {
		if ( 0 !== strpos( $ftvs_fn, 'test_' ) ) {
			continue;
		}
		if ( '' === $ftvs_filter || false !== stripos( $ftvs_fn, $ftvs_filter ) || false !== stripos( basename( $ftvs_file ), $ftvs_filter ) ) {
			$ftvs_suite[ basename( $ftvs_file ) ][] = $ftvs_fn;
		}
	}
}

/* ---- run them ---- */

ftvs_t_snapshot();
register_shutdown_function( 'ftvs_t_restore_site' ); // put the site back even if PHP dies half-way

echo 'Faith TV Series tests | plugin ' . FTVS_VERSION . ' | WordPress ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION . ' | site ' . home_url() . ( '' !== $ftvs_filter ? ' | filter "' . $ftvs_filter . '"' : '' ) . "\n";

$ftvs_count  = array(
	'PASS'  => 0,
	'FAIL'  => 0,
	'ERROR' => 0,
	'SKIP'  => 0,
	'XFAIL' => 0,
	'XPASS' => 0,
);
$ftvs_bugs   = array();
$ftvs_failed = array();
$ftvs_start  = microtime( true );

foreach ( $ftvs_suite as $ftvs_file => $ftvs_tests ) {
	echo "\n" . $ftvs_file . "\n";
	foreach ( $ftvs_tests as $ftvs_fn ) {
		$ftvs_t0                    = microtime( true );
		list( $ftvs_status, $ftvs_msg ) = ftvs_t_execute( $ftvs_fn );
		$ftvs_ms                    = (int) round( ( microtime( true ) - $ftvs_t0 ) * 1000 );
		$ftvs_bug                   = isset( $GLOBALS['ftvs_t_known_bugs'][ $ftvs_fn ] ) ? $GLOBALS['ftvs_t_known_bugs'][ $ftvs_fn ] : null;
		$ftvs_note                  = '';
		if ( $ftvs_bug && ! $ftvs_strict ) {
			if ( 'FAIL' === $ftvs_status || 'ERROR' === $ftvs_status ) {
				$ftvs_status = 'XFAIL';
				$ftvs_bugs[] = array( $ftvs_fn, $ftvs_bug );
			} elseif ( 'PASS' === $ftvs_status ) {
				$ftvs_status = 'XPASS';
				$ftvs_note   = 'known bug marker can be removed: ' . $ftvs_bug['what'];
			}
		}
		++$ftvs_count[ $ftvs_status ];
		printf( "  %-5s %s%s\n", $ftvs_status, $ftvs_fn, $ftvs_ms >= 500 ? ' (' . $ftvs_ms . ' ms)' : '' );
		if ( 'XFAIL' === $ftvs_status ) {
			echo '        KNOWN BUG at ' . $ftvs_bug['where'] . ': ' . $ftvs_bug['what'] . "\n";
		}
		if ( '' !== $ftvs_note ) {
			echo '        ' . $ftvs_note . "\n";
		}
		if ( '' !== $ftvs_msg && in_array( $ftvs_status, array( 'FAIL', 'ERROR', 'SKIP', 'XFAIL' ), true ) ) {
			echo '        ' . str_replace( "\n", "\n        ", $ftvs_msg ) . "\n";
		}
		if ( 'FAIL' === $ftvs_status || 'ERROR' === $ftvs_status ) {
			$ftvs_failed[] = $ftvs_fn;
			if ( $ftvs_ci ) {
				$ftvs_line = preg_match( '/^\[(test-[a-z0-9-]+\.php):(\d+)\]/', $ftvs_msg, $ftvs_m ) ? ',line=' . $ftvs_m[2] : '';
				echo '::error file=tests/' . $ftvs_file . $ftvs_line . '::' . $ftvs_fn . ': ' . str_replace( array( "\r", "\n" ), ' ', $ftvs_msg ) . "\n";
			}
		}
	}
}

/* ---- leave the site as found, and check nothing changed the saved settings ---- */

$ftvs_site = ftvs_t_restore_site();
echo "\nSite: " . count( $ftvs_site['changed'] ) . " plugin option/cron row(s) written by the tests were put back.\n";
if ( $ftvs_site['settings_changed'] ) {
	++$ftvs_count['FAIL'];
	$ftvs_failed[] = '(harness) saved settings';
	echo "  FAIL  a test changed the saved ftvs_settings option (it has been restored). Tests must switch settings with ftvs_t_settings().\n";
}

$ftvs_total = array_sum( $ftvs_count );
$ftvs_bad   = $ftvs_count['FAIL'] + $ftvs_count['ERROR'];

if ( $ftvs_bugs ) {
	echo "\nKnown plugin bugs (XFAIL, not counted as failures):\n";
	foreach ( $ftvs_bugs as $ftvs_b ) {
		echo '  - ' . $ftvs_b[1]['where'] . ': ' . $ftvs_b[1]['what'] . '  [' . $ftvs_b[0] . "]\n";
	}
}

printf(
	"\nSummary: %d tests, %d passed, %d failed, %d errors, %d known bugs (xfail), %d fixed-but-marked (xpass), %d skipped in %.2fs\n",
	$ftvs_total,
	$ftvs_count['PASS'],
	$ftvs_count['FAIL'],
	$ftvs_count['ERROR'],
	$ftvs_count['XFAIL'],
	$ftvs_count['XPASS'],
	$ftvs_count['SKIP'],
	microtime( true ) - $ftvs_start
);
if ( 0 === $ftvs_total ) {
	echo "No tests ran" . ( '' !== $ftvs_filter ? ' (check the filter)' : '' ) . ".\n";
	ftvs_t_halt( 1 );
}
if ( $ftvs_bad > 0 ) {
	echo 'FAILED: ' . implode( ', ', $ftvs_failed ) . "\n";
	ftvs_t_halt( 1 );
}
echo "OK\n";
ftvs_t_halt( 0 );
