<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for plugin settings.
 */
class AQM_GHL_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_aqm_ghl_get_form_fields', array( $this, 'ajax_get_form_fields' ) );
		add_action( 'wp_ajax_aqm_ghl_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_aqm_ghl_clear_update_cache', array( $this, 'ajax_clear_update_cache' ) );
		add_action( 'wp_ajax_aqm_ghl_provision_fields', array( $this, 'ajax_provision_fields' ) );
	}

	/**
	 * Register the plugin page as a top-level admin menu (just after Formidable).
	 */
	public function register_menu() {
		add_menu_page(
			__( 'GHL + Formidable', 'aqm-ghl' ),
			__( 'GHL + Formidable', 'aqm-ghl' ),
			'manage_options',
			'aqm-ghl-connector',
			array( $this, 'render_settings_page' ),
			'dashicons-forms',
			27.1 // Aim to appear immediately after Formidable.
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'aqm_ghl_connector',
			AQM_GHL_OPTION_KEY,
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Enqueue admin assets for the settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		wp_enqueue_style(
			'aqm-ghl-admin',
			AQM_GHL_CONNECTOR_URL . 'assets/css/admin.css',
			array(),
			AQM_GHL_CONNECTOR_VERSION
		);

		wp_enqueue_script(
			'aqm-ghl-admin',
			AQM_GHL_CONNECTOR_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			AQM_GHL_CONNECTOR_VERSION,
			true
		);

		$current_settings = aqm_ghl_get_settings();
		$forms            = aqm_ghl_get_formidable_forms();
		$form_options     = array();
		foreach ( $forms as $form ) {
			$form_options[] = array(
				'id'   => (int) $form->id,
				'name' => $form->name,
			);
		}

		// Normalize mapping keys to integers for consistent JavaScript access
		$mapping_normalized = array();
		if ( ! empty( $current_settings['mapping'] ) && is_array( $current_settings['mapping'] ) ) {
			foreach ( $current_settings['mapping'] as $fid => $map ) {
				$fid_int = absint( $fid );
				$mapping_normalized[ $fid_int ] = $map;
			}
		}

		// Normalize custom fields keys to integers
		$custom_fields_normalized = array();
		if ( ! empty( $current_settings['custom_fields'] ) && is_array( $current_settings['custom_fields'] ) ) {
			foreach ( $current_settings['custom_fields'] as $fid => $fields ) {
				$fid_int = absint( $fid );
				$custom_fields_normalized[ $fid_int ] = $fields;
			}
		}

		wp_localize_script(
			'aqm-ghl-admin',
			'aqmGhlSettings',
			array(
				'nonce'         => wp_create_nonce( 'aqm_ghl_admin' ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'selectedForms' => isset( $current_settings['form_ids'] ) && is_array( $current_settings['form_ids'] ) ? array_map( 'absint', $current_settings['form_ids'] ) : array(),
				'mapping'       => $mapping_normalized,
				'customFields'  => $custom_fields_normalized,
				'forms'         => $form_options,
				'optionKey'     => AQM_GHL_OPTION_KEY,
				'labels'        => array(
					'loading' => __( 'Loading fields…', 'aqm-ghl' ),
					'select'  => __( 'Select a field', 'aqm-ghl' ),
				),
			)
		);
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings     = aqm_ghl_get_settings();
		$forms        = aqm_ghl_get_formidable_forms();
		$last_test    = aqm_ghl_get_last_test_result();
		$last_payload = ! empty( $last_test['payload'] ) ? wp_json_encode( $last_test['payload'], JSON_PRETTY_PRINT ) : '';
		?>
		<div class="wrap aqm-ghl-wrap">
			<h1><?php esc_html_e( 'GHL + Formidable', 'aqm-ghl' ); ?></h1>
			<?php settings_errors(); ?>
			<?php if ( ! class_exists( 'FrmForm' ) ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Formidable Forms is not active. Install and activate it to configure this integration.', 'aqm-ghl' ); ?></p>
				</div>
			<?php endif; ?>
			<?php
			$locations = ! empty( $settings['locations'] ) && is_array( $settings['locations'] ) ? $settings['locations'] : array();
			$has_locations = ! empty( $locations );
			$has_legacy = ! empty( $settings['location_id'] ) && ! empty( $settings['private_token'] );
			
			if ( ! $has_locations && ! $has_legacy ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Configuration incomplete. Add at least one GoHighLevel location and select forms.', 'aqm-ghl' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php" class="aqm-ghl-form">
				<?php
				settings_fields( 'aqm_ghl_connector' );
				?>
				
				<h2><?php esc_html_e( 'GoHighLevel Locations', 'aqm-ghl' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure one or more GHL locations. Each location can have different forms mapped to it.', 'aqm-ghl' ); ?></p>
				
				<div id="aqm-ghl-locations-container">
					<?php if ( ! empty( $locations ) ) : ?>
						<?php foreach ( $locations as $index => $location ) : ?>
							<?php $this->render_location_fields( $index, $location, $forms ); ?>
						<?php endforeach; ?>
					<?php else : ?>
						<?php $this->render_location_fields( 0, array(), $forms ); ?>
					<?php endif; ?>
				</div>
				
				<p>
					<button type="button" class="button button-secondary" id="aqm-ghl-add-location"><?php esc_html_e( '+ Add Location', 'aqm-ghl' ); ?></button>
				</p>
				
				<h2><?php esc_html_e( 'Custom Field Provisioning', 'aqm-ghl' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'The plugin automatically creates required custom fields (UTM parameters, GCLID) in each location. Use this button to manually refresh/provision fields for all locations.', 'aqm-ghl' ); ?>
				</p>
				<p>
					<button type="button" class="button button-secondary" id="aqm-ghl-provision-fields"><?php esc_html_e( 'Refresh/Provision Custom Fields', 'aqm-ghl' ); ?></button>
					<span id="aqm-ghl-provision-result" class="notice inline" style="display:none; margin-left: 10px;"></span>
				</p>

				<h2><?php esc_html_e( 'Field Mapping', 'aqm-ghl' ); ?></h2>
				<div id="aqm-ghl-form-mapping-containers">
					<!-- Per-form mapping containers injected by JS -->
				</div>

				<h2><?php esc_html_e( 'Optional Settings', 'aqm-ghl' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aqm-ghl-tags"><?php esc_html_e( 'Tags', 'aqm-ghl' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[tags]" id="aqm-ghl-tags" type="text" value="<?php echo esc_attr( $settings['tags'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Comma-separated tags to apply to the contact.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aqm-ghl-github-token"><?php esc_html_e( 'GitHub Token (Optional)', 'aqm-ghl' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[github_token]" id="aqm-ghl-github-token" type="password" value="" placeholder="••••••••" class="regular-text" autocomplete="new-password" />
							<p class="description">
								<?php esc_html_e( 'GitHub Personal Access Token for private repository updates. Leave blank to keep current token. Required if repository is private. Create token at: ', 'aqm-ghl' ); ?>
								<a href="https://github.com/settings/tokens" target="_blank">https://github.com/settings/tokens</a>
								<?php esc_html_e( ' (needs "repo" scope)', 'aqm-ghl' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aqm-ghl-logging"><?php esc_html_e( 'Enable logging', 'aqm-ghl' ); ?></label></th>
						<td>
							<label>
								<input name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[enable_logging]" id="aqm-ghl-logging" type="checkbox" value="1" <?php checked( ! empty( $settings['enable_logging'] ) ); ?> />
								<?php esc_html_e( 'Log requests and errors to the PHP error log.', 'aqm-ghl' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Connection Test', 'aqm-ghl' ); ?></h2>
				<p><?php esc_html_e( 'Send a mock "John Doe" contact to your GoHighLevel location to verify credentials.', 'aqm-ghl' ); ?></p>
				<p>
					<button type="button" class="button button-secondary" id="aqm-ghl-test-connection"><?php esc_html_e( 'Send Test Contact', 'aqm-ghl' ); ?></button>
				</p>
				<div id="aqm-ghl-test-result" class="notice inline" style="display:none;"></div>

				<h2><?php esc_html_e( 'Update Management', 'aqm-ghl' ); ?></h2>
				<p><?php esc_html_e( 'If you uploaded files via FTP and WordPress is not detecting updates from GitHub, clear the update cache.', 'aqm-ghl' ); ?></p>
				<p>
					<button type="button" class="button button-secondary" id="aqm-ghl-clear-cache"><?php esc_html_e( 'Clear Update Cache', 'aqm-ghl' ); ?></button>
					<span id="aqm-ghl-cache-result" class="notice inline" style="display:none; margin-left: 10px;"></span>
				</p>
				<p class="description">
					<?php
					$current_version = AQM_GHL_CONNECTOR_VERSION;
					$github_token_set = ! empty( $settings['github_token'] ) || ( defined( 'AQM_GHL_GITHUB_TOKEN' ) && ! empty( AQM_GHL_GITHUB_TOKEN ) );
					printf(
						/* translators: 1: current version, 2: token status */
						esc_html__( 'Current version: %1$s | GitHub token: %2$s', 'aqm-ghl' ),
						esc_html( $current_version ),
						$github_token_set ? '<span style="color: green;">✓ Configured</span>' : '<span style="color: orange;">⚠ Not configured (required if repository is private)</span>'
					);
					?>
				</p>

				<h2><?php esc_html_e( 'Last Test Result', 'aqm-ghl' ); ?></h2>
				<?php if ( ! empty( $last_test['timestamp'] ) ) : ?>
					<p>
						<strong><?php esc_html_e( 'Timestamp:', 'aqm-ghl' ); ?></strong>
						<?php echo esc_html( $last_test['timestamp'] ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Status:', 'aqm-ghl' ); ?></strong>
						<?php echo esc_html( $last_test['status'] ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Message:', 'aqm-ghl' ); ?></strong>
						<?php echo esc_html( $last_test['message'] ); ?>
					</p>
					<?php if ( $last_payload ) : ?>
						<p><strong><?php esc_html_e( 'Request Payload:', 'aqm-ghl' ); ?></strong></p>
						<pre><?php echo esc_html( $last_payload ); ?></pre>
					<?php endif; ?>
					<?php if ( ! empty( $last_test['response'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Response Body:', 'aqm-ghl' ); ?></strong></p>
						<pre><?php echo esc_html( $last_test['response'] ); ?></pre>
					<?php endif; ?>
				<?php else : ?>
					<p><?php esc_html_e( 'No test run yet.', 'aqm-ghl' ); ?></p>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render location fields for a single location.
	 *
	 * @param int   $index    Location index.
	 * @param array $location Location data.
	 * @param array $forms    Available forms.
	 */
	private function render_location_fields( $index, $location, $forms ) {
		$name         = isset( $location['name'] ) ? $location['name'] : '';
		$location_id  = isset( $location['location_id'] ) ? $location['location_id'] : '';
		$private_token = isset( $location['private_token'] ) ? $location['private_token'] : '';
		$form_ids     = isset( $location['form_ids'] ) && is_array( $location['form_ids'] ) ? $location['form_ids'] : array();
		$tags         = isset( $location['tags'] ) ? $location['tags'] : '';
		?>
		<div class="aqm-ghl-location-block" data-location-index="<?php echo esc_attr( $index ); ?>" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; background: #f9f9f9;">
			<h3 style="margin-top: 0;">
				<?php esc_html_e( 'Location', 'aqm-ghl' ); ?> #<?php echo esc_html( $index + 1 ); ?>
				<button type="button" class="button button-small aqm-ghl-remove-location" style="float: right; color: #dc3232;"><?php esc_html_e( 'Remove', 'aqm-ghl' ); ?></button>
			</h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Location Name', 'aqm-ghl' ); ?></label></th>
					<td>
						<input 
							type="text" 
							name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[locations][<?php echo esc_attr( $index ); ?>][name]" 
							value="<?php echo esc_attr( $name ); ?>" 
							class="regular-text" 
							placeholder="<?php esc_attr_e( 'e.g., Client A', 'aqm-ghl' ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'A friendly name for this location (for your reference).', 'aqm-ghl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'GHL Location ID', 'aqm-ghl' ); ?></label></th>
					<td>
						<input 
							type="text" 
							name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[locations][<?php echo esc_attr( $index ); ?>][location_id]" 
							value="<?php echo esc_attr( $location_id ); ?>" 
							class="regular-text" 
							required
						/>
						<p class="description"><?php esc_html_e( 'Paste the GoHighLevel Location ID.', 'aqm-ghl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'GHL Private Integration Token', 'aqm-ghl' ); ?></label></th>
					<td>
						<input 
							type="password" 
							name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[locations][<?php echo esc_attr( $index ); ?>][private_token]" 
							value="" 
							placeholder="<?php echo ! empty( $private_token ) ? '••••••••' : ''; ?>" 
							class="regular-text" 
							autocomplete="new-password"
						/>
						<p class="description"><?php esc_html_e( 'Token is masked after save. Leave blank to keep the current token.', 'aqm-ghl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Formidable Forms', 'aqm-ghl' ); ?></label></th>
					<td>
						<div class="aqm-ghl-form-checkboxes">
							<?php if ( ! empty( $forms ) ) : ?>
								<?php foreach ( $forms as $form ) : ?>
									<?php $is_checked = in_array( (int) $form->id, $form_ids, true ); ?>
									<label class="aqm-ghl-form-checkbox-item">
										<input 
											type="checkbox" 
											name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[locations][<?php echo esc_attr( $index ); ?>][form_ids][]" 
											value="<?php echo esc_attr( $form->id ); ?>" 
											<?php checked( $is_checked ); ?>
										/>
										<span><?php echo esc_html( $form->name ); ?></span>
									</label>
								<?php endforeach; ?>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'No forms found.', 'aqm-ghl' ); ?></p>
							<?php endif; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Select forms that should send to this location.', 'aqm-ghl' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Tags', 'aqm-ghl' ); ?></label></th>
					<td>
						<input 
							type="text" 
							name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[locations][<?php echo esc_attr( $index ); ?>][tags]" 
							value="<?php echo esc_attr( $tags ); ?>" 
							class="regular-text"
						/>
						<p class="description"><?php esc_html_e( 'Comma-separated tags to apply to contacts from this location.', 'aqm-ghl' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Sanitize settings before save.
	 *
	 * @param array $input Raw input.
	 *
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$existing = aqm_ghl_get_settings();
		$sanitized = array();

		// Handle multi-location format
		if ( isset( $input['locations'] ) && is_array( $input['locations'] ) ) {
			$sanitized['locations'] = array();
			foreach ( $input['locations'] as $index => $location ) {
				$sanitized_location = array(
					'name'         => isset( $location['name'] ) ? sanitize_text_field( $location['name'] ) : '',
					'location_id'  => isset( $location['location_id'] ) ? sanitize_text_field( $location['location_id'] ) : '',
					'form_ids'     => array(),
					'tags'         => isset( $location['tags'] ) ? sanitize_text_field( $location['tags'] ) : '',
				);

				// Handle token (preserve if blank)
				$token = isset( $location['private_token'] ) ? trim( wp_unslash( $location['private_token'] ) ) : '';
				if ( '' === $token ) {
					// Preserve existing token for this location if available
					if ( isset( $existing['locations'][ $index ]['private_token'] ) ) {
						$sanitized_location['private_token'] = $existing['locations'][ $index ]['private_token'];
					} else {
						$sanitized_location['private_token'] = '';
					}
				} else {
					$sanitized_location['private_token'] = sanitize_text_field( $token );
				}

				// Handle form_ids
				if ( isset( $location['form_ids'] ) && is_array( $location['form_ids'] ) ) {
					foreach ( $location['form_ids'] as $fid ) {
						$fid = absint( $fid );
						if ( $fid ) {
							$sanitized_location['form_ids'][] = $fid;
						}
					}
				}

				$sanitized['locations'][] = $sanitized_location;
			}
		} else {
			// Preserve existing locations if not in input
			$sanitized['locations'] = isset( $existing['locations'] ) ? $existing['locations'] : array();
		}

		// Legacy single-location support (for backwards compatibility)
		$sanitized['location_id'] = isset( $input['location_id'] ) ? sanitize_text_field( $input['location_id'] ) : '';

		$token = isset( $input['private_token'] ) ? trim( wp_unslash( $input['private_token'] ) ) : '';
		if ( '' === $token ) {
			$sanitized['private_token'] = isset( $existing['private_token'] ) ? $existing['private_token'] : '';
		} else {
			$sanitized['private_token'] = sanitize_text_field( $token );
		}

		$github_token = isset( $input['github_token'] ) ? trim( wp_unslash( $input['github_token'] ) ) : '';
		if ( '' === $github_token ) {
			$sanitized['github_token'] = isset( $existing['github_token'] ) ? $existing['github_token'] : '';
		} else {
			$sanitized['github_token'] = sanitize_text_field( $github_token );
		}

		// Handle form_ids - can be array or empty
		$form_ids = array();
		if ( isset( $input['form_ids'] ) ) {
			// Handle both array and single value (in case WordPress sends it differently)
			if ( is_array( $input['form_ids'] ) ) {
				foreach ( $input['form_ids'] as $fid ) {
					$fid = absint( $fid );
					if ( $fid ) {
						$form_ids[] = $fid;
					}
				}
			} elseif ( ! empty( $input['form_ids'] ) ) {
				// Single value case
				$fid = absint( $input['form_ids'] );
				if ( $fid ) {
					$form_ids[] = $fid;
				}
			}
		}
		$sanitized['form_ids'] = $form_ids;

		// Preserve existing mappings and custom fields for all forms, merge with new data
		$existing_mapping = isset( $existing['mapping'] ) && is_array( $existing['mapping'] ) ? $existing['mapping'] : array();
		$existing_custom_fields = isset( $existing['custom_fields'] ) && is_array( $existing['custom_fields'] ) ? $existing['custom_fields'] : array();
		
		// Process new mapping data from form submission
		// Only update mappings for forms that are currently selected (have mapping data in POST)
		$mapping = isset( $input['mapping'] ) && is_array( $input['mapping'] ) ? $input['mapping'] : array();
		$sanitized['mapping'] = $existing_mapping; // Start with existing - preserve all
		
		// Only update mappings for forms that are in the POST data (currently visible/selected forms)
		// But preserve mappings for forms that are NOT in POST (hidden/deselected forms)
		if ( ! empty( $mapping ) ) {
			foreach ( $mapping as $fid => $map_values ) {
				$fid = absint( $fid );
				if ( ! $fid ) {
					continue;
				}
				// Only update if we have actual values - don't overwrite with empty if form block was hidden
				// Check if this form is in the selected form_ids - if not, preserve existing mapping
				if ( ! in_array( $fid, $form_ids, true ) ) {
					// Form is not selected, preserve existing mapping
					if ( isset( $existing_mapping[ $fid ] ) ) {
						$sanitized['mapping'][ $fid ] = $existing_mapping[ $fid ];
					}
					continue;
				}
				// Update this form's mapping - allow empty values (user can clear a mapping)
				$sanitized['mapping'][ $fid ] = array(
					'email'      => isset( $map_values['email'] ) ? absint( $map_values['email'] ) : '',
					'phone'      => isset( $map_values['phone'] ) ? absint( $map_values['phone'] ) : '',
					'first_name' => isset( $map_values['first_name'] ) ? absint( $map_values['first_name'] ) : '',
					'last_name'  => isset( $map_values['last_name'] ) ? absint( $map_values['last_name'] ) : '',
				);
			}
		}
		
		// Ensure all selected forms have mapping entries (even if empty) to prevent data loss
		foreach ( $form_ids as $fid ) {
			if ( ! isset( $sanitized['mapping'][ $fid ] ) ) {
				$sanitized['mapping'][ $fid ] = isset( $existing_mapping[ $fid ] ) 
					? $existing_mapping[ $fid ] 
					: array(
						'email'      => '',
						'phone'      => '',
						'first_name' => '',
						'last_name'  => '',
					);
			}
		}
		
		// Clean up mappings for forms that are no longer in form_ids (optional cleanup)
		// But preserve them in case user wants to reselect later - so we don't do cleanup

		// Process new custom fields data from form submission
		// The input comes as per-form structure: [form_id] => [ [ghl_field_id, form_field_id], ... ]
		$custom_fields = isset( $input['custom_fields'] ) ? $input['custom_fields'] : array();
		$sanitized_custom_fields = $existing_custom_fields; // Start with existing
		if ( ! empty( $custom_fields ) && is_array( $custom_fields ) ) {
			foreach ( $custom_fields as $fid => $fields_list ) {
				$fid = absint( $fid );
				if ( ! $fid ) {
					continue;
				}
				// Sanitize the fields list for this form
				if ( is_array( $fields_list ) ) {
					$cleaned = array();
					foreach ( $fields_list as $field ) {
						if ( empty( $field['ghl_field_id'] ) && empty( $field['form_field_id'] ) ) {
							continue;
						}
						$ghl_field_id  = isset( $field['ghl_field_id'] ) ? sanitize_text_field( $field['ghl_field_id'] ) : '';
						$form_field_id = isset( $field['form_field_id'] ) ? absint( $field['form_field_id'] ) : 0;
						if ( $ghl_field_id && $form_field_id ) {
							$cleaned[] = array(
								'ghl_field_id'  => $ghl_field_id,
								'form_field_id' => $form_field_id,
							);
						}
					}
					if ( ! empty( $cleaned ) ) {
						$sanitized_custom_fields[ $fid ] = $cleaned;
					} else {
						// If empty, remove this form's custom fields (user cleared them)
						unset( $sanitized_custom_fields[ $fid ] );
					}
				}
			}
		}
		$sanitized['custom_fields'] = $sanitized_custom_fields;

		$sanitized['tags'] = isset( $input['tags'] ) ? sanitize_text_field( $input['tags'] ) : '';

		$sanitized['enable_logging'] = ! empty( $input['enable_logging'] ) ? 1 : 0;

		add_settings_error(
			'aqm-ghl-connector',
			'aqm-ghl-connector-saved',
			__( 'Settings saved.', 'aqm-ghl' ),
			'updated'
		);

		return $sanitized;
	}

	/**
	 * AJAX handler to fetch fields for a form.
	 */
	public function ajax_get_form_fields() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'aqm-ghl' ) ), 403 );
		}

		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		if ( ! $form_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing form ID.', 'aqm-ghl' ) ), 400 );
		}

		$fields = aqm_ghl_get_formidable_form_fields( $form_id );

		wp_send_json_success(
			array(
				'fields' => $fields,
			)
		);
	}

	/**
	 * AJAX handler to test the connection by sending a mock contact.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'aqm-ghl' ) ), 403 );
		}

		$settings = aqm_ghl_get_settings();

		// Try to get first location, or fall back to legacy single location
		$location = null;
		if ( ! empty( $settings['locations'] ) && is_array( $settings['locations'] ) && ! empty( $settings['locations'][0] ) ) {
			$location = $settings['locations'][0];
		} elseif ( ! empty( $settings['location_id'] ) && ! empty( $settings['private_token'] ) ) {
			$location = array(
				'location_id'  => $settings['location_id'],
				'private_token' => $settings['private_token'],
			);
		}

		if ( ! $location || empty( $location['location_id'] ) || empty( $location['private_token'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Add at least one location with Location ID and Private Integration Token, then save settings before testing.', 'aqm-ghl' ),
				),
				400
			);
		}

		$payload = array(
			'locationId' => $location['location_id'],
			'email'      => 'john.doe+ghl-test@example.com',
			'phone'      => '+15555550123',
			'firstName'  => 'John',
			'lastName'   => 'Doe',
			'tags'       => array( 'Test', 'AQM Connector' ),
			'customFields' => array(
				array(
					'id'    => 'custom_test_note',
					'value' => 'Test connection from WordPress',
				),
			),
		);

		// Inject test UTM parameters and GCLID using provisioned field IDs
		$payload = $this->inject_test_utm_data( $payload, $location['location_id'], $location['private_token'] );

		$payload = aqm_ghl_clean_payload( $payload );

		$response = aqm_ghl_send_contact_payload( $payload, $location['private_token'] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Request error: %s', 'aqm-ghl' ),
						$response->get_error_message()
					),
				),
				500
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$body = is_string( $body ) ? $body : wp_json_encode( $body );

		if ( $code < 200 || $code >= 300 ) {
			aqm_ghl_store_last_test_result(
				array(
					'success'  => false,
					'status'   => $code,
					'payload'  => $payload,
					'response' => $body,
					'message'  => sprintf(
						/* translators: 1: status code, 2: response body */
						__( 'Non-2xx response (%1$s): %2$s', 'aqm-ghl' ),
						$code,
						$body
					),
				)
			);

			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: status code, 2: response body */
						__( 'Non-2xx response (%1$s): %2$s', 'aqm-ghl' ),
						$code,
						$body
					),
					'status'  => $code,
					'payload' => $payload,
					'response_body' => $body,
				),
				$code
			);
		}

		aqm_ghl_store_last_test_result(
			array(
				'success'  => true,
				'status'   => $code,
				'payload'  => $payload,
				'response' => $body,
				'message'  => __( 'Test contact sent successfully. Check GoHighLevel contacts.', 'aqm-ghl' ),
			)
		);

		wp_send_json_success(
			array(
				'message' => __( 'Test contact sent successfully. Check GoHighLevel contacts.', 'aqm-ghl' ),
				'status'  => $code,
				'payload' => $payload,
				'response_body' => $body,
			)
		);
	}

	/**
	 * Inject test UTM parameters and GCLID into the test payload using provisioned field IDs.
	 *
	 * @param array  $payload     Existing payload array.
	 * @param string $location_id GHL Location ID.
	 * @param string $token       Private integration token.
	 * @return array Modified payload with test UTM/GCLID data.
	 */
	private function inject_test_utm_data( $payload, $location_id, $token ) {
		$provisioner = new AQM_GHL_Custom_Field_Provisioner();

		// Force refresh to ensure fields are provisioned (provisions if needed)
		$field_mapping = $provisioner->get_field_mapping( $location_id, $token, true );

		if ( empty( $field_mapping ) ) {
			// Log the issue for debugging
			aqm_ghl_log(
				'Test UTM injection: No field mapping available. Fields may need to be provisioned manually.',
				array(
					'location_id' => $location_id,
					'field_mapping' => $field_mapping,
				)
			);
			// Continue without UTM data but log it
			return $payload;
		}

		// Test UTM parameters
		$test_utm_params = array(
			'gclid'        => 'test_gclid_123456789',
			'utm_source'   => 'test_source',
			'utm_medium'   => 'test_medium',
			'utm_campaign' => 'test_campaign',
			'utm_term'     => 'test_term',
			'utm_content'  => 'test_content',
		);

		// Initialize customFields array if needed
		if ( ! isset( $payload['customFields'] ) || ! is_array( $payload['customFields'] ) ) {
			$payload['customFields'] = array();
		}

		// Add each test UTM parameter if we have a field ID
		foreach ( $test_utm_params as $param_key => $value ) {
			// Get the provisioned field ID for this parameter
			if ( ! isset( $field_mapping[ $param_key ] ) || empty( $field_mapping[ $param_key ] ) ) {
				aqm_ghl_log(
					'Test UTM injection: Missing field mapping for parameter.',
					array(
						'param_key' => $param_key,
						'field_mapping' => $field_mapping,
					)
				);
				continue;
			}

			$field_id = $field_mapping[ $param_key ];

			// Check if field already exists (don't overwrite)
			$field_exists = false;
			foreach ( $payload['customFields'] as $field ) {
				if ( isset( $field['id'] ) && $field['id'] === $field_id ) {
					$field_exists = true;
					break;
				}
			}

			if ( ! $field_exists ) {
				$payload['customFields'][] = array(
					'id'    => $field_id,
					'value' => $value,
				);
			}
		}

		return $payload;
	}

	/**
	 * AJAX handler to clear update cache.
	 */
	public function ajax_clear_update_cache() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'aqm-ghl' ) ), 403 );
		}

		// Clear GitHub update cache
		if ( class_exists( 'AQM_GHL_Updater' ) ) {
			AQM_GHL_Updater::clear_cache();
		}

		// Also clear WordPress update transients
		delete_site_transient( 'update_plugins' );
		wp_clean_plugins_cache( true );

		wp_send_json_success(
			array(
				'message' => __( 'Update cache cleared successfully. Visit the Plugins page to check for updates.', 'aqm-ghl' ),
			)
		);
	}

	/**
	 * AJAX handler to provision custom fields for all locations.
	 */
	public function ajax_provision_fields() {
		check_ajax_referer( 'aqm_ghl_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'aqm-ghl' ) ), 403 );
		}

		$settings = aqm_ghl_get_settings();
		$provisioner = new AQM_GHL_Custom_Field_Provisioner();
		$results = array();

		// Provision fields for all locations
		if ( ! empty( $settings['locations'] ) && is_array( $settings['locations'] ) ) {
			foreach ( $settings['locations'] as $location ) {
				if ( empty( $location['location_id'] ) || empty( $location['private_token'] ) ) {
					$results[] = array(
						'location' => isset( $location['name'] ) ? $location['name'] : __( 'Unknown', 'aqm-ghl' ),
						'success'  => false,
						'message'  => __( 'Missing location ID or token.', 'aqm-ghl' ),
					);
					continue;
				}

				// Clear cache and force refresh
				$provisioner->clear_cache( $location['location_id'] );
				$mapping = $provisioner->get_field_mapping( $location['location_id'], $location['private_token'], true );

				if ( ! empty( $mapping ) ) {
					$results[] = array(
						'location' => isset( $location['name'] ) ? $location['name'] : __( 'Unknown', 'aqm-ghl' ),
						'success'  => true,
						'message'  => sprintf(
							/* translators: %d: number of fields */
							__( 'Successfully provisioned %d fields.', 'aqm-ghl' ),
							count( $mapping )
						),
					);
				} else {
					$results[] = array(
						'location' => isset( $location['name'] ) ? $location['name'] : __( 'Unknown', 'aqm-ghl' ),
						'success'  => false,
						'message'  => __( 'Failed to provision fields. Check logs for details.', 'aqm-ghl' ),
					);
				}
			}
		}

		// Also handle legacy single location
		if ( ! empty( $settings['location_id'] ) && ! empty( $settings['private_token'] ) ) {
			$provisioner->clear_cache( $settings['location_id'] );
			$mapping = $provisioner->get_field_mapping( $settings['location_id'], $settings['private_token'], true );

			if ( ! empty( $mapping ) ) {
				$results[] = array(
					'location' => __( 'Default Location (Legacy)', 'aqm-ghl' ),
					'success'  => true,
					'message'  => sprintf(
						/* translators: %d: number of fields */
						__( 'Successfully provisioned %d fields.', 'aqm-ghl' ),
						count( $mapping )
					),
				);
			}
		}

		if ( empty( $results ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No locations configured.', 'aqm-ghl' ),
				),
				400
			);
		}

		$success_count = count( array_filter( $results, function( $r ) { return $r['success']; } ) );
		$total_count = count( $results );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: success count, 2: total count */
					__( 'Provisioned fields for %1$d of %2$d locations.', 'aqm-ghl' ),
					$success_count,
					$total_count
				),
				'results' => $results,
			)
		);
	}
}


