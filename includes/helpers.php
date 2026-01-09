<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'aqm_ghl_get_settings' ) ) {
	/**
	 * Retrieve plugin settings with defaults.
	 *
	 * @return array
	 */
	function aqm_ghl_get_settings() {
		$defaults = array(
			'location_id'    => '',
			'private_token'  => '',
			'github_token'   => '',
			'form_ids'       => array(),
			'mapping'        => array(), // per form: [form_id] => [email, phone, first_name, last_name]
			'custom_fields'  => array(), // per form: [form_id] => [ [ghl_field_id, form_field_id], ... ]
			'tags'           => '',
			'enable_logging' => false,
		);

		$settings = get_option( AQM_GHL_OPTION_KEY, array() );
		
		// Migrate old form_id (singular) to form_ids (plural array)
		if ( ! empty( $settings['form_id'] ) && empty( $settings['form_ids'] ) ) {
			$settings['form_ids'] = array( absint( $settings['form_id'] ) );
			unset( $settings['form_id'] );
			update_option( AQM_GHL_OPTION_KEY, $settings );
		}

		return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
	}
}

if ( ! function_exists( 'aqm_ghl_get_setting' ) ) {
	/**
	 * Helper to fetch a single setting.
	 *
	 * @param string $key Setting key.
	 * @param mixed  $default Default if missing.
	 *
	 * @return mixed
	 */
	function aqm_ghl_get_setting( $key, $default = '' ) {
		$settings = aqm_ghl_get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}
}

if ( ! function_exists( 'aqm_ghl_is_logging_enabled' ) ) {
	/**
	 * Determine if logging is enabled.
	 *
	 * @return bool
	 */
	function aqm_ghl_is_logging_enabled() {
		$settings = aqm_ghl_get_settings();

		return ! empty( $settings['enable_logging'] );
	}
}

if ( ! function_exists( 'aqm_ghl_log' ) ) {
	/**
	 * Log a message to the PHP error log when enabled.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional context array.
	 */
	function aqm_ghl_log( $message, $context = array() ) {
		if ( ! aqm_ghl_is_logging_enabled() ) {
			return;
		}

		$line = '[AQM GHL] ' . ( is_scalar( $message ) ? $message : wp_json_encode( $message ) );

		if ( ! empty( $context ) ) {
			$line .= ' | ' . wp_json_encode( $context );
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

if ( ! function_exists( 'aqm_ghl_normalize_phone' ) ) {
	/**
	 * Normalize a phone number toward E.164 when feasible.
	 *
	 * @param string $phone Input phone.
	 *
	 * @return string
	 */
	function aqm_ghl_normalize_phone( $phone ) {
		$phone = trim( (string) $phone );

		if ( '' === $phone ) {
			return '';
		}

		// Preserve leading + then strip all non-digits.
		$has_plus  = substr( $phone, 0, 1 ) === '+';
		$digits    = preg_replace( '/\D+/', '', $phone );
		$normalized = $digits;

		if ( ! $normalized ) {
			return '';
		}

		// Assume US/Canada if 10 digits without country code.
		if ( strlen( $normalized ) === 10 ) {
			$normalized = '1' . $normalized;
		}

		// If length already includes country code.
		if ( $has_plus && substr( $normalized, 0, 1 ) !== '+' ) {
			$normalized = '+' . $normalized;
		} elseif ( substr( $normalized, 0, 1 ) !== '+' ) {
			$normalized = '+' . $normalized;
		}

		return $normalized;
	}
}

if ( ! function_exists( 'aqm_ghl_clean_payload' ) ) {
	/**
	 * Remove empty values from the payload recursively.
	 *
	 * @param array $payload Payload data.
	 *
	 * @return array
	 */
	function aqm_ghl_clean_payload( $payload ) {
		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				$payload[ $key ] = aqm_ghl_clean_payload( $value );

				if ( empty( $payload[ $key ] ) ) {
					unset( $payload[ $key ] );
				}
			} elseif ( '' === $value || null === $value ) {
				unset( $payload[ $key ] );
			}
		}

		return $payload;
	}
}

if ( ! function_exists( 'aqm_ghl_get_formidable_forms' ) ) {
	/**
	 * Get published Formidable forms.
	 *
	 * @return array
	 */
	function aqm_ghl_get_formidable_forms() {
		if ( ! class_exists( 'FrmForm' ) ) {
			return array();
		}

		$forms = FrmForm::getAll(
			array(
				'status' => 'published',
			)
		);

		return is_array( $forms ) ? $forms : array();
	}
}

if ( ! function_exists( 'aqm_ghl_get_formidable_form_fields' ) ) {
	/**
	 * Get fields for a specific Formidable form.
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array
	 */
	function aqm_ghl_get_formidable_form_fields( $form_id ) {
		if ( ! class_exists( 'FrmField' ) || ! $form_id ) {
			return array();
		}

		$fields = FrmField::getAll(
			array(
				'fi.form_id' => absint( $form_id ),
				'fi.type not' => array( 'divider', 'html', 'break', 'captcha', 'end_divider' ),
			)
		);

		$prepared = array();

		if ( empty( $fields ) ) {
			return $prepared;
		}

		foreach ( $fields as $field ) {
			if ( empty( $field->id ) ) {
				continue;
			}

			$prepared[] = array(
				'id'    => (int) $field->id,
				'label' => isset( $field->name ) ? $field->name : '',
			);
		}

		return $prepared;
	}

if ( ! function_exists( 'aqm_ghl_send_contact_payload' ) ) {
	/**
	 * Send a contact payload to GoHighLevel.
	 *
	 * @param array  $payload Payload array.
	 * @param string $token   Private integration token.
	 *
	 * @return array|\WP_Error
	 */
	function aqm_ghl_send_contact_payload( $payload, $token ) {
		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Version'       => '2021-07-28',
			),
			'timeout' => 15,
			'body'    => wp_json_encode( $payload ),
		);

		return wp_remote_post( 'https://services.leadconnectorhq.com/contacts/', $args );
	}
}

