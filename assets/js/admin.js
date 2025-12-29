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

		console.log(`[AQM GHL] Building mapping container for form ${formIdInt}`, {
			formName,
			existingMap,
			formsList: formsList
		});

		const container = $(`
			<div class="aqm-ghl-form-block" data-form-id="${formIdInt}" style="display: block;">
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
		
		console.log(`[AQM GHL] Container created for form ${formIdInt}, length:`, container.length);

		// Ensure container is visible immediately
		container.show();
		
		loadFields(formIdInt)
			.then((fields) => {
				console.log(`[AQM GHL] Loading fields for form ${formIdInt}`, {
					existingMap,
					fieldsCount: fields.length,
					settingsMapping: settings.mapping,
					containerVisible: container.is(':visible'),
				});
				
				// Use existing mapping directly (no auto-mapping)
				const finalMap = existingMap || {};
				
				console.log(`[AQM GHL] Final mapping for form ${formIdInt}:`, finalMap);
				
				// Ensure container is visible
				container.show();
				
				container.find('select.aqm-ghl-field-select').each(function () {
					const $select = $(this);
					const key = $select.data('map-key');
					const selected = finalMap && finalMap[key] ? String(finalMap[key]) : '';
					console.log(`[AQM GHL] Setting ${key} to "${selected}" for form ${formIdInt}`);
					setSelectOptions($select, fields, selected);
					// Double-check the value was set
					setTimeout(() => {
						const actualValue = $select.val();
						if (selected && actualValue !== selected) {
							console.warn(`[AQM GHL] WARNING: ${key} value mismatch! Expected "${selected}", got "${actualValue}"`);
							$select.val(selected); // Force set it again
						}
					}, 100);
				});
				renderCustomFields(formIdInt, container, fields);
			})
			.catch((error) => {
				console.error(`[AQM GHL] Error loading fields for form ${formIdInt}:`, error);
				// Still show the container even if fields fail to load
				container.show();
				// Show empty selects
				container.find('select.aqm-ghl-field-select').each(function () {
					setSelectOptions($(this), [], '');
				});
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
			hasFormSelect: !!$formSelect.length,
			hasMappingContainer: !!$mappingContainers.length,
			page: window.location.href,
			forms: settings.selectedForms || [],
			settings: settings,
		});

		// Check if required elements exist
		if (!$mappingContainers.length) {
			console.error('[AQM GHL] ERROR: Mapping container not found!');
			return;
		}

		if (!$formSelect.length) {
			console.error('[AQM GHL] ERROR: Form select not found!');
			return;
		}

		// Store existing blocks to preserve settings
		const existingBlocks = {};

		function refreshFormBlocks() {
			const selected = ($formSelect.val() || []).map((v) => parseInt(v, 10)).filter(Boolean);
			
			// Never hide blocks - always show all created blocks
			// Create blocks for selected forms if they don't exist
			if (selected.length > 0) {
				selected.forEach((fid) => {
					const fidInt = parseInt(fid, 10);
					let block = existingBlocks[fidInt];
					if (!block || !block.length) {
						// Create new block if it doesn't exist
						block = buildMappingContainer(fidInt);
						existingBlocks[fidInt] = block;
						$mappingContainers.append(block);
					} else {
						// Ensure existing block is visible and values are restored from settings
						block.show();
						// Re-apply saved mappings to ensure they're displayed correctly
						const mappingByForm = settings.mapping || {};
						const existingMap = mappingByForm[fidInt] || {};
						const fields = formFieldsCache[fidInt] || [];
						if (fields.length > 0 && Object.keys(existingMap).length > 0) {
							block.find('select.aqm-ghl-field-select').each(function () {
								const $select = $(this);
								const key = $select.data('map-key');
								const selectedValue = existingMap[key] || '';
								if (selectedValue) {
									// Update the select to show the saved value
									const currentVal = $select.val();
									if (currentVal !== String(selectedValue)) {
										$select.val(selectedValue);
										console.log(`[AQM GHL] Restored ${key} mapping to ${selectedValue} for form ${fidInt}`);
									}
								}
							});
						}
					}
				});
			}
			
			// Ensure all existing blocks are visible (never hide them)
			$mappingContainers.find('.aqm-ghl-form-block').show();
		}

		$formSelect.on('change', function () {
			refreshFormBlocks();
		});

		// Function to initialize form blocks
		function initializeFormBlocks() {
			// First, get selected forms from the actual select element (in case settings haven't loaded yet)
			const selectedFromDOM = ($formSelect.val() || []).map((v) => parseInt(v, 10)).filter(Boolean);
			const initialSelected = (settings.selectedForms || []).map((v) => parseInt(v, 10)).filter(Boolean);
			const formsWithMappings = Object.keys(settings.mapping || {}).map((v) => parseInt(v, 10)).filter(Boolean);
			// Combine all sources and deduplicate
			const formsToLoad = [...new Set([...selectedFromDOM, ...initialSelected, ...formsWithMappings])];
			
			console.log('[AQM GHL] Initial load - forms to load:', {
				selectedFromDOM,
				initialSelected,
				formsWithMappings,
				formsToLoad,
				formSelectValue: $formSelect.val(),
				formSelectElement: $formSelect[0],
				settings: settings,
				mappingContainerExists: $mappingContainers.length > 0,
				mappingContainerHTML: $mappingContainers.html()
			});
			
			if (formsToLoad.length) {
				formsToLoad.forEach((fid) => {
					const fidInt = parseInt(fid, 10);
					if (existingBlocks[fidInt] && existingBlocks[fidInt].length) {
						console.log(`[AQM GHL] Block already exists for form ${fidInt}, showing it`);
						existingBlocks[fidInt].show();
						return;
					}
					console.log(`[AQM GHL] Creating initial block for form ${fidInt}`);
					try {
						const block = buildMappingContainer(fidInt);
						if (block && block.length) {
							existingBlocks[fidInt] = block;
							$mappingContainers.append(block);
							console.log(`[AQM GHL] Successfully created and appended block for form ${fidInt}`, {
								blockLength: block.length,
								containerHTML: $mappingContainers.html().substring(0, 200)
							});
							// Force show
							block.show();
						} else {
							console.error(`[AQM GHL] Failed to create block for form ${fidInt} - block is empty`);
						}
					} catch (error) {
						console.error(`[AQM GHL] Error creating block for form ${fidInt}:`, error);
					}
				});
			} else {
				console.warn('[AQM GHL] No forms to load on initial page load', {
					selectedFromDOM,
					initialSelected,
					formsWithMappings,
					formSelectOptions: $formSelect.find('option:selected').map(function() { return $(this).val(); }).get()
				});
			}
			
			// Also trigger refresh to ensure everything is visible
			refreshFormBlocks();
			
			// Final check - log what's actually in the container
			setTimeout(() => {
				const blocksInContainer = $mappingContainers.find('.aqm-ghl-form-block');
				console.log('[AQM GHL] Final check - blocks in container:', {
					count: blocksInContainer.length,
					visible: blocksInContainer.filter(':visible').length,
					blockIds: blocksInContainer.map(function() { return $(this).data('form-id'); }).get()
				});
			}, 500);
		}

		// Initialize immediately
		initializeFormBlocks();
		
		// Also try after delays in case DOM isn't fully ready
		setTimeout(initializeFormBlocks, 100);
		setTimeout(initializeFormBlocks, 500);
		setTimeout(initializeFormBlocks, 1000);

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


