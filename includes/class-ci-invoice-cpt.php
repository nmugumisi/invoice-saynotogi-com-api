<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Invoice" custom post type.
 * Each invoice is a post; all invoice-specific data lives in post meta (see class-ci-invoice-metabox.php).
 */
class CI_Invoice_CPT {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ) );
	}

	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Invoices', 'custom-invoices' ),
			'singular_name'      => __( 'Invoice', 'custom-invoices' ),
			'add_new'            => __( 'Add New', 'custom-invoices' ),
			'add_new_item'       => __( 'Add New Invoice', 'custom-invoices' ),
			'edit_item'          => __( 'Edit Invoice', 'custom-invoices' ),
			'new_item'           => __( 'New Invoice', 'custom-invoices' ),
			'view_item'          => __( 'View Invoice', 'custom-invoices' ),
			'search_items'       => __( 'Search Invoices', 'custom-invoices' ),
			'not_found'          => __( 'No invoices found', 'custom-invoices' ),
			'not_found_in_trash' => __( 'No invoices found in Trash', 'custom-invoices' ),
			'menu_name'          => __( 'Invoices', 'custom-invoices' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'invoice' ),
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => 25,
			'menu_icon'          => 'dashicons-media-spreadsheet',
			'supports'           => array( 'title' ),
			'show_in_rest'       => false,
		);

		register_post_type( 'invoice', $args );
	}

	public function title_placeholder( $title ) {
		$screen = get_current_screen();
		if ( $screen && 'invoice' === $screen->post_type ) {
			$title = __( 'Client / job reference (internal — not shown on the invoice)', 'custom-invoices' );
		}
		return $title;
	}
}
