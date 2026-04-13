<?php
// api-settings/ajax-handler.php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * 实时获取 Pollinations 模型列表（所有文本模型，包括付费和免费）
 * 使用 /text/models 端点（专用于文本模型，包含详细 pricing 信息）
 */
function yali_ai_writer_fetch_pollinations_models_handler() {
    // 验证 nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'yali_ai_writer_manager_nonce')) {
        wp_send_json_error(array('message' => __('安全验证失败。', 'yali-ai-writer')));
    }

    // 检查权限
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('权限不足。', 'yali-ai-writer')));
    }

    // 获取 Pollinations 预置 API 配置的 API Key（认证后可获取完整模型列表）
    $api_key = '';
    if (class_exists('Yali_AI_Writer_PredefinedApi')) {
        $predefined_api = new Yali_AI_Writer_PredefinedApi();
        $pollinations_config = $predefined_api->get_config('pollinations');
        if (!empty($pollinations_config['api_key'])) {
            $api_key = $pollinations_config['api_key'];
        }
    }

    // 使用专用于文本模型的端点，包含详细的 pricing、paid_only 等字段
    $url = 'https://gen.pollinations.ai/text/models';

    // 构建请求参数
    $request_args = array(
        'timeout'   => 15,
        'sslverify' => false,
        'headers'   => array(
            'Accept' => 'application/json'
        )
    );

    // 如果有 API Key，添加到请求头（认证后可获取完整模型列表）
    if (!empty($api_key)) {
        $request_args['headers']['Authorization'] = 'Bearer ' . trim($api_key);
    }

    // 使用 wp_remote_get 进行服务器端请求
    $response = wp_remote_get($url, $request_args);

    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => __('无法连接到 Pollinations 接口: ', 'yali-ai-writer') . $response->get_error_message()));
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // /text/models 直接返回模型数组
    if (!$data || !is_array($data)) {
        wp_send_json_error(array('message' => __('获取到的模型数据格式不正确。', 'yali-ai-writer')));
    }

    // 处理模型：保留所有文本模型（包括付费和免费）
    // 使用认证后，API 会根据账户余额过滤，付费模型在无余额时会隐藏
    $filtered_models = array();
    foreach ($data as $model) {
        $model_name = isset($model['name']) ? $model['name'] : '';
        if (empty($model_name)) {
            continue;
        }

        // /text/models 已经只返回文本模型，但再确认一下 output_modalities
        $output_modalities = isset($model['output_modalities']) ? $model['output_modalities'] : array();
        if (!in_array('text', $output_modalities)) {
            continue;
        }
        
        // 排除输出包含 audio/video/image 的模型（纯文本生成模型）
        if (in_array('audio', $output_modalities) || 
            in_array('video', $output_modalities) || 
            in_array('image', $output_modalities)) {
            continue;
        }

        // 排除专业/特殊模型（如音乐作曲、内容审核等）
        if (!empty($model['is_specialized'])) {
            continue; // 跳过专业模型：midijourney, qwen-safety 等
        }

        // 根据 paid_only 字段标记免费/付费
        $is_paid = !empty($model['paid_only']);
        
        $filtered_models[] = array(
            'id' => $model_name,
            'name' => $model_name,
            'description' => isset($model['description']) ? $model['description'] : '',
            'is_free' => !$is_paid,
            'is_paid' => $is_paid,
            'context_length' => isset($model['context_length']) ? $model['context_length'] : 0,
            'has_reasoning' => !empty($model['reasoning']),
            'has_tools' => !empty($model['tools']),
            'input_modalities' => isset($model['input_modalities']) ? $model['input_modalities'] : array(),
            'pricing' => isset($model['pricing']) ? $model['pricing'] : null
        );
    }

    // 排序：免费模型在前，然后按能力排序
    usort($filtered_models, function($a, $b) {
        // 免费模型优先
        if ($a['is_free'] !== $b['is_free']) {
            return $a['is_free'] ? -1 : 1;
        }
        // 然后按能力排序
        $score_a = ($a['has_tools'] ? 200 : 0) + ($a['has_reasoning'] ? 100 : 0) + min($a['context_length'] / 10000, 50);
        $score_b = ($b['has_tools'] ? 200 : 0) + ($b['has_reasoning'] ? 100 : 0) + min($b['context_length'] / 10000, 50);
        return $score_b <=> $score_a;
    });

    // 统计免费和付费模型数量
    $free_count = count(array_filter($filtered_models, function($m) { return $m['is_free']; }));
    $paid_count = count(array_filter($filtered_models, function($m) { return !$m['is_free']; }));

    // 返回模型数据
    wp_send_json_success(array(
        'models' => $filtered_models,
        'total' => count($filtered_models),
        'free_count' => $free_count,
        'paid_count' => $paid_count,
        'note' => sprintf(__('共 %d 个文本生成模型（%d 免费 / %d 付费）', 'yali-ai-writer'), count($filtered_models), $free_count, $paid_count),
        'debug' => array(
            'api_key_configured' => !empty($api_key),
            'api_key_masked' => !empty($api_key) ? substr($api_key, 0, 15) . '...' : 'none',
            'api_key_sent_in_header' => !empty($api_key),
            'raw_model_count_from_api' => count($data)
        )
    ));
}

// 注册 AJAX Action
add_action('wp_ajax_yali_ai_writer_fetch_pollinations_models', 'yali_ai_writer_fetch_pollinations_models_handler');
