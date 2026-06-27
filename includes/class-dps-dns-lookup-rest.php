<?php
/**
 * REST endpoint for DNS lookups.
 *
 * @package DPS_DNS_Lookup_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DNS lookup REST controller.
 */
final class DPS_DNS_Lookup_REST {
	/**
	 * Supported DNS record type codes.
	 *
	 * @var array<string,int>
	 */
	private const TYPE_CODES = array(
		'A'     => 1,
		'NS'    => 2,
		'CNAME' => 5,
		'SOA'   => 6,
		'MX'    => 15,
		'TXT'   => 16,
		'AAAA'  => 28,
		'CAA'   => 257,
	);

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'dps-dns-lookup/v1',
			'/lookup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'lookup' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'domain' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type'   => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'nonce'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Perform a DNS lookup.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function lookup( WP_REST_Request $request ) {
		$nonce = (string) $request->get_param( 'nonce' );
		if ( ! wp_verify_nonce( $nonce, 'dps_dns_lookup' ) ) {
			return new WP_Error(
				'dps_dns_lookup_bad_nonce',
				__( 'Security check failed. Please refresh the page and try again.', 'dps-dns-lookup-widget' ),
				array( 'status' => 403 )
			);
		}

		$rate_limited = $this->check_rate_limit();
		if ( is_wp_error( $rate_limited ) ) {
			return $rate_limited;
		}

		$domain = $this->normalize_domain( (string) $request->get_param( 'domain' ) );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}

		$type = strtoupper( (string) $request->get_param( 'type' ) );
		if ( ! isset( self::TYPE_CODES[ $type ] ) ) {
			return new WP_Error(
				'dps_dns_lookup_bad_type',
				__( 'Unsupported DNS record type.', 'dps-dns-lookup-widget' ),
				array( 'status' => 400 )
			);
		}

		$cache_key = 'dps_dns_lookup_' . md5( $domain . ':' . $type );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return rest_ensure_response(
				array(
					'domain' => $domain,
					'type'   => $type,
					'cached' => true,
					'rows'   => $cached,
				)
			);
		}

		$response = $this->query_google_dns( $domain, $type );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		set_transient( $cache_key, $response, 10 * MINUTE_IN_SECONDS );

		return rest_ensure_response(
			array(
				'domain' => $domain,
				'type'   => $type,
				'cached' => false,
				'rows'   => $response,
			)
		);
	}

	/**
	 * Normalize and validate a domain name.
	 *
	 * @param string $input Raw user input.
	 * @return string|WP_Error
	 */
	private function normalize_domain( $input ) {
		$input = trim( wp_strip_all_tags( $input ) );
		$input = preg_replace( '/^\s*https?:\/\//i', '', $input );
		$input = ltrim( (string) $input, '/' );
		$input = preg_split( '/[\/?#]/', $input )[0];
		$input = strtolower( rtrim( trim( $input ), '.' ) );

		if ( '' === $input || strlen( $input ) > 253 ) {
			return new WP_Error(
				'dps_dns_lookup_bad_domain',
				__( 'Invalid domain name.', 'dps-dns-lookup-widget' ),
				array( 'status' => 400 )
			);
		}

		if ( function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $input, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( false !== $ascii ) {
				$input = strtolower( $ascii );
			}
		}

		$labels = explode( '.', $input );
		if ( count( $labels ) < 2 ) {
			return new WP_Error(
				'dps_dns_lookup_bad_domain',
				__( 'Please enter a fully qualified domain name.', 'dps-dns-lookup-widget' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $labels as $label ) {
			if ( '' === $label || strlen( $label ) > 63 || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label ) ) {
				return new WP_Error(
					'dps_dns_lookup_bad_domain',
					__( 'Invalid domain name.', 'dps-dns-lookup-widget' ),
					array( 'status' => 400 )
				);
			}
		}

		return $input;
	}

	/**
	 * Apply a lightweight public rate limit.
	 *
	 * @return true|WP_Error
	 */
	private function check_rate_limit() {
		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key      = 'dps_dns_lookup_rate_' . md5( $ip . wp_salt( 'nonce' ) );
		$count    = absint( get_transient( $key ) );
		$max_hits = (int) apply_filters( 'dps_dns_lookup_rate_limit_per_minute', 180 );

		if ( $count >= $max_hits ) {
			return new WP_Error(
				'dps_dns_lookup_rate_limited',
				__( 'Too many DNS lookups. Please wait a moment and try again.', 'dps-dns-lookup-widget' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Query Google DNS-over-HTTPS and map the response.
	 *
	 * @param string $domain Normalized domain.
	 * @param string $type Record type.
	 * @return array<int,array<string,string|int>>|WP_Error
	 */
	private function query_google_dns( $domain, $type ) {
		$url = add_query_arg(
			array(
				'name' => rawurlencode( $domain ),
				'type' => self::TYPE_CODES[ $type ],
			),
			'https://dns.google/resolve'
		);

		$remote = wp_safe_remote_get(
			esc_url_raw( $url ),
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array(
					'Accept' => 'application/dns-json',
				),
			)
		);

		if ( is_wp_error( $remote ) ) {
			return new WP_Error(
				'dps_dns_lookup_remote_error',
				$remote->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $remote );
		if ( 200 !== $status ) {
			return new WP_Error(
				'dps_dns_lookup_remote_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'DNS provider returned HTTP %d.', 'dps-dns-lookup-widget' ),
					$status
				),
				array( 'status' => 502 )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $remote ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'dps_dns_lookup_bad_response',
				__( 'DNS provider returned an invalid response.', 'dps-dns-lookup-widget' ),
				array( 'status' => 502 )
			);
		}

		$records = array();
		if ( ! empty( $body['Answer'] ) && is_array( $body['Answer'] ) ) {
			$records = $body['Answer'];
		} elseif ( ! empty( $body['Authority'] ) && is_array( $body['Authority'] ) ) {
			$records = $body['Authority'];
		}

		if ( empty( $records ) ) {
			return array(
				array(
					'domain' => $domain,
					'type'   => $type,
					'name'   => '',
					'ttl'    => '',
					'data'   => __( '(no data)', 'dps-dns-lookup-widget' ),
				),
			);
		}

		$rows = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$rows[] = array(
				'domain' => $domain,
				'type'   => $type,
				'name'   => isset( $record['name'] ) ? sanitize_text_field( (string) $record['name'] ) : '',
				'ttl'    => isset( $record['TTL'] ) ? absint( $record['TTL'] ) : '',
				'data'   => isset( $record['data'] ) ? sanitize_text_field( (string) $record['data'] ) : '',
			);
		}

		return $rows;
	}
}
