/**
 * BuildingCare Lite — Admin JavaScript.
 */
(function ($) {
	'use strict';

	var currentBillId = 0;

	function postAjax(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = bclAdmin.nonce;

		return $.ajax({
			url: bclAdmin.ajaxUrl,
			type: 'POST',
			data: data,
		});
	}

	function showModal() {
		$('#bcl-payment-modal').removeAttr('hidden');
	}

	function hideModal() {
		$('#bcl-payment-modal').attr('hidden', 'hidden');
		currentBillId = 0;
	}

	function setButtonLoading($button, loadingText) {
		if (!$button.data('original-text')) {
			$button.data('original-text', $button.text());
		}
		$button.prop('disabled', true).text(loadingText);
	}

	function resetButton($button) {
		$button.prop('disabled', false).text($button.data('original-text') || $button.text());
	}

	function reloadSoon() {
		window.location.reload();
	}

	// One-click full collection — no confirm, no manual amount.
	$(document).on('click', '.bcl-collect-payment', function (e) {
		e.preventDefault();

		var $button = $(this);
		setButtonLoading($button, bclAdmin.i18n.collecting);

		postAjax('bcl_collect_payment', {
			bill_id: $button.data('bill-id'),
			payment_method: 'cash',
			mark_full: 1,
		})
			.done(reloadSoon)
			.fail(function () {
				resetButton($button);
				window.alert(bclAdmin.i18n.error);
			});
	});

	// Legacy mark-paid support (no confirmation).
	$(document).on('click', '.bcl-mark-paid', function (e) {
		e.preventDefault();

		var $button = $(this);
		setButtonLoading($button, bclAdmin.i18n.collecting);

		postAjax('bcl_collect_payment', {
			bill_id: $button.data('bill-id'),
			payment_method: 'cash',
			mark_full: 1,
		})
			.done(reloadSoon)
			.fail(function () {
				resetButton($button);
				window.alert(bclAdmin.i18n.error);
			});
	});

	$(document).on('click', '.bcl-record-payment', function (e) {
		e.preventDefault();
		currentBillId = $(this).data('bill-id');
		$('#bcl-payment-bill-id').val(currentBillId);
		$('#bcl-payment-amount').val($(this).data('due'));
		showModal();
	});

	$('#bcl-payment-cancel').on('click', hideModal);

	$('#bcl-payment-submit').on('click', function () {
		var amount = parseFloat($('#bcl-payment-amount').val()) || 0;
		var method = $('#bcl-payment-method').val();

		if (amount <= 0) {
			window.alert(bclAdmin.i18n.enterAmount);
			return;
		}

		postAjax('bcl_record_payment', {
			bill_id: currentBillId,
			amount: amount,
			payment_method: method,
		})
			.done(function () {
				hideModal();
				reloadSoon();
			})
			.fail(function () {
				window.alert(bclAdmin.i18n.error);
			});
	});

	// One-click recurring expense payment.
	$(document).on('click', '.bcl-pay-recurring', function (e) {
		e.preventDefault();

		var $button = $(this);
		setButtonLoading($button, bclAdmin.i18n.paying);

		postAjax('bcl_pay_recurring_expense', {
			expense_id: $button.data('expense-id'),
		})
			.done(reloadSoon)
			.fail(function () {
				resetButton($button);
				window.alert(bclAdmin.i18n.error);
			});
	});

	$(document).on('click', '.bcl-mark-expense-paid', function (e) {
		e.preventDefault();

		var $button = $(this);
		setButtonLoading($button, bclAdmin.i18n.paying);

		postAjax('bcl_pay_recurring_expense', {
			expense_id: $button.data('expense-id'),
		})
			.done(reloadSoon)
			.fail(function () {
				resetButton($button);
				window.alert(bclAdmin.i18n.error);
			});
	});

	$('.bcl-upload-attachment').on('click', function (e) {
		e.preventDefault();

		var frame = wp.media({
			title: 'Select Attachment',
			button: { text: 'Use this file' },
			multiple: false,
		});

		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			$('#bc_attachment_id').val(attachment.id);
			$('.bcl-attachment-preview').text(attachment.title);
		});

		frame.open();
	});

	$('#bcl-date-filter').on('change', function () {
		var isCustom = $(this).val() === 'custom';
		$('.bcl-custom-date-field').prop('hidden', !isCustom);
	});

	function renderReportTable(rows) {
		var $table = $('#bcl-report-table');
		var $thead = $table.find('thead');
		var $tbody = $table.find('tbody');

		$thead.empty();
		$tbody.empty();

		if (!rows || !rows.length) {
			$tbody.append('<tr><td colspan="10" class="bcl-empty-state">No data found.</td></tr>');
			return;
		}

		var headers = Object.keys(rows[0]);
		var headRow = '<tr>';

		headers.forEach(function (h) {
			headRow += '<th>' + h.replace(/_/g, ' ') + '</th>';
		});
		headRow += '</tr>';
		$thead.append(headRow);

		rows.forEach(function (row) {
			var tr = '<tr>';
			headers.forEach(function (h) {
				tr += '<td>' + (row[h] !== undefined ? row[h] : '') + '</td>';
			});
			tr += '</tr>';
			$tbody.append(tr);
		});
	}

	$('#bcl-load-report').on('click', function () {
		postAjax('bcl_get_report_data', {
			report_type: $('#bcl-report-type').val(),
			date_filter: $('#bcl-date-filter').val(),
			start_date: $('#bcl-start-date').val(),
			end_date: $('#bcl-end-date').val(),
		})
			.done(function (response) {
				if (response.success) {
					renderReportTable(response.data.rows);
				}
			})
			.fail(function () {
				window.alert(bclAdmin.i18n.error);
			});
	});

	$('#bcl-export-csv').on('click', function () {
		if (!bclAdmin.exportNonce) {
			window.alert(bclAdmin.i18n.error);
			return;
		}

		var exportUrl =
			bclAdmin.adminPostUrl +
			'?action=bcl_export_csv' +
			'&report_type=' + encodeURIComponent($('#bcl-report-type').val()) +
			'&date_filter=' + encodeURIComponent($('#bcl-date-filter').val()) +
			'&start_date=' + encodeURIComponent($('#bcl-start-date').val()) +
			'&end_date=' + encodeURIComponent($('#bcl-end-date').val()) +
			'&_wpnonce=' + encodeURIComponent(bclAdmin.exportNonce);

		window.location.href = exportUrl;
	});
})(jQuery);