if ( ! function_exists( 'aqm_ghl_store_last_test_result' ) ) {
	/**
	 * Store the last test result for display in admin.
	 *
	 * @param array $data Result data.
	 */
	function aqm_ghl_store_last_test_result( $data ) {
		$payload = array(
			'timestamp' => current_time( 'mysql' ),
			'success'   => isset( $data['success'] ) ? (bool) $data['success'] : false,
			'status'    => isset( $data['status'] ) ? (int) $data['status'] : 0,
			'payload'   => isset( $data['payload'] ) ? $data['payload'] : array(),
			'response'  => isset( $data['response'] ) ? $data['response'] : '',
			'message'   => isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '',
		);

		update_option( AQM_GHL_TEST_RESULT_KEY, $payload, false );
	}
}

if ( ! function_exists( 'aqm_ghl_get_last_test_result' ) ) {
	/**
	 * Retrieve the last stored test result.
	 *
	 * @return array
	 */
	function aqm_ghl_get_last_test_result() {
		$defaults = array(
			'timestamp' => '',
			'success'   => false,
			'status'    => 0,
			'payload'   => array(),
			'response'  => '',
			'message'   => '',
		);

		$saved = get_option( AQM_GHL_TEST_RESULT_KEY, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}
}
}

if ( ! function_exists( 'aqm_ghl_sanitize_custom_fields' ) ) {
	/**
	 * Sanitize custom field mappings.
	 *
	 * @param array $custom_fields Raw input (per form or flat).
	 *
	 * @return array
	 */
	function aqm_ghl_sanitize_custom_fields( $custom_fields ) {
		if ( empty( $custom_fields ) || ! is_array( $custom_fields ) ) {
			return array();
		}

		// Detect per-form structure.
		$is_per_form = false;
		foreach ( $custom_fields as $key => $value ) {
			if ( is_array( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
				$is_per_form = true;
				break;
			}
		}

		// Helper to clean a list.
		$clean_list = function ( $list ) {
			$out = array();
			foreach ( $list as $custom_field ) {
				if ( empty( $custom_field['ghl_field_id'] ) && empty( $custom_field['form_field_id'] ) ) {
					continue;
				}
				$ghl_field_id  = isset( $custom_field['ghl_field_id'] ) ? sanitize_text_field( $custom_field['ghl_field_id'] ) : '';
				$form_field_id = isset( $custom_field['form_field_id'] ) ? absint( $custom_field['form_field_id'] ) : 0;
				if ( ! $ghl_field_id || ! $form_field_id ) {
					continue;
				}
				$out[] = array(
					'ghl_field_id'  => $ghl_field_id,
					'form_field_id' => $form_field_id,
				);
			}
			return $out;
		};

		if ( ! $is_per_form ) {
			return $clean_list( $custom_fields );
		}

		$clean = array();
		foreach ( $custom_fields as $form_id => $list ) {
			$form_id = absint( $form_id );
			if ( ! $form_id ) {
				continue;
			}
			$cleaned = $clean_list( $list );
			if ( ! empty( $cleaned ) ) {
				$clean[ $form_id ] = $cleaned;
			}
		}

		return $clean;
	}
}


