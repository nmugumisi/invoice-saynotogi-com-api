<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page: Settings > Invoice Settings
 * Stores company details, currency, and payment instructions used on every invoice.
 */
class CI_Settings {

	private static $instance = null;
	const OPTION_KEY = 'ci_settings';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'save_business_details' ) );
		add_action( 'wp_ajax_ci_fetch_rates', array( $this, 'ajax_fetch_rates' ) );
	}

	public function add_settings_page() {
		add_options_page(
			__( 'Invoice Settings', 'custom-invoices' ),
			__( 'Invoice Settings', 'custom-invoices' ),
			'manage_options',
			'ci-invoice-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'edit.php?post_type=invoice',
			__( 'Business Details', 'custom-invoices' ),
			__( 'Business Details', 'custom-invoices' ),
			'manage_options',
			'ci-business-details',
			array( $this, 'render_business_details_page' )
		);
	}

	public static function get( $key, $default = '' ) {
		$settings = get_option( self::OPTION_KEY, array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	public function register_settings() {
		register_setting( 'ci_settings_group', self::OPTION_KEY, array( $this, 'sanitize' ) );
	}

	public function sanitize( $input ) {
		// register_setting() installs this as a filter on update_option(), so it
		// runs for *every* writer of the option — the settings form, the Business
		// Details form, and the rate fetcher. Each of those submits only its own
		// fields, so only overwrite keys this save actually carried; anything else
		// keeps the value already stored. (Writing defaults for absent keys is what
		// used to throw the business details away on save.)
		$output = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $input ) ) {
			return $output;
		}

		$text_fields = array(
			'currency_symbol',
			'currency_code',
			'invoice_prefix',
			'company_name',
			'company_phone',
			'company_email',
		);
		foreach ( $text_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$output[ $field ] = sanitize_text_field( $input[ $field ] );
			}
		}

		// Note: 'payment_details' was the old single global field, now replaced by
		// the Payment Methods admin item + per-invoice picker. Its historic value
		// is left in the option (if any) but is no longer read or shown anywhere.
		$textarea_fields = array( 'default_notes', 'company_address' );
		foreach ( $textarea_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$output[ $field ] = sanitize_textarea_field( $input[ $field ] );
			}
		}

		$int_fields = array( 'start_number', 'logo_id' );
		foreach ( $int_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$output[ $field ] = absint( $input[ $field ] );
			}
		}

		$float_fields = array( 'zar_rate', 'bwp_rate' );
		foreach ( $float_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$output[ $field ] = (float) $input[ $field ];
			}
		}

		return $output;
	}

	/**
	 * AJAX: fetch current USD→ZAR and USD→BWP rates from a live exchange-rate feed
	 * and save them straight away. Reports the real reason on failure — a silent
	 * "could not fetch" is impossible to act on when the host is the problem.
	 */
	public function ajax_fetch_rates() {
		check_ajax_referer( 'ci_fetch_rates', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'custom-invoices' ) ) );
		}

		$result = $this->fetch_live_rates();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$rates = $result['rates'];
		$zar   = isset( $rates['ZAR'] ) ? (float) $rates['ZAR'] : false;
		$bwp   = isset( $rates['BWP'] ) ? (float) $rates['BWP'] : false;

		if ( false === $zar && false === $bwp ) {
			wp_send_json_error(
				array(
					/* translators: %s: name of the exchange-rate feed */
					'message' => sprintf( __( '%s answered, but did not include ZAR or BWP. Enter the rates manually.', 'custom-invoices' ), $result['source'] ),
				)
			);
		}

		// Persist straight away — this can be triggered from the invoice screen,
		// which has no separate "Save Changes" step of its own for these rates.
		$settings = get_option( self::OPTION_KEY, array() );
		if ( false !== $zar ) {
			$settings['zar_rate'] = $zar;
		}
		if ( false !== $bwp ) {
			$settings['bwp_rate'] = $bwp;
		}
		update_option( self::OPTION_KEY, $settings );

		wp_send_json_success(
			array(
				'zar'    => false !== $zar ? $zar : null,
				'bwp'    => false !== $bwp ? $bwp : null,
				'source' => $result['source'],
			)
		);
	}

	/**
	 * The no-key, USD-based rate feeds we try, in order. Each entry names the
	 * key the rates live under and whether that feed uses lowercase codes.
	 *
	 * @return array
	 */
	private function rate_sources() {
		return array(
			array(
				'label' => 'ExchangeRate-API',
				'url'   => 'https://open.er-api.com/v6/latest/USD',
				'key'   => 'rates',
			),
			array(
				'label' => 'Currency-API',
				'url'   => 'https://latest.currency-api.pages.dev/v1/currencies/usd.json',
				'key'   => 'usd',
			),
			array(
				'label' => 'Currency-API (jsDelivr)',
				'url'   => 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json',
				'key'   => 'usd',
			),
		);
	}

	/**
	 * Tries each feed in turn and returns the first usable set of rates.
	 * Falling back matters: any single free feed goes down often enough that
	 * one outage should not leave the invoice screen without rates.
	 *
	 * @return array|WP_Error [ 'rates' => code => rate, 'source' => label ], or the collected errors.
	 */
	private function fetch_live_rates() {
		// A site can be configured to refuse all outbound requests; that produces
		// a WP_Error that reads like a network fault, so name it explicitly.
		if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL && ! $this->hosts_are_allowed() ) {
			return new WP_Error(
				'ci_rates_blocked',
				__( 'This site is set to block outgoing requests (WP_HTTP_BLOCK_EXTERNAL), so the rate feeds cannot be reached. Add open.er-api.com and latest.currency-api.pages.dev to WP_ACCESSIBLE_HOSTS in wp-config.php, or enter the rates manually.', 'custom-invoices' )
			);
		}

		$failures = array();

		foreach ( $this->rate_sources() as $source ) {
			$rates = $this->fetch_from_source( $source, $failure );

			if ( ! empty( $rates ) ) {
				return array(
					'rates'  => $rates,
					'source' => $source['label'],
				);
			}

			$failures[] = $source['label'] . ': ' . $failure;
		}

		return new WP_Error(
			'ci_rates_unavailable',
			__( 'Could not reach any exchange-rate feed — enter the rates manually below.', 'custom-invoices' ) . ' (' . implode( '; ', $failures ) . ')'
		);
	}

	/**
	 * @param array  $source  One entry from rate_sources().
	 * @param string $failure Set to a human-readable reason when this feed fails.
	 * @return array Currency code => rate, uppercased. Empty on failure.
	 */
	private function fetch_from_source( $source, &$failure ) {
		$failure = '';

		$response = wp_remote_get(
			$source['url'],
			array(
				'timeout'     => 15,
				'redirection' => 5,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$failure = $response->get_error_message();
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code */
			$failure = sprintf( __( 'HTTP %d', 'custom-invoices' ), $code );
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data[ $source['key'] ] ) || ! is_array( $data[ $source['key'] ] ) ) {
			$failure = __( 'unreadable response', 'custom-invoices' );
			return array();
		}

		// Feeds disagree on case (ZAR vs zar), so normalise before lookup.
		$rates = array();
		foreach ( $data[ $source['key'] ] as $currency => $rate ) {
			if ( is_numeric( $rate ) ) {
				$rates[ strtoupper( $currency ) ] = (float) $rate;
			}
		}

		if ( ! isset( $rates['ZAR'] ) && ! isset( $rates['BWP'] ) ) {
			$failure = __( 'no ZAR or BWP in response', 'custom-invoices' );
			return array();
		}

		return $rates;
	}

	/**
	 * True when WP_ACCESSIBLE_HOSTS already whitelists a feed we use, so a
	 * blocked-external site that has been configured for this still works.
	 */
	private function hosts_are_allowed() {
		if ( ! defined( 'WP_ACCESSIBLE_HOSTS' ) ) {
			return false;
		}

		$allowed = array_map( 'trim', explode( ',', WP_ACCESSIBLE_HOSTS ) );

		foreach ( $this->rate_sources() as $source ) {
			$host = wp_parse_url( $source['url'], PHP_URL_HOST );
			foreach ( $allowed as $pattern ) {
				if ( $pattern && ( $pattern === $host || fnmatch( $pattern, $host ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Saves the Business Details page — merges into the existing settings
	 * option so it never touches invoice numbering, currency, or rates.
	 */
	public function save_business_details() {
		if ( ! isset( $_POST['ci_business_nonce'] ) || ! wp_verify_nonce( $_POST['ci_business_nonce'], 'ci_save_business' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_option( self::OPTION_KEY, array() );

		$settings['company_name']    = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
		$settings['company_phone']   = isset( $_POST['company_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['company_phone'] ) ) : '';
		$settings['company_email']   = isset( $_POST['company_email'] ) ? sanitize_text_field( wp_unslash( $_POST['company_email'] ) ) : '';
		$settings['company_address'] = isset( $_POST['company_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['company_address'] ) ) : '';
		$settings['logo_id']         = isset( $_POST['logo_id'] ) ? absint( $_POST['logo_id'] ) : 0;

		update_option( self::OPTION_KEY, $settings );

		add_action( 'admin_notices', array( $this, 'business_saved_notice' ) );
	}

	public function business_saved_notice() {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Business details saved.', 'custom-invoices' ) . '</p></div>';
	}

	public function render_business_details_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$logo_id  = self::get( 'logo_id' );
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		?>
		<div class="wrap ci-settings-wrap">
			<h1><?php esc_html_e( 'Business Details', 'custom-invoices' ); ?></h1>
			<p><?php esc_html_e( 'This shows up on every invoice you create — logo, name, address, phone, and email.', 'custom-invoices' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'ci_save_business', 'ci_business_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ci_logo"><?php esc_html_e( 'Logo', 'custom-invoices' ); ?></label></th>
						<td>
							<div class="ci-logo-preview">
								<img id="ci_logo_preview" src="<?php echo esc_url( $logo_url ); ?>" style="max-width:180px;<?php echo $logo_url ? '' : 'display:none;'; ?>" />
							</div>
							<input type="hidden" name="logo_id" id="ci_logo_id" value="<?php echo esc_attr( $logo_id ); ?>" />
							<button type="button" class="button" id="ci_logo_upload"><?php esc_html_e( 'Select Logo', 'custom-invoices' ); ?></button>
							<button type="button" class="button" id="ci_logo_remove" style="<?php echo $logo_url ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'custom-invoices' ); ?></button>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="company_name"><?php esc_html_e( 'Company / Your Name', 'custom-invoices' ); ?></label></th>
						<td><input name="company_name" type="text" id="company_name" value="<?php echo esc_attr( self::get( 'company_name' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="company_address"><?php esc_html_e( 'Address', 'custom-invoices' ); ?></label></th>
						<td><textarea name="company_address" id="company_address" rows="3" class="large-text"><?php echo esc_textarea( self::get( 'company_address' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="company_phone"><?php esc_html_e( 'Phone', 'custom-invoices' ); ?></label></th>
						<td><input name="company_phone" type="text" id="company_phone" value="<?php echo esc_attr( self::get( 'company_phone' ) ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="company_email"><?php esc_html_e( 'Email', 'custom-invoices' ); ?></label></th>
						<td><input name="company_email" type="email" id="company_email" value="<?php echo esc_attr( self::get( 'company_email' ) ); ?>" class="regular-text" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Business Details', 'custom-invoices' ) ); ?>
			</form>
		</div>

		<script>
		jQuery(function($){
			var frame;
			$('#ci_logo_upload').on('click', function(e){
				e.preventDefault();
				if (frame) { frame.open(); return; }
				frame = wp.media({ title: 'Select Logo', multiple: false, library: { type: 'image' } });
				frame.on('select', function(){
					var attachment = frame.state().get('selection').first().toJSON();
					$('#ci_logo_id').val(attachment.id);
					$('#ci_logo_preview').attr('src', attachment.url).show();
					$('#ci_logo_remove').show();
				});
				frame.open();
			});
			$('#ci_logo_remove').on('click', function(e){
				e.preventDefault();
				$('#ci_logo_id').val('');
				$('#ci_logo_preview').hide();
				$(this).hide();
			});
		});
		</script>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap ci-settings-wrap">
			<h1><?php esc_html_e( 'Invoice Settings', 'custom-invoices' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: link to the Business Details page */
					esc_html__( 'Invoice numbering, currency, and payment instructions. Your company name, logo, and address are set on the %s page.', 'custom-invoices' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=invoice&page=ci-business-details' ) ) . '">' . esc_html__( 'Business Details', 'custom-invoices' ) . '</a>'
				);
				?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'ci_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Invoice Defaults', 'custom-invoices' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="invoice_prefix"><?php esc_html_e( 'Invoice Number Prefix', 'custom-invoices' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[invoice_prefix]" type="text" id="invoice_prefix" value="<?php echo esc_attr( self::get( 'invoice_prefix', '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. INV- (leave blank for plain numbers)', 'custom-invoices' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="start_number"><?php esc_html_e( 'Starting Invoice Number', 'custom-invoices' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[start_number]" type="number" min="0" id="start_number" value="<?php echo esc_attr( self::get( 'start_number', 285 ) ); ?>" class="small-text" />
						<p class="description"><?php esc_html_e( 'Used only for the very first invoice. After that, each new invoice automatically continues from the highest saved invoice number, formatted as 4 digits (e.g. 0285, 0286…) — still editable per invoice.', 'custom-invoices' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="currency_symbol"><?php esc_html_e( 'Currency Symbol', 'custom-invoices' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[currency_symbol]" type="text" id="currency_symbol" value="<?php echo esc_attr( self::get( 'currency_symbol', 'US$' ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="currency_code"><?php esc_html_e( 'Currency Code (shown on total)', 'custom-invoices' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[currency_code]" type="text" id="currency_code" value="<?php echo esc_attr( self::get( 'currency_code', 'USD' ) ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="default_notes"><?php esc_html_e( 'Default Notes to Customer', 'custom-invoices' ); ?></label></th>
						<td><textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_notes]" id="default_notes" rows="3" class="large-text"><?php echo esc_textarea( self::get( 'default_notes' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Pre-fills the notes field on new invoices — e.g. amounts in other currencies. Editable per-invoice.', 'custom-invoices' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment Details', 'custom-invoices' ); ?></th>
						<td>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the Payment Methods admin page */
									esc_html__( 'Payment details now live on their own page, so you can save several (bank transfer, eWallet, Mukuru, etc.) and pick which ones to show per invoice. Manage them on the %s page.', 'custom-invoices' ),
									'<a href="' . esc_url( admin_url( 'edit.php?post_type=ci_payment_method' ) ) . '">' . esc_html__( 'Payment Methods', 'custom-invoices' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Currency Conversion', 'custom-invoices' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Set your current exchange rates here — enter them manually, or fetch the latest rates (saved immediately either way). When ticked on an invoice, the converted total is calculated automatically from these rates and rounded to the nearest 10.', 'custom-invoices' ); ?></p>
				<p>
					<button type="button" class="button" id="ci-fetch-rates"><?php esc_html_e( 'Fetch Current Exchange Rates', 'custom-invoices' ); ?></button>
					<span id="ci-fetch-rates-status" style="margin-left:8px;"></span>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zar_rate"><?php esc_html_e( 'USD to ZAR Rate', 'custom-invoices' ); ?></label></th>
						<td>1 <?php echo esc_html( self::get( 'currency_code', 'USD' ) ); ?> = <input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[zar_rate]" type="number" step="any" min="0" id="zar_rate" value="<?php echo esc_attr( self::get( 'zar_rate', '' ) ); ?>" class="small-text" /> R</td>
					</tr>
					<tr>
						<th scope="row"><label for="bwp_rate"><?php esc_html_e( 'USD to BWP Rate', 'custom-invoices' ); ?></label></th>
						<td>1 <?php echo esc_html( self::get( 'currency_code', 'USD' ) ); ?> = <input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[bwp_rate]" type="number" step="any" min="0" id="bwp_rate" value="<?php echo esc_attr( self::get( 'bwp_rate', '' ) ); ?>" class="small-text" /> P</td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'Fetching pulls current USD-based rates from a live feed and saves them right away — no need to click Save Changes just for that. Several feeds are tried in turn, so one being down is not a problem; if they all fail, the exact reason is shown next to the button.', 'custom-invoices' ); ?></p>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
