jQuery(function ($) {
	'use strict';

	// Client dropdown (Bill To section) — updates the read-only preview.
	var $clientSelect = $('#ci_client_select');
	var $clientPreview = $('#ci-client-preview');
	if ($clientSelect.length && typeof ciClients !== 'undefined') {
		$clientSelect.on('change', function () {
			var client = ciClients[$(this).val()];
			if (!client) {
				$clientPreview.html('<p class="description">No client selected yet.</p>');
				return;
			}
			var html = '<p class="ci-client-preview-name"><strong>' + $('<div>').text(client.name || '').html() + '</strong></p>';
			if (client.address) {
				html += '<p>' + $('<div>').text(client.address).html().replace(/\n/g, '<br>') + '</p>';
			}
			if (client.email) {
				html += '<p>' + $('<div>').text(client.email).html() + '</p>';
			}
			if (client.phone) {
				html += '<p>Phone: ' + $('<div>').text(client.phone).html() + '</p>';
			}
			$clientPreview.html(html);
		});
	}

	// wp_localize_script serialises every value as a string, so an unset rate
	// arrives as "0" — which is truthy. Normalise to real numbers up front.
	if (typeof ciRates !== 'undefined') {
		ciRates.zar = parseFloat(ciRates.zar) || 0;
		ciRates.bwp = parseFloat(ciRates.bwp) || 0;
	}

	// Fetch Current Exchange Rates (Invoice Settings page and invoice screen).
	var $fetchBtn = $('#ci-fetch-rates');
	if ($fetchBtn.length && typeof ciFetchRates !== 'undefined') {
		$fetchBtn.on('click', function () {
			var $status = $('#ci-fetch-rates-status');
			$fetchBtn.prop('disabled', true);
			$status.removeClass('ci-fetch-error').text('Fetching…');

			$.post(ajaxurl, {
				action: 'ci_fetch_rates',
				nonce: ciFetchRates.nonce
			}).done(function (response) {
				if (!response || !response.success) {
					$status.addClass('ci-fetch-error')
						.text((response && response.data && response.data.message) || 'Could not fetch rates.');
					return;
				}

				var zar = parseFloat(response.data.zar) || 0;
				var bwp = parseFloat(response.data.bwp) || 0;

				if (zar) {
					$('#zar_rate').val(zar);
				}
				if (bwp) {
					$('#bwp_rate').val(bwp);
				}

				if (typeof ciRates !== 'undefined') {
					if (zar) {
						ciRates.zar = zar;
						$('#ci_show_zar').prop('disabled', false);
					}
					if (bwp) {
						ciRates.bwp = bwp;
						$('#ci_show_bwp').prop('disabled', false);
					}
					if (ciRates.zar && ciRates.bwp) {
						$('#ci-rates-missing-notice').hide();
					}
					if (typeof recalc === 'function') {
						recalc();
					}
				}

				var parts = [];
				if (zar) {
					parts.push('R' + zar);
				}
				if (bwp) {
					parts.push('P' + bwp);
				}
				$status.text('Saved: ' + parts.join(' / ') + (response.data.source ? ' (' + response.data.source + ')' : ''));
			}).fail(function (xhr) {
				// A bare failure here is almost always the session or a security
				// plugin rather than the rate feed, so say which.
				var reason = 'Could not reach the site (' + (xhr.status || 'no response') + ').';
				if (xhr.status === 403 || xhr.status === 401) {
					reason = 'Your session expired — reload the page and try again.';
				} else if (xhr.status >= 500) {
					reason = 'The site returned a server error (' + xhr.status + '). Check the PHP error log.';
				}
				$status.addClass('ci-fetch-error').text(reason);
			}).always(function () {
				$fetchBtn.prop('disabled', false);
			});
		});
	}

	var $table = $('#ci-items-table');
	if (!$table.length) {
		return;
	}

	var $body = $('#ci-items-body');
	var rowTemplate = $('#ci-row-template').html();

	function renumberRows() {
		$body.find('.ci-item-row').each(function (i) {
			$(this).find('.ci-row-num').text(i + 1);
		});
	}

	function recalc() {
		var subtotal = 0;

		$body.find('.ci-item-row').each(function () {
			var qty = parseFloat($(this).find('.ci-item-qty').val()) || 0;
			var price = parseFloat($(this).find('.ci-item-price').val()) || 0;
			var amount = qty * price;
			$(this).find('.ci-item-amount').text(amount.toFixed(2));
			subtotal += amount;
		});

		var amountPaid = parseFloat($('#ci_amount_paid').val()) || 0;
		var total = subtotal;
		var due = Math.max(0, total - amountPaid);

		$('#ci-subtotal').text(subtotal.toFixed(2));
		$('#ci-total').text(total.toFixed(2));
		$('#ci-due').text(due.toFixed(2));

		if (typeof ciRates !== 'undefined') {
			if (ciRates.zar) {
				var zarTotal = Math.round((total * ciRates.zar) / 10) * 10;
				$('#ci-zar-preview').text('R' + zarTotal.toLocaleString());
			}
			if (ciRates.bwp) {
				var bwpTotal = Math.round((total * ciRates.bwp) / 10) * 10;
				$('#ci-bwp-preview').text('P' + bwpTotal.toLocaleString());
			}
		}
	}

	$('#ci-add-row').on('click', function () {
		var index = $body.find('.ci-item-row').length;
		var html = rowTemplate.replace(/__INDEX__/g, index);
		$body.append(html);
		renumberRows();
		recalc();
	});

	$body.on('click', '.ci-remove-row', function () {
		if ($body.find('.ci-item-row').length <= 1) {
			// Keep at least one row; just clear it instead of removing.
			$(this).closest('tr').find('input[type=text], input[type=number]').val('');
			recalc();
			return;
		}
		$(this).closest('tr').remove();
		renumberRows();
		recalc();
	});

	$(document).on('input', '.ci-recalc, .ci-item-qty, .ci-item-price', recalc);

	recalc();
});
