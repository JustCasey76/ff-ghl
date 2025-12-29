(function ($) {
	'use strict';

	const settings = window.aqmGhlSettings || {};
	const mappingFields = ['email', 'phone', 'first_name', 'last_name'];
	let currentFields = [];
	const endpoint = 'https://services.leadconnectorhq.com/contacts/';

	function setSelectOptions($select, fields, selected) {
		$select.empty();
		$select.append(new Option(settings.labels.select, '', false, false));

		fields.forEach((field) => {
			const isSelected = selected && parseInt(selected, 10) === parseInt(field.id, 10);
			$select.append(new Option(field.label, field.id, isSelected, isSelected));
		});
	}

	function renderMapping(fields) {
		mappingFields.forEach((key) => {
			const selected = settings.mapping && settings.mapping[key] ? settings.mapping[key] : '';
			setSelectOptions($(`#aqm-ghl-map-${key.replace('_', '-')}`), fields, selected);
		});
	}

	function addCustomFieldRow(data, fields) {
		const ghlFieldId = data && data.ghl_field_id ? data.ghl_field_id : '';
		const formFieldId = data && data.form_field_id ? data.form_field_id : '';

		const row = $(`
			<div class="aqm-ghl-custom-field-row">
				<input type="text" name="${settings.optionKey || 'aqm_ghl_connector_settings'}[custom_fields][][ghl_field_id]" placeholder="GHL Custom Field ID" value="${ghlFieldId ? $('<div>').text(ghlFieldId).html() : ''}" class="regular-text aqm-ghl-custom-ghl" />
				<select name="${settings.optionKey || 'aqm_ghl_connector_settings'}[custom_fields][][form_field_id]" class="regular-text aqm-ghl-custom-select"></select>
				<button type="button" class="button-link-delete aqm-ghl-remove-custom-field">Remove</button>
			</div>
		`);

		setSelectOptions(row.find('select'), fields, formFieldId);

		row.on('click', '.aqm-ghl-remove-custom-field', function (e) {
			e.preventDefault();
			row.remove();
		});

		$('#aqm-ghl-custom-fields').append(row);
	}

	function renderCustomFields(fields) {
		$('#aqm-ghl-custom-fields').empty();
		const existing = settings.customFields || [];

		if (existing.length === 0) {
			addCustomFieldRow(null, fields);
			return;
		}

		existing.forEach((field) => addCustomFieldRow(field, fields));
	}

	function loadFields(formId, callback) {
		if (!formId) {
			renderMapping([]);
			renderCustomFields([]);
			return;
		}

		const data = new URLSearchParams();
		data.append('action', 'aqm_ghl_get_form_fields');
		data.append('nonce', settings.nonce);
		data.append('form_id', formId);

		mappingFields.forEach((key) => {
			const $select = $(`#aqm-ghl-map-${key.replace('_', '-')}`);
			$select.empty().append(new Option(settings.labels.loading, '', false, false));
		});

		fetch(settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: data.toString(),
		})
			.then((response) => response.json())
			.then((json) => {
				if (!json || !json.success || !json.data || !Array.isArray(json.data.fields)) {
					throw new Error('Unable to load fields');
				}

				currentFields = json.data.fields;
				renderMapping(currentFields);
				renderCustomFields(currentFields);

				if (typeof callback === 'function') {
					callback();
				}
			})
			.catch(() => {
				renderMapping([]);
				renderCustomFields([]);
			});
	}

	$(function () {
		const $formSelect = $('#aqm-ghl-form-select');
		const $testButton = $('#aqm-ghl-test-connection');
		const $testResult = $('#aqm-ghl-test-result');

		console.info('[AQM GHL] Admin script initialized', {
			hasTestButton: !!$testButton.length,
			page: window.location.href,
		});

		if (!$testButton.length) {
			console.warn('[AQM GHL] Test button not found on page; script loaded but no control present.');
		}

		$('#aqm-ghl-add-custom-field').on('click', function (e) {
			e.preventDefault();
			addCustomFieldRow(null, currentFields);
		});

		$formSelect.on('change', function () {
			const formId = $(this).val();
			settings.mapping = {}; // prevent stale selections
			loadFields(formId);
		});

		if (settings.selectedForm) {
			loadFields(settings.selectedForm);
		} else {
			renderMapping([]);
			renderCustomFields([]);
		}

		$testButton.on('click', function (e) {
			e.preventDefault();
			if ($testButton.prop('disabled')) {
				return;
			}

			console.log('[AQM GHL] Sending test contact…', {
				ajaxUrl: settings.ajaxUrl,
				endpoint,
			});

			$testResult.hide().removeClass('notice-success notice-error').text('');
			$testButton.prop('disabled', true).text('Sending…');

			const data = new URLSearchParams();
			data.append('action', 'aqm_ghl_test_connection');
			data.append('nonce', settings.nonce);

			fetch(settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: data.toString(),
			})
				.then(async (response) => {
					console.log('[AQM GHL] Test response received', { status: response.status });
					let json;
					try {
						json = await response.json();
					} catch (err) {
						console.warn('[AQM GHL] Response was not JSON; reading as text', err);
						const text = await response.text();
						return {
							success: false,
							data: {
								message: 'Unexpected response from server.',
								status: response.status,
								response_body: text,
							},
						};
					}
					return json;
				})
				.then((json) => {
					console.log('[AQM GHL] Parsed test result', json);
					const payload = json && json.data ? json.data.payload : null;
					const responseBody = json && json.data ? json.data.response_body : null;
					const status = json && json.data ? json.data.status : null;

					let detail = '';
					if (payload) {
						detail += `<p><strong>Request URL:</strong> ${endpoint}</p>`;
						detail += `<p><strong>Request Payload:</strong></p><pre>${JSON.stringify(payload, null, 2)}</pre>`;
					}
					if (status) {
						detail += `<p><strong>Status:</strong> ${status}</p>`;
					}
					if (responseBody) {
						detail += `<p><strong>Response Body:</strong></p><pre>${responseBody}</pre>`;
					}

					if (json && json.success) {
						console.log('[AQM GHL] Test contact succeeded');
						$testResult
							.addClass('notice-success')
							.removeClass('notice-error')
							.html((json.data.message || 'Success') + detail)
							.show();
					} else {
						const msg = (json && json.data && json.data.message) ? json.data.message : 'Test failed.';
						console.error('[AQM GHL] Test contact failed', { message: msg, json });
						$testResult
							.addClass('notice-error')
							.removeClass('notice-success')
							.html(msg + detail)
							.show();
					}
				})
				.catch((err) => {
					console.error('[AQM GHL] Test contact request failed', err);
					$testResult.addClass('notice-error').removeClass('notice-success').text('Test failed. Please check console/network.').show();
				})
				.finally(() => {
					$testButton.prop('disabled', false).text('Send Test Contact');
				});
		});
	});
})(jQuery);


