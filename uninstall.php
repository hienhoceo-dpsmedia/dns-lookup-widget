<?php
/**
 * Uninstall cleanup.
 *
 * @package DPS_DNS_Lookup_Widget
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_dps_dns_lookup_%',
		'_transient_timeout_dps_dns_lookup_%'
	)
);
