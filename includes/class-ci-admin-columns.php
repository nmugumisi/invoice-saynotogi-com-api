<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds Invoice #, Client, Total, and Status columns to the Invoices admin list.
 */
class CI_Admin_Columns {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'manage_invoice_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_invoice_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['ci_number']  = __( 'Invoice #', 'custom-invoices' );
				$new['ci_client']  = __( 'Client', 'custom-invoices' );
				$new['ci_total']   = __( 'Total', 'custom-invoices' );
				$new['ci_status']  = __( 'Status', 'custom-invoices' );
			}
		}
		return $new;
	}

	public function render_column( $column, $post_id ) {
		$symbol = CI_Settings::get( 'currency_symbol', 'US$' );

		switch ( $column ) {
			case 'ci_number':
				echo esc_html( get_post_meta( $post_id, '_ci_invoice_number', true ) );
				break;

			case 'ci_client':
				echo esc_html( get_post_meta( $post_id, '_ci_bill_to_name', true ) );
				break;

			case 'ci_total':
				$total = (float) get_post_meta( $post_id, '_ci_total', true );
				echo esc_html( $symbol . number_format( $total, 2 ) );
				break;

			case 'ci_status':
				$total       = (float) get_post_meta( $post_id, '_ci_total', true );
				$amount_paid = (float) get_post_meta( $post_id, '_ci_amount_paid', true );
				$due         = $total - $amount_paid;
				if ( $total > 0 && $due <= 0.001 ) {
					echo '<span class="ci-badge ci-badge-paid">' . esc_html__( 'Paid', 'custom-invoices' ) . '</span>';
				} elseif ( $amount_paid > 0 ) {
					echo '<span class="ci-badge ci-badge-partial">' . esc_html__( 'Partial', 'custom-invoices' ) . '</span>';
				} else {
					echo '<span class="ci-badge ci-badge-due">' . esc_html__( 'Due', 'custom-invoices' ) . '</span>';
				}
				break;
		}
	}
}
