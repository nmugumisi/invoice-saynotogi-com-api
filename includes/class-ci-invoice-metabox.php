<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The invoice edit form: invoice number/dates, bill-to details, line items, notes, amount paid.
 */
class CI_Invoice_Metabox {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_boxes' ) );
		add_action( 'save_post_invoice', array( $this, 'save' ) );
		add_action( 'admin_notices', array( $this, 'shortcode_notice' ) );
	}

	public function add_boxes() {
		add_meta_box( 'ci_invoice_details', __( 'Invoice Details', 'custom-invoices' ), array( $this, 'render_details' ), 'invoice', 'normal', 'high' );
		add_meta_box( 'ci_bill_to', __( 'Bill To', 'custom-invoices' ), array( $this, 'render_bill_to' ), 'invoice', 'normal', 'high' );
		add_meta_box( 'ci_line_items', __( 'Items', 'custom-invoices' ), array( $this, 'render_items' ), 'invoice', 'normal', 'high' );
		add_meta_box( 'ci_notes_payment', __( 'Notes & Payment', 'custom-invoices' ), array( $this, 'render_notes' ), 'invoice', 'normal', 'default' );
		add_meta_box( 'ci_preview', __( 'Invoice', 'custom-invoices' ), array( $this, 'render_preview_box' ), 'invoice', 'side', 'default' );
	}

	/**
	 * Next invoice number: one more than the highest number among already-saved
	 * invoices, or the configured starting number if none exist yet.
	 */
	public static function next_invoice_number() {
		$prefix = CI_Settings::get( 'invoice_prefix', '' );
		$start  = (int) CI_Settings::get( 'start_number', 285 );

		$existing_ids = get_posts(
			array(
				'post_type'      => 'invoice',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$max = 0;
		foreach ( $existing_ids as $id ) {
			$number = get_post_meta( $id, '_ci_invoice_number', true );
			$digits = preg_replace( '/[^0-9]/', '', $number );
			if ( '' === $digits ) {
				continue;
			}
			$value = (int) $digits;
			if ( $value > $max ) {
				$max = $value;
			}
		}

		$next = $max > 0 ? $max + 1 : max( $start, 0 );

		return $prefix . str_pad( $next, 4, '0', STR_PAD_LEFT );
	}

	public function render_details( $post ) {
		wp_nonce_field( 'ci_save_invoice', 'ci_invoice_nonce' );

		$number   = get_post_meta( $post->ID, '_ci_invoice_number', true );
		$date     = get_post_meta( $post->ID, '_ci_invoice_date', true );
		$due      = get_post_meta( $post->ID, '_ci_due_date', true );
		$paid_amt = get_post_meta( $post->ID, '_ci_amount_paid', true );

		if ( 'auto-draft' === $post->post_status && ! $number ) {
			$number = self::next_invoice_number();
		}
		if ( ! $date ) {
			$date = current_time( 'Y-m-d' );
		}
		if ( ! $due ) {
			$due = current_time( 'Y-m-d' );
		}
		?>
		<table class="form-table ci-form-table">
			<tr>
				<th><label for="ci_invoice_number"><?php esc_html_e( 'Invoice No#', 'custom-invoices' ); ?></label></th>
				<td><input type="text" id="ci_invoice_number" name="ci_invoice_number" value="<?php echo esc_attr( $number ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="ci_invoice_date"><?php esc_html_e( 'Invoice Date', 'custom-invoices' ); ?></label></th>
				<td><input type="date" id="ci_invoice_date" name="ci_invoice_date" value="<?php echo esc_attr( $date ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ci_due_date"><?php esc_html_e( 'Due Date', 'custom-invoices' ); ?></label></th>
				<td><input type="date" id="ci_due_date" name="ci_due_date" value="<?php echo esc_attr( $due ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ci_amount_paid"><?php esc_html_e( 'Amount Paid', 'custom-invoices' ); ?></label></th>
				<td><input type="number" step="0.01" min="0" id="ci_amount_paid" name="ci_amount_paid" value="<?php echo esc_attr( $paid_amt ? $paid_amt : '0' ); ?>" class="ci-recalc" />
				<p class="description"><?php esc_html_e( 'Leave as 0 until payment is received. The invoice will mark itself PAID once this equals the total.', 'custom-invoices' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	public function render_bill_to( $post ) {
		$client_id = get_post_meta( $post->ID, '_ci_client_id', true );
		$clients   = CI_Client_CPT::get_all_clients();
		?>
		<table class="form-table ci-form-table">
			<tr>
				<th><label for="ci_client_select"><?php esc_html_e( 'Client', 'custom-invoices' ); ?></label></th>
				<td>
					<select id="ci_client_select" name="ci_client_id">
						<option value=""><?php esc_html_e( '— Select a client —', 'custom-invoices' ); ?></option>
						<?php foreach ( $clients as $id => $client ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $client_id, $id ); ?>><?php echo esc_html( $client['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to add a new client */
							esc_html__( 'Client details come from the contacts book — nothing to type here. %s', 'custom-invoices' ),
							'<a href="' . esc_url( admin_url( 'post-new.php?post_type=ci_client' ) ) . '" target="_blank">' . esc_html__( 'Add a new client', 'custom-invoices' ) . '</a>'
						);
						?>
					</p>
					<div id="ci-client-preview" class="ci-client-preview">
						<?php echo $this->client_preview_html( isset( $clients[ $client_id ] ) ? $clients[ $client_id ] : null ); ?>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Read-only summary of the selected client shown under the dropdown.
	 */
	private function client_preview_html( $client ) {
		if ( ! $client ) {
			return '<p class="description">' . esc_html__( 'No client selected yet.', 'custom-invoices' ) . '</p>';
		}
		ob_start();
		?>
		<p class="ci-client-preview-name"><strong><?php echo esc_html( $client['name'] ); ?></strong></p>
		<?php if ( ! empty( $client['address'] ) ) : ?><p><?php echo nl2br( esc_html( $client['address'] ) ); ?></p><?php endif; ?>
		<?php if ( ! empty( $client['email'] ) ) : ?><p><?php echo esc_html( $client['email'] ); ?></p><?php endif; ?>
		<?php if ( ! empty( $client['phone'] ) ) : ?><p><?php esc_html_e( 'Phone:', 'custom-invoices' ); ?> <?php echo esc_html( $client['phone'] ); ?></p><?php endif; ?>
		<?php
		return ob_get_clean();
	}

	public function render_items( $post ) {
		$items = get_post_meta( $post->ID, '_ci_items', true );
		if ( ! is_array( $items ) || empty( $items ) ) {
			$items = array( array( 'desc' => '', 'description' => '', 'qty' => 1, 'price' => 0 ) );
		}
		$symbol = CI_Settings::get( 'currency_symbol', 'US$' );
		?>
		<table class="widefat ci-items-table" id="ci-items-table">
			<thead>
				<tr>
					<th style="width:10%"><?php esc_html_e( '#', 'custom-invoices' ); ?></th>
					<th><?php esc_html_e( 'Item & Description', 'custom-invoices' ); ?></th>
					<th style="width:10%"><?php esc_html_e( 'Qty/Hrs', 'custom-invoices' ); ?></th>
					<th style="width:15%"><?php esc_html_e( 'Price', 'custom-invoices' ); ?></th>
					<th style="width:15%"><?php esc_html_e( 'Amount', 'custom-invoices' ); ?></th>
					<th style="width:5%"></th>
				</tr>
			</thead>
			<tbody id="ci-items-body">
			<?php foreach ( $items as $i => $item ) : ?>
				<?php $this->render_item_row( $i, $item ); ?>
			<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<td colspan="6">
						<button type="button" class="button" id="ci-add-row"><?php esc_html_e( '+ Add Item', 'custom-invoices' ); ?></button>
					</td>
				</tr>
			</tfoot>
		</table>

		<table class="ci-totals-table">
			<tr>
				<th><?php esc_html_e( 'Subtotal', 'custom-invoices' ); ?></th>
				<td><span class="ci-currency-symbol"><?php echo esc_html( $symbol ); ?></span><span id="ci-subtotal">0.00</span></td>
			</tr>
			<tr class="ci-total-row">
				<th><?php esc_html_e( 'TOTAL', 'custom-invoices' ); ?></th>
				<td><span class="ci-currency-symbol"><?php echo esc_html( $symbol ); ?></span><span id="ci-total">0.00</span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Amount Due', 'custom-invoices' ); ?></th>
				<td><span class="ci-currency-symbol"><?php echo esc_html( $symbol ); ?></span><span id="ci-due">0.00</span></td>
			</tr>
		</table>

		<script type="text/template" id="ci-row-template">
			<?php $this->render_item_row( '__INDEX__', array( 'desc' => '', 'description' => '', 'qty' => 1, 'price' => 0 ), true ); ?>
		</script>
		<?php
	}

	private function render_item_row( $index, $item, $is_template = false ) {
		$row_num     = $is_template ? '' : ( (int) $index + 1 );
		$description = isset( $item['description'] ) ? $item['description'] : '';
		?>
		<tr class="ci-item-row">
			<td class="ci-row-num"><?php echo esc_html( $row_num ); ?></td>
			<td>
				<input type="text" class="large-text ci-item-desc" name="ci_items[<?php echo esc_attr( $index ); ?>][desc]" value="<?php echo esc_attr( $item['desc'] ); ?>" placeholder="<?php esc_attr_e( 'Item name, e.g. IT Support', 'custom-invoices' ); ?>" />
				<input type="text" class="large-text ci-item-description" name="ci_items[<?php echo esc_attr( $index ); ?>][description]" value="<?php echo esc_attr( $description ); ?>" placeholder="<?php esc_attr_e( 'Description (optional), e.g. April - June', 'custom-invoices' ); ?>" />
			</td>
			<td><input type="number" step="0.01" min="0" class="ci-item-qty ci-recalc" name="ci_items[<?php echo esc_attr( $index ); ?>][qty]" value="<?php echo esc_attr( $item['qty'] ); ?>" /></td>
			<td><input type="number" step="0.01" min="0" class="ci-item-price ci-recalc" name="ci_items[<?php echo esc_attr( $index ); ?>][price]" value="<?php echo esc_attr( $item['price'] ); ?>" /></td>
			<td class="ci-item-amount">0.00</td>
			<td><button type="button" class="button-link ci-remove-row" title="<?php esc_attr_e( 'Remove', 'custom-invoices' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	public function render_notes( $post ) {
		$notes = get_post_meta( $post->ID, '_ci_notes', true );
		if ( ! $notes && 'auto-draft' === $post->post_status ) {
			$notes = CI_Settings::get( 'default_notes' );
		}
		$payment_methods    = CI_Payment_Method_CPT::get_all();
		$selected_method_ids = get_post_meta( $post->ID, '_ci_payment_method_ids', true );
		if ( ! is_array( $selected_method_ids ) ) {
			$selected_method_ids = array();
		}
		$show_zar        = get_post_meta( $post->ID, '_ci_show_zar', true );
		$show_bwp        = get_post_meta( $post->ID, '_ci_show_bwp', true );
		$zar_rate        = (float) CI_Settings::get( 'zar_rate', 0 );
		$bwp_rate        = (float) CI_Settings::get( 'bwp_rate', 0 );
		$zar_locked      = get_post_meta( $post->ID, '_ci_zar_total', true );
		$bwp_locked      = get_post_meta( $post->ID, '_ci_bwp_total', true );
		?>
		<table class="form-table ci-form-table">
			<tr>
				<th><?php esc_html_e( 'Show Converted Total', 'custom-invoices' ); ?></th>
				<td>
					<p>
						<button type="button" class="button" id="ci-fetch-rates"><?php esc_html_e( 'Fetch Current Exchange Rates', 'custom-invoices' ); ?></button>
						<span id="ci-fetch-rates-status" style="margin-left:8px;"></span>
					</p>
					<label style="display:block;margin-bottom:6px;">
						<input type="checkbox" id="ci_show_zar" name="ci_show_zar" value="1" <?php checked( $show_zar, '1' ); ?> <?php echo $zar_rate ? '' : 'disabled'; ?> />
						<?php esc_html_e( 'Show total in South African Rand', 'custom-invoices' ); ?>
						<?php if ( '' !== $zar_locked && '1' === $show_zar ) : ?>
							— <?php esc_html_e( 'saved as', 'custom-invoices' ); ?> <strong>R<?php echo esc_html( number_format( (float) $zar_locked, 0, '.', ' ' ) ); ?></strong>
						<?php else : ?>
							— <?php esc_html_e( 'will save as', 'custom-invoices' ); ?> <span id="ci-zar-preview">R0</span>
						<?php endif; ?>
					</label>
					<label style="display:block;">
						<input type="checkbox" id="ci_show_bwp" name="ci_show_bwp" value="1" <?php checked( $show_bwp, '1' ); ?> <?php echo $bwp_rate ? '' : 'disabled'; ?> />
						<?php esc_html_e( 'Show total in Botswana Pula', 'custom-invoices' ); ?>
						<?php if ( '' !== $bwp_locked && '1' === $show_bwp ) : ?>
							— <?php esc_html_e( 'saved as', 'custom-invoices' ); ?> <strong>P<?php echo esc_html( number_format( (float) $bwp_locked, 0, '.', ' ' ) ); ?></strong>
						<?php else : ?>
							— <?php esc_html_e( 'will save as', 'custom-invoices' ); ?> <span id="ci-bwp-preview">P0</span>
						<?php endif; ?>
					</label>
					<?php if ( ! $zar_rate || ! $bwp_rate ) : ?>
						<p class="description" id="ci-rates-missing-notice">
							<?php
							printf(
								/* translators: %s: link to settings page */
								esc_html__( 'Set exchange rates on the %s (or fetch them above) to enable these.', 'custom-invoices' ),
								'<a href="' . esc_url( admin_url( 'options-general.php?page=ci-invoice-settings' ) ) . '">' . esc_html__( 'Invoice Settings page', 'custom-invoices' ) . '</a>'
							);
							?>
						</p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Locked in using the exchange rate at the moment you save. It will not change if the rate on the settings page changes later — only editing and re-saving this invoice recalculates it. Appears as the first line(s) of Notes to Customer, Rand then Pula.', 'custom-invoices' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="ci_notes"><?php esc_html_e( 'Notes to Customer', 'custom-invoices' ); ?></label></th>
				<td><textarea id="ci_notes" name="ci_notes" rows="4" class="large-text"><?php echo esc_textarea( $notes ); ?></textarea></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Payment Details', 'custom-invoices' ); ?></th>
				<td>
					<details class="ci-collapsible" <?php echo $selected_method_ids ? 'open' : ''; ?>>
						<summary>
							<?php
							if ( $selected_method_ids ) {
								printf(
									/* translators: %d: number of payment methods selected */
									esc_html( _n( '%d payment method selected', '%d payment methods selected', count( $selected_method_ids ), 'custom-invoices' ) ),
									count( $selected_method_ids )
								);
							} else {
								esc_html_e( 'Select payment methods to show', 'custom-invoices' );
							}
							?>
						</summary>
						<div class="ci-collapsible-body">
							<?php if ( empty( $payment_methods ) ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: link to add a new payment method */
										esc_html__( 'No payment methods yet. %s', 'custom-invoices' ),
										'<a href="' . esc_url( admin_url( 'post-new.php?post_type=ci_payment_method' ) ) . '" target="_blank">' . esc_html__( 'Add one', 'custom-invoices' ) . '</a>'
									);
									?>
								</p>
							<?php else : ?>
								<?php foreach ( $payment_methods as $id => $method ) : ?>
									<label class="ci-payment-method-option">
										<input type="checkbox" name="ci_payment_method_ids[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $selected_method_ids, true ) ); ?> />
										<strong><?php echo esc_html( $method['title'] ); ?></strong>
										<?php if ( $method['content'] ) : ?>
											<span class="ci-payment-method-preview"><?php echo esc_html( wp_trim_words( $method['content'], 10 ) ); ?></span>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: link to manage payment methods */
										esc_html__( 'Checked ones appear under a "Payment details" heading below Notes to Customer. %s', 'custom-invoices' ),
										'<a href="' . esc_url( admin_url( 'edit.php?post_type=ci_payment_method' ) ) . '" target="_blank">' . esc_html__( 'Manage payment methods', 'custom-invoices' ) . '</a>'
									);
									?>
								</p>
							<?php endif; ?>
						</div>
					</details>
				</td>
			</tr>
		</table>
		<?php
	}

	public function render_preview_box( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the invoice to preview it.', 'custom-invoices' ) . '</p>';
			return;
		}
		$url = add_query_arg( array( 'ci_invoice' => $post->ID ), home_url( '/' ) );
		?>
		<p><a href="<?php echo esc_url( $url ); ?>" class="button button-primary" target="_blank"><?php esc_html_e( 'View / Print Invoice', 'custom-invoices' ); ?></a></p>
		<p class="description"><?php esc_html_e( 'Or embed it anywhere with the shortcode:', 'custom-invoices' ); ?></p>
		<code>[ci_invoice id="<?php echo (int) $post->ID; ?>"]</code>
		<?php
	}

	public function shortcode_notice() {
		// Reserved for future validation notices.
	}

	public function save( $post_id ) {
		if ( ! isset( $_POST['ci_invoice_nonce'] ) || ! wp_verify_nonce( $_POST['ci_invoice_nonce'], 'ci_save_invoice' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$text_fields = array(
			'ci_invoice_number' => '_ci_invoice_number',
			'ci_invoice_date'   => '_ci_invoice_date',
			'ci_due_date'       => '_ci_due_date',
		);
		foreach ( $text_fields as $field => $meta_key ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		// Bill To comes entirely from the selected client record — snapshot its
		// current details onto the invoice so the printed invoice has its own copy.
		$client_id = isset( $_POST['ci_client_id'] ) ? absint( $_POST['ci_client_id'] ) : 0;
		update_post_meta( $post_id, '_ci_client_id', $client_id );

		if ( $client_id && 'ci_client' === get_post_type( $client_id ) ) {
			update_post_meta( $post_id, '_ci_bill_to_name', get_the_title( $client_id ) );
			update_post_meta( $post_id, '_ci_bill_to_address', get_post_meta( $client_id, '_ci_client_address', true ) );
			update_post_meta( $post_id, '_ci_bill_to_email', get_post_meta( $client_id, '_ci_client_email', true ) );
			update_post_meta( $post_id, '_ci_bill_to_phone', get_post_meta( $client_id, '_ci_client_phone', true ) );
		} else {
			update_post_meta( $post_id, '_ci_bill_to_name', '' );
			update_post_meta( $post_id, '_ci_bill_to_address', '' );
			update_post_meta( $post_id, '_ci_bill_to_email', '' );
			update_post_meta( $post_id, '_ci_bill_to_phone', '' );
		}

		if ( isset( $_POST['ci_notes'] ) ) {
			update_post_meta( $post_id, '_ci_notes', sanitize_textarea_field( wp_unslash( $_POST['ci_notes'] ) ) );
		}

		$method_ids = array();
		if ( isset( $_POST['ci_payment_method_ids'] ) && is_array( $_POST['ci_payment_method_ids'] ) ) {
			$method_ids = array_map( 'absint', wp_unslash( $_POST['ci_payment_method_ids'] ) );
		}
		update_post_meta( $post_id, '_ci_payment_method_ids', $method_ids );

		update_post_meta( $post_id, '_ci_show_zar', isset( $_POST['ci_show_zar'] ) ? '1' : '' );
		update_post_meta( $post_id, '_ci_show_bwp', isset( $_POST['ci_show_bwp'] ) ? '1' : '' );

		$amount_paid = isset( $_POST['ci_amount_paid'] ) ? (float) $_POST['ci_amount_paid'] : 0;
		update_post_meta( $post_id, '_ci_amount_paid', $amount_paid );

		$items = array();
		if ( isset( $_POST['ci_items'] ) && is_array( $_POST['ci_items'] ) ) {
			foreach ( $_POST['ci_items'] as $item ) {
				$desc        = isset( $item['desc'] ) ? sanitize_text_field( wp_unslash( $item['desc'] ) ) : '';
				$description = isset( $item['description'] ) ? sanitize_text_field( wp_unslash( $item['description'] ) ) : '';
				$qty         = isset( $item['qty'] ) ? (float) $item['qty'] : 0;
				$price       = isset( $item['price'] ) ? (float) $item['price'] : 0;
				if ( '' === $desc && '' === $description && 0 === $qty && 0 === $price ) {
					continue;
				}
				$items[] = array(
					'desc'        => $desc,
					'description' => $description,
					'qty'         => $qty,
					'price'       => $price,
				);
			}
		}
		update_post_meta( $post_id, '_ci_items', $items );

		$total = 0;
		foreach ( $items as $item ) {
			$total += $item['qty'] * $item['price'];
		}
		update_post_meta( $post_id, '_ci_total', $total );

		// Lock in conversions using the exchange rate at the moment of saving —
		// they won't shift later if the settings-page rate changes, only on the next edit+save.
		if ( isset( $_POST['ci_show_zar'] ) ) {
			$zar_rate = (float) CI_Settings::get( 'zar_rate', 0 );
			if ( $zar_rate > 0 ) {
				update_post_meta( $post_id, '_ci_zar_total', round( ( $total * $zar_rate ) / 10 ) * 10 );
				update_post_meta( $post_id, '_ci_zar_rate_used', $zar_rate );
			}
		}
		if ( isset( $_POST['ci_show_bwp'] ) ) {
			$bwp_rate = (float) CI_Settings::get( 'bwp_rate', 0 );
			if ( $bwp_rate > 0 ) {
				update_post_meta( $post_id, '_ci_bwp_total', round( ( $total * $bwp_rate ) / 10 ) * 10 );
				update_post_meta( $post_id, '_ci_bwp_rate_used', $bwp_rate );
			}
		}
	}
}
