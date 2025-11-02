<?php
/**
 * AJAX Handler for Keyword Research Tool
 */

if (!defined('ABSPATH')) {
    exit;
}

class Keyword_Research_AJAX_Handler {

    private function get_decoded_keyword($param_name = 'keyword') {
        if (!isset($_POST[$param_name])) {
            return '';
        }

        $raw_value = wp_unslash($_POST[$param_name]);
        if ($raw_value === '') {
            return '';
        }

        // Sanitize_text_field works for mixed CJK/English strings when provided with the raw value.
        $sanitized_value = sanitize_text_field($raw_value);
        if ($sanitized_value !== '') {
            return $sanitized_value;
        }

        // Fallback to decoding in case the value was URL-encoded before reaching this handler.
        $decoded_value = urldecode($raw_value);
        $decoded_value = sanitize_text_field($decoded_value);

        return trim($decoded_value);
    }

    public function __construct() {
        add_action('wp_ajax_keyword_research_mine', array($this, 'handle_keyword_mining'));
        add_action('wp_ajax_keyword_research_segmented_mine', array($this, 'handle_segmented_mining'));
        add_action('wp_ajax_keyword_research_finalize_mine', array($this, 'handle_finalize_mining'));
        add_action('wp_ajax_keyword_research_trend', array($this, 'handle_trend_analysis'));
    }

