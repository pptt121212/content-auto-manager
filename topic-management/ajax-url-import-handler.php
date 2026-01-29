<?php
/**
 * AJAX Handler for URL Import with AI Title Optimization
 */

if (!defined('ABSPATH')) {
    exit;
}

// Register AJAX action
add_action('wp_ajax_cam_url_import', 'cam_ajax_url_import');

function cam_ajax_url_import() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Permission denied'));
    }
    
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cam_import_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }

    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    $provided_content = isset($_POST['extracted_content']) ? stripslashes($_POST['extracted_content']) : '';
    $provided_title = isset($_POST['extracted_title']) ? sanitize_text_field(stripslashes($_POST['extracted_title'])) : '';

    $result = cam_process_url_import($url, $provided_content, $provided_title);

    if ($result['success']) {
        wp_send_json_success($result['data']);
    } else {
        wp_send_json_error($result['data']);
    }
}

/**
 * Process URL Import Logic (Reusable)
 */
function cam_process_url_import($url, $provided_content = '', $provided_title = '') {
    $raw_html = '';
    $title = '';
    $source_type = 'server_fetch';

    // 1. Determine Source
    if (!empty($provided_content)) {
        // Client provided content
        $raw_html = $provided_content;
        $title = $provided_title;
        $source_type = 'client_push';
        
        // If no title provided, try to extract from content
        if (empty($title) && preg_match('/<title[^>]*>(.*?)<\/title>/is', $raw_html, $matches)) {
            $title = trim($matches[1]);
        }
    } elseif (!empty($url)) {
        // Server fetch fallback
        $response = wp_safe_remote_get($url, array('timeout' => 30, 'user-agent' => 'Mozilla/5.0 (WordPress ContentAutoManager; +http://example.com)'));
        
        if (is_wp_error($response)) {
             return ['success' => false, 'data' => ['message' => 'Failed to fetch URL: ' . $response->get_error_message()]];
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
             return ['success' => false, 'data' => ['message' => 'Failed to fetch URL, Status Code: ' . $response_code]];
        }
        
        $raw_html = wp_remote_retrieve_body($response);
        if (empty($raw_html)) {
             return ['success' => false, 'data' => ['message' => 'Empty response from URL']];
        }
        
        // Extract Title from fetched content
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $raw_html, $matches)) {
            $title = trim($matches[1]);
            $title = preg_replace('/ [-|] .*$/', '', $title);
        }
    } else {
         return ['success' => false, 'data' => ['message' => 'No URL or Content provided']];
    }
    
    // 2. Clean Content
    $cleaned_content = cam_clean_extracted_content($raw_html);
    
    // 3. AI Title Optimization
    $optimized_title = $title;
    
    // Check if Unified API Handler exists
    if (!class_exists('ContentAuto_UnifiedApiHandler')) {
        $api_handler_path = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/services/class-unified-api-handler.php';
        if (file_exists($api_handler_path)) {
            require_once $api_handler_path;
        }
    }
    
    if (class_exists('ContentAuto_UnifiedApiHandler') && !empty($title)) {
        $handler = new ContentAuto_UnifiedApiHandler();
        
        $prompt = "Task: Optimize this article title for better CTR and SEO.\n";
        $prompt .= "Requirements:\n";
        $prompt .= "- Remove any site names or branding.\n";
        $prompt .= "- Make it engaging and professional.\n";
        $prompt .= "- Keep it under 60 characters if possible.\n";
        $prompt .= "- Return ONLY the new title, no quotes or explanations.\n\n";
        $prompt .= "Original Title: " . $title;
        
        $ai_response = $handler->generate_content($prompt, 'title_optimization', array('timeout' => 15));
        
        if ($ai_response && !is_array($ai_response)) {
             // Basic cleanup of AI response
             $optimized_title_raw = trim(strip_tags($ai_response));
             $optimized_title_raw = trim($optimized_title_raw, '"\'');
             if (!empty($optimized_title_raw)) {
                 $optimized_title = $optimized_title_raw;
             }
        }
    }
    
    return [
        'success' => true,
        'data' => [
            'original_title' => $title,
            'title' => $optimized_title,
            'content' => $cleaned_content,
            'url' => $url,
            'message' => 'Successfully imported and optimized.'
        ]
    ];
}

/**
 * Robust HTML Cleaner
 */
function cam_clean_extracted_content($html) {
    // 1. Remove scripts, styles, iframes, comments
    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $html);
    $html = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', "", $html);
    $html = preg_replace('/<!--(.*?)-->/is', "", $html);
    
    // 2. Initial strip tags with whitelist
    $allowed_tags = '<p><h1><h2><h3><h4><h5><h6><div><span><ul><ol><li><br><img><a><blockquote><table><tr><td><th><thead><tbody><b><i><strong><em>';
    $clean_html = strip_tags($html, $allowed_tags);
    
    // 3. Advanced Attribute Cleaning via DOMDocument
    if (class_exists('DOMDocument') && !empty($clean_html)) {
        $dom = new DOMDocument();
        // Suppress HTML parsing errors
        libxml_use_internal_errors(true);
        // Force UTF-8
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $clean_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*');
        
        $allowed_attributes = array(
            'a' => array('href', 'title', 'target'),
            'img' => array('src', 'alt', 'title', 'width', 'height'),
        );
        
        foreach ($nodes as $node) {
            // Remove empty nodes (optional, maybe risky for br)
            // if (!$node->hasChildNodes() && empty($node->nodeValue) && $node->nodeName !== 'img' && $node->nodeName !== 'br') {
            //    $node->parentNode->removeChild($node);
            //    continue;
            // }

            // Clean attributes
            if ($node->hasAttributes()) {
                $attrs_to_remove = array();
                foreach ($node->attributes as $attr) {
                    $attr_name = $attr->nodeName;
                    $node_name = $node->nodeName;
                    
                    // Check if attribute is allowed for this tag
                    if (isset($allowed_attributes[$node_name]) && in_array($attr_name, $allowed_attributes[$node_name])) {
                        continue;
                    }
                    
                    // Allow 'id' if needed? No, user said "aggressive removal".
                    $attrs_to_remove[] = $attr_name;
                }
                
                foreach ($attrs_to_remove as $attr) {
                    $node->removeAttribute($attr);
                }
            }
        }
        
        $clean_html = $dom->saveHTML();
        // Clean up XML declaration
        $clean_html = str_replace('<?xml encoding="utf-8" ?>', '', $clean_html);
    }
    
    return trim($clean_html);
}
