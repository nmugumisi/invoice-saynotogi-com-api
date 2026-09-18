<?php
/**
 * Plugin Name: Custom Invoices
 * Description: Simple invoice creation plugin — settings page for your company/payment details, an invoice form (custom post type), and a printable invoice layout.
 * Version: 1.1.6
 * Author: Nyasha
 * Text Domain: custom-invoices
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'CI_PLUGIN_FILE', __FILE__ );
define( 'CI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CI_VERSION', '1.1.6' );

require_once CI_PLUGIN_DIR . 'includes/class-ci-settings.php';
require_once CI_PLUGIN_DIR . 'includes/class-ci-invoice-cpt.php';
require_once CI_PLUGIN_DIR . 'includes/class-ci-client-cpt.php';
require_once CI_PLUGIN_DIR . 'includes/class-ci-payment-method-cpt.php';
require_once CI_PLUGIN_DIR . 'includes/class-ci-invoice-metabox.php';
require_once CI_PLUGIN_DIR . 'includes/class-ci-invoice-template.php';
require_once CI_PLUGIN_DIR . 'includes/class-ci-admin-columns.php';

/**
 * Main plugin bootstrap.
 */
final class Custom_Invoices {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		CI_Settings::instance();
		CI_Invoice_CPT::instance();
		CI_Client_CPT::instance();
		CI_Payment_Method_CPT::instance();
		CI_Invoice_Metabox::instance();
		CI_Invoice_Template::instance();
		CI_Admin_Columns::instance();

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
	}

	public function admin_assets( $hook ) {
		global $post_type;

		if ( 'invoice' === $post_type || 'ci_client' === $post_type || 'ci_payment_method' === $post_type ) {
			wp_enqueue_style( 'ci-admin', CI_PLUGIN_URL . 'assets/css/admin.css', array(), CI_VERSION );
			wp_enqueue_script( 'ci-admin', CI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), CI_VERSION, true );

			if ( 'invoice' === $post_type ) {
				wp_localize_script( 'ci-admin', 'ciClients', CI_Client_CPT::get_all_clients() );
				wp_localize_script(
					'ci-admin',
					'ciRates',
					array(
						'zar' => (float) CI_Settings::get( 'zar_rate', 0 ),
						'bwp' => (float) CI_Settings::get( 'bwp_rate', 0 ),
					)
				);
				wp_localize_script(
					'ci-admin',
					'ciFetchRates',
					array(
						'nonce' => wp_create_nonce( 'ci_fetch_rates' ),
					)
				);
			}
		}

		if ( 'settings_page_ci-invoice-settings' === $hook || 'invoice_page_ci-business-details' === $hook ) {
			wp_enqueue_style( 'ci-admin', CI_PLUGIN_URL . 'assets/css/admin.css', array(), CI_VERSION );
			wp_enqueue_media();

			if ( 'settings_page_ci-invoice-settings' === $hook ) {
				wp_enqueue_script( 'ci-admin', CI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), CI_VERSION, true );
				wp_localize_script(
					'ci-admin',
					'ciFetchRates',
					array(
						'nonce' => wp_create_nonce( 'ci_fetch_rates' ),
					)
				);
			}
		}
	}
}

function custom_invoices() {
	return Custom_Invoices::instance();
}
add_action( 'plugins_loaded', 'custom_invoices' );

/**
 * Activation: flush rewrite rules so the invoice CPT permalinks work.
 */
function ci_activate() {
	require_once CI_PLUGIN_DIR . 'includes/class-ci-invoice-cpt.php';
	CI_Invoice_CPT::instance()->register_post_type();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'ci_activate' );

function ci_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ci_deactivate' );
