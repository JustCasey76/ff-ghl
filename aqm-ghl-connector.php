<?php
/**
 * Plugin Name: AQM GHL Formidable Connector
 * Description: Sends Formidable Forms submissions to GoHighLevel (LeadConnector) as Contacts using a Private Integration token.
 * Version: 1.3.2
 * Author: AQMarketing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AQM_GHL_CONNECTOR_VERSION', '1.3.2' );
define( 'AQM_GHL_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'AQM_GHL_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );
define( 'AQM_GHL_OPTION_KEY', 'aqm_ghl_connector_settings' );
define( 'AQM_GHL_TEST_RESULT_KEY', 'aqm_ghl_last_test_result' );

require_once AQM_GHL_CONNECTOR_DIR . 'includes/helpers.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-admin.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-handler.php';
require_once AQM_GHL_CONNECTOR_DIR . 'includes/class-aqm-ghl-updater.php';

/**
 * Bootstrap the plugin components.
 */
function aqm_ghl_connector_init() {
	new AQM_GHL_Admin();
	new AQM_GHL_Handler();
	
	// Initialize GitHub updater
	// For private repos, you can optionally add a GitHub token via filter or constant
	$github_token = defined( 'AQM_GHL_GITHUB_TOKEN' ) ? AQM_GHL_GITHUB_TOKEN : '';
	new AQM_GHL_Updater(
		__FILE__,
		'JustCasey76',
		'ff-ghl',
		$github_token
	);
}
add_action( 'plugins_loaded', 'aqm_ghl_connector_init' );


