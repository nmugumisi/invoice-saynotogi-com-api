<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the invoice for viewing/printing — either as a full standalone page
 * (?ci_invoice=ID) or embedded via the [ci_invoice id="X"] shortcode.
 */
class CI_Invoice_Template {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_render_standalone' ) );
		add_shortcode( 'ci_invoice', array( $this, 'shortcode' ) );
	}

	public function maybe_render_standalone() {
		if ( empty( $_GET['ci_invoice'] ) ) {
			return;
		}
		$invoice_id = absint( $_GET['ci_invoice'] );
		if ( ! $invoice_id || 'invoice' !== get_post_type( $invoice_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) && get_post_status( $invoice_id ) !== 'publish' ) {
			wp_die( esc_html__( 'This invoice is not available.', 'custom-invoices' ) );
		}

		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '">';
		// Browsers use the document title as the default "Save as PDF" filename,
		// so name it after the invoice rather than the underlying post title.
		$number    = get_post_meta( $invoice_id, '_ci_invoice_number', true );
		$doc_title = $number ? 'Invoice-' . $number : get_the_title( $invoice_id );
		echo '<title>' . esc_html( $doc_title ) . '</title>';
		wp_enqueue_style( 'ci-invoice-font', 'https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,400;0,700;0,900;1,400&display=swap', array(), null );
		wp_enqueue_style( 'ci-invoice', CI_PLUGIN_URL . 'assets/css/invoice.css', array( 'ci-invoice-font' ), CI_VERSION );
		wp_print_styles( 'ci-invoice' );
		echo '</head><body class="ci-standalone">';
		echo $this->get_invoice_html( $invoice_id );
		echo '<div class="ci-print-bar no-print"><button onclick="window.print()">' . esc_html__( 'Print / Save as PDF', 'custom-invoices' ) . '</button></div>';
		echo '</body></html>';
		exit;
	}

	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'ci_invoice' );
		$invoice_id = absint( $atts['id'] );
		if ( ! $invoice_id || 'invoice' !== get_post_type( $invoice_id ) ) {
			return '';
		}
		wp_enqueue_style( 'ci-invoice-font', 'https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,400;0,700;0,900;1,400&display=swap', array(), null );
		wp_enqueue_style( 'ci-invoice', CI_PLUGIN_URL . 'assets/css/invoice.css', array( 'ci-invoice-font' ), CI_VERSION );
		return $this->get_invoice_html( $invoice_id );
	}

	private function get_invoice_html( $invoice_id ) {
		$number     = get_post_meta( $invoice_id, '_ci_invoice_number', true );
		$date       = get_post_meta( $invoice_id, '_ci_invoice_date', true );
		$due        = get_post_meta( $invoice_id, '_ci_due_date', true );
		$bill_name  = get_post_meta( $invoice_id, '_ci_bill_to_name', true );
		$bill_addr  = get_post_meta( $invoice_id, '_ci_bill_to_address', true );
		$bill_email = get_post_meta( $invoice_id, '_ci_bill_to_email', true );
		$bill_phone = get_post_meta( $invoice_id, '_ci_bill_to_phone', true );
		$items      = get_post_meta( $invoice_id, '_ci_items', true );
		$notes      = get_post_meta( $invoice_id, '_ci_notes', true );
		$amount_paid = (float) get_post_meta( $invoice_id, '_ci_amount_paid', true );

		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$subtotal = 0;
		foreach ( $items as $item ) {
			$subtotal += ( (float) $item['qty'] ) * ( (float) $item['price'] );
		}
		$total     = $subtotal;
		$due_amt   = max( 0, $total - $amount_paid );
		$is_paid   = $due_amt <= 0.001 && $total > 0;

		$symbol   = CI_Settings::get( 'currency_symbol', 'US$' );
		$currency = CI_Settings::get( 'currency_code', 'USD' );

		$company_name    = CI_Settings::get( 'company_name' );
		$company_address = CI_Settings::get( 'company_address' );
		$company_phone   = CI_Settings::get( 'company_phone' );
		$company_email   = CI_Settings::get( 'company_email' );
		$logo_id         = CI_Settings::get( 'logo_id' );
		$logo_url        = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

		$all_methods      = CI_Payment_Method_CPT::get_all();
		$selected_ids     = get_post_meta( $invoice_id, '_ci_payment_method_ids', true );
		$selected_methods = array();
		if ( is_array( $selected_ids ) ) {
			foreach ( $selected_ids as $method_id ) {
				if ( isset( $all_methods[ $method_id ] ) ) {
					$selected_methods[] = $all_methods[ $method_id ];
				}
			}
		}

		$show_zar = '1' === get_post_meta( $invoice_id, '_ci_show_zar', true );
		$show_bwp = '1' === get_post_meta( $invoice_id, '_ci_show_bwp', true );
		$zar_locked = get_post_meta( $invoice_id, '_ci_zar_total', true );
		$bwp_locked = get_post_meta( $invoice_id, '_ci_bwp_total', true );

		$conversion_lines = array();
		if ( $show_zar && '' !== $zar_locked ) {
			$conversion_lines[] = 'Total in South African Rand: R' . number_format( (float) $zar_locked, 0, '.', ' ' );
		}
		if ( $show_bwp && '' !== $bwp_locked ) {
			$conversion_lines[] = 'Total in Botswana Pula: P' . number_format( (float) $bwp_locked, 0, '.', ' ' );
		}

		ob_start();
		?>
		<div class="ci-invoice">
			<div class="ci-header">
				<div class="ci-header-logo">
					<?php if ( $logo_url ) : ?>
						<img class="ci-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $company_name ); ?>" />
					<?php endif; ?>
				</div>
				<div class="ci-header-from">
					<h1 class="ci-doc-title">INVOICE</h1>
					<?php if ( $company_name ) : ?><div class="ci-company-name"><?php echo esc_html( $company_name ); ?></div><?php endif; ?>
					<?php if ( $company_address ) : ?><div class="ci-company-address"><?php echo nl2br( esc_html( $company_address ) ); ?></div><?php endif; ?>
					<?php
					$contact_bits = array();
					if ( $company_phone ) {
						$contact_bits[] = 'Phone: ' . $company_phone;
					}
					if ( $company_email ) {
						$contact_bits[] = $company_email;
					}
					if ( $contact_bits ) :
						?>
						<div class="ci-company-contact"><?php echo esc_html( implode( '; ', $contact_bits ) ); ?></div>
					<?php endif; ?>
				</div>
			</div>

			<div class="ci-meta-band">
				<div class="ci-meta-fields">
					<div class="ci-meta-row"><span class="ci-meta-label">Invoice No#</span> : <span class="ci-meta-value"><?php echo esc_html( $number ); ?></span></div>
					<div class="ci-meta-row"><span class="ci-meta-label">Invoice Date</span> : <span class="ci-meta-value"><?php echo esc_html( $this->format_date( $date ) ); ?></span></div>
					<div class="ci-meta-row"><span class="ci-meta-label">Due Date</span> : <span class="ci-meta-value"><?php echo esc_html( $this->format_date( $due ) ); ?></span></div>
				</div>
				<div class="ci-meta-due">
					<?php if ( $is_paid ) : ?>
						<span class="ci-stamp ci-stamp-paid">PAID</span>
					<?php elseif ( $amount_paid > 0 ) : ?>
						<span class="ci-stamp ci-stamp-partial">PARTIALLY PAID</span>
					<?php endif; ?>
					<div class="ci-due-figure"><?php echo esc_html( $symbol . $this->fmt_money( $due_amt ) ); ?></div>
					<div class="ci-due-caption">AMOUNT DUE</div>
				</div>
			</div>

			<div class="ci-bill-to">
				<div class="ci-label">BILL TO</div>
				<div class="ci-bill-name"><?php echo esc_html( $bill_name ); ?></div>
				<?php if ( $bill_addr ) : ?><div><?php echo nl2br( esc_html( $bill_addr ) ); ?></div><?php endif; ?>
				<?php if ( $bill_email ) : ?><div><?php echo esc_html( $bill_email ); ?></div><?php endif; ?>
				<?php if ( $bill_phone ) : ?><div>Phone: <?php echo esc_html( $bill_phone ); ?></div><?php endif; ?>
			</div>

			<table class="ci-items">
				<thead>
					<tr>
						<th class="ci-col-num">#</th>
						<th class="ci-col-desc">ITEMS &amp; DESCRIPTION</th>
						<th class="ci-num ci-col-qty">QTY/HRS</th>
						<th class="ci-num ci-col-price">PRICE</th>
						<th class="ci-num ci-col-amount">AMOUNT(<?php echo esc_html( $symbol ); ?>)</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $i => $item ) : ?>
						<tr>
							<td class="ci-col-num"><?php echo (int) $i + 1; ?></td>
							<td class="ci-col-desc">
								<?php echo nl2br( esc_html( $item['desc'] ) ); ?>
								<?php if ( ! empty( $item['description'] ) ) : ?>
									<div class="ci-item-description"><?php echo nl2br( esc_html( $item['description'] ) ); ?></div>
								<?php endif; ?>
							</td>
							<td class="ci-num ci-col-qty"><?php echo esc_html( $this->fmt_num( $item['qty'] ) ); ?></td>
							<td class="ci-num ci-col-price"><?php echo esc_html( $symbol . $this->fmt_money( $item['price'] ) ); ?></td>
							<td class="ci-num ci-col-amount"><?php echo esc_html( $symbol . $this->fmt_money( $item['qty'] * $item['price'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="ci-summary">
				<table>
					<tr class="ci-summary-subtotal"><th>Subtotal</th><td><?php echo esc_html( $symbol . $this->fmt_money( $subtotal ) ); ?></td></tr>
					<tr class="ci-total"><th>TOTAL</th><td><?php echo esc_html( $symbol . $this->fmt_money( $total ) . ' ' . $currency ); ?></td></tr>
					<?php if ( $amount_paid > 0 ) : ?>
						<tr class="ci-summary-paid"><th>Amount paid</th><td><?php echo esc_html( $symbol . $this->fmt_money( $amount_paid ) ); ?></td></tr>
					<?php endif; ?>
					<tr class="ci-due"><th>AMOUNT DUE</th><td><?php echo esc_html( $symbol . $this->fmt_money( $due_amt ) . ' ' . $currency ); ?></td></tr>
				</table>
			</div>

			<?php if ( $conversion_lines || $notes || $selected_methods ) : ?>
			<div class="ci-notes">
				<div class="ci-label">NOTES TO CUSTOMER</div>
				<?php if ( $conversion_lines ) : ?>
					<p class="ci-conversion-lines"><?php echo nl2br( esc_html( implode( "\n", $conversion_lines ) ) ); ?></p>
				<?php endif; ?>
				<?php if ( $notes ) : ?><p><?php echo nl2br( esc_html( $notes ) ); ?></p><?php endif; ?>
				<?php if ( $selected_methods ) : ?>
					<div class="ci-payment-details">
						<div class="ci-payment-heading">Payment details</div>
						<?php foreach ( $selected_methods as $method ) : ?>
							<div class="ci-payment-method">
								<?php if ( $method['title'] ) : ?><p class="ci-payment-method-title"><?php echo esc_html( $method['title'] ); ?></p><?php endif; ?>
								<?php if ( $method['content'] ) : ?><p><?php echo nl2br( esc_html( $method['content'] ) ); ?></p><?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function format_date( $date ) {
		if ( ! $date ) {
			return '';
		}
		$timestamp = strtotime( $date );
		return $timestamp ? date_i18n( 'd M Y', $timestamp ) : $date;
	}

	private function fmt_money( $number ) {
		return number_format( (float) $number, 2 );
	}

	private function fmt_num( $number ) {
		$number = (float) $number;
		return ( floor( $number ) === $number ) ? (string) (int) $number : rtrim( rtrim( number_format( $number, 2 ), '0' ), '.' );
	}
}
