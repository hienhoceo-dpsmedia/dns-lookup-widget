<?php
/**
 * Admin settings and optional logs.
 *
 * @package DPS_DNS_Lookup_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller.
 */
final class DPS_DNS_Lookup_Admin {
	/**
	 * Hook admin behavior.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Activation setup.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_logs_table();

		if ( false === get_option( DPS_DNS_Lookup_Settings::OPTION_NAME, false ) ) {
			add_option( DPS_DNS_Lookup_Settings::OPTION_NAME, DPS_DNS_Lookup_Settings::defaults() );
		}
	}

	/**
	 * Create logs table.
	 *
	 * @return void
	 */
	public static function create_logs_table() {
		global $wpdb;

		$table_name      = self::logs_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			time datetime NOT NULL,
			ip varchar(100) NOT NULL,
			domain varchar(253) NOT NULL,
			type varchar(20) NOT NULL,
			status varchar(50) NOT NULL,
			message text NULL,
			user_agent varchar(500) NULL,
			PRIMARY KEY  (id),
			KEY ip (ip),
			KEY time (time),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Register menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'DPS DNS Tools', 'dps-dns-lookup-widget' ),
			__( 'DPS DNS Tools', 'dps-dns-lookup-widget' ),
			'manage_options',
			'dps-dns-lookup',
			array( $this, 'render_page' ),
			'dashicons-networking',
			80
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			DPS_DNS_Lookup_Settings::OPTION_GROUP,
			DPS_DNS_Lookup_Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'DPS_DNS_Lookup_Settings', 'sanitize' ),
				'default'           => DPS_DNS_Lookup_Settings::defaults(),
			)
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

		if ( 'logs' === $tab && isset( $_POST['dps_dns_clear_logs'] ) ) {
			check_admin_referer( 'dps_dns_clear_logs' );
			self::clear_logs();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Logs cleared.', 'dps-dns-lookup-widget' ) . '</p></div>';
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'DPS DNS Tools', 'dps-dns-lookup-widget' ); ?></h1>
			<h2 class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=dps-dns-lookup&tab=settings' ) ); ?>"><?php esc_html_e( 'Settings', 'dps-dns-lookup-widget' ); ?></a>
				<a class="nav-tab <?php echo 'logs' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=dps-dns-lookup&tab=logs' ) ); ?>"><?php esc_html_e( 'Logs', 'dps-dns-lookup-widget' ); ?></a>
			</h2>
			<?php
			if ( 'logs' === $tab ) {
				$this->render_logs_tab();
			} else {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render settings.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		$options = DPS_DNS_Lookup_Settings::get();
		$name    = DPS_DNS_Lookup_Settings::OPTION_NAME;
		?>
		<form method="post" action="options.php">
			<?php settings_fields( DPS_DNS_Lookup_Settings::OPTION_GROUP ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Server checks', 'dps-dns-lookup-widget' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enable_http]" value="1" <?php checked( 1, $options['enable_http'] ); ?>> <?php esc_html_e( 'Enable HTTP status checks', 'dps-dns-lookup-widget' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enable_ssl]" value="1" <?php checked( 1, $options['enable_ssl'] ); ?>> <?php esc_html_e( 'Enable SSL certificate checks', 'dps-dns-lookup-widget' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Logging', 'dps-dns-lookup-widget' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enable_logging]" value="1" <?php checked( 1, $options['enable_logging'] ); ?>> <?php esc_html_e( 'Store lookup logs in the database', 'dps-dns-lookup-widget' ); ?></label>
						<p class="description"><?php esc_html_e( 'Keep disabled if you do not need audit data.', 'dps-dns-lookup-widget' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dps-dns-rate-limit"><?php esc_html_e( 'Rate limit', 'dps-dns-lookup-widget' ); ?></label></th>
					<td><input id="dps-dns-rate-limit" class="small-text" type="number" min="0" max="1000" name="<?php echo esc_attr( $name ); ?>[rate_limit]" value="<?php echo esc_attr( (string) $options['rate_limit'] ); ?>"> <?php esc_html_e( 'requests per minute per IP. Use 0 to disable.', 'dps-dns-lookup-widget' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="dps-dns-ban-duration"><?php esc_html_e( 'Temporary ban duration', 'dps-dns-lookup-widget' ); ?></label></th>
					<td><input id="dps-dns-ban-duration" class="small-text" type="number" min="1" max="1440" name="<?php echo esc_attr( $name ); ?>[ban_duration]" value="<?php echo esc_attr( (string) $options['ban_duration'] ); ?>"> <?php esc_html_e( 'minutes after rate limit is exceeded.', 'dps-dns-lookup-widget' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><label for="dps-dns-banned-ips"><?php esc_html_e( 'Banned IPs', 'dps-dns-lookup-widget' ); ?></label></th>
					<td><textarea id="dps-dns-banned-ips" class="large-text code" rows="5" name="<?php echo esc_attr( $name ); ?>[banned_ips]"><?php echo esc_textarea( (string) $options['banned_ips'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="dps-dns-whitelisted-ips"><?php esc_html_e( 'Whitelisted IPs', 'dps-dns-lookup-widget' ); ?></label></th>
					<td><textarea id="dps-dns-whitelisted-ips" class="large-text code" rows="5" name="<?php echo esc_attr( $name ); ?>[whitelisted_ips]"><?php echo esc_textarea( (string) $options['whitelisted_ips'] ); ?></textarea></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render logs.
	 *
	 * @return void
	 */
	private function render_logs_tab() {
		global $wpdb;

		self::create_logs_table();

		$table_name = self::logs_table_name();
		$page       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page   = 50;
		$offset     = ( $page - 1 ) * $per_page;
		$total      = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name}" );
		$logs       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_name} ORDER BY time DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
		$pages      = max( 1, (int) ceil( $total / $per_page ) );
		?>
		<form method="post" style="margin: 16px 0;">
			<?php wp_nonce_field( 'dps_dns_clear_logs' ); ?>
			<?php submit_button( __( 'Clear logs', 'dps-dns-lookup-widget' ), 'secondary', 'dps_dns_clear_logs', false ); ?>
		</form>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Time', 'dps-dns-lookup-widget' ); ?></th>
				<th><?php esc_html_e( 'IP', 'dps-dns-lookup-widget' ); ?></th>
				<th><?php esc_html_e( 'Domain', 'dps-dns-lookup-widget' ); ?></th>
				<th><?php esc_html_e( 'Type', 'dps-dns-lookup-widget' ); ?></th>
				<th><?php esc_html_e( 'Status', 'dps-dns-lookup-widget' ); ?></th>
				<th><?php esc_html_e( 'Message', 'dps-dns-lookup-widget' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( $logs ) : ?>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log->time ); ?></td>
						<td><?php echo esc_html( $log->ip ); ?></td>
						<td><?php echo esc_html( $log->domain ); ?></td>
						<td><?php echo esc_html( $log->type ); ?></td>
						<td><?php echo esc_html( $log->status ); ?></td>
						<td><?php echo esc_html( $log->message ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No logs found.', 'dps-dns-lookup-widget' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<p>
			<?php echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'dps-dns-lookup-widget' ), $page, $pages ) ); ?>
			<?php if ( $page > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=dps-dns-lookup&tab=logs&paged=' . ( $page - 1 ) ) ); ?>"><?php esc_html_e( 'Previous', 'dps-dns-lookup-widget' ); ?></a>
			<?php endif; ?>
			<?php if ( $page < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=dps-dns-lookup&tab=logs&paged=' . ( $page + 1 ) ) ); ?>"><?php esc_html_e( 'Next', 'dps-dns-lookup-widget' ); ?></a>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Log a lookup when enabled.
	 *
	 * @param string $ip IP address.
	 * @param string $domain Domain.
	 * @param string $type Type.
	 * @param string $status Status.
	 * @param string $message Message.
	 * @return void
	 */
	public static function log_lookup( $ip, $domain, $type, $status, $message = '' ) {
		$options = DPS_DNS_Lookup_Settings::get();
		if ( empty( $options['enable_logging'] ) ) {
			return;
		}

		global $wpdb;
		$table_name = self::logs_table_name();

		$wpdb->insert(
			$table_name,
			array(
				'time'       => current_time( 'mysql' ),
				'ip'         => substr( sanitize_text_field( $ip ), 0, 100 ),
				'domain'     => substr( sanitize_text_field( $domain ), 0, 253 ),
				'type'       => substr( sanitize_text_field( $type ), 0, 20 ),
				'status'     => substr( sanitize_text_field( $status ), 0, 50 ),
				'message'    => sanitize_textarea_field( $message ),
				'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Clear logs.
	 *
	 * @return void
	 */
	private static function clear_logs() {
		global $wpdb;
		$table_name = self::logs_table_name();
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );
	}

	/**
	 * Logs table name.
	 *
	 * @return string
	 */
	public static function logs_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'dps_dns_lookup_logs';
	}
}
