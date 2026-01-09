<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles sending Formidable entries to GoHighLevel.
 */
class AQM_GHL_Handler {

	/**
	 * UTM Tracker instance.
	 *
	 * @var AQM_GHL_UTM_Tracker
	 */
	private $utm_tracker;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'frm_after_create_entry', array( $this, 'maybe_send_to_ghl' ), 20, 2 );
		$this->utm_tracker = new AQM_GHL_UTM_Tracker();
	}

	/**
	 * Process the entry and send to GoHighLevel when applicable.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $form_id  Form ID.
	 */
	public function maybe_send_to_ghl( $entry_id, $form_id ) {
		$settings = aqm_ghl_get_settings();

		$form_ids = ! empty( $settings['form_ids'] ) && is_array( $settings['form_ids'] ) ? array_map( 'absint', $settings['form_ids'] ) : array();

		if ( empty( $form_ids ) || ! in_array( (int) $form_id, $form_ids, true ) ) {
			return;
		}

		if ( empty( $settings['location_id'] ) || empty( $settings['private_token'] ) ) {
			aqm_ghl_log( 'Missing configuration. Aborting send.' );
			return;
		}

		if ( ! class_exists( 'FrmEntry' ) ) {
			aqm_ghl_log( 'Formidable Forms not available when processing entry.' );
			return;
		}

		$entry = FrmEntry::getOne( $entry_id, true );

		if ( ! $entry || empty( $entry->metas ) || ! is_array( $entry->metas ) ) {
			aqm_ghl_log( 'Unable to load entry metas.', array( 'entry_id' => $entry_id ) );
			return;
		}

		$metas = $entry->metas;
		$map_all = isset( $settings['mapping'] ) ? $settings['mapping'] : array();
		$map     = isset( $map_all[ $form_id ] ) ? $map_all[ $form_id ] : array();

		$email      = $this->get_meta_value( $metas, isset( $map['email'] ) ? $map['email'] : 0 );
		$raw_phone  = $this->get_meta_value( $metas, isset( $map['phone'] ) ? $map['phone'] : 0 );
		$first_name = $this->get_meta_value( $metas, isset( $map['first_name'] ) ? $map['first_name'] : 0 );
		$last_name  = $this->get_meta_value( $metas, isset( $map['last_name'] ) ? $map['last_name'] : 0 );

		$phone = aqm_ghl_normalize_phone( $raw_phone );

		if ( empty( $email ) && empty( $phone ) ) {
			aqm_ghl_log( 'Email or phone required; both missing.', array( 'entry_id' => $entry_id ) );
			return;
		}

		$payload = array(
			'locationId' => $settings['location_id'],
			'email'      => is_array( $email ) ? reset( $email ) : $email,
			'phone'      => $phone,
			'firstName'  => is_array( $first_name ) ? reset( $first_name ) : $first_name,
			'lastName'   => is_array( $last_name ) ? reset( $last_name ) : $last_name,
		);

		if ( ! empty( $settings['tags'] ) ) {
			$tags = array_filter(
				array_map(
					'trim',
					explode( ',', $settings['tags'] )
				)
			);
			if ( ! empty( $tags ) ) {
				$payload['tags'] = array_values( $tags );
			}
		}

		$custom_fields = $this->prepare_custom_fields( $settings, $metas, $form_id );
		if ( ! empty( $custom_fields ) ) {
			$payload['customFields'] = $custom_fields;
		}

		// Inject UTM parameters and GCLID
		$payload = $this->inject_utm_data( $payload );

		$payload = aqm_ghl_clean_payload( $payload );

		$response = aqm_ghl_send_contact_payload( $payload, $settings['private_token'] );

		if ( is_wp_error( $response ) ) {
			aqm_ghl_log(
				'Error sending to GoHighLevel.',
				array(
					'error'     => $response->get_error_message(),
					'entry_id'  => $entry_id,
					'form_id'   => $form_id,
				)
			);
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			aqm_ghl_log(
				'Non-2xx response from GoHighLevel.',
				array(
					'status'   => $code,
					'body'     => wp_remote_retrieve_body( $response ),
					'entry_id' => $entry_id,
				)
			);
			return;
		}

		aqm_ghl_log(
			'Successfully sent contact to GoHighLevel.',
			array(
				'entry_id' => $entry_id,
				'status'   => $code,
			)
		);
	}

	/**
	 * Prepare custom fields payload.
	 *
	 * @param array $settings Plugin settings.
	 * @param array $metas    Entry metas.
	 * @param int   $form_id  Current form ID.
	 *
	 * @return array
	 */
	private function prepare_custom_fields( $settings, $metas, $form_id ) {
		if ( empty( $settings['custom_fields'] ) || ! is_array( $settings['custom_fields'] ) ) {
			return array();
		}

		$form_custom_fields = isset( $settings['custom_fields'][ $form_id ] ) ? $settings['custom_fields'][ $form_id ] : array();

		$prepared = array();

		foreach ( $form_custom_fields as $custom ) {
			$ghl_id = isset( $custom['ghl_field_id'] ) ? $custom['ghl_field_id'] : '';
			$field  = isset( $custom['form_field_id'] ) ? (int) $custom['form_field_id'] : 0;

			if ( ! $ghl_id || ! $field ) {
				continue;
			}

			$value = $this->get_meta_value( $metas, $field );

			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}

			$prepared[] = array(
				'id'    => $ghl_id,
				'value' => is_array( $value ) ? implode( ', ', array_filter( $value ) ) : $value,
			);
		}

		return $prepared;
	}

	/**
	 * Fetch a single meta value by field ID.
	 *
	 * @param array $metas    Entry metas.
	 * @param int   $field_id Field ID.
	 *
	 * @return mixed|null
	 */
	private function get_meta_value( $metas, $field_id ) {
		if ( ! $field_id ) {
			return null;
		}

		return isset( $metas[ $field_id ] ) ? $metas[ $field_id ] : null;
	}

	/**
	 * Inject UTM parameters and GCLID into the payload.
	 *
	 * Maps:
	 * - gclid → customFields array (custom_gclid)
	 * - utm_* → system fields if GHL supports them (source, medium, campaign)
	 *
	 * @param array $payload Existing payload array.
	 * @return array Modified payload with UTM/GCLID data.
	 */
	private function inject_utm_data( $payload ) {
		$params = $this->utm_tracker->get_tracked_parameters();

		if ( empty( $params ) ) {
			return $payload;
		}

		// Extract GCLID (goes to custom field)
		$gclid = isset( $params['gclid'] ) ? $params['gclid'] : '';

		// Extract UTM parameters
		$utm_source   = isset( $params['utm_source'] ) ? $params['utm_source'] : '';
		$utm_medium   = isset( $params['utm_medium'] ) ? $params['utm_medium'] : '';
		$utm_campaign = isset( $params['utm_campaign'] ) ? $params['utm_campaign'] : '';
		$utm_term     = isset( $params['utm_term'] ) ? $params['utm_term'] : '';
		$utm_content  = isset( $params['utm_content'] ) ? $params['utm_content'] : '';

		// Add GCLID to customFields array
		if ( ! empty( $gclid ) ) {
			if ( ! isset( $payload['customFields'] ) || ! is_array( $payload['customFields'] ) ) {
				$payload['customFields'] = array();
			}

			// Check if gclid custom field already exists (don't overwrite form field data)
			$gclid_exists = false;
			foreach ( $payload['customFields'] as $key => $field ) {
				if ( isset( $field['id'] ) && $field['id'] === 'custom_gclid' ) {
					$gclid_exists = true;
					break;
				}
			}

			// Only add if it doesn't already exist
			if ( ! $gclid_exists ) {
				$payload['customFields'][] = array(
					'id'    => 'custom_gclid',
					'value' => $gclid,
				);
			}
		}

		// Add UTM parameters to customFields array
		// Note: GHL API accepts custom fields with IDs like 'custom_utm_source', etc.
		// If your GHL instance uses different field IDs, update them accordingly
		$utm_custom_fields = array(
			'utm_source'   => 'custom_utm_source',
			'utm_medium'   => 'custom_utm_medium',
			'utm_campaign' => 'custom_utm_campaign',
		);

		foreach ( $utm_custom_fields as $utm_param => $ghl_field_id ) {
			$value = '';
			if ( 'utm_source' === $utm_param && ! empty( $utm_source ) ) {
				$value = $utm_source;
			} elseif ( 'utm_medium' === $utm_param && ! empty( $utm_medium ) ) {
				$value = $utm_medium;
			} elseif ( 'utm_campaign' === $utm_param && ! empty( $utm_campaign ) ) {
				$value = $utm_campaign;
			}

			if ( ! empty( $value ) ) {
				if ( ! isset( $payload['customFields'] ) || ! is_array( $payload['customFields'] ) ) {
					$payload['customFields'] = array();
				}

				// Check if field already exists (don't overwrite)
				$field_exists = false;
				foreach ( $payload['customFields'] as $field ) {
					if ( isset( $field['id'] ) && $field['id'] === $ghl_field_id ) {
						$field_exists = true;
						break;
					}
				}

				if ( ! $field_exists ) {
					$payload['customFields'][] = array(
						'id'    => $ghl_field_id,
						'value' => $value,
					);
				}
			}
		}

		// utm_term and utm_content typically go to custom fields
		if ( ! empty( $utm_term ) ) {
			if ( ! isset( $payload['customFields'] ) || ! is_array( $payload['customFields'] ) ) {
				$payload['customFields'] = array();
			}

			$utm_term_exists = false;
			foreach ( $payload['customFields'] as $field ) {
				if ( isset( $field['id'] ) && $field['id'] === 'custom_utm_term' ) {
					$utm_term_exists = true;
					break;
				}
			}

			if ( ! $utm_term_exists ) {
				$payload['customFields'][] = array(
					'id'    => 'custom_utm_term',
					'value' => $utm_term,
				);
			}
		}

		if ( ! empty( $utm_content ) ) {
			if ( ! isset( $payload['customFields'] ) || ! is_array( $payload['customFields'] ) ) {
				$payload['customFields'] = array();
			}

			$utm_content_exists = false;
			foreach ( $payload['customFields'] as $field ) {
				if ( isset( $field['id'] ) && $field['id'] === 'custom_utm_content' ) {
					$utm_content_exists = true;
					break;
				}
			}

			if ( ! $utm_content_exists ) {
				$payload['customFields'][] = array(
					'id'    => 'custom_utm_content',
					'value' => $utm_content,
				);
			}
		}

		return $payload;
	}

}


