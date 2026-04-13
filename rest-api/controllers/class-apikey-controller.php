<?php
namespace ContentAutoManager\RestApi\Controllers;

/**
 * Controller for API Key management
 */
class ApiKey_Controller extends Base_Controller {

    public function register_routes() {
        // Generate new API key (requires logged in admin)
        register_rest_route( $this->namespace, '/generate-api-key', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_api_key' ),
            'permission_callback' => array( $this, 'check_admin_cookie_permission' ),
        ) );
        
        // Get License Info (For Extension Sync & Validation)
        register_rest_route( $this->namespace, '/license-info', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_license_info' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );
    }

    /**
     * Check if user is logged in admin (cookie-based, for WP admin panel use)
     */
    public function check_admin_cookie_permission() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Generate a new API key
     */
    public function generate_api_key( $request ) {
        // Verify License Status
        if ( ! class_exists( '\Yali_AI_Writer_License_Manager' ) ) {
            if ( defined( 'YALI_AI_WRITER_PLUGIN_DIR' ) ) {
                require_once YALI_AI_WRITER_PLUGIN_DIR . 'includes/class-license-manager.php';
            }
        }
        if ( class_exists( '\Yali_AI_Writer_License_Manager' ) && ! \Yali_AI_Writer_License_Manager::is_license_active() ) {
            return new \WP_Error( 'rest_forbidden', 'Plugin License Invalid', array( 'status' => 403 ) );
        }

        // Generate a secure random key
        $new_key = wp_generate_password( 32, false, false );
        
        // Store it
        update_option( 'cam_extension_api_key', $new_key );
        
        return rest_ensure_response( array(
            'success' => true,
            'api_key' => $new_key,
            'message' => 'New API key generated. Copy it now - it will not be shown again in full.'
        ) );
    }

    /**
     * Get current API key (partially masked)
     */
    public function get_api_key( $request ) {
        // Verify License Status
        if ( ! class_exists( '\Yali_AI_Writer_License_Manager' ) ) {
            if ( defined( 'YALI_AI_WRITER_PLUGIN_DIR' ) ) {
                require_once YALI_AI_WRITER_PLUGIN_DIR . 'includes/class-license-manager.php';
            }
        }
        if ( class_exists( '\Yali_AI_Writer_License_Manager' ) && ! \Yali_AI_Writer_License_Manager::is_license_active() ) {
            return rest_ensure_response( array(
                'exists' => false,
                'api_key' => null,
                'message' => 'Plugin License Invalid. Cannot retrieve API Key.'
            ) );
        }

        $stored_key = get_option( 'cam_extension_api_key', '' );
        
        if ( empty( $stored_key ) ) {
            return rest_ensure_response( array(
                'exists' => false,
                'api_key' => null,
                'message' => 'No API key configured. Generate one first.'
            ) );
        }
        
        // Return masked version
        $masked = substr( $stored_key, 0, 6 ) . '...' . substr( $stored_key, -4 );
        
        return rest_ensure_response( array(
            'exists' => true,
            'api_key_masked' => $masked,
            'message' => 'API key exists. If you need the full key, generate a new one.'
        ) );
    }

    /**
     * Get License Information for Extension Verification
     */
    public function get_license_info( $request ) {
        if ( ! class_exists( '\Yali_AI_Writer_License_Manager' ) ) {
             if ( defined( 'YALI_AI_WRITER_PLUGIN_DIR' ) ) {
                require_once YALI_AI_WRITER_PLUGIN_DIR . 'includes/class-license-manager.php';
             }
        }

        $license_active = class_exists( '\Yali_AI_Writer_License_Manager' ) && \Yali_AI_Writer_License_Manager::is_license_active();
        $license_data = get_option( 'yali_ai_writer_manager_license_data', array() );
        
        // For security, we do NOT return the full License Key. 
        // We return the authorized domain and the server signature.
        // The extension can verify the signature locally or check the domain remotely.
        
        $response = array(
            'is_active' => $license_active,
            'authorized_domain' => isset($license_data['domain']) ? $license_data['domain'] : null,
            'license_status' => isset($license_data['status']) ? $license_data['status'] : 'unknown',
            // Proofs for offline/remote verification
            'signature' => isset($license_data['signature']) ? $license_data['signature'] : null,
            'raw_payload' => isset($license_data['raw_payload']) ? $license_data['raw_payload'] : null,
        );

        return rest_ensure_response( $response );
    }
}
