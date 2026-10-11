<?php
/**
 * Fresh native PHP request for installed production runtime verification.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Isolated native WordPress fixture process.
require getenv( 'WP_TESTS_DIR' ) . '/wp-tests-config.php';
require_once ABSPATH . 'wp-includes/plugin.php';
if ( 'idle' === $argv[3] ) {
	define( 'DISABLE_WP_CRON', true );
}
$_SERVER['HTTP_HOST']       = 'example.org';
$_SERVER['SERVER_NAME']     = 'example.org';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
add_filter(
	'option_active_plugins',
	static function () {
		return array();
	}
);
$egress_eye_result = array();
$egress_eye_wrong  = array();
$egress_eye_events = array();

$egress_eye_boot_queries  = array();
$egress_eye_owned_queries = 0;

$egress_eye_boot_query_spy = static function ( $sql ) use ( &$egress_eye_boot_queries, &$egress_eye_owned_queries ) {
	$egress_eye_boot_queries[] = $sql;
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Test-only SQL attribution; arguments are excluded and no trace is persisted.
	foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ) as $egress_eye_query_frame ) {
		if ( isset( $egress_eye_query_frame['file'] ) && 0 === strpos( $egress_eye_query_frame['file'], dirname( $GLOBALS['argv'][1] ) . '/src/' ) ) {
			++$egress_eye_owned_queries;
			break;
		}
	}
	return $sql;
};
add_filter( 'query', $egress_eye_boot_query_spy );
add_action(
	'doing_it_wrong_run',
	static function ( $function_name ) {
		global $egress_eye_wrong;
		$egress_eye_wrong[] = $function_name;
	}
);
add_action(
	'egress_eye_slow_request_recorded',
	static function ( $payload ) {
		global $egress_eye_events;
		$egress_eye_events[] = $payload;
		if ( 'throw' === $GLOBALS['argv'][3] ) {
			throw new Error( 'fixture slow listener' );
		}
	}
);
add_action(
	'plugins_loaded',
	static function () {
		global $egress_eye_result;
		if ( in_array( $GLOBALS['argv'][3], array( 'qm-on', 'qm-off', 'qm-block' ), true ) ) {
			if ( 'qm-off' !== $GLOBALS['argv'][3] ) {
				define( 'QM_TESTS', true );
			}
			require getenv( 'EGRESS_EYE_QM_PATH' );
			add_action(
				'init',
				static function (): void {
					$egress_eye_administrators = get_users(
						array(
							'role'   => 'administrator',
							'number' => 1,
							'fields' => 'ID',
						)
					);
					if ( empty( $egress_eye_administrators ) ) {
						throw new RuntimeException( 'Query Monitor fixture requires an actual administrator.' );
					}
					wp_set_current_user( (int) $egress_eye_administrators[0] );
				},
				-1
			);

		}
		if ( 'no-outbound' === $GLOBALS['argv'][3] ) {
			require dirname( __DIR__ ) . '/Support/NetworkTrap.php';
			$GLOBALS['egress_eye_network_trap'] = new \EgressEye\Tests\Support\NetworkTrap();
			$GLOBALS['egress_eye_network_trap']->install();
		}
		require $GLOBALS['argv'][1];
		// Exercise idempotent boot: the second call must not assemble another guard.
		\EgressEye\Plugin::boot( $GLOBALS['argv'][1] );
		if ( 'early' === $GLOBALS['argv'][3] ) {
			load_plugin_textdomain( 'egress-eye', false, 'egress-eye/languages' );
			$response                      = wp_remote_get( $GLOBALS['argv'][2] );
			$egress_eye_result['response'] = is_wp_error( $response ) ? $response->get_error_code() : wp_remote_retrieve_response_code( $response );
			$egress_eye_result['message']  = is_wp_error( $response ) ? $response->get_error_message() : '';
		}
	},
	1
);
require ABSPATH . 'wp-settings.php';
if ( in_array( $argv[3], array( 'no-outbound', 'idle' ), true ) ) {
	$_SERVER['REQUEST_URI'] = '/';
	$_SERVER['PHP_SELF']    = '/index.php';
	wp();
	$egress_eye_result['front_main'] = did_action( 'wp' );
}
remove_filter( 'query', $egress_eye_boot_query_spy );
if ( ! in_array( $argv[3], array( 'early', 'no-outbound', 'idle' ), true ) ) {
	global $wpdb;
	$egress_eye_queries = array();

	$egress_eye_query_spy = static function ( $sql ) use ( &$egress_eye_queries ) {
		$egress_eye_queries[] = $sql;
		return $sql;
	};
	add_filter( 'query', $egress_eye_query_spy );
	$egress_eye_result['responses'] = array();

	$egress_eye_repeats = 'ten' === $argv[3] ? 10 : ( 'multi' === $argv[3] ? 3 : 1 );
	if ( in_array( $argv[3], array( 'qm-on', 'qm-off' ), true ) ) {
		$egress_eye_repeats = 2;
	}
	for ( $egress_eye_index = 0; $egress_eye_index < $egress_eye_repeats; ++$egress_eye_index ) {
		$response                      = wp_remote_get(
			1 === $egress_eye_index && in_array( $argv[3], array( 'qm-on', 'qm-off' ), true ) ? $argv[2] . '?egress_eye_slow_fixture=1' : $argv[2],
			array(
				'headers' => array( 'X-Fixture-Secret' => 'private-header' ),
				'body'    => array( 'secret' => 'private-body' ),
				'timeout' => 8,
			)
		);
		$egress_eye_result['response'] = is_wp_error( $response ) ? $response->get_error_code() : wp_remote_retrieve_response_code( $response );

		$egress_eye_result['responses'][] = $egress_eye_result['response'];
	}
	remove_filter( 'query', $egress_eye_query_spy );
	$egress_eye_result['snapshot_reads'] = 0;
	$egress_eye_result['host_reads']     = 0;
	$egress_eye_result['writes']         = 0;
	foreach ( array_merge( $egress_eye_boot_queries, $egress_eye_queries ) as $egress_eye_sql ) {
		if ( false !== strpos( $egress_eye_sql, 'egress_eye_cache_version' ) && false !== strpos( $egress_eye_sql, $wpdb->prefix . 'http_diag_domains' ) ) {
			++$egress_eye_result['snapshot_reads'];
		}
		if ( false !== strpos( $egress_eye_sql, 'is_blocked = 1' ) && false !== strpos( $egress_eye_sql, $wpdb->prefix . 'http_diag_domains' ) ) {
			++$egress_eye_result['host_reads'];
		}
	}
	foreach ( $egress_eye_queries as $egress_eye_sql ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP|TRUNCATE)\b/i', $egress_eye_sql ) ) {
			++$egress_eye_result['writes'];
		}
	}
}
if ( 'benchmark' === $argv[3] ) {
	require __DIR__ . '/benchmark.php';
}
$egress_eye_result['boot_query_count'] = count( $egress_eye_boot_queries );
$egress_eye_result['owned_queries']    = $egress_eye_owned_queries;

$egress_eye_result['wrong']          = $egress_eye_wrong;
$egress_eye_result['events']         = $egress_eye_events;
$egress_eye_result['stack']          = current_filter();
$egress_eye_result['debug_priority'] = $GLOBALS['wp_filter']['http_api_debug']->current_priority();
$egress_eye_result['guards']         = 0;
foreach ( $GLOBALS['wp_filter']['pre_http_request']->callbacks as $callbacks ) {
	foreach ( $callbacks as $callback ) {
		if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof \EgressEye\Http\HttpObserver ) {
			++$egress_eye_result['guards'];
			$egress_eye_store_property = new ReflectionProperty( $callback['function'][0], 'context_store' );
			$egress_eye_store_property->setAccessible( true );
			$egress_eye_result['contexts'] = $egress_eye_store_property->getValue( $callback['function'][0] )->count();
		}
	}
}
if ( in_array( $argv[3], array( 'qm-on', 'qm-off', 'qm-block' ), true ) ) {
	$egress_eye_result['qm_diagnostics'] = array(
		'version'    => defined( 'QM_VERSION' ) ? QM_VERSION : '',
		'installing' => defined( 'WP_INSTALLING' ) && WP_INSTALLING,
		'cron'       => defined( 'DOING_CRON' ) && DOING_CRON,
		'disabled'   => defined( 'QM_DISABLED' ) && QM_DISABLED,
		'ceases'     => did_action( 'qm/cease' ),
		'main_class' => class_exists( 'QueryMonitor' ),
		'http_class' => class_exists( 'QM_Collector_HTTP' ),
	);

	$egress_eye_qm = class_exists( 'QM_Collectors' ) ? QM_Collectors::get( 'http' ) : null;

	$egress_eye_result['qm_debug_callbacks'] = 0;
	foreach ( $GLOBALS['wp_filter']['http_api_debug']->callbacks as $egress_eye_qm_callbacks ) {
		foreach ( $egress_eye_qm_callbacks as $egress_eye_qm_callback ) {
			if ( is_array( $egress_eye_qm_callback['function'] ) && $egress_eye_qm_callback['function'][0] instanceof QM_Collector_HTTP && 'action_http_api_debug' === $egress_eye_qm_callback['function'][1] ) {
				++$egress_eye_result['qm_debug_callbacks'];
			}
		}
	}

	$egress_eye_result['qm_authorized'] = defined( 'QM_TESTS' ) && QM_TESTS && QM_Dispatcher::user_can_view();

	$egress_eye_result['qm_tests']    = defined( 'QM_TESTS' ) && QM_TESTS;
	$egress_eye_result['qm_priority'] = null !== $egress_eye_qm ? has_action( 'http_api_debug', array( $egress_eye_qm, 'action_http_api_debug' ) ) : false;
	foreach ( array( 'http_requests', 'http_responses' ) as $egress_eye_qm_field ) {
		$egress_eye_result[ $egress_eye_qm_field ] = 0;
		if ( null !== $egress_eye_qm ) {
			$egress_eye_qm_property = new ReflectionProperty( $egress_eye_qm, $egress_eye_qm_field );
			$egress_eye_qm_property->setAccessible( true );
			$egress_eye_result[ $egress_eye_qm_field ] = count( $egress_eye_qm_property->getValue( $egress_eye_qm ) );
		}
	}
}
if ( 'no-outbound' === $argv[3] ) {
	// WordPress registered its shutdown function earlier; capture after it runs.
	register_shutdown_function(
		static function (): void {
			global $egress_eye_result;
			$egress_eye_result['outbound_http']    = $GLOBALS['egress_eye_network_trap']->http;
			$egress_eye_result['outbound_streams'] = \EgressEye\Tests\Support\NetworkTrap::$streams;
			$egress_eye_result['shutdowns']        = did_action( 'shutdown' );
			$GLOBALS['egress_eye_network_trap']->restore();
			echo 'RESULT ' . wp_json_encode( $egress_eye_result ) . "\n";
		}
	);
} else {
	echo 'RESULT ' . wp_json_encode( $egress_eye_result ) . "\n";
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals
