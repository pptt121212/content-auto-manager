<?php
namespace ContentAutoManager\RestApi\Controllers;

use ContentAuto_ApiConfig;

/**
 * Controller for Config API
 */
class Config_Controller extends Base_Controller {

    public function register_routes() {
        register_rest_route( $this->namespace, '/config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_config' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );
    }

    /**
     * Get API configuration
     * Returns all active LLM configs and the global Vector API config
     * 
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get_config( $request ) {
        if ( ! class_exists( 'ContentAuto_ApiConfig' ) ) {
            if ( defined( 'CONTENT_AUTO_MANAGER_PLUGIN_DIR' ) ) {
                 $api_config_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'api-settings/class-api-config.php';
                 if ( file_exists( $api_config_path ) ) {
                     require_once $api_config_path;
                 }
            }
        }

        $api_config = new ContentAuto_ApiConfig();

        // 获取所有激活的 LLM 配置（排除向量API）
        $all_configs = $api_config->get_configs();
        
        $llm_configs = array();
        $vector_config = null;
        
        foreach ( $all_configs as $config ) {
            // 判断是否是向量API配置
            $is_vector = !empty($config['vector_api_url']) || 
                         !empty($config['vector_api_key']) || 
                         !empty($config['vector_model_name']);
            
            if ( $is_vector ) {
                // 向量API（全局唯一，不需要激活状态）
                $vector_config = array(
                    'id' => $config['id'],
                    'name' => $config['name'],
                    'provider' => isset($config['vector_api_type']) ? $config['vector_api_type'] : 'openai',
                    'apiKey' => $config['vector_api_key'],
                    'baseUrl' => $config['vector_api_url'],
                    'model' => $config['vector_model_name']
                );
            } else {
                // LLM API（需要激活状态）
                if ( $config['is_active'] ) {
                    $llm_configs[] = array(
                        'id' => $config['id'],
                        'name' => $config['name'],
                        'provider' => !empty($config['predefined_channel']) ? $config['predefined_channel'] : 'custom',
                        'apiKey' => $config['api_key'],
                        'baseUrl' => $config['api_url'],
                        'model' => $config['model_name'],
                        'maxTokens' => $config['max_tokens'],
                        'temperature' => $config['temperature']
                    );
                }
            }
        }

        // 构建响应
        $response = array(
            'llm' => null,
            'llm_list' => $llm_configs,  // 所有激活的 LLM
            'vector' => $vector_config
        );

        // 兼容性：llm 字段返回第一个激活的 LLM
        if ( !empty($llm_configs) ) {
            $response['llm'] = $llm_configs[0];
        }

        return rest_ensure_response( $response );
    }
}

