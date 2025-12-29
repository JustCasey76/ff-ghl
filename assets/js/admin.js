(function ($) {
	'use strict';

	const settings = window.aqmGhlSettings || {};
	const mappingFields = ['email', 'phone', 'first_name', 'last_name'];
	const endpoint = 'https://services.leadconnectorhq.com/contacts/';
	const formsList = settings.forms || [];
	let formFieldsCache = {};

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

	/**
	 * Auto-map fields based on label patterns.
	 * Only maps if the current mapping is empty.
	 *
	 * @param {Array} fields - Array of field objects with id and label.
	 * @param {Object} existingMap - Current mapping object.
	 * @returns {Object} Auto-mapped values.
	 */
	function autoMapFields(fields, existingMap) {
		const autoMap = {};
		const labelPatterns = {
			email: [
				/^email$/i,
				/^e-mail$/i,
				/^e mail$/i,
				/email address/i,
				/email addr/i,
				/^email$/i,
			],
			phone: [
				/^phone$/i,
				/^telephone$/i,
				/^tel$/i,
				/phone number/i,
				/phone #/i,
				/mobile/i,
				/cell/i,
				/cell phone/i,
				/mobile phone/i,
			],
			first_name: [
				/^first name$/i,
				/^first$/i,
				/^fname$/i,
				/^f\.?name$/i,
				/firstname/i,
				/^given name$/i,
				/^name$/i, // Only if no last_name match
			],
			last_name: [
				/^last name$/i,
				/^last$/i,
				/^lname$/i,
				/^l\.?name$/i,
				/lastname/i,
				/^surname$/i,
				/^family name$/i,
			],
		};

		// Only auto-map if existing mapping is empty
		const shouldAutoMap = !existingMap || Object.keys(existingMap).length === 0 || 
			Object.values(existingMap).every(val => !val || val === '');

		if (!shouldAutoMap) {
			console.log('[AQM GHL] Skipping auto-map - existing mappings found');
			return autoMap;
		}

		mappingFields.forEach((key) => {
			// Skip if already mapped
			if (existingMap && existingMap[key]) {
				return;
			}

			const patterns = labelPatterns[key] || [];
			let matchedField = null;

			// Try exact matches first
			for (const field of fields) {
				const label = (field.label || '').trim();
				if (!label) continue;

				for (const pattern of patterns) {
					if (pattern.test(label)) {
						// Special handling for "name" - only match if it's likely first name
						if (key === 'first_name' && /^name$/i.test(label)) {
							// Check if there's a last_name field - if so, skip this match
							const hasLastName = fields.some(f => 
								labelPatterns.last_name.some(p => p.test(f.label || ''))
							);
							if (hasLastName) {
								continue;
							}
						}
						matchedField = field;
						break;
					}
				}
				if (matchedField) break;
			}

			if (matchedField) {
				autoMap[key] = parseInt(matchedField.id, 10);
				console.log(`[AQM GHL] Auto-mapped ${key} to field "${matchedField.label}" (ID: ${matchedField.id})`);
			}
		});

		return autoMap;
	}

	function addCustomFieldRow(formId, container, data, fields) {
		const formIdInt = parseInt(formId, 10);
		const ghlFieldId = data && data.ghl_field_id ? data.ghl_field_id : '';
		const formFieldId = data && data.form_field_id ? data.form_field_id : '';

		const row = $(`
			<div class="aqm-ghl-custom-field-row">
				<input type="text" name="${settings.optionKey || 'aqm_ghl_connector_settings'}[custom_fields][${formIdInt}][][ghl_field_id]" placeholder="GHL Custom Field ID" value="${ghlFieldId ? $('<div>').text(ghlFieldId).html() : ''}" class="regular-text aqm-ghl-custom-ghl" />
				<select name="${settings.optionKey || 'aqm_ghl_connector_settings'}[custom_fields][${formIdInt}][][form_field_id]" class="regular-text aqm-ghl-custom-select"></select>
				<button type="button" class="button-link-delete aqm-ghl-remove-custom-field">Remove</button>
			</div>
		`);

		setSelectOptions(row.find('select'), fields, formFieldId);

		row.on('click', '.aqm-ghl-remove-custom-field', function (e) {
			e.preventDefault();
			row.remove();
		});

		container.find('.aqm-ghl-custom-fields').append(row);
	}

	function renderCustomFields(formId, container, fields) {
		const formIdInt = parseInt(formId, 10);
		container.find('.aqm-ghl-custom-fields').empty();
		const existingByForm = settings.customFields || {};
		const existing = existingByForm[formIdInt] || [];

		if (existing.length === 0) {
			addCustomFieldRow(formId, container, null, fields);
			return;
		}

		existing.forEach((field) => addCustomFieldRow(formId, container, field, fields));
	}

	function loadFields(formId) {
		const formIdInt = parseInt(formId, 10);
		if (formFieldsCache[formIdInt]) {
			return Promise.resolve(formFieldsCache[formIdInt]);
		}

		const data = new URLSearchParams();
		data.append('action', 'aqm_ghl_get_form_fields');
		data.append('nonce', settings.nonce);
		data.append('form_id', formIdInt);

		return fetch(settings.ajaxUrl, {
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
				formFieldsCache[formIdInt] = json.data.fields;
				return json.data.fields;
			});
	}

	function buildMappingContainer(formId) {
		const formIdInt = parseInt(formId, 10);
		const form = formsList.find((f) => parseInt(f.id, 10) === formIdInt);
		const formName = form ? form.name : `Form ${formId}`;
		const mappingByForm = settings.mapping || {};
		const customByForm = settings.customFields || {};
		// Use integer key (normalized from PHP)
		const existingMap = mappingByForm[formIdInt] || {};

		const container = $(`
			<div class="aqm-ghl-form-block" data-form-id="${formIdInt}">
				<h3>${formName}</h3>
				<div class="aqm-ghl-mapping-rows">
					<label>Email (required)
						<select name="${settings.optionKey || 'aqm_ghl_connector_settings'}[mapping][${formIdInt}][email]" class="regular-text aqm-ghl-field-select" data-map-key="email"></select>
					</label>
					<label>Phone (optional)
						<select name="${settings.optionKey || 'aqm_ghl_connector_settings'}[mapping][${formIdInt}][phone]" class="regular-text aqm-ghl-field-select" data-map-key="phone"></select>
					</label>
					<label>First Name
						<select name="${settings.optionKey || 'aqm_ghl_connector_settings'}[mapping][${formIdInt}][first_name]" class="regular-text aqm-ghl-field-select" data-map-key="first_name"></select>
					</label>
					<label>Last Name
						<select name="${settings.optionKey || 'aqm_ghl_connector_settings'}[mapping][${formIdInt}][last_name]" class="regular-text aqm-ghl-field-select" data-map-key="last_name"></select>
					</label>
				</div>
				<h4>Custom Fields</h4>
				<div class="aqm-ghl-custom-fields"></div>
				<p><button type="button" class="button aqm-ghl-add-custom-field" data-form-id="${formIdInt}">Add Custom Field</button></p>
			</div>
		`);

		loadFields(formIdInt)
			.then((fields) => {
				console.log(`[AQM GHL] Loading fields for form ${formIdInt}, existing map:`, existingMap);
				
				// Try auto-mapping if no existing mappings
				const autoMapped = autoMapFields(fields, existingMap);
				const finalMap = { ...existingMap, ...autoMapped };
				
				container.find('select.aqm-ghl-field-select').each(function () {
					const key = $(this).data('map-key');
					const selected = finalMap && finalMap[key] ? finalMap[key] : '';
					console.log(`[AQM GHL] Setting ${key} to ${selected} for form ${formIdInt}${autoMapped[key] ? ' (auto-mapped)' : ''}`);
					setSelectOptions($(this), fields, selected);
				});
				renderCustomFields(formIdInt, container, fields);
			})
			.catch(() => {
				container.find('select.aqm-ghl-field-select').each(function () {
					setSelectOptions($(this), [], '');
				});
				renderCustomFields(formIdInt, container, []);
			});

		container.on('click', '.aqm-ghl-add-custom-field', function (e) {
			e.preventDefault();
			const fields = formFieldsCache[formIdInt] || [];
			addCustomFieldRow(formIdInt, container, null, fields);
		});

		container.on('click', '.aqm-ghl-remove-custom-field', function (e) {
			e.preventDefault();
			$(this).closest('.aqm-ghl-custom-field-row').remove();
		});

		return container;
	}

	$(function () {
		const $formSelect = $('#aqm-ghl-form-select');
		const $testButton = $('#aqm-ghl-test-connection');
		const $testResult = $('#aqm-ghl-test-result');
		const $mappingContainers = $('#aqm-ghl-form-mapping-containers');
		const $clearCacheButton = $('#aqm-ghl-clear-cache');
		const $cacheResult = $('#aqm-ghl-cache-result');

		console.info('[AQM GHL] Admin script initialized', {
			hasTestButton: !!$testButton.length,
			page: window.location.href,
			forms: settings.selectedForms || [],
		});

		// Store existing blocks to preserve settings
		const existingBlocks = {};

		function refreshFormBlocks() {
			const selected = ($formSelect.val() || []).map((v) => parseInt(v, 10)).filter(Boolean);
			
			// Hide all blocks first
			$mappingContainers.find('.aqm-ghl-form-block').hide();
			
			if (!selected.length) {
				return;
			}

			// Show or create blocks for selected forms
			selected.forEach((fid) => {
				const fidInt = parseInt(fid, 10);
				let block = existingBlocks[fidInt];
				if (!block || !block.length) {
					// Create new block if it doesn't exist
					block = buildMappingContainer(fidInt);
					existingBlocks[fidInt] = block;
					$mappingContainers.append(block);
				} else {
					// Show existing block
					block.show();
				}
			});
		}

		$formSelect.on('change', function () {
			refreshFormBlocks();
		});

		// Initial load - build blocks for all selected forms
		const initialSelected = (settings.selectedForms || []).map((v) => parseInt(v, 10)).filter(Boolean);
		if (initialSelected.length) {
			initialSelected.forEach((fid) => {
				const fidInt = parseInt(fid, 10);
				const block = buildMappingContainer(fidInt);
				existingBlocks[fidInt] = block;
				$mappingContainers.append(block);
			});
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

		// Clear update cache button
		$clearCacheButton.on('click', function (e) {
			e.preventDefault();
			if ($clearCacheButton.prop('disabled')) {
				return;
			}

			$cacheResult.hide().removeClass('notice-success notice-error').text('');
			$clearCacheButton.prop('disabled', true).text('Clearing…');

			const data = new URLSearchParams();
			data.append('action', 'aqm_ghl_clear_update_cache');
			data.append('nonce', settings.nonce);

			fetch(settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: data.toString(),
			})
				.then((response) => response.json())
				.then((json) => {
					if (json && json.success) {
						$cacheResult
							.addClass('notice-success')
							.removeClass('notice-error')
							.html(json.data.message || 'Cache cleared successfully.')
							.show();
					} else {
						const msg = (json && json.data && json.data.message) ? json.data.message : 'Failed to clear cache.';
						$cacheResult
							.addClass('notice-error')
							.removeClass('notice-success')
							.html(msg)
							.show();
					}
				})
				.catch((err) => {
					console.error('[AQM GHL] Clear cache request failed', err);
					$cacheResult
						.addClass('notice-error')
						.removeClass('notice-success')
						.text('Failed to clear cache. Please check console/network.')
						.show();
				})
				.finally(() => {
					$clearCacheButton.prop('disabled', false).text('Clear Update Cache');
				});
		});
	});
})(jQuery);


