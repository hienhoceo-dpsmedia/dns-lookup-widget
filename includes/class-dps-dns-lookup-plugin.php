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
				'title'       => 'Tra Cứu DNS & Server Hàng Loạt',
				'subtitle'    => 'Công cụ chuyên nghiệp từ DPS.MEDIA',
				'description' => 'Nhập mỗi tên miền một dòng, chọn nhiều cột cần kiểm tra, rồi copy kết quả dạng TSV.',
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

		$title       = $this->translate( $atts['title'] );
		$subtitle    = $this->translate( $atts['subtitle'] );
		$description = $this->translate( $atts['description'] );

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="dps-dns-widget"
			data-limit="<?php echo esc_attr( (string) $limit ); ?>"
			data-delay="<?php echo esc_attr( (string) $delay ); ?>"
			data-brand="<?php echo esc_attr( sanitize_text_field( $atts['brand'] ) ); ?>"
			data-txt-all-dns="<?php echo esc_attr( $this->translate( 'ALL DNS' ) ); ?>"
			data-txt-stopping="<?php echo esc_attr( $this->translate( 'Đang dừng...' ) ); ?>"
			data-txt-enter-domain="<?php echo esc_attr( $this->translate( 'Vui lòng nhập ít nhất một tên miền.' ) ); ?>"
			data-txt-select-column="<?php echo esc_attr( $this->translate( 'Vui lòng chọn ít nhất một cột cần kiểm tra.' ) ); ?>"
			data-txt-limit-exceeded="<?php echo esc_attr( $this->translate( 'Danh sách vượt giới hạn %s tên miền. Hãy chia nhỏ danh sách.' ) ); ?>"
			data-txt-missing-config="<?php echo esc_attr( $this->translate( 'Thiếu cấu hình REST endpoint.' ) ); ?>"
			data-txt-stopped-at="<?php echo esc_attr( $this->translate( 'Đã dừng tại %s/%s.' ) ); ?>"
			data-txt-completed="<?php echo esc_attr( $this->translate( 'Hoàn thành: %s domain x %s cột.' ) ); ?>"
			data-txt-no-data-copy="<?php echo esc_attr( $this->translate( 'Không có dữ liệu để sao chép.' ) ); ?>"
			data-txt-copied="<?php echo esc_attr( $this->translate( 'Đã sao chép TSV vào clipboard.' ) ); ?>"
			data-txt-copy-failed="<?php echo esc_attr( $this->translate( 'Sao chép thất bại: %s' ) ); ?>"
			data-txt-ready="<?php echo esc_attr( $this->translate( 'Sẵn sàng tra cứu DNS & server' ) ); ?>"
			data-txt-empty-subtext="<?php echo esc_attr( $this->translate( 'Mỗi domain là một dòng, mỗi loại kiểm tra là một cột.' ) ); ?>"
			data-txt-domain="<?php echo esc_attr( $this->translate( 'Domain' ) ); ?>"
			data-txt-empty="<?php echo esc_attr( $this->translate( 'Empty' ) ); ?>"
			data-txt-error="<?php echo esc_attr( $this->translate( 'Error' ) ); ?>"
			data-txt-conn-failed="<?php echo esc_attr( $this->translate( 'Connection failed' ) ); ?>"
			data-txt-no-cert="<?php echo esc_attr( $this->translate( 'No certificate' ) ); ?>"
			data-txt-parse-error="<?php echo esc_attr( $this->translate( 'Parse error' ) ); ?>"
			data-txt-days-left="<?php echo esc_attr( $this->translate( 'days left' ) ); ?>"
		>
			<div class="dps-dns-shell">
				<!-- Hidden translation container for TranslatePress compatibility -->
				<div class="dps-dns-translations" style="display: none;" aria-hidden="true">
					<span class="t-txt-all-dns"><?php $this->e_translate( 'ALL DNS' ); ?></span>
					<span class="t-txt-stopping"><?php $this->e_translate( 'Đang dừng...' ); ?></span>
					<span class="t-txt-enter-domain"><?php $this->e_translate( 'Vui lòng nhập ít nhất một tên miền.' ); ?></span>
					<span class="t-txt-select-column"><?php $this->e_translate( 'Vui lòng chọn ít nhất một cột cần kiểm tra.' ); ?></span>
					<span class="t-txt-limit-exceeded"><?php $this->e_translate( 'Danh sách vượt giới hạn %s tên miền. Hãy chia nhỏ danh sách.' ); ?></span>
					<span class="t-txt-missing-config"><?php $this->e_translate( 'Thiếu cấu hình REST endpoint.' ); ?></span>
					<span class="t-txt-stopped-at"><?php $this->e_translate( 'Đã dừng tại %s/%s.' ); ?></span>
					<span class="t-txt-completed"><?php $this->e_translate( 'Hoàn thành: %s domain x %s cột.' ); ?></span>
					<span class="t-txt-no-data-copy"><?php $this->e_translate( 'Không có dữ liệu để sao chép.' ); ?></span>
					<span class="t-txt-copied"><?php $this->e_translate( 'Đã sao chép TSV vào clipboard.' ); ?></span>
					<span class="t-txt-copy-failed"><?php $this->e_translate( 'Sao chép thất bại: %s' ); ?></span>
					<span class="t-txt-ready"><?php $this->e_translate( 'Sẵn sàng tra cứu DNS & server' ); ?></span>
					<span class="t-txt-empty-subtext"><?php $this->e_translate( 'Mỗi domain là một dòng, mỗi loại kiểm tra là một cột.' ); ?></span>
					<span class="t-txt-domain"><?php $this->e_translate( 'Domain' ); ?></span>
					<span class="t-txt-empty"><?php $this->e_translate( 'Empty' ); ?></span>
					<span class="t-txt-error"><?php $this->e_translate( 'Error' ); ?></span>
					<span class="t-txt-conn-failed"><?php $this->e_translate( 'Connection failed' ); ?></span>
					<span class="t-txt-no-cert"><?php $this->e_translate( 'No certificate' ); ?></span>
					<span class="t-txt-parse-error"><?php $this->e_translate( 'Parse error' ); ?></span>
					<span class="t-txt-days-left"><?php $this->e_translate( 'days left' ); ?></span>
				</div>
				<div class="dps-dns-header">
					<div class="dps-dns-header-mark" aria-hidden="true">DPS</div>
					<div class="dps-dns-header-copy">
						<div class="dps-dns-title"><?php echo esc_html( $title ); ?></div>
						<div class="dps-dns-subtitle"><?php echo esc_html( $subtitle ); ?></div>
					</div>
					<div class="dps-dns-brand"><?php echo esc_html( sanitize_text_field( $atts['brand'] ) ); ?></div>
				</div>

				<div class="dps-dns-description"><?php echo esc_html( $description ); ?></div>

				<div class="dps-dns-workbench">
					<div class="dps-dns-input-panel">
						<label class="dps-dns-label" for="<?php echo esc_attr( $instance_id ); ?>-domains"><?php $this->e_translate( 'Danh sách tên miền' ); ?></label>
						<div class="dps-dns-textarea-wrap">
							<textarea id="<?php echo esc_attr( $instance_id ); ?>-domains" class="dps-dns-textarea" rows="7" spellcheck="false" placeholder="dps.media&#10;example.com&#10;https://sub.domain.com/path"></textarea>
							<div class="dps-dns-count" aria-live="polite">0</div>
						</div>
					</div>

					<div class="dps-dns-control-panel">
						<div class="dps-dns-control-group">
							<div class="dps-dns-label"><?php $this->e_translate( 'Cột cần kiểm tra' ); ?></div>
							<div class="dps-dns-types" role="group" aria-label="<?php echo esc_attr( $this->translate( 'Chọn các cột kiểm tra DNS và server' ) ); ?>"></div>
						</div>

						<div class="dps-dns-control-row">
							<label class="dps-dns-delay">
								<span class="dps-dns-label"><?php $this->e_translate( 'Độ trễ' ); ?></span>
								<span class="dps-dns-delay-input">
									<input class="dps-dns-delay-field" type="number" min="50" max="5000" step="10" value="<?php echo esc_attr( (string) $delay ); ?>">
									<span>ms</span>
								</span>
							</label>
							<button class="dps-dns-button dps-dns-button-primary" type="button" data-action="run"><?php $this->e_translate( 'Bắt đầu' ); ?></button>
							<button class="dps-dns-button dps-dns-button-danger" type="button" data-action="stop" hidden><?php $this->e_translate( 'Dừng' ); ?></button>
						</div>
					</div>
				</div>

				<div class="dps-dns-toolbar">
					<button class="dps-dns-tool-button" type="button" data-action="copy" disabled><?php $this->e_translate( 'Sao chép TSV' ); ?></button>
					<button class="dps-dns-tool-button" type="button" data-action="clear"><?php $this->e_translate( 'Xóa kết quả' ); ?></button>
					<div class="dps-dns-progress" aria-hidden="true"><span></span></div>
					<div class="dps-dns-status" aria-live="polite"></div>
				</div>

				<div class="dps-dns-error" role="alert" hidden></div>
				<div class="dps-dns-results" aria-live="polite">
					<div class="dps-dns-empty">
						<div class="dps-dns-empty-icon" aria-hidden="true">Lookup</div>
						<div class="dps-dns-empty-title"><?php $this->e_translate( 'Sẵn sàng tra cứu DNS & server' ); ?></div>
						<div class="dps-dns-empty-copy"><?php $this->e_translate( 'Mỗi domain là một dòng, mỗi loại kiểm tra là một cột.' ); ?></div>
					</div>
				</div>

				<div class="dps-dns-footer">
					<span><?php $this->e_translate( 'Phát triển bởi' ); ?></span>
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
			'HTTP'  => ! empty( $options['enable_http'] ),
			'SERVER' => ! empty( $options['enable_http'] ),
			'SSL'   => ! empty( $options['enable_ssl'] ),
		);
	}

	/**
	 * Detect current language based on URL path or TranslatePress global.
	 *
	 * @return string 'vi', 'en', or 'zh'.
	 */
	private function get_current_language() {
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			global $TRP_LANGUAGE;
			if ( ! empty( $TRP_LANGUAGE ) ) {
				$lang = strtolower( substr( $TRP_LANGUAGE, 0, 2 ) );
				if ( in_array( $lang, array( 'en', 'zh', 'vi' ), true ) ) {
					return $lang;
				}
			}
		}

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$uri = $_SERVER['REQUEST_URI'];
			if ( preg_match( '#^/en(/|$)#i', $uri ) || strpos( $uri, '/en/' ) !== false ) {
				return 'en';
			}
			if ( preg_match( '#^/zh(/|$)#i', $uri ) || strpos( $uri, '/zh/' ) !== false ) {
				return 'zh';
			}
		}

		$locale = get_locale();
		$lang = strtolower( substr( $locale, 0, 2 ) );
		if ( in_array( $lang, array( 'en', 'zh', 'vi' ), true ) ) {
			return $lang;
		}

		return 'vi';
	}

	/**
	 * Translate string dynamically based on detected language.
	 *
	 * @param string $string Original string.
	 * @return string Translated string.
	 */
	private function translate( $string ) {
		$lang = $this->get_current_language();

		$dictionary = array(
			'ALL DNS' => array(
				'vi' => 'Tất cả DNS',
				'en' => 'ALL DNS',
				'zh' => '所有 DNS'
			),
			'Đang dừng...' => array(
				'vi' => 'Đang dừng...',
				'en' => 'Stopping...',
				'zh' => '正在停止...'
			),
			'Vui lòng nhập ít nhất một tên miền.' => array(
				'vi' => 'Vui lòng nhập ít nhất một tên miền.',
				'en' => 'Please enter at least one domain.',
				'zh' => '请至少输入一个域名。'
			),
			'Vui lòng chọn ít nhất một cột cần kiểm tra.' => array(
				'vi' => 'Vui lòng chọn ít nhất một cột cần kiểm tra.',
				'en' => 'Please select at least one check type.',
				'zh' => '请至少选择一个检查类型。'
			),
			'Danh sách vượt giới hạn %s tên miền. Hãy chia nhỏ danh sách.' => array(
				'vi' => 'Danh sách vượt giới hạn %s tên miền. Hãy chia nhỏ danh sách.',
				'en' => 'The list exceeds the limit of %s domains. Please split the list.',
				'zh' => '列表已超过 %s 个域名的限制。请拆分列表。'
			),
			'Thiếu cấu hình REST endpoint.' => array(
				'vi' => 'Thiếu cấu hình REST endpoint.',
				'en' => 'Missing REST endpoint configuration.',
				'zh' => '缺少 REST 端点配置。'
			),
			'Đã dừng tại %s/%s.' => array(
				'vi' => 'Đã dừng tại %s/%s.',
				'en' => 'Stopped at %s/%s.',
				'zh' => '已在 %s/%s 处停止。'
			),
			'Hoàn thành: %s domain x %s cột.' => array(
				'vi' => 'Hoàn thành: %s domain x %s cột.',
				'en' => 'Completed: %s domain(s) x %s column(s).',
				'zh' => '已完成：%s 个域名 x %s 列。'
			),
			'Không có dữ liệu để sao chép.' => array(
				'vi' => 'Không có dữ liệu để sao chép.',
				'en' => 'No data to copy.',
				'zh' => '没有可复制的数据。'
			),
			'Đã sao chép TSV vào clipboard.' => array(
				'vi' => 'Đã sao chép TSV vào clipboard.',
				'en' => 'TSV copied to clipboard.',
				'zh' => 'TSV 已复制到剪贴板。'
			),
			'Sao chép thất bại: %s' => array(
				'vi' => 'Sao chép thất bại: %s',
				'en' => 'Copy failed: %s',
				'zh' => '复制失败：%s'
			),
			'Sẵn sàng tra cứu DNS & server' => array(
				'vi' => 'Sẵn sàng tra cứu DNS & server',
				'en' => 'Ready for DNS & server lookup',
				'zh' => '准备进行 DNS 和服务器查询'
			),
			'Mỗi domain là một dòng, mỗi loại kiểm tra là một cột.' => array(
				'vi' => 'Mỗi domain là một dòng, mỗi loại kiểm tra là một cột.',
				'en' => 'One domain per line, check types are columns.',
				'zh' => '每行一个域名，检查类型为列。'
			),
			'Domain' => array(
				'vi' => 'Domain',
				'en' => 'Domain',
				'zh' => '域名'
			),
			'Empty' => array(
				'vi' => 'Trống',
				'en' => 'Empty',
				'zh' => '空'
			),
			'Error' => array(
				'vi' => 'Lỗi',
				'en' => 'Error',
				'zh' => '错误'
			),
			'Danh sách tên miền' => array(
				'vi' => 'Danh sách tên miền',
				'en' => 'Domain list',
				'zh' => '域名列表'
			),
			'Cột cần kiểm tra' => array(
				'vi' => 'Cột cần kiểm tra',
				'en' => 'Columns to check',
				'zh' => '检查列'
			),
			'Chọn các cột kiểm tra DNS và server' => array(
				'vi' => 'Chọn các cột kiểm tra DNS và server',
				'en' => 'Select DNS and server columns to check',
				'zh' => '选择要检查的 DNS 和服务器列'
			),
			'Độ trễ' => array(
				'vi' => 'Độ trễ',
				'en' => 'Delay',
				'zh' => '延迟'
			),
			'Bắt đầu' => array(
				'vi' => 'Bắt đầu',
				'en' => 'Start',
				'zh' => '开始'
			),
			'Dừng' => array(
				'vi' => 'Dừng',
				'en' => 'Stop',
				'zh' => '停止'
			),
			'Sao chép TSV' => array(
				'vi' => 'Sao chép TSV',
				'en' => 'Copy TSV',
				'zh' => '复制 TSV'
			),
			'Xóa kết quả' => array(
				'vi' => 'Xóa kết quả',
				'en' => 'Clear results',
				'zh' => '清除结果'
			),
			'Phát triển bởi' => array(
				'vi' => 'Phát triển bởi',
				'en' => 'Developed by',
				'zh' => '开发人员'
			),
			'Tra Cứu DNS & Server Hàng Loạt' => array(
				'vi' => 'Tra Cứu DNS & Server Hàng Loạt',
				'en' => 'Bulk DNS & Server Lookup',
				'zh' => '批量 DNS 和服务器查询'
			),
			'Công cụ chuyên nghiệp từ DPS.MEDIA' => array(
				'vi' => 'Công cụ chuyên nghiệp từ DPS.MEDIA',
				'en' => 'Professional tool from DPS.MEDIA',
				'zh' => '来自 DPS.MEDIA 的专业工具'
			),
			'Nhập mỗi tên miền một dòng, chọn nhiều cột cần kiểm tra, rồi copy kết quả dạng TSV.' => array(
				'vi' => 'Nhập mỗi tên miền một dòng, chọn nhiều cột cần kiểm tra, rồi copy kết quả dạng TSV.',
				'en' => 'Enter one domain per line, select columns to check, then copy results as TSV.',
				'zh' => '输入域名（每行一个），选择要检查的列，然后复制为 TSV 结果。'
			),
			'Connection failed' => array(
				'vi' => 'Kết nối thất bại',
				'en' => 'Connection failed',
				'zh' => '连接失败'
			),
			'No certificate' => array(
				'vi' => 'Không có chứng chỉ',
				'en' => 'No certificate',
				'zh' => '无证书'
			),
			'Parse error' => array(
				'vi' => 'Lỗi phân tích',
				'en' => 'Parse error',
				'zh' => '解析错误'
			),
			'days left' => array(
				'vi' => 'ngày còn lại',
				'en' => 'days left',
				'zh' => '天剩余'
			)
		);

		if ( isset( $dictionary[ $string ][ $lang ] ) ) {
			return $dictionary[ $string ][ $lang ];
		}

		return $string;
	}

	/**
	 * Output translated and escaped HTML string.
	 *
	 * @param string $string Original string.
	 * @return void
	 */
	private function e_translate( $string ) {
		echo esc_html( $this->translate( $string ) );
	}
}
