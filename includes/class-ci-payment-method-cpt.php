<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Payment Methods" — a library of free-form payment details entries (e.g.
 * "Bank Transfer (USD)", "PayPal", "Mukuru"), each with a title and a
 * free-form body. On each invoice, the admin picks which of these to show
 * under the "Payment details" heading in the printed Notes to Customer.
 */
class CI_Payment_Method_CPT {

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
		add_action( 'save_post_ci_payment_method', array( $this, 'save' ) );
		add_filter( 'manage_ci_payment_method_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_ci_payment_method_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-ci_payment_method_sortable_columns', array( $this, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( $this, 'default_admin_order' ) );
	}

	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Payment Methods', 'custom-invoices' ),
			'singular_name'      => __( 'Payment Method', 'custom-invoices' ),
			'add_new'            => __( 'Add New', 'custom-invoices' ),
			'add_new_item'       => __( 'Add New Payment Method', 'custom-invoices' ),
			'edit_item'          => __( 'Edit Payment Method', 'custom-invoices' ),
			'new_item'           => __( 'New Payment Method', 'custom-invoices' ),
			'view_item'          => __( 'View Payment Method', 'custom-invoices' ),
			'search_items'       => __( 'Search Payment Methods', 'custom-invoices' ),
			'not_found'          => __( 'No payment methods found', 'custom-invoices' ),
			'not_found_in_trash' => __( 'No payment methods found in Trash', 'custom-invoices' ),
			'menu_name'          => __( 'Payment Methods', 'custom-invoices' ),
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
			'supports'           => array( 'title', 'page-attributes' ),
			'show_in_rest'       => false,
		);

		register_post_type( 'ci_payment_method', $args );
	}

	public function title_placeholder( $title ) {
		$screen = get_current_screen();
		if ( $screen && 'ci_payment_method' === $screen->post_type ) {
			$title = __( 'e.g. Bank Transfer (USD)', 'custom-invoices' );
		}
		return $title;
	}

	public function add_boxes() {
		add_meta_box( 'ci_payment_method_details', __( 'Payment Details', 'custom-invoices' ), array( $this, 'render_details' ), 'ci_payment_method', 'normal', 'high' );
	}

	public function render_details( $post ) {
		wp_nonce_field( 'ci_save_payment_method', 'ci_payment_method_nonce' );

		$content = get_post_meta( $post->ID, '_ci_payment_content', true );
		?>
		<p class="description"><?php esc_html_e( 'Free-form — write this out however you want it to appear on the invoice (account numbers, wallet numbers, instructions, etc).', 'custom-invoices' ); ?></p>
		<textarea id="ci_payment_content" name="ci_payment_content" rows="6" class="large-text code"><?php echo esc_textarea( $content ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Tip: use the "Order" box in the sidebar to set this method\'s priority — lower numbers show first, both in the invoice checklist and on the printed invoice.', 'custom-invoices' ); ?></p>
		<?php
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST['ci_payment_method_nonce'] ) || ! wp_verify_nonce( $_POST['ci_payment_method_nonce'], 'ci_save_payment_method' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['ci_payment_content'] ) ) {
			update_post_meta( $post_id, '_ci_payment_content', sanitize_textarea_field( wp_unslash( $_POST['ci_payment_content'] ) ) );
		}
	}

	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['ci_priority']       = __( 'Priority', 'custom-invoices' );
				$new['ci_payment_preview'] = __( 'Details', 'custom-invoices' );
			}
		}
		return $new;
	}

	public function render_column( $column, $post_id ) {
		if ( 'ci_priority' === $column ) {
			$post = get_post( $post_id );
			echo esc_html( $post ? $post->menu_order : 0 );
		} elseif ( 'ci_payment_preview' === $column ) {
			$content = get_post_meta( $post_id, '_ci_payment_content', true );
			echo esc_html( wp_trim_words( $content, 12 ) );
		}
	}

	/**
	 * Makes the Priority column clickable to sort the list table by it.
	 */
	public function sortable_columns( $columns ) {
		$columns['ci_priority'] = 'menu_order';
		return $columns;
	}

	/**
	 * Payment methods list defaults to priority order (lowest first, i.e. the
	 * same order they're shown in on the invoice), unless the admin has
	 * explicitly clicked a different column to sort by.
	 */
	public function default_admin_order( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'ci_payment_method' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( ! $query->get( 'orderby' ) ) {
			$query->set( 'orderby', 'menu_order title' );
			$query->set( 'order', 'ASC' );
		}
	}

	/**
	 * All payment methods as a simple array for the invoice checkbox list,
	 * in priority order (lowest number first — set via the "Order" field
	 * on each payment method, labeled Priority in the list table).
	 *
	 * @return array [ id => [ title, content ] ]
	 */
	public static function get_all() {
		$posts = get_posts(
			array(
				'post_type'      => 'ci_payment_method',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
			)
		);

		$methods = array();
		foreach ( $posts as $post ) {
			$methods[ $post->ID ] = array(
				'title'   => $post->post_title,
				'content' => get_post_meta( $post->ID, '_ci_payment_content', true ),
			);
		}
		return $methods;
	}
}
