<?php
/**
 * Shared settings helpers.
 *
 * @package DPS_DNS_Lookup_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and sanitizes plugin settings.
 */
final class DPS_DNS_Lookup_Settings {
	public const OPTION_NAME = 'dps_dns_lookup_settings';
	public const OPTION_GROUP = 'dps_dns_lookup_settings_group';

	/**
	 * Defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enable_http'     => 1,
			'enable_ssl'      => 1,
			'enable_logging'  => 0,
			'rate_limit'      => 180,
			'ban_duration'    => 30,
			'banned_ips'      => '',
			'whitelisted_ips' => '',
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get() {
		$options = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		return wp_parse_args( $options, self::defaults() );
	}

	/**
	 * Sanitize settings from wp-admin.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();

		return array(
			'enable_http'     => empty( $input['enable_http'] ) ? 0 : 1,
			'enable_ssl'      => empty( $input['enable_ssl'] ) ? 0 : 1,
			'enable_logging'  => empty( $input['enable_logging'] ) ? 0 : 1,
			'rate_limit'      => self::clamp_int( isset( $input['rate_limit'] ) ? $input['rate_limit'] : 180, 0, 1000, 180 ),
			'ban_duration'    => self::clamp_int( isset( $input['ban_duration'] ) ? $input['ban_duration'] : 30, 1, 1440, 30 ),
			'banned_ips'      => self::sanitize_ip_list( isset( $input['banned_ips'] ) ? $input['banned_ips'] : '' ),
			'whitelisted_ips' => self::sanitize_ip_list( isset( $input['whitelisted_ips'] ) ? $input['whitelisted_ips'] : '' ),
		);
	}

	/**
	 * Parse one-IP-per-line setting.
	 *
	 * @param string $value Raw list.
	 * @return array<int,string>
	 */
	public static function ip_list_to_array( $value ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		$ips   = array();

		foreach ( (array) $lines as $line ) {
			$ip = trim( sanitize_text_field( $line ) );
			if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$ips[] = $ip;
			}
		}

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Clamp integer.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min Minimum.
	 * @param int   $max Maximum.
	 * @param int   $fallback Fallback.
	 * @return int
	 */
	private static function clamp_int( $value, $min, $max, $fallback ) {
		$value = absint( $value );
		if ( $value < $min || $value > $max ) {
			return $fallback;
		}

		return $value;
	}

	/**
	 * Sanitize IP list as newline text.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_ip_list( $value ) {
		return implode( "\n", self::ip_list_to_array( (string) $value ) );
	}
}
