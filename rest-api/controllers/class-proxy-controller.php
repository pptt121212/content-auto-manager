<?php
namespace ContentAutoManager\RestApi\Controllers;

use ContentAuto_UnifiedApiHandler;
use ContentAuto_VectorApiHandler;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Controller for Proxy API
 * Acts as a gateway for the browser extension to use server-side API configurations
 */
class Proxy_Controller extends Base_Controller {

    private $unified_handler;
    private $vector_handler;

    public function __construct( $namespace ) {
        parent::__construct( $namespace );
        
        // init handlers
        if (!class_exists('ContentAuto_UnifiedApiHandler')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';
        }
        if (!class_exists('ContentAuto_VectorApiHandler')) {
            require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-vector-api-handler.php';
        }

        $this->unified_handler = new ContentAuto_UnifiedApiHandler();
        $this->vector_handler = new ContentAuto_VectorApiHandler();
    }

    public function register_routes() {
        // Chat Proxy (Standard OpenAI-like endpoint)
        // URL: /wp-json/content-auto-manager/v1/chat/completions
        register_rest_route( $this->namespace, '/chat/completions', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'proxy_chat' ),
            'permission_callback' => array( $this, 'check_admin_permission' ), // Requires valid WP API Key
        ) );

        // Vector Proxy (Standard OpenAI-like endpoint)
        // URL: /wp-json/content-auto-manager/v1/embeddings
        register_rest_route( $this->namespace, '/embeddings', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'proxy_embeddings' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );
    }

    /**
     * Proxy Chat Request
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function proxy_chat( $request ) {
        // Increase execution time and memory limit for AI processing
        if (function_exists('set_time_limit')) {
            set_time_limit(300); // 5 minutes
        }
        @ini_set('memory_limit', '512M');

        $params = $request->get_json_params();
        $messages = isset($params['messages']) ? $params['messages'] : array();
        
        if (empty($messages)) {
            return new WP_REST_Response(array(
                'error' => array(
                    'message' => '缺少 messages 参数',
                    'type' => 'invalid_request_error',
                    'code' => 'missing_messages'
                )
            ), 400);
        }

        // UnifiedApiHandler generate_content expects a prompt string.
        // Since we want to leverage the site's existing polling logic (which often handles single-turn tasks),
        // we need to serialize the chat history into a string prompt that the LLM can understand as context.
        $prompt = "";
        
        // Context instruction to help the LLM understand this is a chat history
        // Only add this if there are multiple messages
        if (count($messages) > 1) {
            $prompt .= "Below is a conversation history. Please reply to the last user message.\n\n";
        }

        foreach ($messages as $msg) {
            $role = isset($msg['role']) ? $msg['role'] : 'user';
            $content = isset($msg['content']) ? $msg['content'] : '';
            
            // Format: 
            // User: Hello
            // Assistant: Hi there
            $role_label = ucfirst($role);
            $prompt .= "{$role_label}: {$content}\n";
        }

        // The Unified handler will wrap this entire prompt in a 'user' message to the LLM.
        // Most LLMs (GPT-4, DeepSeek, etc.) handle this "prompt within a prompt" pattern very well for context.
        
        $final_prompt = $prompt; 

        // Call the Unified Handler
        // task_type = 'extension_chat' can be used for logging/tracking
        $result = $this->unified_handler->generate_content($final_prompt, 'extension_chat');

        if (isset($result['error'])) {
            return new WP_REST_Response(array(
                'error' => array(
                    'message' => $result['error'],
                    'type' => 'api_error',
                    'code' => 'proxy_execution_failed'
                )
            ), 500);
        }

        // Construct standardized OpenAI-compatible response
        $response_data = array(
            'id' => 'chatcmpl-' . wp_generate_uuid4(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'wordpress-proxy-model', // Virtual model name
            'choices' => array(
                array(
                    'index' => 0,
                    'message' => array(
                        'role' => 'assistant',
                        'content' => $result // The handler returns the raw string content
                    ),
                    'finish_reason' => 'stop'
                )
            ),
            'usage' => array(
                'prompt_tokens' => -1, // Unknown without calculation
                'completion_tokens' => -1,
                'total_tokens' => -1
            )
        );

        return new WP_REST_Response($response_data, 200);
    }

    /**
     * Proxy Embeddings Request
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function proxy_embeddings( $request ) {
        // Increase execution time and memory limit for Vector processing
        if (function_exists('set_time_limit')) {
            set_time_limit(300); // 5 minutes
        }
        @ini_set('memory_limit', '512M');

        $params = $request->get_json_params();
        $input = isset($params['input']) ? $params['input'] : null;
        
        if (empty($input)) {
            return new WP_REST_Response(array(
                'error' => array(
                    'message' => '缺少 input 参数',
                    'type' => 'invalid_request_error',
                    'code' => 'missing_input'
                )
            ), 400);
        }

        // Handle string vs array input
        $texts = is_array($input) ? $input : array($input);

        // Call Vector Handler
        // config_id = null means use the active/default vector config
        $result = $this->vector_handler->generate_embeddings_batch($texts, null);

        if ($result === false) {
             return new WP_REST_Response(array(
                'error' => array(
                    'message' => $this->vector_handler->get_last_error() ?: '向量生成失败',
                    'type' => 'api_error',
                    'code' => 'vector_generation_failed'
                )
            ), 500);
        }

        // VectorHandler already returns a structured array, but we should ensure it matches OpenAI format exactly
        // result structure: ['embeddings' => [...], 'model' => ..., 'tokens_used' => ...]
        
        // Format formatting to strictly match OpenAI standard: [{ "object": "embedding", "embedding": [...], "index": 0 }, ...]
        $formatted_data = array();
        if (is_array($result['embeddings'])) {
            foreach ($result['embeddings'] as $index => $vector) {
                $embedding_val = is_array($vector) && isset($vector['embedding']) ? $vector['embedding'] : $vector;
                
                // VectorApiHandler converts floats to Base64 for DB storage efficiency.
                // But for the Proxy API, the frontend expects standard JSON float arrays (OpenAI format).
                // So we must decode it back if it is a string.
                if (is_string($embedding_val)) {
                    $binary = base64_decode($embedding_val);
                    if ($binary !== false) {
                        $unpacked = unpack('f*', $binary);
                        if ($unpacked !== false) {
                            $embedding_val = array_values($unpacked);
                        }
                    }
                }

                $formatted_data[] = array(
                    'object' => 'embedding',
                    'embedding' => $embedding_val,
                    'index' => $index
                );
            }
        }

        $openai_response = array(
            'object' => 'list',
            'data' => $formatted_data,
            'model' => $result['model'],
            'usage' => array(
                'prompt_tokens' => $result['tokens_used'],
                'total_tokens' => $result['tokens_used']
            )
        );

        return new WP_REST_Response($openai_response, 200);
    }
}