    public function handle_keyword_mining() {
        check_ajax_referer('keyword_research_nonce');

        $keyword = $this->get_decoded_keyword();
        if (empty($keyword)) {
            wp_send_json_error('无效的关键词输入');
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : uniqid();
        $data_sources = isset($_POST['data_sources']) ? (array) $_POST['data_sources'] : ['default'];
        $depth = isset($_POST['depth']) ? intval($_POST['depth']) : 1;
        if ($depth < 1) $depth = 1;
        if ($depth > 3) $depth = 3;

        if (!class_exists('FreeKeywordAPIs')) {
            require_once plugin_dir_path(__FILE__) . 'free_keyword_apis.php';
        }
        $api = new FreeKeywordAPIs();

        $total_steps = 0;
        foreach ($data_sources as $ds) {
            $total_steps += 39; // 1 + 1 + 11 + 26
        }

        wp_send_json_success([
            'session_id' => $session_id,
            'total_steps' => $total_steps,
            'data_sources' => $data_sources,
            'keyword' => $keyword,
            'lang_specifics' => isset($_POST['lang_specifics']) ? sanitize_text_field($_POST['lang_specifics']) : 'cn-zh-CN',
            'message' => '挖掘任务已初始化，开始分段执行'
        ]);
    }
    
    public function handle_segmented_mining() {
        check_ajax_referer('keyword_research_nonce');

        $keyword = $this->get_decoded_keyword();
        $session_id = sanitize_text_field($_POST['session_id']);
        $data_source = sanitize_text_field($_POST['data_source']);
        $step_type = sanitize_text_field($_POST['step_type']);
        $step_param = isset($_POST['step_param']) ? sanitize_text_field($_POST['step_param']) : '';
        $lang_specifics = isset($_POST['lang_specifics']) ? sanitize_text_field($_POST['lang_specifics']) : 'cn-zh-CN';

        if (empty($keyword) || empty($session_id) || empty($data_source) || empty($step_type)) {
            wp_send_json_error('参数不完整');
        }

        $parts = explode('-', $lang_specifics, 2);
        $country = isset($parts[0]) ? $parts[0] : 'cn';
        $language = isset($parts[1]) ? $parts[1] : 'zh-CN';

        if (!class_exists('FreeKeywordAPIs')) {
            require_once plugin_dir_path(__FILE__) . 'free_keyword_apis.php';
        }
        $api = new FreeKeywordAPIs();
        
        // 使用统一的挖掘方法处理所有数据源
        $result = $api->performSingleMiningStepByDataSource($keyword, $data_source, $step_type, $step_param, $language, $country);
        
        $temp_file_path = $api->getTempStorageFilePath($keyword, $session_id);
        $api->appendKeywordsToTempFile($temp_file_path, $result['keywords']);
        
        $current_step = isset($_POST['current_step']) ? intval($_POST['current_step']) : 1;
        $total_steps = isset($_POST['total_steps']) ? intval($_POST['total_steps']) : 1;
        $progress = round(($current_step / $total_steps) * 100, 2);
        
        wp_send_json_success([
            'keywords' => $result['keywords'],
            'description' => $result['description'],
            'current_step' => $current_step,
            'total_steps' => $total_steps,
            'progress' => $progress,
            'step_complete' => true
        ]);
    }
    
    public function handle_finalize_mining() {
        check_ajax_referer('keyword_research_nonce');

        $keyword = $this->get_decoded_keyword();
        $session_id = sanitize_text_field($_POST['session_id']);
        
        if (empty($keyword) || empty($session_id)) {
            wp_send_json_error('参数不完整');
        }

        if (!class_exists('FreeKeywordAPIs')) {
            require_once plugin_dir_path(__FILE__) . 'free_keyword_apis.php';
        }
        $api = new FreeKeywordAPIs();
        
        $temp_file_path = $api->getTempStorageFilePath($keyword, $session_id);
        $all_keywords = $api->readKeywordsFromTempFile($temp_file_path);
        
        $unique_keywords = array_unique($all_keywords);
        $final_keywords = array_values(array_diff($unique_keywords, [$keyword]));
        
        $api->deleteTempFile($temp_file_path);
        
        wp_send_json_success([
            'keywords' => $final_keywords,
            'total_found' => count($final_keywords),
            'message' => '挖掘任务完成，共找到 ' . count($final_keywords) . ' 个关键词'
        ]);
    }

    public function handle_trend_analysis() {
        check_ajax_referer('keyword_research_nonce');

        $keyword = $this->get_decoded_keyword();
        if (empty($keyword)) {
            wp_send_json_error('无效的关键词');
        }

        if (!class_exists('FreeKeywordAPIs')) {
            require_once plugin_dir_path(__FILE__) . 'free_keyword_apis.php';
        }
        $api = new FreeKeywordAPIs();

        // Note: Trend analysis does not currently use the language/country dropdown.
        // It is hardcoded to 'CN' as per original code.
        $exploreResult = $api->getTrendsExploreData($keyword, 'CN', 'today 12-m', 0);
        if ($exploreResult['http_code'] !== 200) {
            if ($exploreResult['http_code'] === 429) {
                wp_send_json_error('步骤1/2失败 (错误: 429 Too Many Requests)。您的服务器IP已被Google暂时限制，请稍后或明天再试。');
            } else {
                wp_send_json_error('步骤1/2失败：无法连接到Google Trends API (HTTP Code: ' . $exploreResult['http_code'] . ')');
            }
            return;
        }

        $exploreData = json_decode(substr($exploreResult['body'], 5), true);
        if (!$exploreData || !isset($exploreData['widgets'][0]['token'])) {
            wp_send_json_error('步骤1/2失败：无法解析来自Google的访问令牌(token)。API可能已更改。');
            return;
        }

        $widget = $exploreData['widgets'][0];
        $token = $widget['token'];
        $requestData = $widget['request'];
        $widgetResult = $api->getTrendsWidgetData($requestData, $token);

        if ($widgetResult['http_code'] !== 200) {
             if ($widgetResult['http_code'] === 429) {
                wp_send_json_error('步骤2/2失败 (错误: 429 Too Many Requests)。您的服务器IP已被Google暂时限制，请稍后或明天再试。');
            } else {
                wp_send_json_error('步骤2/2失败：无法获取趋势数据 (HTTP Code: ' . $widgetResult['http_code'] . ')');
            }
            return;
        }

        $trend_data = json_decode(substr($widgetResult['body'], 5), true);
        if ($trend_data && isset($trend_data['default']['timelineData']) && count($trend_data['default']['timelineData']) > 0) {
            $timeline = $trend_data['default']['timelineData'];
            $values = [];
            foreach ($timeline as $point) {
                if (isset($point['value'][0])) {
                    $values[] = $point['value'][0];
                }
            }

            if (empty($values)) {
                 wp_send_json_error('步骤2/2失败：趋势数据为空。');
                 return;
            }

            $response = [
                'average_interest' => array_sum($values) / count($values),
                'peak_interest' => max($values),
                'lowest_interest' => min($values),
                'timeline' => $timeline
            ];

            wp_send_json_success($response);
        } else {
            wp_send_json_error('步骤2/2失败：已获取令牌，但无法解析最终趋势数据。');
        }
    }
}

// 实例化处理器
new Keyword_Research_AJAX_Handler();
