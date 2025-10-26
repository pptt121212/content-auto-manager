<?php
// image-api-settings/class-image-api-handler.php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Ensure the admin page class is available for settings retrieval
if (!class_exists('CAM_Image_API_Admin_Page')) {
    require_once plugin_dir_path(__FILE__) . 'class-image-api-admin-page.php';
}

class CAM_Image_API_Handler {

    const MODELSCOPE_BASE_URL = 'https://api-inference.modelscope.cn/';

    /**
     * Generate an image using the saved settings. (For production use)
     *
     * @param string $prompt The prompt to generate the image from.
     * @return string|WP_Error The Base64 encoded image data on success, or a WP_Error on failure.
     */
    public static function generate_image_from_saved_settings($prompt) {
        $settings = CAM_Image_API_Admin_Page::get_settings();
        $provider = $settings['provider'];

        if (empty($provider)) {
            return new WP_Error('no_provider_selected', 'No image API provider is selected in settings.');
        }

        if (!isset($settings[$provider])) {
            return new WP_Error('provider_config_missing', 'Configuration for the active provider is missing.');
        }

        $config = $settings[$provider];
        return self::generate_image($prompt, $provider, $config);
    }

    /**
     * Generate an image using a specific provider and configuration. (For testing or direct calls)
     *
     * @param string $prompt The prompt to generate the image from.
     * @param string $provider The provider name (e.g., 'modelscope', 'openai').
     * @param array $config The configuration for the specified provider.
     * @return string|WP_Error The Base64 encoded image data on success, or a WP_Error on failure.
     */
    public static function generate_image($prompt, $provider, $config) {
        switch ($provider) {
            case 'modelscope':
                return self::generate_with_modelscope($prompt, $config);
            case 'openai':
                return self::generate_with_openai($prompt, $config);
            case 'siliconflow':
                return self::generate_with_siliconflow($prompt, $config);
            case 'pollinations':
                return self::generate_with_pollinations($prompt, $config);
            default:
                return new WP_Error('unknown_provider', 'The selected image API provider is not supported.');
        }
    }

