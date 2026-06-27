<?php
/**
 * Static checks for the standalone plugin repository.
 *
 * @package DPS_DNS_Lookup_Widget
 */

$root = dirname( __DIR__ );

$required_files = array(
	'dps-dns-lookup-widget.php',
	'includes/class-dps-dns-lookup-settings.php',
	'includes/class-dps-dns-lookup-admin.php',
	'includes/class-dps-dns-lookup-plugin.php',
	'includes/class-dps-dns-lookup-rest.php',
	'assets/css/dps-dns-lookup.css',
	'assets/js/dps-dns-lookup.js',
	'readme.txt',
	'uninstall.php',
);

$failures = array();

foreach ( $required_files as $file ) {
	if ( ! file_exists( $root . DIRECTORY_SEPARATOR . $file ) ) {
		$failures[] = "Missing required file: {$file}";
	}
}

$main = file_get_contents( $root . DIRECTORY_SEPARATOR . 'dps-dns-lookup-widget.php' );
$plugin = file_get_contents( $root . DIRECTORY_SEPARATOR . 'includes/class-dps-dns-lookup-plugin.php' );
$rest = file_get_contents( $root . DIRECTORY_SEPARATOR . 'includes/class-dps-dns-lookup-rest.php' );
$admin = file_get_contents( $root . DIRECTORY_SEPARATOR . 'includes/class-dps-dns-lookup-admin.php' );
$settings = file_get_contents( $root . DIRECTORY_SEPARATOR . 'includes/class-dps-dns-lookup-settings.php' );
$js = file_get_contents( $root . DIRECTORY_SEPARATOR . 'assets/js/dps-dns-lookup.js' );
$css = file_get_contents( $root . DIRECTORY_SEPARATOR . 'assets/css/dps-dns-lookup.css' );
$readme = file_get_contents( $root . DIRECTORY_SEPARATOR . 'readme.txt' );

$checks = array(
	'Plugin header exists' => false !== strpos( $main, 'Plugin Name: DPS DNS Lookup Widget' ),
	'Shortcode registered' => false !== strpos( $plugin, "add_shortcode( 'dps_dns_lookup'" ),
	'Legacy shortcode registered' => false !== strpos( $plugin, "add_shortcode( 'dps_bulk_dns'" ),
	'REST route registered' => false !== strpos( $rest, "register_rest_route(\n\t\t\t'dps-dns-lookup/v1'" ),
	'Nonce is verified' => false !== strpos( $rest, 'wp_verify_nonce' ),
	'Remote call uses safe HTTP API' => false !== strpos( $rest, 'wp_safe_remote_get' ),
	'Transient cache is used' => false !== strpos( $rest, 'get_transient' ) && false !== strpos( $rest, 'set_transient' ),
	'HTTP checks are server-side' => false !== strpos( $rest, 'wp_safe_remote_head' ),
	'SSL checks are server-side' => false !== strpos( $rest, 'stream_socket_client' ) && false !== strpos( $rest, 'openssl_x509_parse' ),
	'Admin settings exist' => false !== strpos( $admin, 'add_menu_page' ) && false !== strpos( $settings, 'sanitize' ),
	'Optional logs table exists' => false !== strpos( $admin, 'dps_dns_lookup_logs' ),
	'Escaping is used in shortcode' => false !== strpos( $plugin, 'esc_html' ) && false !== strpos( $plugin, 'esc_attr' ),
	'Sanitization is used in REST' => false !== strpos( $rest, 'sanitize_text_field' ) && false !== strpos( $rest, 'sanitize_key' ),
	'No external CDN references' => false === strpos( $js . $css . $plugin, 'cdn.' ),
	'CSS scoped to widget root' => false !== strpos( $css, '.dps-dns-widget' ),
	'JS initializes widget instances' => false !== strpos( $js, 'DpsDnsLookupWidget' ),
	'Readme has stable tag' => false !== strpos( $readme, 'Stable tag: 1.1.0' ),
);

foreach ( $checks as $label => $passed ) {
	if ( ! $passed ) {
		$failures[] = "Failed check: {$label}";
	}
}

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo 'Static checks passed.' . PHP_EOL;
