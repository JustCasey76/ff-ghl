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
			<?php if ( empty( $settings['location_id'] ) || empty( $settings['private_token'] ) || empty( $settings['form_ids'] ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Configuration incomplete. Add your GoHighLevel credentials and select at least one Formidable form.', 'aqm-ghl' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php" class="aqm-ghl-form">
				<?php
				settings_fields( 'aqm_ghl_connector' );
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aqm-ghl-location-id"><?php esc_html_e( 'GHL Location ID', 'aqm-ghl' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[location_id]" id="aqm-ghl-location-id" type="text" value="<?php echo esc_attr( $settings['location_id'] ); ?>" class="regular-text" required />
							<p class="description"><?php esc_html_e( 'Paste the GoHighLevel Location ID.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aqm-ghl-private-token"><?php esc_html_e( 'GHL Private Integration Token', 'aqm-ghl' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[private_token]" id="aqm-ghl-private-token" type="password" value="" placeholder="••••••••" class="regular-text" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Token is masked after save. Leave blank to keep the current token.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aqm-ghl-form-select"><?php esc_html_e( 'Formidable Forms', 'aqm-ghl' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[form_ids][]" id="aqm-ghl-form-select" class="regular-text" multiple size="6">
								<?php foreach ( $forms as $form ) : ?>
									<option value="<?php echo esc_attr( $form->id ); ?>" <?php echo in_array( (int) $form->id, isset( $settings['form_ids'] ) ? (array) $settings['form_ids'] : array(), true ) ? 'selected' : ''; ?>>
										<?php echo esc_html( $form->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Select one or more forms to send to GoHighLevel.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
				</table>

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
					$github_token_set = defined( 'AQM_GHL_GITHUB_TOKEN' ) && ! empty( AQM_GHL_GITHUB_TOKEN );
					printf(
						/* translators: 1: current version, 2: token status */
						esc_html__( 'Current version: %1$s | GitHub token: %2$s', 'aqm-ghl' ),
						esc_html( $current_version ),
						$github_token_set ? '<span style="color: green;">✓ Configured</span>' : '<span style="color: red;">✗ Not configured (required for private repos)</span>'
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
	 * Sanitize settings before save.
	 *
	 * @param array $input Raw input.
	 *
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$existing             = aqm_ghl_get_settings();
		$sanitized            = array();
		$sanitized['location_id'] = isset( $input['location_id'] ) ? sanitize_text_field( $input['location_id'] ) : '';

		$token = isset( $input['private_token'] ) ? trim( wp_unslash( $input['private_token'] ) ) : '';
		if ( '' === $token ) {
			$sanitized['private_token'] = isset( $existing['private_token'] ) ? $existing['private_token'] : '';
		} else {
			$sanitized['private_token'] = sanitize_text_field( $token );
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
		if ( ! empty( $mapping ) ) {
			foreach ( $mapping as $fid => $map_values ) {
				$fid = absint( $fid );
				if ( ! $fid ) {
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

		if ( empty( $settings['location_id'] ) || empty( $settings['private_token'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Add Location ID and Private Integration Token, then save settings before testing.', 'aqm-ghl' ),
				),
				400
			);
		}

		$payload = aqm_ghl_clean_payload(
			array(
				'locationId' => $settings['location_id'],
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
			)
		);

		$response = aqm_ghl_send_contact_payload( $payload, $settings['private_token'] );

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
}


