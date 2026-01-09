<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UTM Parameter and GCLID Tracker
 *
 * Captures UTM parameters and GCLID from landing page URLs,
 * stores them in secure HTTP-only cookies, and provides
 * methods to retrieve them for GHL payload injection.
 */
class AQM_GHL_UTM_Tracker {

	/**
	 * Cookie names (prefix to avoid conflicts)
	 */
	const COOKIE_PREFIX = 'aqm_ghl_';

	/**
	 * Cookie expiration (90 days in seconds)
	 */
	const COOKIE_EXPIRY = 7776000; // 90 * 24 * 60 * 60

	/**
	 * Parameters to track
	 */
	const TRACKED_PARAMS = array(
		'gclid',
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Capture parameters on page load (early, before headers sent)
		add_action( 'init', array( $this, 'capture_url_parameters' ), 1 );
	}

	/**
	 * Capture URL parameters and store in cookies.
	 *
	 * Runs on 'init' hook (priority 1) to execute before headers are sent.
	 */
	public function capture_url_parameters() {
		// Only run on frontend, not admin or AJAX
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		// Check if we have parameters to capture
		$has_params = false;
		foreach ( self::TRACKED_PARAMS as $param ) {
			if ( isset( $_GET[ $param ] ) ) {
				$has_params = true;
				break;
			}
		}

		if ( ! $has_params ) {
			return;
		}

		// Capture and store each parameter
		foreach ( self::TRACKED_PARAMS as $param ) {
			if ( ! isset( $_GET[ $param ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_GET[ $param ] ) );

			// Only set cookie if value is not empty
			if ( ! empty( $value ) ) {
				$this->set_cookie( $param, $value );
			}
		}
	}

	/**
	 * Set a secure HTTP-only cookie.
	 *
	 * @param string $name  Cookie name (parameter name).
	 * @param string $value Cookie value (sanitized).
	 */
	private function set_cookie( $name, $value ) {
		$cookie_name = self::COOKIE_PREFIX . $name;

		// Use setcookie() with secure options
		$set = setcookie(
			$cookie_name,
			$value,
			time() + self::COOKIE_EXPIRY,
			COOKIEPATH,
			COOKIE_DOMAIN,
			is_ssl(), // Secure flag (HTTPS only if site uses SSL)
			true      // HTTP-only flag (prevent JavaScript access)
		);

		// Also set in $_COOKIE for immediate access in same request
		if ( $set ) {
			$_COOKIE[ $cookie_name ] = $value;
		}
	}

	/**
	 * Get all tracked parameters from cookies.
	 *
	 * @return array Associative array of parameter => value (only non-empty values).
	 */
	public function get_tracked_parameters() {
		$params = array();

		foreach ( self::TRACKED_PARAMS as $param ) {
			$cookie_name = self::COOKIE_PREFIX . $param;
			$value       = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

			if ( ! empty( $value ) ) {
				$params[ $param ] = $value;
			}
		}

		return $params;
	}

	/**
	 * Get a specific parameter value from cookie.
	 *
	 * @param string $param Parameter name (e.g., 'gclid', 'utm_source').
	 * @return string Empty string if not found.
	 */
	public function get_parameter( $param ) {
		if ( ! in_array( $param, self::TRACKED_PARAMS, true ) ) {
			return '';
		}

		$cookie_name = self::COOKIE_PREFIX . $param;
		$value       = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

		return $value;
	}
}
