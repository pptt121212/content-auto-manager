<?php
// image-api-settings/ajax-handler.php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Ensure the main handler class is loaded
if (!class_exists('Yali_AI_Writer_Image_API_Handler')) {
    require_once plugin_dir_path(__FILE__) . 'class-image-api-handler.php';
}

/**
 * AJAX handler for synchronous providers (OpenAI, Silicon Flow).
 */
function cam_test_image_api_handler() {
    if (!check_ajax_referer('yali_ai_writer_manager_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.'], 403);
    }

    $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : null;
    if ($provider === 'modelscope') {
        wp_send_json_error(['message' => 'Invalid handler for ModelScope.'], 400);
    }

    $config = isset($_POST['config']) ? stripslashes_deep($_POST['config']) : null;
    $prompt = isset($_POST['prompt']) ? sanitize_textarea_field($_POST['prompt']) : null;

    if (!$provider || !$config || !$prompt) {
        wp_send_json_error(['message' => 'Missing required parameters.'], 400);
    }

    $result = Yali_AI_Writer_Image_API_Handler::generate_image($prompt, $provider, $config);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 500);
    } else {
        wp_send_json_success(['base64_image' => $result]);
    }
}

/**
 * AJAX handler to start a ModelScope image generation task.
 */
function cam_modelscope_start_task_handler() {
    if (!check_ajax_referer('yali_ai_writer_manager_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.'], 403);
    }

    $config = isset($_POST['config']) ? stripslashes_deep($_POST['config']) : null;
    $prompt = isset($_POST['prompt']) ? sanitize_textarea_field($_POST['prompt']) : null;

    if (!$config || !$prompt) {
        wp_send_json_error(['message' => 'Missing required parameters.'], 400);
    }

    $result = Yali_AI_Writer_Image_API_Handler::start_modelscope_task($prompt, $config);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 500);
    } else {
        // $result is the task_id
        wp_send_json_success(['task_id' => $result]);
    }
}

/**
 * AJAX handler to check the status of a ModelScope task.
 */
function cam_modelscope_check_task_handler() {
    if (!check_ajax_referer('yali_ai_writer_manager_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Nonce verification failed.'], 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied.'], 403);
    }

    $config = isset($_POST['config']) ? stripslashes_deep($_POST['config']) : null;
    $task_id = isset($_POST['task_id']) ? sanitize_text_field($_POST['task_id']) : null;

    if (!$config || !$task_id) {
        wp_send_json_error(['message' => 'Missing required parameters.'], 400);
    }

    $result = Yali_AI_Writer_Image_API_Handler::check_modelscope_task($task_id, $config);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 500);
    } else {
        // $result is the full task object from the API, potentially with an added base64_image key
        wp_send_json_success(['task' => $result]);
    }
}

/**
 * AJAX handler to fetch Pollinations image models.
 * 使用 /image/models 端点，专用于图像生成模型
 */
function cam_fetch_pollinations_image_models_handler() {
    if (!check_ajax_referer('yali_ai_writer_manager_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => __('安全验证失败。', 'yali-ai-writer')], 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('权限不足。', 'yali-ai-writer')], 403);
    }

    // 获取 Pollinations 预置 API 配置的 API Key（认证后根据余额过滤模型）
    $api_key = '';
    if (class_exists('Yali_AI_Writer_PredefinedApi')) {
        $predefined_api = new Yali_AI_Writer_PredefinedApi();
        $pollinations_config = $predefined_api->get_config('pollinations');
        if (!empty($pollinations_config['api_key'])) {
            $api_key = $pollinations_config['api_key'];
        }
    }

    $url = 'https://gen.pollinations.ai/image/models';

    // 构建请求参数
    $request_args = array(
        'timeout'   => 15,
        'sslverify' => false,
        'headers'   => array(
            'Accept' => 'application/json'
        )
    );

    // 如果有 API Key，添加到请求头（认证后根据账户余额过滤模型）
    if (!empty($api_key)) {
        $request_args['headers']['Authorization'] = 'Bearer ' . trim($api_key);
    }

    $response = wp_remote_get($url, $request_args);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => __('无法连接到 Pollinations 接口: ', 'yali-ai-writer') . $response->get_error_message()]);
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (!$data || !is_array($data)) {
        wp_send_json_error(['message' => __('获取到的模型数据格式不正确。', 'yali-ai-writer')]);
    }

    // 过滤模型：只保留图像生成模型（输出包含 image，不包含 video）
    $filtered_models = array();
    foreach ($data as $model) {
        $model_name = isset($model['name']) ? $model['name'] : '';
        if (empty($model_name)) {
            continue;
        }

        $output_modalities = isset($model['output_modalities']) ? $model['output_modalities'] : array();
        
        // 必须输出图像
        if (!in_array('image', $output_modalities)) {
            continue;
        }
        
        // 排除视频生成模型
        if (in_array('video', $output_modalities)) {
            continue;
        }

        // 排除专业/特殊模型
        if (!empty($model['is_specialized'])) {
            continue;
        }
        
        $is_paid = !empty($model['paid_only']);
        
        $filtered_models[] = array(
            'id' => $model_name,
            'name' => $model_name,
            'description' => isset($model['description']) ? $model['description'] : '',
            'is_free' => !$is_paid,
            'is_paid' => $is_paid,
            'input_modalities' => isset($model['input_modalities']) ? $model['input_modalities'] : array(),
            'pricing' => isset($model['pricing']) ? $model['pricing'] : null
        );
    }

    // 排序：免费模型在前，然后按名称排序
    usort($filtered_models, function($a, $b) {
        if ($a['is_free'] === $b['is_free']) {
            return strcmp($a['name'], $b['name']);
        }
        return $a['is_free'] ? -1 : 1;
    });

    // 统计免费和付费模型数量
    $free_count = count(array_filter($filtered_models, function($m) { return $m['is_free']; }));
    $paid_count = count(array_filter($filtered_models, function($m) { return !$m['is_free']; }));

    wp_send_json_success(array(
        'models' => $filtered_models,
        'total' => count($filtered_models),
        'free_count' => $free_count,
        'paid_count' => $paid_count,
        'note' => sprintf(__('共 %d 个图像生成模型（%d 免费 / %d 付费）', 'yali-ai-writer'), count($filtered_models), $free_count, $paid_count),
        'debug' => array(
            'api_key_configured' => !empty($api_key),
            'api_key_masked' => !empty($api_key) ? substr($api_key, 0, 15) . '...' : 'none',
            'api_key_sent_in_header' => !empty($api_key),
            'raw_model_count_from_api' => count($data)
        )
    ));
}