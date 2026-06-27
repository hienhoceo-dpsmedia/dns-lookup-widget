<?php
/**
 * Main plugin bootstrap.
 *
 * @package DPS_DNS_Lookup_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers assets, shortcode, and REST controller.
 */
final class DPS_DNS_Lookup_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Number of shortcode instances on the current page.
	 *
	 * @var int
	 */
	private $instances = 0;

	/**
	 * REST controller.
	 *
	 * @var DPS_DNS_Lookup_REST
	 */
	private $rest_controller;

	/**
	 * Admin controller.
	 *
	 * @var DPS_DNS_Lookup_Admin
	 */
	private $admin_controller;

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->rest_controller = new DPS_DNS_Lookup_REST();
		$this->admin_controller = new DPS_DNS_Lookup_Admin();

		add_action( 'init', array( $this, 'register_assets' ) );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'rest_api_init', array( $this->rest_controller, 'register_routes' ) );
		$this->admin_controller->hooks();
	}

	/**
	 * Register frontend assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style(
			'dps-dns-lookup-widget',
			DPS_DNS_LOOKUP_WIDGET_URL . 'assets/css/dps-dns-lookup.css',
			array(),
			DPS_DNS_LOOKUP_WIDGET_VERSION
		);

		wp_register_script(
			'dps-dns-lookup-widget',
			DPS_DNS_LOOKUP_WIDGET_URL . 'assets/js/dps-dns-lookup.js',
			array(),
			DPS_DNS_LOOKUP_WIDGET_VERSION,
			true
		);
	}

	/**
	 * Register shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode() {
		add_shortcode( 'dps_dns_lookup', array( $this, 'render_shortcode' ) );
		add_shortcode( 'dps_bulk_dns', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Render the DNS lookup widget.
	 *
	 * @param array<string,string> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'       => __( 'Tra Cứu DNS & Server Hàng Loạt', 'dps-dns-lookup-widget' ),
				'subtitle'    => __( 'Công cụ chuyên nghiệp từ DPS.MEDIA', 'dps-dns-lookup-widget' ),
				'description' => __( 'Nhập mỗi tên miền một dòng, chọn nhiều cột cần kiểm tra, rồi copy kết quả dạng TSV.', 'dps-dns-lookup-widget' ),
				'brand'       => 'DPS.MEDIA',
				'limit'       => '100',
				'delay'       => '120',
			),
			(array) $atts,
			'dps_dns_lookup'
		);

		$limit = absint( $atts['limit'] );
		if ( 1 > $limit || 250 < $limit ) {
			$limit = 100;
		}

		$delay = absint( $atts['delay'] );
		if ( 50 > $delay || 5000 < $delay ) {
			$delay = 120;
		}

		++$this->instances;
		$instance_id = 'dps-dns-lookup-' . $this->instances . '-' . wp_rand( 1000, 9999 );

		wp_enqueue_style( 'dps-dns-lookup-widget' );
		wp_enqueue_script( 'dps-dns-lookup-widget' );
		wp_add_inline_script(
			'dps-dns-lookup-widget',
			'window.dpsDnsLookupConfig = window.dpsDnsLookupConfig || ' . wp_json_encode(
				array(
					'restUrl'      => esc_url_raw( rest_url( 'dps-dns-lookup/v1/lookup' ) ),
					'nonceUrl'     => esc_url_raw( rest_url( 'dps-dns-lookup/v1/nonce' ) ),
					'nonce'        => wp_create_nonce( 'dps_dns_lookup' ),
					'enabledTools' => $this->get_enabled_tools(),
				)
			) . ';',
			'before'
		);

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="dps-dns-widget"
			data-limit="<?php echo esc_attr( (string) $limit ); ?>"
			data-delay="<?php echo esc_attr( (string) $delay ); ?>"
			data-brand="<?php echo esc_attr( sanitize_text_field( $atts['brand'] ) ); ?>"
		>
			<div class="dps-dns-shell">
				<div class="dps-dns-header">
					<div class="dps-dns-header-mark" aria-hidden="true">DPS</div>
					<div class="dps-dns-header-copy">
						<div class="dps-dns-title"><?php echo esc_html( sanitize_text_field( $atts['title'] ) ); ?></div>
						<div class="dps-dns-subtitle"><?php echo esc_html( sanitize_text_field( $atts['subtitle'] ) ); ?></div>
					</div>
					<div class="dps-dns-brand"><?php echo esc_html( sanitize_text_field( $atts['brand'] ) ); ?></div>
				</div>

				<div class="dps-dns-description"><?php echo esc_html( sanitize_text_field( $atts['description'] ) ); ?></div>

				<div class="dps-dns-workbench">
					<div class="dps-dns-input-panel">
						<label class="dps-dns-label" for="<?php echo esc_attr( $instance_id ); ?>-domains"><?php esc_html_e( 'Danh sách tên miền', 'dps-dns-lookup-widget' ); ?></label>
						<div class="dps-dns-textarea-wrap">
							<textarea id="<?php echo esc_attr( $instance_id ); ?>-domains" class="dps-dns-textarea" rows="7" spellcheck="false" placeholder="dps.media&#10;example.com&#10;https://sub.domain.com/path"></textarea>
							<div class="dps-dns-count" aria-live="polite">0</div>
						</div>
					</div>

					<div class="dps-dns-control-panel">
						<div class="dps-dns-control-group">
							<div class="dps-dns-label"><?php esc_html_e( 'Cột cần kiểm tra', 'dps-dns-lookup-widget' ); ?></div>
							<div class="dps-dns-types" role="group" aria-label="<?php esc_attr_e( 'Chọn các cột kiểm tra DNS và server', 'dps-dns-lookup-widget' ); ?>"></div>
						</div>

						<div class="dps-dns-control-row">
							<label class="dps-dns-delay">
								<span class="dps-dns-label"><?php esc_html_e( 'Độ trễ', 'dps-dns-lookup-widget' ); ?></span>
								<span class="dps-dns-delay-input">
									<input class="dps-dns-delay-field" type="number" min="50" max="5000" step="10" value="<?php echo esc_attr( (string) $delay ); ?>">
									<span>ms</span>
								</span>
							</label>
							<button class="dps-dns-button dps-dns-button-primary" type="button" data-action="run"><?php esc_html_e( 'Bắt đầu', 'dps-dns-lookup-widget' ); ?></button>
							<button class="dps-dns-button dps-dns-button-danger" type="button" data-action="stop" hidden><?php esc_html_e( 'Dừng', 'dps-dns-lookup-widget' ); ?></button>
						</div>
					</div>
				</div>

				<div class="dps-dns-toolbar">
					<button class="dps-dns-tool-button" type="button" data-action="copy" disabled><?php esc_html_e( 'Sao chép TSV', 'dps-dns-lookup-widget' ); ?></button>
					<button class="dps-dns-tool-button" type="button" data-action="clear"><?php esc_html_e( 'Xóa kết quả', 'dps-dns-lookup-widget' ); ?></button>
					<div class="dps-dns-progress" aria-hidden="true"><span></span></div>
					<div class="dps-dns-status" aria-live="polite"></div>
				</div>

				<div class="dps-dns-error" role="alert" hidden></div>
				<div class="dps-dns-results" aria-live="polite">
					<div class="dps-dns-empty">
						<div class="dps-dns-empty-icon" aria-hidden="true">Lookup</div>
						<div class="dps-dns-empty-title"><?php esc_html_e( 'Sẵn sàng tra cứu DNS & server', 'dps-dns-lookup-widget' ); ?></div>
						<div class="dps-dns-empty-copy"><?php esc_html_e( 'Mỗi domain là một dòng, mỗi loại kiểm tra là một cột.', 'dps-dns-lookup-widget' ); ?></div>
					</div>
				</div>

				<div class="dps-dns-footer">
					<span><?php esc_html_e( 'Phát triển bởi', 'dps-dns-lookup-widget' ); ?></span>
					<a href="https://dps.media/" target="_blank" rel="noopener noreferrer">DPS.MEDIA</a>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Get enabled frontend tools.
	 *
	 * @return array<string,bool>
	 */
	private function get_enabled_tools() {
		$options = DPS_DNS_Lookup_Settings::get();

		return array(
			'HTTP' => ! empty( $options['enable_http'] ),
			'SSL'  => ! empty( $options['enable_ssl'] ),
		);
	}
}
