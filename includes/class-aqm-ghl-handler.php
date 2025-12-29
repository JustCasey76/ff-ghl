<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles sending Formidable entries to GoHighLevel.
 */
class AQM_GHL_Handler {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'frm_after_create_entry', array( $this, 'maybe_send_to_ghl' ), 20, 2 );
	}

	/**
	 * Process the entry and send to GoHighLevel when applicable.
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $form_id  Form ID.
	 */
	public function maybe_send_to_ghl( $entry_id, $form_id ) {
		$settings = aqm_ghl_get_settings();

		if ( empty( $settings['form_id'] ) || (int) $settings['form_id'] !== (int) $form_id ) {
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
		$map   = isset( $settings['mapping'] ) ? $settings['mapping'] : array();

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

		$custom_fields = $this->prepare_custom_fields( $settings, $metas );
		if ( ! empty( $custom_fields ) ) {
			$payload['customFields'] = $custom_fields;
		}

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
	 *
	 * @return array
	 */
	private function prepare_custom_fields( $settings, $metas ) {
		if ( empty( $settings['custom_fields'] ) || ! is_array( $settings['custom_fields'] ) ) {
			return array();
		}

		$prepared = array();

		foreach ( $settings['custom_fields'] as $custom ) {
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

}


