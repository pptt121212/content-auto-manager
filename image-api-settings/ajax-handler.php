<?php
// image-api-settings/ajax-handler.php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Ensure the main handler class is loaded
if (!class_exists('CAM_Image_API_Handler')) {
    require_once plugin_dir_path(__FILE__) . 'class-image-api-handler.php';
}

/**
 * AJAX handler for synchronous providers (OpenAI, Silicon Flow).
 */
function cam_test_image_api_handler() {
    if (!check_ajax_referer('content_auto_manager_nonce', 'nonce', false)) {
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

    $result = CAM_Image_API_Handler::generate_image($prompt, $provider, $config);

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
    if (!check_ajax_referer('content_auto_manager_nonce', 'nonce', false)) {
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

    $result = CAM_Image_API_Handler::start_modelscope_task($prompt, $config);

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
    if (!check_ajax_referer('content_auto_manager_nonce', 'nonce', false)) {
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

    $result = CAM_Image_API_Handler::check_modelscope_task($task_id, $config);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()], 500);
    } else {
        // $result is the full task object from the API, potentially with an added base64_image key
        wp_send_json_success(['task' => $result]);
    }
}