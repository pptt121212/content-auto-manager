<?php
namespace ContentAutoManager\RestApi\Controllers;

/**
 * Base Controller for REST API
 */
abstract class Base_Controller {
    
    protected $namespace;
    protected $rest_base;

    public function __construct( $namespace ) {
        $this->namespace = $namespace;
    }

    /**
     * Register routes for this controller
     */
    abstract public function register_routes();

    /**
     * Check if request has valid API key
     * Uses custom X-CAM-API-Key header for authentication
     */
    public function check_admin_permission( $request ) {
        // Verify License Status First
        if ( ! class_exists( '\Yali_AI_Writer_License_Manager' ) ) {
            if ( defined( 'YALI_AI_WRITER_PLUGIN_DIR' ) ) {
                require_once YALI_AI_WRITER_PLUGIN_DIR . 'includes/class-license-manager.php';
            }
        }
        
        if ( class_exists( '\Yali_AI_Writer_License_Manager' ) && ! \Yali_AI_Writer_License_Manager::is_license_active() ) {
            return new \WP_Error(
                'rest_forbidden',
                'Plugin License Invalid. Please activate the plugin license in WordPress admin to use the extension.',
                array( 'status' => 403 )
            );
        }

        // Allow logged in administrators to bypass API key check
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }

        // Get API key from header
        $api_key = $request->get_header('X-CAM-API-Key');
        
        if ( empty( $api_key ) ) {
            return new \WP_Error(
                'rest_forbidden',
                'Missing API Key. Please provide X-CAM-API-Key header.',
                array( 'status' => 401 )
            );
        }
        
        // Get stored API key from WordPress options
        $stored_key = get_option( 'cam_extension_api_key', '' );
        
        if ( empty( $stored_key ) ) {
            return new \WP_Error(
                'rest_forbidden',
                'API Key not configured. Please generate one in WordPress admin.',
                array( 'status' => 401 )
            );
        }
        
        // Verify API key
        if ( ! hash_equals( $stored_key, $api_key ) ) {
            return new \WP_Error(
                'rest_forbidden',
                'Invalid API Key.',
                array( 'status' => 401 )
            );
        }
        
        return true;
    }
}