    /**
     * Starts a ModelScope task and returns the task ID.
     */
    public static function start_modelscope_task($prompt, $config) {
        $api_key = $config['api_key'];
        $model_id = $config['model_id'];

        if (empty($api_key) || empty($model_id)) {
            return new WP_Error('api_credentials_missing', 'ModelScope API Key or Model ID is not configured.');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ];

        $body = [
            'model'  => $model_id,
            'prompt' => $prompt,
            'size'   => '1024x576', // Unified size (16:9 aspect ratio)
        ];

        $response = wp_remote_post(self::MODELSCOPE_BASE_URL . 'v1/images/generations', [
            'headers' => array_merge($headers, ['X-ModelScope-Async-Mode' => 'true']),
            'body'    => json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code >= 300 || empty($data['task_id'])) {
            $error_message = isset($data['message']) ? $data['message'] : 'Failed to start ModelScope task.';
            return new WP_Error('start_task_failed', $error_message, ['status' => $response_code, 'response' => $data]);
        }

        return $data['task_id'];
    }

    /**
     * Checks a ModelScope task, and if complete, downloads and encodes the image.
     */
    public static function check_modelscope_task($task_id, $config) {
        $api_key = $config['api_key'];
        if (empty($api_key)) {
            return new WP_Error('api_key_missing', 'ModelScope API Key is not configured.');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'X-ModelScope-Task-Type' => 'image_generation',
        ];

        $response = wp_remote_get(self::MODELSCOPE_BASE_URL . 'v1/tasks/' . $task_id, [
            'headers' => $headers,
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $task_data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($task_data['task_status']) && $task_data['task_status'] === 'SUCCEED') {
            if (!empty($task_data['output_images'][0])) {
                $image_url = $task_data['output_images'][0];
                $image_response = wp_remote_get($image_url, ['timeout' => 30]);

                if (is_wp_error($image_response)) {
                    $task_data['task_status'] = 'FAILED';
                    $task_data['message'] = 'Image download failed: ' . $image_response->get_error_message();
                } else {
                    $image_bytes = wp_remote_retrieve_body($image_response);
                    if (empty($image_bytes)) {
                        $task_data['task_status'] = 'FAILED';
                        $task_data['message'] = 'Image download succeeded but body was empty.';
                    } else {
                        $task_data['base64_image'] = base64_encode($image_bytes);
                    }
                }
            }
        }
        return $task_data;
    }

    /**
     * Handles image generation via ModelScope API (synchronous wrapper).
     * Wraps the async ModelScope API to provide synchronous behavior.
     *
     * @param string $prompt The generation prompt.
     * @param array $config The specific configuration for ModelScope.
     * @return string|WP_Error Base64 image data on success, WP_Error on failure.
     */
    private static function generate_with_modelscope($prompt, $config) {
        // Step 1: Start the async task
        $task_id_result = self::start_modelscope_task($prompt, $config);

        if (is_wp_error($task_id_result)) {
            return $task_id_result;
        }

        $task_id = $task_id_result;

        // Step 2: Poll the task status until completion or timeout
        $max_attempts = 30; // Maximum 30 attempts
        $attempt_delay = 2; // 2 seconds between attempts
        $timeout = 60; // Maximum 60 seconds total timeout

        $start_time = time();

        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            // Check if we've exceeded the overall timeout
            if (time() - $start_time > $timeout) {
                return new WP_Error('modelscope_timeout', 'ModelScope image generation timed out after ' . $timeout . ' seconds.');
            }

            // Check task status
            $task_result = self::check_modelscope_task($task_id, $config);

            if (is_wp_error($task_result)) {
                return $task_result;
            }

            // Check if task succeeded
            if (isset($task_result['task_status']) && $task_result['task_status'] === 'SUCCEED') {
                if (!empty($task_result['base64_image'])) {
                    return $task_result['base64_image'];
                } else {
                    return new WP_Error('modelscope_no_image', 'ModelScope task succeeded but no image was returned.');
                }
            }

            // Check if task failed
            if (isset($task_result['task_status']) && $task_result['task_status'] === 'FAILED') {
                $error_message = isset($task_result['message']) ? $task_result['message'] : 'ModelScope image generation failed.';
                return new WP_Error('modelscope_task_failed', $error_message);
            }

            // Task is still running, wait before next attempt
            sleep($attempt_delay);
        }

        // If we get here, the task didn't complete within the maximum attempts
        return new WP_Error('modelscope_max_attempts', 'ModelScope image generation did not complete within ' . ($max_attempts * $attempt_delay) . ' seconds.');
    }

    /**
     * Handles image generation via Silicon Flow API.
     *
     * @param string $prompt The generation prompt.
     * @param array $config The specific configuration for Silicon Flow.
     * @return string|WP_Error Base64 image data on success, WP_Error on failure.
     */
    private static function generate_with_siliconflow($prompt, $config) {
        $api_key = $config['api_key'];
        $model = $config['model'];

        if (empty($api_key)) {
            return new WP_Error('api_key_missing', 'Silicon Flow API Key is not configured.');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ];

        $body = [
            'model'   => $model,
            'prompt'  => $prompt,
            'n'       => 1,
        ];

        // Set image size based on user's preference for Qwen/Qwen-Image
        $body['image_size'] = '1024x576'; // Unified size (16:9 aspect ratio)
        // Qwen-Image-Edit models do not support the image_size field

        $response = wp_remote_post('https://api.siliconflow.cn/v1/images/generations', [
            'headers' => $headers,
            'body'    => json_encode($body),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        // Assuming the response contains a URL in images[0].url, which then needs to be downloaded
        if ($response_code >= 300 || empty($data['images'][0]['url'])) {
            $error_message = isset($data['message']) ? $data['message'] : 'Failed to generate image with Silicon Flow.';
            return new WP_Error('siliconflow_error', $error_message, ['status' => $response_code, 'response' => $data]);
        }

        $image_url = $data['images'][0]['url'];

        // Download the image and base64 encode it
        $image_response = wp_remote_get($image_url, ['timeout' => 30]);
        if (is_wp_error($image_response) || wp_remote_retrieve_response_code($image_response) >= 300) {
            return new WP_Error('image_download_failed', 'Failed to download the generated image from the URL provided by Silicon Flow.');
        }
        
        $image_bytes = wp_remote_retrieve_body($image_response);
        return base64_encode($image_bytes);
    }

    /**
     * Handles image generation via OpenAI API.
     *
     * @param string $prompt The generation prompt.
     * @param array $config The specific configuration for OpenAI.
     * @return string|WP_Error Image URL on success, WP_Error on failure.
     */
    private static function generate_with_openai($prompt, $config) {
        $api_key = $config['api_key'];
        $model = $config['model'];

        if (empty($api_key)) {
            return new WP_Error('api_key_missing', 'OpenAI API Key is not configured.');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ];

        $body = [
            'model'   => $model,
            'prompt'  => $prompt,
            'n'       => 1,
            'size'    => '1024x576', // Unified size (16:9 aspect ratio)
            'quality' => 'standard', // 'standard' is the lower quality for DALL-E 3. Corresponds to 'low' for gpt-image-1.
            'response_format' => 'b64_json', // Request Base64 data
        ];

        $response = wp_remote_post('https://api.openai.com/v1/images/generations', [
            'headers' => $headers,
            'body'    => json_encode($body),
            'timeout' => 120, // Increased timeout for potentially slow models
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($response_code >= 300 || empty($data['data'][0]['b64_json'])) {
            $error_message = isset($data['error']['message']) ? $data['error']['message'] : 'Failed to generate image with OpenAI.';
            return new WP_Error('openai_error', $error_message, ['status' => $response_code, 'response' => $data]);
        }

        return $data['data'][0]['b64_json'];
    }

    /**
     * Handles image generation via Pollinations.AI API.
     *
     * @param string $prompt The generation prompt.
     * @param array $config The specific configuration for Pollinations.AI.
     * @return string|WP_Error Base64 image data on success, WP_Error on failure.
     */
    private static function generate_with_pollinations($prompt, $config) {
        // Get site domain as referrer
        $site_url = get_site_url();
        $parsed_url = parse_url($site_url);
        $referrer = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        
        // Prepare query parameters
        $query_args = array();
        
        // Add model if available
        if (!empty($config['model'])) {
            $query_args['model'] = $config['model'];
        } else {
            $query_args['model'] = 'flux'; // Default model
        }
        
        // Use fixed dimensions to match other image APIs (1024x576)
        $query_args['width'] = 1024;
        $query_args['height'] = 576;
        
        // Add nologo setting if a token is provided (for registered users)
        if (!empty($config['token'])) {
            $query_args['nologo'] = 'true';
        }
        
        // Always add referrer (site domain)
        if (!empty($referrer)) {
            $query_args['referrer'] = $referrer;
        }
        
        // Prevent image from appearing in public feed
        $query_args['private'] = 'true';
        
        // Add token if available (to be used in header)
        $token = !empty($config['token']) ? $config['token'] : null;
        
        // For testing purposes only - uncomment the line below to use the test API key
        // $token = 'Y5jVdg3LEebuO451';
        
        // Add token to query parameters if available (as fallback)
        if (!empty($token)) {
            $query_args['token'] = $token;
        }
        
        // Build URL with prompt and query parameters
        $prompt_encoded = urlencode($prompt);
        $query_string = http_build_query($query_args);
        $url = "https://image.pollinations.ai/prompt/{$prompt_encoded}";
        if (!empty($query_string)) {
            $url .= "?{$query_string}";
        }

        // Prepare headers
        $headers = array(
            'Content-Type' => 'application/json'
        );
        
        // Add authorization header if token is provided
        if (!empty($token)) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => 120, // Increased timeout for image generation
            'stream' => false
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $image_bytes = wp_remote_retrieve_body($response);

        // Check if the response is an image
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if (strpos($content_type, 'image/') !== 0) {
            // The API might return an error message as text instead of an image
            $error_message = $image_bytes;
            if (strlen($error_message) < 500) { // Only include short error messages
                return new WP_Error('pollinations_error', 'Pollinations.AI API returned an error: ' . $error_message, ['status' => $response_code, 'response' => $error_message]);
            } else {
                return new WP_Error('pollinations_error', 'Pollinations.AI API returned an error with status code: ' . $response_code, ['status' => $response_code]);
            }
        }

        if ($response_code >= 300 || empty($image_bytes)) {
            return new WP_Error('pollinations_error', 'Failed to generate image with Pollinations.AI.', ['status' => $response_code]);
        }

        return base64_encode($image_bytes);
    }

}