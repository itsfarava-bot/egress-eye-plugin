<?php
/**
 * Included after native installed runtime boot and its first cold HTTP call.
 *
 * @package Egress_Eye
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Isolated native fixture process.
$egress_eye_bench_observer = null;
foreach ( $GLOBALS['wp_filter']['pre_http_request']->callbacks as $egress_eye_callbacks ) {
	foreach ( $egress_eye_callbacks as $egress_eye_callback ) {
		if ( is_array( $egress_eye_callback['function'] ) && $egress_eye_callback['function'][0] instanceof \EgressEye\Http\HttpObserver ) {
			$egress_eye_bench_observer = $egress_eye_callback['function'][0];
		}
	}
}
$egress_eye_samples = array(
	'baseline' => array(),
	'active'   => array(),
);

$egress_eye_hooks = array(
	'http_request_args' => array( 'filterRequestArgs', 2 ),
	'pre_http_request'  => array( 'filterPreHttpRequest', 3 ),
	'http_api_debug'    => array( 'actionHttpApiDebug', 5 ),
);

$egress_eye_before = $wpdb->num_queries;
for ( $egress_eye_index = -20; $egress_eye_index < 100; ++$egress_eye_index ) {
	// Alternate order to reduce time/order bias in this local-only test process.
	foreach ( 0 === $egress_eye_index % 2 ? array( 'baseline', 'active' ) : array( 'active', 'baseline' ) as $egress_eye_variant ) {
		if ( 'baseline' === $egress_eye_variant ) {
			foreach ( $egress_eye_hooks as $egress_eye_hook => $egress_eye_spec ) {
				remove_filter( $egress_eye_hook, array( $egress_eye_bench_observer, $egress_eye_spec[0] ), PHP_INT_MAX );
			}
		}
		try {
			$egress_eye_started  = hrtime( true );
			$egress_eye_response = wp_remote_get( $argv[2], array( 'timeout' => 8 ) );
			$egress_eye_elapsed  = ( hrtime( true ) - $egress_eye_started ) / 1000;
			if ( 200 !== wp_remote_retrieve_response_code( $egress_eye_response ) ) {
				throw new RuntimeException( 'Benchmark local HTTP request did not succeed.' );
			}
			if ( $egress_eye_index >= 0 ) {
				$egress_eye_samples[ $egress_eye_variant ][] = $egress_eye_elapsed;
			}
		} finally {
			foreach ( $egress_eye_hooks as $egress_eye_hook => $egress_eye_spec ) {
				if ( 'baseline' === $egress_eye_variant ) {
					add_filter( $egress_eye_hook, array( $egress_eye_bench_observer, $egress_eye_spec[0] ), PHP_INT_MAX, $egress_eye_spec[1] );
				}
			}
		}
	}
}
$egress_eye_result['benchmark'] = array(
	'samples'      => 100,
	'warmup'       => 20,
	'warm_queries' => $wpdb->num_queries - $egress_eye_before,
	'cold_queries' => $egress_eye_result['snapshot_reads'] + $egress_eye_result['host_reads'],
	'writes'       => $egress_eye_result['writes'],
	'raw_us'       => $egress_eye_samples,
	'budget'       => 'active p95 <= baseline p95 * 3 + 5000 us',
);
foreach ( $egress_eye_samples as $egress_eye_variant => $egress_eye_values ) {
	sort( $egress_eye_values, SORT_NUMERIC );
	$egress_eye_result['benchmark'][ $egress_eye_variant . '_median_us' ] = ( $egress_eye_values[49] + $egress_eye_values[50] ) / 2;
	$egress_eye_result['benchmark'][ $egress_eye_variant . '_p95_us' ]    = $egress_eye_values[94];
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals
