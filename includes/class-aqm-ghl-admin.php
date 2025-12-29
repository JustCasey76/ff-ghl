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

		wp_localize_script(
			'aqm-ghl-admin',
			'aqmGhlSettings',
			array(
				'nonce'         => wp_create_nonce( 'aqm_ghl_admin' ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'selectedForm'  => absint( $current_settings['form_id'] ),
				'mapping'       => isset( $current_settings['mapping'] ) ? $current_settings['mapping'] : array(),
				'customFields'  => isset( $current_settings['custom_fields'] ) ? $current_settings['custom_fields'] : array(),
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
			<?php if ( empty( $settings['location_id'] ) || empty( $settings['private_token'] ) || empty( $settings['form_id'] ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Configuration incomplete. Add your GoHighLevel credentials and select a Formidable form.', 'aqm-ghl' ); ?></p>
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
						<th scope="row"><label for="aqm-ghl-form-select"><?php esc_html_e( 'Formidable Form', 'aqm-ghl' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[form_id]" id="aqm-ghl-form-select" class="regular-text">
								<option value=""><?php esc_html_e( 'Select a form', 'aqm-ghl' ); ?></option>
								<?php foreach ( $forms as $form ) : ?>
									<option value="<?php echo esc_attr( $form->id ); ?>" <?php selected( (int) $settings['form_id'], (int) $form->id ); ?>>
										<?php echo esc_html( $form->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Choose the form whose submissions will be sent to GoHighLevel.', 'aqm-ghl' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Field Mapping', 'aqm-ghl' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="aqm-ghl-map-email"><?php esc_html_e( 'Email (required)', 'aqm-ghl' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[mapping][email]" id="aqm-ghl-map-email" class="regular-text aqm-ghl-field-select"></select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aqm-ghl-map-phone"><?php esc_html_e( 'Phone (optional)', 'aqm-ghl' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[mapping][phone]" id="aqm-ghl-map-phone" class="regular-text aqm-ghl-field-select"></select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aqm-ghl-map-first-name"><?php esc_html_e( 'First Name', 'aqm-ghl' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[mapping][first_name]" id="aqm-ghl-map-first-name" class="regular-text aqm-ghl-field-select"></select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aqm-ghl-map-last-name"><?php esc_html_e( 'Last Name', 'aqm-ghl' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( AQM_GHL_OPTION_KEY ); ?>[mapping][last_name]" id="aqm-ghl-map-last-name" class="regular-text aqm-ghl-field-select"></select>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Custom Fields', 'aqm-ghl' ); ?></h2>
				<div id="aqm-ghl-custom-fields">
					<!-- Rows injected by JS -->
				</div>
				<p>
					<button type="button" class="button" id="aqm-ghl-add-custom-field"><?php esc_html_e( 'Add Custom Field', 'aqm-ghl' ); ?></button>
				</p>

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
		$existing       = aqm_ghl_get_settings();
		$sanitized      = array();
		$sanitized['location_id'] = isset( $input['location_id'] ) ? sanitize_text_field( $input['location_id'] ) : '';

		$token = isset( $input['private_token'] ) ? trim( wp_unslash( $input['private_token'] ) ) : '';
		if ( '' === $token ) {
			$sanitized['private_token'] = isset( $existing['private_token'] ) ? $existing['private_token'] : '';
		} else {
			$sanitized['private_token'] = sanitize_text_field( $token );
		}

		$sanitized['form_id'] = isset( $input['form_id'] ) ? absint( $input['form_id'] ) : '';

		$mapping = isset( $input['mapping'] ) && is_array( $input['mapping'] ) ? $input['mapping'] : array();
		$sanitized['mapping'] = array(
			'email'      => isset( $mapping['email'] ) ? absint( $mapping['email'] ) : '',
			'phone'      => isset( $mapping['phone'] ) ? absint( $mapping['phone'] ) : '',
			'first_name' => isset( $mapping['first_name'] ) ? absint( $mapping['first_name'] ) : '',
			'last_name'  => isset( $mapping['last_name'] ) ? absint( $mapping['last_name'] ) : '',
		);

		$custom_fields = isset( $input['custom_fields'] ) ? $input['custom_fields'] : array();
		$sanitized['custom_fields'] = aqm_ghl_sanitize_custom_fields( $custom_fields );

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
}


