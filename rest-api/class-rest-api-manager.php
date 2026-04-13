<?php
namespace ContentAutoManager\RestApi;

use ContentAutoManager\RestApi\Controllers\Config_Controller;
use ContentAutoManager\RestApi\Controllers\ApiKey_Controller;
use ContentAutoManager\RestApi\Controllers\Proxy_Controller;
use ContentAutoManager\RestApi\Controllers\Task_Controller;
use ContentAutoManager\RestApi\Controllers\Editor_Assistant_Controller;
use ContentAutoManager\RestApi\Controllers\Extension_Controller;

/**
 * REST API Manager
 */
class Rest_Api_Manager {

    /**
     * API Namespace
     */
    const NAMESPACE = 'content-auto-manager/v1';

    /**
     * Initialize REST API
     */
    public function init() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Include controller files manually since custom autoloader might not cover namespaces perfectly
        $this->load_controllers();

        $controllers = array(
            new Config_Controller( self::NAMESPACE ),
            new ApiKey_Controller( self::NAMESPACE ),
            new Proxy_Controller( self::NAMESPACE ),
            new Task_Controller( self::NAMESPACE ),
            new Editor_Assistant_Controller( self::NAMESPACE ),
            new Extension_Controller( self::NAMESPACE ),
        );

        foreach ( $controllers as $controller ) {
            $controller->register_routes();
        }
    }

    /**
     * Load controller files
     */
    private function load_controllers() {
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/controllers/class-base-controller.php';
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/controllers/class-config-controller.php';
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/controllers/class-apikey-controller.php';
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/controllers/class-proxy-controller.php';
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/controllers/class-task-controller.php';
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/controllers/class-editor-assistant-controller.php';
        require_once YALI_AI_WRITER_PLUGIN_DIR . 'rest-api/controllers/class-extension-controller.php';
    }
}

