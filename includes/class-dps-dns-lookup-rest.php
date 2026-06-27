<?php
/**
 * REST endpoint for DNS and server checks.
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
	 * Server-side tools.
	 *
	 * @var array<int,string>
	 */
	private const SERVER_TYPES = array( 'HTTP', 'SSL' );

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
	 * Perform a lookup.
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

		$domain = $this->normalize_domain( (string) $request->get_param( 'domain' ) );
		$type   = strtoupper( (string) $request->get_param( 'type' ) );
		$ip     = $this->get_client_ip();

		if ( is_wp_error( $domain ) ) {
			DPS_DNS_Lookup_Admin::log_lookup( $ip, '', $type, 'INVALID_DOMAIN', $domain->get_error_message() );
			return $domain;
		}

		if ( ! $this->is_supported_type( $type ) ) {
			DPS_DNS_Lookup_Admin::log_lookup( $ip, $domain, $type, 'INVALID_TYPE' );
			return new WP_Error(
				'dps_dns_lookup_bad_type',
				__( 'Unsupported DNS or server check type.', 'dps-dns-lookup-widget' ),
				array( 'status' => 400 )
			);
		}

		$enabled = $this->check_type_enabled( $type );
		if ( is_wp_error( $enabled ) ) {
			DPS_DNS_Lookup_Admin::log_lookup( $ip, $domain, $type, 'DISABLED', $enabled->get_error_message() );
			return $enabled;
		}

		$access = $this->check_access( $ip );
		if ( is_wp_error( $access ) ) {
			DPS_DNS_Lookup_Admin::log_lookup( $ip, $domain, $type, 'BLOCKED', $access->get_error_message() );
			return $access;
		}

		$cache_key = 'dps_dns_lookup_' . md5( $domain . ':' . $type );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			DPS_DNS_Lookup_Admin::log_lookup( $ip, $domain, $type, 'CACHE' );
			return rest_ensure_response(
				array(
					'domain' => $domain,
					'type'   => $type,
					'cached' => true,
					'rows'   => $cached,
				)
			);
		}

		$response = $this->dispatch_lookup( $domain, $type );
		if ( is_wp_error( $response ) ) {
			DPS_DNS_Lookup_Admin::log_lookup( $ip, $domain, $type, 'ERROR', $response->get_error_message() );
			return $response;
		}

		set_transient( $cache_key, $response, $this->cache_ttl( $type ) );
		DPS_DNS_Lookup_Admin::log_lookup( $ip, $domain, $type, 'SUCCESS' );

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
	 * Check whether type is supported.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	private function is_supported_type( $type ) {
		return isset( self::TYPE_CODES[ $type ] ) || in_array( $type, self::SERVER_TYPES, true );
	}

	/**
	 * Check settings for optional tools.
	 *
	 * @param string $type Type.
	 * @return true|WP_Error
	 */
	private function check_type_enabled( $type ) {
		$options = DPS_DNS_Lookup_Settings::get();

		if ( 'HTTP' === $type && empty( $options['enable_http'] ) ) {
			return new WP_Error( 'dps_dns_lookup_http_disabled', __( 'HTTP checks are disabled by the site administrator.', 'dps-dns-lookup-widget' ), array( 'status' => 403 ) );
		}

		if ( 'SSL' === $type && empty( $options['enable_ssl'] ) ) {
			return new WP_Error( 'dps_dns_lookup_ssl_disabled', __( 'SSL checks are disabled by the site administrator.', 'dps-dns-lookup-widget' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Check bans, whitelist, and rate limits.
	 *
	 * @param string $ip Client IP.
	 * @return true|WP_Error
	 */
	private function check_access( $ip ) {
		$options     = DPS_DNS_Lookup_Settings::get();
		$whitelisted = DPS_DNS_Lookup_Settings::ip_list_to_array( (string) $options['whitelisted_ips'] );

		if ( in_array( $ip, $whitelisted, true ) ) {
			return true;
		}

		$banned = DPS_DNS_Lookup_Settings::ip_list_to_array( (string) $options['banned_ips'] );
		if ( in_array( $ip, $banned, true ) || get_transient( $this->ban_key( $ip ) ) ) {
			return new WP_Error(
				'dps_dns_lookup_banned',
				__( 'Access denied. This IP is blocked.', 'dps-dns-lookup-widget' ),
				array( 'status' => 403 )
			);
		}

		$max_hits = absint( $options['rate_limit'] );
		if ( 0 === $max_hits ) {
			return true;
		}

		$key   = 'dps_dns_lookup_rate_' . md5( $ip . wp_salt( 'nonce' ) );
		$count = absint( get_transient( $key ) );

		if ( $count >= $max_hits ) {
			set_transient( $this->ban_key( $ip ), 1, absint( $options['ban_duration'] ) * MINUTE_IN_SECONDS );

			return new WP_Error(
				'dps_dns_lookup_rate_limited',
				__( 'Too many lookup requests. Please wait and try again.', 'dps-dns-lookup-widget' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Dispatch lookup by type.
	 *
	 * @param string $domain Domain.
	 * @param string $type Type.
	 * @return array<int,array<string,string|int>>|WP_Error
	 */
	private function dispatch_lookup( $domain, $type ) {
		if ( 'HTTP' === $type ) {
			return $this->query_http( $domain );
		}

		if ( 'SSL' === $type ) {
			return $this->query_ssl( $domain );
		}

		return $this->query_google_dns( $domain, $type );
	}

	/**
	 * Cache TTL by type.
	 *
	 * @param string $type Type.
	 * @return int
	 */
	private function cache_ttl( $type ) {
		if ( 'HTTP' === $type ) {
			return 2 * MINUTE_IN_SECONDS;
		}

		if ( 'SSL' === $type ) {
			return 30 * MINUTE_IN_SECONDS;
		}

		return 10 * MINUTE_IN_SECONDS;
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
				'name' => $domain,
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
			return new WP_Error( 'dps_dns_lookup_remote_error', $remote->get_error_message(), array( 'status' => 502 ) );
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
			return new WP_Error( 'dps_dns_lookup_bad_response', __( 'DNS provider returned an invalid response.', 'dps-dns-lookup-widget' ), array( 'status' => 502 ) );
		}

		$records = array();
		if ( ! empty( $body['Answer'] ) && is_array( $body['Answer'] ) ) {
			$records = $body['Answer'];
		} elseif ( ! empty( $body['Authority'] ) && is_array( $body['Authority'] ) ) {
			$records = $body['Authority'];
		}

		if ( empty( $records ) ) {
			return array( $this->make_row( $domain, $type, '', '', __( '(no data)', 'dps-dns-lookup-widget' ), '' ) );
		}

		$rows = array();
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$rows[] = $this->make_row(
				$domain,
				$type,
				isset( $record['name'] ) ? sanitize_text_field( (string) $record['name'] ) : '',
				isset( $record['TTL'] ) ? absint( $record['TTL'] ) : '',
				isset( $record['data'] ) ? sanitize_text_field( (string) $record['data'] ) : '',
				''
			);
		}

		return $rows;
	}

	/**
	 * Query HTTP status.
	 *
	 * @param string $domain Domain.
	 * @return array<int,array<string,string|int>>|WP_Error
	 */
	private function query_http( $domain ) {
		$url = 'https://' . $domain . '/';

		$remote = wp_safe_remote_head(
			esc_url_raw( $url ),
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'sslverify'   => true,
				'user-agent'  => 'DPS DNS Lookup Widget/' . DPS_DNS_LOOKUP_WIDGET_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $remote ) ) {
			return array( $this->make_row( $domain, 'HTTP', '', '', 'Error', $remote->get_error_message() ) );
		}

		$code    = (int) wp_remote_retrieve_response_code( $remote );
		$message = sanitize_text_field( wp_remote_retrieve_response_message( $remote ) );
		$server  = sanitize_text_field( (string) wp_remote_retrieve_header( $remote, 'server' ) );
		$data    = trim( $code . ' ' . $message );

		return array( $this->make_row( $domain, 'HTTP', '', '', $data, $server ) );
	}

	/**
	 * Query SSL certificate metadata.
	 *
	 * @param string $domain Domain.
	 * @return array<int,array<string,string|int>>|WP_Error
	 */
	private function query_ssl( $domain ) {
		if ( ! function_exists( 'openssl_x509_parse' ) ) {
			return new WP_Error( 'dps_dns_lookup_ssl_unavailable', __( 'OpenSSL is not available on this server.', 'dps-dns-lookup-widget' ), array( 'status' => 500 ) );
		}

		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'peer_name'         => $domain,
					'SNI_enabled'       => true,
					'verify_peer'       => false,
					'verify_peer_name'  => false,
				),
			)
		);

		$client = @stream_socket_client( 'ssl://' . $domain . ':443', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context );
		if ( ! $client ) {
			return array( $this->make_row( $domain, 'SSL', '', '', 'Connection failed', sanitize_text_field( $errstr ) ) );
		}

		$params = stream_context_get_params( $client );
		fclose( $client );

		if ( empty( $params['options']['ssl']['peer_certificate'] ) ) {
			return array( $this->make_row( $domain, 'SSL', '', '', 'No certificate', '' ) );
		}

		$cert = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
		if ( ! is_array( $cert ) || empty( $cert['validTo_time_t'] ) ) {
			return array( $this->make_row( $domain, 'SSL', '', '', 'Parse error', '' ) );
		}

		$days_left = (int) floor( ( (int) $cert['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
		$valid_to  = gmdate( 'Y-m-d', (int) $cert['validTo_time_t'] );
		$issuer    = '';
		if ( ! empty( $cert['issuer']['O'] ) ) {
			$issuer = sanitize_text_field( (string) $cert['issuer']['O'] );
		}

		$data   = sprintf(
			/* translators: %d: days left. */
			__( '%d days left', 'dps-dns-lookup-widget' ),
			$days_left
		);
		$detail = trim( 'Until: ' . $valid_to . ( $issuer ? ' | ' . $issuer : '' ) );

		return array( $this->make_row( $domain, 'SSL', '', '', $data, $detail ) );
	}

	/**
	 * Build a normalized row.
	 *
	 * @param string     $domain Domain.
	 * @param string     $type Type.
	 * @param string     $name Name.
	 * @param string|int $ttl TTL.
	 * @param string     $data Data.
	 * @param string     $detail Detail.
	 * @return array<string,string|int>
	 */
	private function make_row( $domain, $type, $name, $ttl, $data, $detail ) {
		return array(
			'domain' => sanitize_text_field( $domain ),
			'type'   => sanitize_text_field( $type ),
			'name'   => sanitize_text_field( (string) $name ),
			'ttl'    => '' === $ttl ? '' : absint( $ttl ),
			'data'   => sanitize_text_field( (string) $data ),
			'detail' => sanitize_text_field( (string) $detail ),
		);
	}

	/**
	 * Client IP.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}

	/**
	 * Ban transient key.
	 *
	 * @param string $ip IP.
	 * @return string
	 */
	private function ban_key( $ip ) {
		return 'dps_dns_lookup_ban_' . md5( $ip . wp_salt( 'nonce' ) );
	}
}
