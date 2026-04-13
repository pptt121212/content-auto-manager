<?php
/**
 * AJAX Handler for Keyword Research Tool
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_Keyword_Research_AJAX_Handler {

    private function get_decoded_keyword($param_name = 'keyword') {
        if (!isset($_POST[$param_name])) {
            return '';
        }

        $raw_value = wp_unslash($_POST[$param_name]);
        if ($raw_value === '') {
            return '';
        }

        // 先检查是否是URL编码的字符串（包含%）
        if (strpos($raw_value, '%') !== false) {
            // URL解码后再清理
            $decoded_value = urldecode($raw_value);
            return sanitize_text_field($decoded_value);
        }

        // 直接使用原始值清理
        return sanitize_text_field($raw_value);
    }

    public function __construct() {
        add_action('wp_ajax_keyword_research_mine', array($this, 'handle_keyword_mining'));
        add_action('wp_ajax_keyword_research_segmented_mine', array($this, 'handle_segmented_mining'));
        add_action('wp_ajax_keyword_research_finalize_mine', array($this, 'handle_finalize_mining'));
        add_action('wp_ajax_keyword_research_trend', array($this, 'handle_trend_analysis'));
    }

    public function handle_keyword_mining() {
        check_ajax_referer('keyword_research_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('您没有权限执行此操作。', 'yali-ai-writer'));
        }

        $keyword = $this->get_decoded_keyword();
        if (empty($keyword)) {
            wp_send_json_error(__('无效的关键词输入', 'yali-ai-writer'));
        }
        
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : uniqid();
        $data_sources = isset($_POST['data_sources']) ? (array) $_POST['data_sources'] : ['default'];
        $deep_mining = isset($_POST['deep_mining']) ? filter_var($_POST['deep_mining'], FILTER_VALIDATE_BOOLEAN) : false;

        if (!class_exists('Yali_AI_Writer_FreeKeywordAPIs')) {
            require_once plugin_dir_path(__FILE__) . 'free_keyword_apis.php';
        }
        $api = new Yali_AI_Writer_FreeKeywordAPIs();

        // Total steps will be calculated by the frontend based on selected sources and deep mining depth

        wp_send_json_success([
            'session_id' => $session_id,
            'total_steps' => $total_steps,
            'data_sources' => $data_sources,
            'keyword' => $keyword,
            'lang_specifics' => isset($_POST['lang_specifics']) ? sanitize_text_field($_POST['lang_specifics']) : 'cn-zh-CN',
            'message' => __('挖掘任务已初始化，开始分段执行', 'yali-ai-writer')
        ]);
    }
    
    public function handle_segmented_mining() {
        check_ajax_referer('keyword_research_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('您没有权限执行此操作。', 'yali-ai-writer'));
        }

        $keyword = $this->get_decoded_keyword();
        $session_id = sanitize_text_field($_POST['session_id']);
        $data_source = sanitize_text_field($_POST['data_source']);
        $step_type = sanitize_text_field($_POST['step_type']);
        $step_param = isset($_POST['step_param']) ? sanitize_text_field($_POST['step_param']) : '';
        $lang_specifics = isset($_POST['lang_specifics']) ? sanitize_text_field($_POST['lang_specifics']) : 'cn-zh-CN';

        if (empty($keyword) || empty($session_id) || empty($data_source) || empty($step_type)) {
            wp_send_json_error(__('参数不完整', 'yali-ai-writer'));
        }

        $parts = explode('-', $lang_specifics, 2);
        $country = isset($parts[0]) ? $parts[0] : 'cn';
        $language = isset($parts[1]) ? $parts[1] : 'zh-CN';

        if (!class_exists('Yali_AI_Writer_FreeKeywordAPIs')) {
            require_once plugin_dir_path(__FILE__) . 'free_keyword_apis.php';
        }
        $api = new Yali_AI_Writer_FreeKeywordAPIs();
        
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
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('您没有权限执行此操作。', 'yali-ai-writer'));
        }

        $keyword = $this->get_decoded_keyword();
        $session_id = sanitize_text_field($_POST['session_id']);
        
        if (empty($keyword) || empty($session_id)) {
            wp_send_json_error(__('参数不完整', 'yali-ai-writer'));
        }

        if (!class_exists('Yali_AI_Writer_FreeKeywordAPIs')) {
            require_once plugin_dir_path(__FILE__) . 'free_keyword_apis.php';
        }
        $api = new Yali_AI_Writer_FreeKeywordAPIs();
        
        $temp_file_path = $api->getTempStorageFilePath($keyword, $session_id);
        $all_keywords = $api->readKeywordsFromTempFile($temp_file_path);
        
        $unique_keywords = array_unique($all_keywords);
        $final_keywords = array_values(array_diff($unique_keywords, [$keyword]));
        
        $api->deleteTempFile($temp_file_path);
        
        wp_send_json_success([
            'keywords' => $final_keywords,
            'total_found' => count($final_keywords),
            'message' => sprintf(__('挖掘任务完成，共找到 %d 个关键词', 'yali-ai-writer'), count($final_keywords))
        ]);
    }

    public function handle_trend_analysis() {
        check_ajax_referer('keyword_research_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('您没有权限执行此操作。', 'yali-ai-writer'));
        }

        $keyword = $this->get_decoded_keyword();
        if (empty($keyword)) {
            wp_send_json_error(__('无效的关键词', 'yali-ai-writer'));
        }

        // 记录请求的关键词（用于调试）
        error_log('Google Trends AJAX: 请求关键词 = ' . $keyword);

        // 使用混合方案类，支持WordPress HTTP API和cURL自动降级，更好的429错误处理
        if (!class_exists('Yali_AI_Writer_Yali_AI_Writer_FreeKeywordAPIs_Hybrid')) {
            require_once plugin_dir_path(__FILE__) . 'free_keyword_apis_hybrid.php';
        }
        $api = new Yali_AI_Writer_Yali_AI_Writer_FreeKeywordAPIs_Hybrid();

        // 使用混合方案的 getTrendsData_Hybrid 方法，自动处理Session预热、Cookie持久化、限流重试
        $trend_data = $api->getTrendsData_Hybrid($keyword);

        // 调试日志
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Google Trends AJAX: trend_data = ' . ($trend_data ? '有数据' : 'null'));
            if ($trend_data) {
                error_log('Google Trends AJAX: has timelineData = ' . (isset($trend_data['default']['timelineData']) ? '是' : '否'));
                if (isset($trend_data['default']['timelineData'])) {
                    error_log('Google Trends AJAX: timelineData count = ' . count($trend_data['default']['timelineData']));
                }
            }
        }

        if ($trend_data && isset($trend_data['default']['timelineData']) && count($trend_data['default']['timelineData']) > 0) {
            $timeline = $trend_data['default']['timelineData'];
            $values = [];
            foreach ($timeline as $point) {
                if (isset($point['value'][0])) {
                    $values[] = $point['value'][0];
                }
            }

            if (empty($values)) {
                 wp_send_json_error(__('步骤2/2失败：趋势数据点为空。', 'yali-ai-writer'));
                 return;
            }

            $response = [
                'average_interest' => round(array_sum($values) / count($values), 2),
                'peak_interest' => max($values),
                'lowest_interest' => min($values),
                'timeline' => $timeline
            ];

            wp_send_json_success($response);
        } else {
            // 获取详细错误信息
            $error_info = $api->getLastError();

            // 记录调试信息到错误日志
            error_log('Google Trends AJAX失败: keyword=' . $keyword . ', error=' . $error_info);

            // 根据情况提供更详细的错误信息
            if (empty($trend_data)) {
                $error_msg = empty($error_info) ? 'API请求失败，请稍后重试。' : $error_info;
            } elseif (!isset($trend_data['default']['timelineData'])) {
                $error_msg = 'API返回数据结构异常（缺少timelineData）。';
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Google Trends 数据结构: ' . json_encode(array_keys($trend_data)));
                }
            } elseif (empty($trend_data['default']['timelineData'])) {
                $error_msg = '该关键词在指定时间段内没有足够的数据。';
            } else {
                $error_msg = empty($error_info) ? '数据解析失败，请稍后重试。' : $error_info;
            }

            wp_send_json_error(sprintf(__('趋势分析失败：%s', 'yali-ai-writer'), $error_msg));
        }
    }
}

// 实例化处理器
new Yali_AI_Writer_Keyword_Research_AJAX_Handler();
