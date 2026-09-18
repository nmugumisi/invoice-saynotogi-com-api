<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API for the mobile/companion app: exposes the same invoices, clients,
 * payment methods, and settings that the WordPress admin screens manage, so
 * an external client can fetch and write the same data.
 *
 * Auth: the plugin's own API key (Settings > Invoice Settings > Mobile App),
 * sent as an X-CI-API-Key header — no WordPress account needed. A logged-in
 * administrator (cookie auth) is also accepted, for testing from a browser.
 */
class CI_REST_API {

	const NAMESPACE_V1 = 'custom-invoices/v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'rest_api_init', array( $this, 'allow_authorization_header_for_cors' ) );
	}

	/**
	 * WordPress core already sends Access-Control-Allow-Origin for REST
	 * requests, but doesn't know about our custom X-CI-API-Key header —
	 * without allowing it explicitly, a browser-based client gets blocked
	 * at the CORS preflight before our own permission check ever runs. A
	 * native app's HTTP client isn't subject to CORS, so this only matters
	 * for browser-based consumers.
	 */
	public function allow_authorization_header_for_cors() {
		add_filter(
			'rest_pre_serve_request',
			function ( $value ) {
				header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-CI-API-Key' );
				return $value;
			}
		);
	}

	/**
	 * Allows either a logged-in administrator (cookie auth — e.g. testing
	 * from a browser) or the plugin's own API key, sent by the app as
	 * X-CI-API-Key, so connecting the app never requires a WordPress
	 * account or Application Password.
	 */
	public function check_permission( $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$provided = $request instanceof WP_REST_Request ? $request->get_header( 'x-ci-api-key' ) : '';
		$expected = CI_Settings::get_api_key();

		if ( $provided && $expected && hash_equals( $expected, $provided ) ) {
			return true;
		}

		return new WP_Error(
			'ci_rest_forbidden',
			__( 'You are not allowed to access the invoicing API.', 'custom-invoices' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * JSON body if present, falling back to regular request params (query
	 * string / form-encoded) so the API is easy to poke with curl too.
	 */
	private function body( WP_REST_Request $request ) {
		$json = $request->get_json_params();
		return is_array( $json ) ? $json : $request->get_params();
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/clients',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_clients' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_client' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/clients/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_client' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_client' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_client' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/payment-methods',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_payment_methods' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_payment_method' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/payment-methods/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_payment_method' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_payment_method' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_payment_method' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/invoices',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_invoices' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_invoice' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/invoices/next-number',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_next_number' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/invoices/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_invoice' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_invoice' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_invoice' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	public function get_settings( $request ) {
		$logo_id  = (int) CI_Settings::get( 'logo_id', 0 );
		$zar_rate = CI_Settings::get( 'zar_rate', '' );
		$bwp_rate = CI_Settings::get( 'bwp_rate', '' );

		return rest_ensure_response(
			array(
				'company_name'    => CI_Settings::get( 'company_name', '' ),
				'company_address' => CI_Settings::get( 'company_address', '' ),
				'company_phone'   => CI_Settings::get( 'company_phone', '' ),
				'company_email'   => CI_Settings::get( 'company_email', '' ),
				'logo_url'        => $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '',
				'invoice_prefix'  => CI_Settings::get( 'invoice_prefix', '' ),
				'start_number'    => (int) CI_Settings::get( 'start_number', 285 ),
				'currency_symbol' => CI_Settings::get( 'currency_symbol', 'US$' ),
				'currency_code'   => CI_Settings::get( 'currency_code', 'USD' ),
				'default_notes'   => CI_Settings::get( 'default_notes', '' ),
				'zar_rate'        => '' !== $zar_rate ? (float) $zar_rate : null,
				'bwp_rate'        => '' !== $bwp_rate ? (float) $bwp_rate : null,
			)
		);
	}

	/**
	 * Merges into the existing settings option, same pattern the Business
	 * Details page and rate-fetcher already use — only keys present in the
	 * request body are touched.
	 */
	public function update_settings( $request ) {
		$p        = $this->body( $request );
		$settings = get_option( CI_Settings::OPTION_KEY, array() );

		$text_fields = array( 'company_name', 'company_phone', 'company_email', 'currency_symbol', 'currency_code', 'invoice_prefix' );
		foreach ( $text_fields as $field ) {
			if ( isset( $p[ $field ] ) ) {
				$settings[ $field ] = sanitize_text_field( $p[ $field ] );
			}
		}

		$textarea_fields = array( 'company_address', 'default_notes' );
		foreach ( $textarea_fields as $field ) {
			if ( isset( $p[ $field ] ) ) {
				$settings[ $field ] = sanitize_textarea_field( $p[ $field ] );
			}
		}

		if ( isset( $p['start_number'] ) ) {
			$settings['start_number'] = absint( $p['start_number'] );
		}
		if ( array_key_exists( 'zar_rate', $p ) ) {
			$settings['zar_rate'] = null === $p['zar_rate'] ? '' : (float) $p['zar_rate'];
		}
		if ( array_key_exists( 'bwp_rate', $p ) ) {
			$settings['bwp_rate'] = null === $p['bwp_rate'] ? '' : (float) $p['bwp_rate'];
		}

		update_option( CI_Settings::OPTION_KEY, $settings );

		return $this->get_settings( $request );
	}

	// ---------------------------------------------------------------------
	// Clients
	// ---------------------------------------------------------------------

	private function client_to_array( $id ) {
		return array(
			'id'      => (string) $id,
			'name'    => get_the_title( $id ),
			'address' => get_post_meta( $id, '_ci_client_address', true ),
			'email'   => get_post_meta( $id, '_ci_client_email', true ),
			'phone'   => get_post_meta( $id, '_ci_client_phone', true ),
		);
	}

	public function get_clients( $request ) {
		$clients = CI_Client_CPT::get_all_clients();
		$items   = array();
		foreach ( $clients as $id => $client ) {
			$items[] = array_merge( array( 'id' => (string) $id ), $client );
		}
		return rest_ensure_response( $items );
	}

	public function get_client( $request ) {
		$id = (int) $request['id'];
		if ( 'ci_client' !== get_post_type( $id ) ) {
			return $this->not_found( 'Client' );
		}
		return rest_ensure_response( $this->client_to_array( $id ) );
	}

	public function create_client( $request ) {
		$p = $this->body( $request );
		if ( empty( $p['name'] ) ) {
			return new WP_Error( 'ci_missing_name', __( 'name is required.', 'custom-invoices' ), array( 'status' => 400 ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'ci_client',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $p['name'] ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_ci_client_address', isset( $p['address'] ) ? sanitize_textarea_field( $p['address'] ) : '' );
		update_post_meta( $post_id, '_ci_client_email', isset( $p['email'] ) ? sanitize_text_field( $p['email'] ) : '' );
		update_post_meta( $post_id, '_ci_client_phone', isset( $p['phone'] ) ? sanitize_text_field( $p['phone'] ) : '' );

		$response = rest_ensure_response( $this->client_to_array( $post_id ) );
		$response->set_status( 201 );
		return $response;
	}

	public function update_client( $request ) {
		$id = (int) $request['id'];
		if ( 'ci_client' !== get_post_type( $id ) ) {
			return $this->not_found( 'Client' );
		}
		$p = $this->body( $request );

		if ( isset( $p['name'] ) && '' !== trim( $p['name'] ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => sanitize_text_field( $p['name'] ),
				)
			);
		}
		if ( isset( $p['address'] ) ) {
			update_post_meta( $id, '_ci_client_address', sanitize_textarea_field( $p['address'] ) );
		}
		if ( isset( $p['email'] ) ) {
			update_post_meta( $id, '_ci_client_email', sanitize_text_field( $p['email'] ) );
		}
		if ( isset( $p['phone'] ) ) {
			update_post_meta( $id, '_ci_client_phone', sanitize_text_field( $p['phone'] ) );
		}

		return rest_ensure_response( $this->client_to_array( $id ) );
	}

	public function delete_client( $request ) {
		$id = (int) $request['id'];
		if ( 'ci_client' !== get_post_type( $id ) ) {
			return $this->not_found( 'Client' );
		}
		wp_delete_post( $id, true );
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => (string) $id,
			)
		);
	}

	// ---------------------------------------------------------------------
	// Payment methods
	// ---------------------------------------------------------------------

	private function method_to_array( $id ) {
		$post = get_post( $id );
		return array(
			'id'      => (string) $id,
			'title'   => get_the_title( $id ),
			'content' => get_post_meta( $id, '_ci_payment_content', true ),
			'order'   => $post ? (int) $post->menu_order : 0,
		);
	}

	public function get_payment_methods( $request ) {
		$methods = CI_Payment_Method_CPT::get_all();
		$items   = array();
		foreach ( $methods as $id => $method ) {
			$items[] = array_merge( array( 'id' => (string) $id ), $method );
		}
		return rest_ensure_response( $items );
	}

	public function get_payment_method( $request ) {
		$id = (int) $request['id'];
		if ( 'ci_payment_method' !== get_post_type( $id ) ) {
			return $this->not_found( 'Payment method' );
		}
		return rest_ensure_response( $this->method_to_array( $id ) );
	}

	public function create_payment_method( $request ) {
		$p = $this->body( $request );
		if ( empty( $p['title'] ) ) {
			return new WP_Error( 'ci_missing_title', __( 'title is required.', 'custom-invoices' ), array( 'status' => 400 ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'ci_payment_method',
				'post_status' => 'publish',
				'post_title'  => sanitize_text_field( $p['title'] ),
				'menu_order'  => isset( $p['order'] ) ? absint( $p['order'] ) : 0,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_ci_payment_content', isset( $p['content'] ) ? sanitize_textarea_field( $p['content'] ) : '' );

		$response = rest_ensure_response( $this->method_to_array( $post_id ) );
		$response->set_status( 201 );
		return $response;
	}

	public function update_payment_method( $request ) {
		$id = (int) $request['id'];
		if ( 'ci_payment_method' !== get_post_type( $id ) ) {
			return $this->not_found( 'Payment method' );
		}
		$p = $this->body( $request );

		$update = array( 'ID' => $id );
		if ( isset( $p['title'] ) && '' !== trim( $p['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $p['title'] );
		}
		if ( isset( $p['order'] ) ) {
			$update['menu_order'] = absint( $p['order'] );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}
		if ( isset( $p['content'] ) ) {
			update_post_meta( $id, '_ci_payment_content', sanitize_textarea_field( $p['content'] ) );
		}

		return rest_ensure_response( $this->method_to_array( $id ) );
	}

	public function delete_payment_method( $request ) {
		$id = (int) $request['id'];
		if ( 'ci_payment_method' !== get_post_type( $id ) ) {
			return $this->not_found( 'Payment method' );
		}
		wp_delete_post( $id, true );
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => (string) $id,
			)
		);
	}

	// ---------------------------------------------------------------------
	// Invoices
	// ---------------------------------------------------------------------

	private function invoice_to_array( $id ) {
		$items_raw = get_post_meta( $id, '_ci_items', true );
		$items     = is_array( $items_raw ) ? array_values( $items_raw ) : array();
		$items     = array_map(
			function ( $item ) {
				return array(
					'desc'        => isset( $item['desc'] ) ? $item['desc'] : '',
					'description' => isset( $item['description'] ) ? $item['description'] : '',
					'qty'         => isset( $item['qty'] ) ? (float) $item['qty'] : 0,
					'price'       => isset( $item['price'] ) ? (float) $item['price'] : 0,
				);
			},
			$items
		);

		$total = 0;
		foreach ( $items as $item ) {
			$total += $item['qty'] * $item['price'];
		}
		$amount_paid = (float) get_post_meta( $id, '_ci_amount_paid', true );
		$due         = max( 0, $total - $amount_paid );
		if ( $total > 0 && $due <= 0.001 ) {
			$status = 'paid';
		} elseif ( $amount_paid > 0 ) {
			$status = 'partial';
		} else {
			$status = 'unpaid';
		}

		$method_ids = get_post_meta( $id, '_ci_payment_method_ids', true );
		$method_ids = is_array( $method_ids ) ? array_map( 'strval', $method_ids ) : array();

		$client_id = (int) get_post_meta( $id, '_ci_client_id', true );
		$zar_total = get_post_meta( $id, '_ci_zar_total', true );
		$zar_rate  = get_post_meta( $id, '_ci_zar_rate_used', true );
		$bwp_total = get_post_meta( $id, '_ci_bwp_total', true );
		$bwp_rate  = get_post_meta( $id, '_ci_bwp_rate_used', true );
		$post      = get_post( $id );

		return array(
			'id'                => (string) $id,
			'number'            => get_post_meta( $id, '_ci_invoice_number', true ),
			'date'              => get_post_meta( $id, '_ci_invoice_date', true ),
			'due_date'          => get_post_meta( $id, '_ci_due_date', true ),
			'client_id'         => $client_id ? (string) $client_id : null,
			'bill_to_name'      => get_post_meta( $id, '_ci_bill_to_name', true ),
			'bill_to_address'   => get_post_meta( $id, '_ci_bill_to_address', true ),
			'bill_to_email'     => get_post_meta( $id, '_ci_bill_to_email', true ),
			'bill_to_phone'     => get_post_meta( $id, '_ci_bill_to_phone', true ),
			'items'             => $items,
			'notes'             => get_post_meta( $id, '_ci_notes', true ),
			'payment_method_ids' => $method_ids,
			'amount_paid'       => $amount_paid,
			'total'             => $total,
			'amount_due'        => $due,
			'status'            => $status,
			'show_zar'          => '1' === get_post_meta( $id, '_ci_show_zar', true ),
			'show_bwp'          => '1' === get_post_meta( $id, '_ci_show_bwp', true ),
			'zar_total'         => '' !== $zar_total ? (float) $zar_total : null,
			'zar_rate_used'     => '' !== $zar_rate ? (float) $zar_rate : null,
			'bwp_total'         => '' !== $bwp_total ? (float) $bwp_total : null,
			'bwp_rate_used'     => '' !== $bwp_rate ? (float) $bwp_rate : null,
			'created_at'        => $post ? mysql_to_rfc3339( $post->post_date_gmt ) : null,
			'updated_at'        => $post ? mysql_to_rfc3339( $post->post_modified_gmt ) : null,
		);
	}

	public function get_invoices( $request ) {
		$ids = get_posts(
			array(
				'post_type'      => 'invoice',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'meta_value',
				'meta_key'       => '_ci_invoice_date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		return rest_ensure_response( array_map( array( $this, 'invoice_to_array' ), $ids ) );
	}

	public function get_invoice( $request ) {
		$id = (int) $request['id'];
		if ( 'invoice' !== get_post_type( $id ) ) {
			return $this->not_found( 'Invoice' );
		}
		return rest_ensure_response( $this->invoice_to_array( $id ) );
	}

	public function get_next_number( $request ) {
		return rest_ensure_response( array( 'number' => CI_Invoice_Metabox::next_invoice_number() ) );
	}

	public function create_invoice( $request ) {
		$p = $this->body( $request );

		if ( empty( $p['client_id'] ) ) {
			return new WP_Error( 'ci_missing_client', __( 'client_id is required.', 'custom-invoices' ), array( 'status' => 400 ) );
		}
		$client_id = absint( $p['client_id'] );
		if ( 'ci_client' !== get_post_type( $client_id ) ) {
			return new WP_Error( 'ci_invalid_client', __( 'client_id does not refer to a known client.', 'custom-invoices' ), array( 'status' => 400 ) );
		}

		$number = ! empty( $p['number'] ) ? sanitize_text_field( $p['number'] ) : CI_Invoice_Metabox::next_invoice_number();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'invoice',
				'post_status' => 'publish',
				'post_title'  => $number,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->apply_invoice_fields( $post_id, $p, $number, $client_id );

		$response = rest_ensure_response( $this->invoice_to_array( $post_id ) );
		$response->set_status( 201 );
		return $response;
	}

	public function update_invoice( $request ) {
		$id = (int) $request['id'];
		if ( 'invoice' !== get_post_type( $id ) ) {
			return $this->not_found( 'Invoice' );
		}
		$p = $this->body( $request );

		$client_id = isset( $p['client_id'] ) ? absint( $p['client_id'] ) : (int) get_post_meta( $id, '_ci_client_id', true );
		if ( $client_id && 'ci_client' !== get_post_type( $client_id ) ) {
			return new WP_Error( 'ci_invalid_client', __( 'client_id does not refer to a known client.', 'custom-invoices' ), array( 'status' => 400 ) );
		}

		$number = ! empty( $p['number'] ) ? sanitize_text_field( $p['number'] ) : get_post_meta( $id, '_ci_invoice_number', true );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => $number,
			)
		);

		$this->apply_invoice_fields( $id, $p, $number, $client_id );

		return rest_ensure_response( $this->invoice_to_array( $id ) );
	}

	/**
	 * Writes the invoice meta shared by create and update. Mirrors
	 * CI_Invoice_Metabox::save() field-for-field so invoices created or
	 * edited via the API behave identically to ones saved in wp-admin
	 * (same bill-to snapshot, same "only lock the rate when checked" rule
	 * for ZAR/BWP totals).
	 */
	private function apply_invoice_fields( $post_id, $p, $number, $client_id ) {
		update_post_meta( $post_id, '_ci_invoice_number', $number );
		if ( isset( $p['date'] ) ) {
			update_post_meta( $post_id, '_ci_invoice_date', sanitize_text_field( $p['date'] ) );
		}
		if ( isset( $p['due_date'] ) ) {
			update_post_meta( $post_id, '_ci_due_date', sanitize_text_field( $p['due_date'] ) );
		}

		update_post_meta( $post_id, '_ci_client_id', $client_id );
		if ( $client_id ) {
			update_post_meta( $post_id, '_ci_bill_to_name', get_the_title( $client_id ) );
			update_post_meta( $post_id, '_ci_bill_to_address', get_post_meta( $client_id, '_ci_client_address', true ) );
			update_post_meta( $post_id, '_ci_bill_to_email', get_post_meta( $client_id, '_ci_client_email', true ) );
			update_post_meta( $post_id, '_ci_bill_to_phone', get_post_meta( $client_id, '_ci_client_phone', true ) );
		}

		if ( isset( $p['notes'] ) ) {
			update_post_meta( $post_id, '_ci_notes', sanitize_textarea_field( $p['notes'] ) );
		}

		if ( isset( $p['payment_method_ids'] ) && is_array( $p['payment_method_ids'] ) ) {
			update_post_meta( $post_id, '_ci_payment_method_ids', array_map( 'absint', $p['payment_method_ids'] ) );
		}

		if ( isset( $p['amount_paid'] ) ) {
			update_post_meta( $post_id, '_ci_amount_paid', (float) $p['amount_paid'] );
		}

		$total = null;
		if ( isset( $p['items'] ) && is_array( $p['items'] ) ) {
			$items = array();
			foreach ( $p['items'] as $item ) {
				$desc        = isset( $item['desc'] ) ? sanitize_text_field( $item['desc'] ) : '';
				$description = isset( $item['description'] ) ? sanitize_text_field( $item['description'] ) : '';
				$qty         = isset( $item['qty'] ) ? (float) $item['qty'] : 0;
				$price       = isset( $item['price'] ) ? (float) $item['price'] : 0;
				if ( '' === $desc && '' === $description && 0 === $qty && 0 === $price ) {
					continue;
				}
				$items[] = compact( 'desc', 'description', 'qty', 'price' );
			}
			update_post_meta( $post_id, '_ci_items', $items );

			$total = 0;
			foreach ( $items as $item ) {
				$total += $item['qty'] * $item['price'];
			}
			update_post_meta( $post_id, '_ci_total', $total );
		}

		if ( isset( $p['show_zar'] ) ) {
			update_post_meta( $post_id, '_ci_show_zar', $p['show_zar'] ? '1' : '' );
		}
		if ( isset( $p['show_bwp'] ) ) {
			update_post_meta( $post_id, '_ci_show_bwp', $p['show_bwp'] ? '1' : '' );
		}

		if ( null === $total ) {
			$items_raw = get_post_meta( $post_id, '_ci_items', true );
			$total     = 0;
			if ( is_array( $items_raw ) ) {
				foreach ( $items_raw as $item ) {
					$total += ( isset( $item['qty'] ) ? (float) $item['qty'] : 0 ) * ( isset( $item['price'] ) ? (float) $item['price'] : 0 );
				}
			}
		}

		if ( ! empty( $p['show_zar'] ) ) {
			$zar_rate = (float) CI_Settings::get( 'zar_rate', 0 );
			if ( $zar_rate > 0 ) {
				update_post_meta( $post_id, '_ci_zar_total', round( ( $total * $zar_rate ) / 10 ) * 10 );
				update_post_meta( $post_id, '_ci_zar_rate_used', $zar_rate );
			}
		}
		if ( ! empty( $p['show_bwp'] ) ) {
			$bwp_rate = (float) CI_Settings::get( 'bwp_rate', 0 );
			if ( $bwp_rate > 0 ) {
				update_post_meta( $post_id, '_ci_bwp_total', round( ( $total * $bwp_rate ) / 10 ) * 10 );
				update_post_meta( $post_id, '_ci_bwp_rate_used', $bwp_rate );
			}
		}
	}

	public function delete_invoice( $request ) {
		$id = (int) $request['id'];
		if ( 'invoice' !== get_post_type( $id ) ) {
			return $this->not_found( 'Invoice' );
		}
		wp_delete_post( $id, true );
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => (string) $id,
			)
		);
	}

	private function not_found( $what ) {
		return new WP_Error(
			'ci_rest_not_found',
			sprintf(
				/* translators: %s: type of resource, e.g. "Invoice" */
				__( '%s not found.', 'custom-invoices' ),
				$what
			),
			array( 'status' => 404 )
		);
	}
}
