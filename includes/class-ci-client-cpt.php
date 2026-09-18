<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Clients" contact book — a simple CPT storing name/address/email/phone,
 * used to populate the Bill To fields on an invoice via a dropdown.
 */
class CI_Client_CPT {

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
		add_action( 'add_meta_boxes', array( $this, 'add_boxes' ) );
		add_action( 'save_post_ci_client', array( $this, 'save' ) );
		add_filter( 'manage_ci_client_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_ci_client_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Clients', 'custom-invoices' ),
			'singular_name'      => __( 'Client', 'custom-invoices' ),
			'add_new'            => __( 'Add New', 'custom-invoices' ),
			'add_new_item'       => __( 'Add New Client', 'custom-invoices' ),
			'edit_item'          => __( 'Edit Client', 'custom-invoices' ),
			'new_item'           => __( 'New Client', 'custom-invoices' ),
			'view_item'          => __( 'View Client', 'custom-invoices' ),
			'search_items'       => __( 'Search Clients', 'custom-invoices' ),
			'not_found'          => __( 'No clients found', 'custom-invoices' ),
			'not_found_in_trash' => __( 'No clients found in Trash', 'custom-invoices' ),
			'menu_name'          => __( 'Clients', 'custom-invoices' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'edit.php?post_type=invoice',
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title' ),
			'show_in_rest'       => false,
		);

		register_post_type( 'ci_client', $args );
	}

	public function title_placeholder( $title ) {
		$screen = get_current_screen();
		if ( $screen && 'ci_client' === $screen->post_type ) {
			$title = __( 'Client name', 'custom-invoices' );
		}
		return $title;
	}

	public function add_boxes() {
		add_meta_box( 'ci_client_details', __( 'Client Details', 'custom-invoices' ), array( $this, 'render_details' ), 'ci_client', 'normal', 'high' );
	}

	public function render_details( $post ) {
		wp_nonce_field( 'ci_save_client', 'ci_client_nonce' );

		$address = get_post_meta( $post->ID, '_ci_client_address', true );
		$email   = get_post_meta( $post->ID, '_ci_client_email', true );
		$phone   = get_post_meta( $post->ID, '_ci_client_phone', true );
		?>
		<table class="form-table ci-form-table">
			<tr>
				<th><label for="ci_client_address"><?php esc_html_e( 'Address', 'custom-invoices' ); ?></label></th>
				<td><textarea id="ci_client_address" name="ci_client_address" rows="3" class="large-text"><?php echo esc_textarea( $address ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="ci_client_email"><?php esc_html_e( 'Email', 'custom-invoices' ); ?></label></th>
				<td><input type="email" id="ci_client_email" name="ci_client_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="ci_client_phone"><?php esc_html_e( 'Phone', 'custom-invoices' ); ?></label></th>
				<td><input type="text" id="ci_client_phone" name="ci_client_phone" value="<?php echo esc_attr( $phone ); ?>" class="regular-text" /></td>
			</tr>
		</table>
		<?php
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST['ci_client_nonce'] ) || ! wp_verify_nonce( $_POST['ci_client_nonce'], 'ci_save_client' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['ci_client_address'] ) ) {
			update_post_meta( $post_id, '_ci_client_address', sanitize_textarea_field( wp_unslash( $_POST['ci_client_address'] ) ) );
		}
		if ( isset( $_POST['ci_client_email'] ) ) {
			update_post_meta( $post_id, '_ci_client_email', sanitize_text_field( wp_unslash( $_POST['ci_client_email'] ) ) );
		}
		if ( isset( $_POST['ci_client_phone'] ) ) {
			update_post_meta( $post_id, '_ci_client_phone', sanitize_text_field( wp_unslash( $_POST['ci_client_phone'] ) ) );
		}
	}

	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['ci_client_email'] = __( 'Email', 'custom-invoices' );
				$new['ci_client_phone'] = __( 'Phone', 'custom-invoices' );
			}
		}
		return $new;
	}

	public function render_column( $column, $post_id ) {
		if ( 'ci_client_email' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_ci_client_email', true ) );
		} elseif ( 'ci_client_phone' === $column ) {
			echo esc_html( get_post_meta( $post_id, '_ci_client_phone', true ) );
		}
	}

	/**
	 * All clients as a simple array for the invoice dropdown / JS auto-fill.
	 *
	 * @return array [ id => [ name, address, email, phone ] ]
	 */
	public static function get_all_clients() {
		$posts = get_posts(
			array(
				'post_type'      => 'ci_client',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$clients = array();
		foreach ( $posts as $post ) {
			$clients[ $post->ID ] = array(
				'name'    => $post->post_title,
				'address' => get_post_meta( $post->ID, '_ci_client_address', true ),
				'email'   => get_post_meta( $post->ID, '_ci_client_email', true ),
				'phone'   => get_post_meta( $post->ID, '_ci_client_phone', true ),
			);
		}
		return $clients;
	}
}
