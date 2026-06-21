/**
 * BuildingCare Lite — Admin JavaScript.
 */
(function ($) {
	'use strict';

	var currentBillId = 0;

	/* -------------------------------------------------------------------------
	 * AJAX tab navigation (no full page reload).
	 * ---------------------------------------------------------------------- */

	function getCurrentParams() {
		var params = {};
		var search = window.location.search.replace(/^\?/, '');
		if (search) {
			search.split('&').forEach(function (pair) {
				var kv = pair.split('=');
				if (kv[0]) {
					params[decodeURIComponent(kv[0])] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
				}
			});
		}
		params.page = 'bcl-dashboard';
		return params;
	}

	function buildUrl(params) {
		params = $.extend({}, params, { page: 'bcl-dashboard' });
		return window.location.pathname + '?' + $.param(params);
	}

	function paramsFromUrl(url) {
		var a = document.createElement('a');
		a.href = url;
		var params = {};
		a.search.replace(/^\?/, '').split('&').forEach(function (pair) {
			if (!pair) {
				return;
			}
			var kv = pair.split('=');
			params[decodeURIComponent(kv[0])] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' '));
		});
		return params;
	}

	function loadPanel(params, push) {
		var $panel = $('.bcl-tab-panel');
		if (!$panel.length) {
			return;
		}

		$panel.addClass('bcl-loading');

		$.ajax({
			url: bclAdmin.ajaxUrl,
			type: 'POST',
			data: { action: 'bcl_load_panel', nonce: bclAdmin.nonce, params: params },
		})
			.done(function (resp) {
				if (resp && resp.success) {
					$panel.html(resp.data.html);
					$('.bcl-tab').removeClass('is-active');
					var $activeTab = $('.bcl-tab[data-tab="' + resp.data.tab + '"]').addClass('is-active');
					if ($activeTab.length) {
						$('.bcl-menu-current').text($activeTab.text().trim());
					}
					if (push !== false) {
						window.history.pushState({ bcl: true }, '', buildUrl(params));
					}
					$('html, body').animate({ scrollTop: Math.max(0, $('.bcl-tabs').offset().top - 40) }, 150);
				} else {
					window.location.reload();
				}
			})
			.fail(function () {
				window.location.reload();
			})
			.always(function () {
				$panel.removeClass('bcl-loading');
			});
	}

	function reloadPanel() {
		loadPanel(getCurrentParams(), false);
	}

	function closeMobileMenu() {
		$('#bcl-tabs-nav').removeClass('is-open');
		$('.bcl-menu-toggle').attr('aria-expanded', 'false');
	}

	// Mobile hamburger toggle.
	$(document).on('click', '.bcl-menu-toggle', function (e) {
		e.preventDefault();
		var $nav = $('#bcl-tabs-nav');
		var open = $nav.toggleClass('is-open').hasClass('is-open');
		$(this).attr('aria-expanded', open ? 'true' : 'false');
	});

	// Close the mobile menu when tapping outside it.
	$(document).on('click', function (e) {
		if (!$(e.target).closest('.bcl-menu-toggle, #bcl-tabs-nav').length) {
			closeMobileMenu();
		}
	});

	// Tab clicks.
	$(document).on('click', '.bcl-tab', function (e) {
		e.preventDefault();
		var slug = $(this).data('tab');
		$('.bcl-menu-current').text($(this).text().trim());
		closeMobileMenu();
		loadPanel({ tab: slug }, true);
	});

	// In-panel navigation links pointing back to the dashboard (Add/Edit/Back/pagination).
	$(document).on('click', '.bcl-tab-panel a', function (e) {
		var href = $(this).attr('href') || '';
		if (href.indexOf('page=bcl-dashboard') === -1) {
			return; // external / admin-post links navigate normally.
		}
		if ($(this).hasClass('bcl-delete-entity')) {
			return; // handled by its own confirm + full navigation.
		}
		e.preventDefault();
		loadPanel(paramsFromUrl(href), true);
	});

	// GET filter/search forms inside the panel.
	$(document).on('submit', '.bcl-list-search, .bcl-list-form', function (e) {
		e.preventDefault();
		var params = {};
		$.each($(this).serializeArray(), function (i, field) {
			if (params[field.name] !== undefined) {
				if (!$.isArray(params[field.name])) {
					params[field.name] = [params[field.name]];
				}
				params[field.name].push(field.value);
			} else {
				params[field.name] = field.value;
			}
		});
		params.page = 'bcl-dashboard';
		loadPanel(params, true);
	});

	window.addEventListener('popstate', function () {
		loadPanel(getCurrentParams(), false);
	});

	/* -------------------------------------------------------------------------
	 * Payments & expenses (delegated so they survive panel swaps).
	 * ---------------------------------------------------------------------- */

	function postAjax(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = bclAdmin.nonce;

		return $.ajax({ url: bclAdmin.ajaxUrl, type: 'POST', data: data });
	}

	function selectedCollectMethod() {
		var $method = $('#bcl-collect-method');
		return $method.length ? $method.val() : 'cash';
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

	function collectFull(e) {
		e.preventDefault();
		var $button = $(this);
		setButtonLoading($button, bclAdmin.i18n.collecting);

		postAjax('bcl_collect_payment', {
			bill_id: $button.data('bill-id'),
			payment_method: selectedCollectMethod(),
			mark_full: 1,
		})
			.done(reloadPanel)
			.fail(function () {
				resetButton($button);
				window.alert(bclAdmin.i18n.error);
			});
	}

	$(document).on('click', '.bcl-collect-payment, .bcl-mark-paid', collectFull);

	$(document).on('click', '.bcl-record-payment', function (e) {
		e.preventDefault();
		currentBillId = $(this).data('bill-id');
		$('#bcl-payment-bill-id').val(currentBillId);
		$('#bcl-payment-amount').val($(this).data('due'));
		showModal();
	});

	$(document).on('click', '#bcl-payment-cancel', hideModal);

	$(document).on('click', '#bcl-payment-submit', function () {
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
				reloadPanel();
			})
			.fail(function () {
				window.alert(bclAdmin.i18n.error);
			});
	});

	function payRecurring(e) {
		e.preventDefault();
		var $button = $(this);
		setButtonLoading($button, bclAdmin.i18n.paying);

		postAjax('bcl_pay_recurring_expense', {
			expense_id: $button.data('expense-id'),
		})
			.done(reloadPanel)
			.fail(function () {
				resetButton($button);
				window.alert(bclAdmin.i18n.error);
			});
	}

	$(document).on('click', '.bcl-pay-recurring, .bcl-mark-expense-paid', payRecurring);

	/* -------------------------------------------------------------------------
	 * Misc UI (delegated).
	 * ---------------------------------------------------------------------- */

	$(document).on('click', '.bcl-upload-attachment', function (e) {
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

	$(document).on('click', '.bcl-delete-entity', function (e) {
		if (!window.confirm(bclAdmin.i18n.confirmDelete || 'Delete this record?')) {
			e.preventDefault();
		}
	});

	$(document).on('change', '#bcl-date-filter', function () {
		var isCustom = $(this).val() === 'custom';
		$('.bcl-custom-date-field').prop('hidden', !isCustom);
	});

	/* -------------------------------------------------------------------------
	 * Reports.
	 * ---------------------------------------------------------------------- */

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

	$(document).on('click', '#bcl-load-report', function () {
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

	$(document).on('click', '#bcl-export-csv', function () {
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
