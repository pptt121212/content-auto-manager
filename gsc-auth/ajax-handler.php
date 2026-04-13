<?php
/**
 * GSC AJAX Handler
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_gsc_get_metrics', 'yali_gsc_ajax_get_metrics');
add_action('wp_ajax_gsc_get_data', 'yali_gsc_ajax_get_data');
add_action('wp_ajax_gsc_disconnect', 'yali_gsc_ajax_disconnect');
add_action('wp_ajax_gsc_get_keyword_packs', 'yali_gsc_ajax_get_keyword_packs');
add_action('wp_ajax_gsc_save_negative_keywords', 'yali_gsc_ajax_save_negative_keywords');
add_action('wp_ajax_gsc_get_negative_keywords', 'yali_gsc_ajax_get_negative_keywords');
add_action('wp_ajax_gsc_segmented_mine', 'yali_gsc_ajax_segmented_mine');
add_action('wp_ajax_gsc_finalize_mine', 'yali_gsc_ajax_finalize_mine');
add_action('wp_ajax_gsc_discard_pack', 'yali_gsc_ajax_discard_pack');

function yali_gsc_ajax_get_metrics() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $days = isset($_POST['days']) ? intval($_POST['days']) : 30;
    
    $client = Yali_AI_Writer_GSC_API_Client::get_instance();
    $data = $client->get_metrics($days);
    
    if (isset($data['error'])) {
        wp_send_json_error($data['error']['message'] ?? 'API Error');
    }

    // Process data to structured summary
    $metrics = [
        'total_clicks' => 0,
        'total_impressions' => 0,
        'avg_position' => 0,
        'avg_ctr' => 0,
        'rows' => $data['rows'] ?? []
    ];

    if (!empty($data['rows'])) {
        $count = count($data['rows']);
        foreach ($data['rows'] as $row) {
            $metrics['total_clicks'] += $row['clicks'];
            $metrics['total_impressions'] += $row['impressions'];
            $metrics['avg_position'] += $row['position'];
            $metrics['avg_ctr'] += $row['ctr'];
        }
        $metrics['avg_position'] /= $count;
        $metrics['avg_ctr'] = ($metrics['avg_ctr'] / $count) * 100; // to percentage
    }

    wp_send_json_success($metrics);
}

function yali_gsc_ajax_get_data() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $dimension = isset($_POST['dimension']) ? sanitize_text_field($_POST['dimension']) : 'query';
    $days = isset($_POST['days']) ? intval($_POST['days']) : 30;

    $client = Yali_AI_Writer_GSC_API_Client::get_instance();
    
    // For queries, we need the page URL to match with local articles
    $api_dimension = $dimension === 'query' ? ['query', 'page'] : $dimension;
    $data = $client->get_analytics_data($api_dimension, $days);

    if (isset($data['error'])) {
        wp_send_json_error($data['error']['message'] ?? 'API Error');
    }

    $rows = $data['rows'] ?? [];

    // Process rows to map local AI articles if looking at queries
    if ($dimension === 'query' && !empty($rows)) {
        $site_url = trailingslashit(get_site_url());
        
        // We might get multiple rows for the same query if it ranks on different pages.
        // Let's aggregate by query to avoid duplicates in the table.
        $aggregated = [];

        foreach ($rows as $row) {
            $query = $row['keys'][0];
            $url = $row['keys'][1] ?? '';
            
            if (!isset($aggregated[$query])) {
                $aggregated[$query] = [
                    'keys' => [$query],
                    'url' => $url,
                    'clicks' => 0,
                    'impressions' => 0,
                    'ctr' => 0,
                    'position' => $row['position'], // Rough approximation, should ideally be weighted
                    'local_title' => null,
                    'edit_link' => null
                ];
                
                // Try to find local post
                if (!empty($url) && strpos($url, $site_url) === 0) {
                    $post_id = url_to_postid($url);
                    if ($post_id) {
                        $is_ai = get_post_meta($post_id, '_ai_generated', true);
                        if ($is_ai) {
                            $aggregated[$query]['local_title'] = get_the_title($post_id);
                            $aggregated[$query]['edit_link'] = get_edit_post_link($post_id, '');
                        }
                    }
                }
            }
            
            $aggregated[$query]['clicks'] += $row['clicks'];
            $aggregated[$query]['impressions'] += $row['impressions'];
        }
        
        // Re-calculate CTR and average position (simplified for aggregation)
        foreach ($aggregated as &$agg_row) {
            $agg_row['ctr'] = $agg_row['impressions'] > 0 ? $agg_row['clicks'] / $agg_row['impressions'] : 0;
            // Sorting requires keys array to match original structure
        }
        
        $rows = array_values($aggregated);
        
        // Sort by clicks DESC, impressions DESC
        usort($rows, function ($a, $b) {
            if ($a['clicks'] == $b['clicks']) {
                return $b['impressions'] <=> $a['impressions'];
            }
            return $b['clicks'] <=> $a['clicks'];
        });
        
        // Limit to top 1000 to match original behavior roughly
        $rows = array_slice($rows, 0, 1000);
    }

    wp_send_json_success($rows);
}

/**
 * Get Time-Series Chart Data
 */
add_action('wp_ajax_gsc_get_chart_data', 'yali_gsc_ajax_get_chart_data');
function yali_gsc_ajax_get_chart_data() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $days = isset($_POST['days']) ? intval($_POST['days']) : 28;
    $client = Yali_AI_Writer_GSC_API_Client::get_instance();
    
    // Request date dimension for the chart
    $data = $client->get_analytics_data('date', $days, 100);

    if (isset($data['error'])) {
        wp_send_json_error($data['error']['message'] ?? 'API Error');
    }

    $rows = $data['rows'] ?? [];
    
    // Sort by date ascending for the chart
    usort($rows, function ($a, $b) {
        return strcmp($a['keys'][0], $b['keys'][0]);
    });

    wp_send_json_success($rows);
}

function yali_gsc_ajax_disconnect() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    Yali_AI_Writer_GSC_API_Client::disconnect();
    wp_send_json_success();
}

add_action('wp_ajax_gsc_get_sites', 'yali_gsc_ajax_get_sites');
add_action('wp_ajax_gsc_save_site', 'yali_gsc_ajax_save_site');

function yali_gsc_ajax_get_sites() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $client = Yali_AI_Writer_GSC_API_Client::get_instance();
    $sites = $client->get_sites();
    if (!$sites) wp_send_json_error('无法获取站点列表');

    wp_send_json_success($sites);
}

function yali_gsc_ajax_save_site() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $site_url = isset($_POST['site_url']) ? sanitize_text_field($_POST['site_url']) : '';
    if (empty($site_url)) wp_send_json_error('站点 URL 不能为空');

    update_option('yali_gsc_selected_site', $site_url);
    wp_send_json_success();
}

add_action('wp_ajax_gsc_create_task', 'yali_gsc_ajax_create_task');

function yali_gsc_ajax_create_task() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $pack_id = isset($_POST['pack_id']) ? sanitize_text_field($_POST['pack_id']) : '';
    
    // Parse the JSON string sent from the frontend to securely preserve slashes and quotes
    $keywords_json = isset($_POST['keywords_json']) ? wp_unslash($_POST['keywords_json']) : '';
    $keywords_data = json_decode($keywords_json, true) ?: [];
    
    // Fallback: If strings are passed somehow, convert to array format
    $keywords = [];
    foreach ($keywords_data as $kd) {
        if (is_array($kd)) {
            $keywords[] = $kd;
        } else if (is_string($kd)) {
            $keywords[] = ['keyword' => $kd];
        }
    }

    if (empty($keywords)) {
        wp_send_json_error('关键词列表为空或解析失败');
    }

    global $wpdb;
    $table_rules = $wpdb->prefix . 'yali_ai_writer_rules';
    $table_items = $wpdb->prefix . 'yali_ai_writer_rule_items';
    $table_keywords = $wpdb->prefix . 'yali_ai_writer_gsc_used_keywords';

    // Map Pack ID to Human Readable Pack Name
    $pack_name_map = [
        'core' => __('核心流量', 'yali-ai-writer'),
        'rankup' => __('冲刺机会', 'yali-ai-writer'),
        'traffic' => __('点击优化', 'yali-ai-writer'),
        'longtail' => __('长尾发现', 'yali-ai-writer'),
        'contentgap' => __('寻找缺口', 'yali-ai-writer')
    ];
    $feature_name = isset($pack_name_map[$pack_id]) ? $pack_name_map[$pack_id] : (strpos($pack_id, 'shorttail') !== false ? __('挖掘扩展', 'yali-ai-writer') : __('智能导入', 'yali-ai-writer'));

    // 1. Create a new Rule
    $rule_name = "[GSC {$feature_name}] " . date('m-d H:i');
    
    // Convert array of objects to array of string keywords for the WP Options format
    $keyword_strings = array_map(function($k) { return $k['keyword']; }, $keywords);
    
    // The specific 'import_keywords' format expected by the backend
    $rule_conditions = serialize([
        'keywords' => $keyword_strings,
        'loop_count' => 1
    ]);
    
    $wpdb->insert($table_rules, [
        'rule_name' => $rule_name,
        'rule_type' => 'import_keywords',
        'rule_conditions' => $rule_conditions,
        'item_count' => 1,
        'status' => '1',
        'created_at' => current_time('mysql')
    ]);
    $rule_id = $wpdb->insert_id;

    // 2. Add keywords to items and management table
    $chunk_size = 200;
    $chunks = array_chunk($keywords, $chunk_size);
    
    $rule_ids = [];
    $total_imported = 0;

    foreach ($chunks as $index => $keyword_chunk) {
        $current_rule_id = $rule_id;
        $current_rule_name = $rule_name;

        // If multiple chunks, create additional rules
        if (count($chunks) > 1) {
            $suffix = chr(65 + $index); // A, B, C...
            $current_rule_name = "[GSC {$feature_name}] " . date('m-d H:i') . " (" . $suffix . __('卷', 'yali-ai-writer') . ")";
            
            if ($index > 0) {
                // Determine conditions for subset
                $subset_strings = array_map(function($k) { return $k['keyword']; }, $keyword_chunk);
                $subset_conditions = serialize([
                    'keywords' => $subset_strings,
                    'loop_count' => 1
                ]);

                $wpdb->insert($table_rules, [
                    'rule_name' => $current_rule_name,
                    'rule_type' => 'import_keywords',
                    'rule_conditions' => $subset_conditions,
                    'item_count' => 1,
                    'status' => '1',
                    'created_at' => current_time('mysql')
                ]);
                $current_rule_id = $wpdb->insert_id;
            } else {
                // Update first rule name
                $wpdb->update($table_rules, ['rule_name' => $current_rule_name], ['id' => $rule_id]);
            }
        }

        foreach ($keyword_chunk as $kw_data) {
            $kw_text = isset($kw_data['keyword']) ? wp_strip_all_tags(wp_unslash($kw_data['keyword'])) : '';
            $kw_text = trim($kw_text);
            if (empty($kw_text)) continue;
            
            // Extract remaining fields if available
            $page_url = isset($kw_data['url']) ? sanitize_text_field($kw_data['url']) : '';
            $trend_score = isset($kw_data['impressions']) ? intval($kw_data['impressions']) : 0; // Using impressions as trend score
            // If the GSC data doesn't have a trend_at (we don't pull specific dates for queries default), use current
            $trend_at = current_time('mysql'); 

            // Insert into rule items
            $wpdb->insert($table_items, [
                'rule_id' => $current_rule_id,
                'post_title' => $kw_text,
                'upload_text' => $kw_text,
                'created_at' => current_time('mysql')
            ]);

            // Insert into management table (Used Keywords)
            // Use mb_strtolower for consistent hashing
            $hash = md5(mb_strtolower($kw_text));
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table_keywords} (keyword, keyword_hash, page_url, source, pack_type, trend_score, trend_at, created_at) VALUES (%s, %s, %s, %s, %s, %d, %s, %s)",
                $kw_text, $hash, $page_url, 'gsc_pack_' . $pack_id, $pack_id, $trend_score, $trend_at, current_time('mysql')
            ));
            $total_imported++;
        }
        $rule_ids[] = $current_rule_id;
    }

    wp_send_json_success([
        'message' => sprintf(__('已成功将 %d 个关键词导入规则库！', 'yali-ai-writer'), $total_imported),
        'redirect' => admin_url('admin.php?page=yali-ai-writer-rules&action=edit&id=' . $rule_ids[0])
    ]);
}

/**
 * Save Negative Keywords
 */
function yali_gsc_ajax_save_negative_keywords() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $keywords = isset($_POST['keywords']) ? sanitize_textarea_field($_POST['keywords']) : '';
    update_option('yali_gsc_negative_keywords', $keywords);
    wp_send_json_success('保存成功');
}

/**
 * Get Negative Keywords
 */
function yali_gsc_ajax_get_negative_keywords() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $keywords = get_option('yali_gsc_negative_keywords', '');
    wp_send_json_success($keywords);
}

/**
 * Advanced Keyword Packs Generation based on GSC data
 */
function yali_gsc_ajax_get_keyword_packs() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $days = isset($_POST['days']) ? intval($_POST['days']) : 30;
    $client = Yali_AI_Writer_GSC_API_Client::get_instance();
    // Demand 'query' and 'page' dimensions to record ranking URLs
    $data = $client->get_analytics_data(['query', 'page'], $days, 2000); 

    if (empty($data['rows'])) {
        wp_send_json_success([]);
    }

    $rows = $data['rows'];
    
    // Since we requested 'query' and 'page', we might get duplicate queries.
    // Aggregate to find the best performing page per query.
    $aggregated_queries = [];
    foreach ($rows as $row) {
        $q = $row['keys'][0];
        $u = $row['keys'][1] ?? '';
        if (!isset($aggregated_queries[$q])) {
            $aggregated_queries[$q] = $row;
            // Map the url key
            $aggregated_queries[$q]['url'] = $u;
        } else {
            $aggregated_queries[$q]['clicks'] += $row['clicks'];
            $aggregated_queries[$q]['impressions'] += $row['impressions'];
            // Retain the best position
            if ($row['position'] < $aggregated_queries[$q]['position']) {
                $aggregated_queries[$q]['position'] = $row['position'];
                $aggregated_queries[$q]['url'] = $u; // Update URL to the best ranking one
            }
        }
    }
    
    // Recalculate CTR
    foreach ($aggregated_queries as &$aq) {
        $aq['ctr'] = $aq['impressions'] > 0 ? $aq['clicks'] / $aq['impressions'] : 0;
    }
    
    $queries = array_values($aggregated_queries);

    $packs = [
        'rankup' => [
            'id' => 'rankup',
            'name' => __('冲刺词包 (Page 2 Gems)', 'yali-ai-writer'),
            'desc' => __('排名在 11-25 位且流量底盘高于全站平均值的关键词，这是最具价值的优化机会。', 'yali-ai-writer'),
            'keywords' => [],
            'strategy_icon' => '<span class="dashicons dashicons-chart-line" style="color:#3b82f6;"></span>',
            'badge' => __('冲刺机会', 'yali-ai-writer')
        ],
        'traffic' => [
            'id' => 'traffic',
            'name' => __('转化词包 (Low CTR)', 'yali-ai-writer'),
            'desc' => __('稳定排名在首页但点击率严重低于全站平均水平，急需优化标题或描述以促成转化。', 'yali-ai-writer'),
            'keywords' => [],
            'strategy_icon' => '<span class="dashicons dashicons-warning" style="color:#ef4444;"></span>',
            'badge' => __('点击优化', 'yali-ai-writer')
        ],
        'contentgap' => [
            'id' => 'contentgap',
            'name' => __('黑马词包 (Content Gap)', 'yali-ai-writer'),
            'desc' => __('搜索量高于均值但本站几乎没有相关排位（>30或未排），存在严重的内容结构缺失。', 'yali-ai-writer'),
            'keywords' => [],
            'strategy_icon' => '<span class="dashicons dashicons-flag" style="color:#10b981;"></span>',
            'badge' => __('寻找缺口', 'yali-ai-writer')
        ]
    ];

    // Calc Dynamic Thresholds
    $all_imps = [];
    $sum_imp = 0;
    $count_ctr = 0;
    $sum_ctr = 0;

    foreach ($queries as $q) { 
        $all_imps[] = $q['impressions'];
        $sum_imp += $q['impressions'];
        if ($q['impressions'] > 5) { 
            $sum_ctr += $q['ctr']; 
            $count_ctr++; 
        } 
    }
    
    $avg_site_ctr = $count_ctr > 0 ? $sum_ctr / $count_ctr : 0.02;
    $avg_site_imp = count($all_imps) > 0 ? $sum_imp / count($all_imps) : 10;
    
    // Adaptive threshold: Protect small sites with a minimum of 10 impressions
    $adaptive_imp_threshold = max(10, $avg_site_imp);

    sort($all_imps);
    $top_10_index = floor(count($all_imps) * 0.9);
    $top_10_imp = $all_imps[$top_10_index] ?? 500;

    // Filter out used keywords
    global $wpdb;

    // Fetch User Negative Keywords
    $negative_keywords_raw = get_option('yali_gsc_negative_keywords', '');
    $negative_keywords = array_filter(array_map('trim', explode("\n", strtolower($negative_keywords_raw))));
    $table_keywords = $wpdb->prefix . 'yali_ai_writer_gsc_used_keywords';
    $used_hashes = $wpdb->get_col("SELECT keyword_hash FROM {$table_keywords}");
    $used_hashes = array_flip($used_hashes);

    foreach ($queries as $q) {
        $query = $q['keys'][0] ?? '';
        if (empty($query)) continue;

        // 1. Skip if already used in a plugin task
        $hash = md5(mb_strtolower(trim($query)));
        if (isset($used_hashes[$hash])) continue;

        // 2. Skip Negative Keywords (User defined exclusions)
        if (!empty($negative_keywords)) {
            $is_negative = false;
            $query_lower = mb_strtolower($query);
            foreach ($negative_keywords as $nk) {
                if (mb_strpos($query_lower, $nk) !== false) {
                    $is_negative = true;
                    break;
                }
            }
            if ($is_negative) continue;
        }

        $pos = $q['position'];
        $imp = $q['impressions'];
        $ctr = $q['ctr'];
        $len = mb_strlen($query);

        // EXCLUSION: Pure Winners (Do Not Touch)
        if ($pos <= 5 && $ctr > $avg_site_ctr && $imp > $avg_site_imp) {
            continue; 
        }

        // Build detailed keyword data object
        $kw_data = [
            'keyword' => $query,
            'url' => $q['url'] ?? '',
            'impressions' => $imp,
            'clicks' => $q['clicks'] ?? 0,
            'position' => $pos
        ];

        // 1. Short-tail Fission Mining (Unbound by position, must be broad)
        if (($imp > $top_10_imp || $imp > 500) && $len <= 10) {
            $pack_id = 'shorttail_' . md5($query);
            $packs[$pack_id] = [
                'id' => $pack_id,
                'name' => __('提取词根: ', 'yali-ai-writer') . esc_html($query),
                'desc' => __('该短词曝光势能极强。强烈建议进行【长尾词裂变挖掘】，而不是盲目生成单一文章。', 'yali-ai-writer'),
                'keywords' => [$kw_data],
                'strategy_icon' => '<span class="dashicons dashicons-search" style="color:#8b5cf6;"></span>',
                'badge' => __('深度挖掘', 'yali-ai-writer'),
                'action' => 'mine'
            ];
            continue;
        }

        // 2. Rankup logic (Page 2 Gems)
        if ($pos > 10 && $pos <= 25 && $imp >= $adaptive_imp_threshold) {
            $packs['rankup']['keywords'][] = $kw_data;
            continue;
        }

        // 3. Low CTR logic (Visible but ignored)
        if ($pos <= 10 && $imp >= ($adaptive_imp_threshold * 1.5) && $ctr < ($avg_site_ctr * 0.8)) {
            $packs['traffic']['keywords'][] = $kw_data;
            continue;
        }

        // 4. Content Gap logic (Demand exists, but no dedicated content)
        if ($imp >= $adaptive_imp_threshold && ($pos > 30 || $pos == 0)) {
            $packs['contentgap']['keywords'][] = $kw_data;
        }
    }

    // Remove empty packs
    $packs = array_filter($packs, function($p) { return !empty($p['keywords']); });
    
    // Convert to values explicitly to ensure array indexing in JSON
    $packs = array_values($packs);

    wp_send_json_success($packs);
}

/**
 * Segmented Mining for GSC - Follows Keyword Research Tool logic
 */
function yali_gsc_ajax_segmented_mine() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $keyword = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';
    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
    $data_source = isset($_POST['data_source']) ? sanitize_text_field($_POST['data_source']) : 'default';
    $lang_specifics = isset($_POST['lang_specifics']) ? sanitize_text_field($_POST['lang_specifics']) : 'cn-zh-CN';

    if (empty($keyword) || empty($session_id)) {
        wp_send_json_error('参数不完整');
    }

    $parts = explode('-', $lang_specifics, 2);
    $country = isset($parts[0]) ? $parts[0] : 'cn';
    $language = isset($parts[1]) ? $parts[1] : 'zh-CN';

    $keyword_tool_dir = dirname(__FILE__) . '/../keyword-research-tool/';
    if (!class_exists('Yali_AI_Writer_FreeKeywordAPIs')) {
        require_once $keyword_tool_dir . 'free_keyword_apis.php';
    }
    $api = new Yali_AI_Writer_FreeKeywordAPIs();
    
    // For GSC mining from pack cards, we mostly do 'base' mining but for multi-sources
    // The frontend will send step_type='base' for each engine
    $step_type = isset($_POST['step_type']) ? sanitize_text_field($_POST['step_type']) : 'base';
    $step_param = isset($_POST['step_param']) ? sanitize_text_field($_POST['step_param']) : '';

    $result = $api->performSingleMiningStepByDataSource($keyword, $data_source, $step_type, $step_param, $language, $country);
    
    $temp_file_path = $api->getTempStorageFilePath($keyword, $session_id);
    $api->appendKeywordsToTempFile($temp_file_path, $result['keywords']);
    
    wp_send_json_success([
        'keywords' => $result['keywords'],
        'description' => $result['description']
    ]);
}

/**
 * Finalize Mining for GSC - Consolidate results and check usage
 */
function yali_gsc_ajax_finalize_mine() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $keywords_json = isset($_POST['keywords_json']) ? wp_unslash($_POST['keywords_json']) : '';
    $targets_data = json_decode($keywords_json, true) ?: [];
    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';

    if (empty($targets_data) || empty($session_id)) {
        wp_send_json_error(__('参数不完整', 'yali-ai-writer'));
    }

    $keyword_tool_dir = dirname(__FILE__) . '/../keyword-research-tool/';
    if (!class_exists('Yali_AI_Writer_FreeKeywordAPIs')) {
        require_once $keyword_tool_dir . 'free_keyword_apis.php';
    }
    $api = new Yali_AI_Writer_FreeKeywordAPIs();

    $all_mined_keywords = [];
    $targets = [];

    foreach ($targets_data as $kd) {
        $seed = is_array($kd) ? ($kd['keyword'] ?? '') : $kd;
        if (empty($seed)) continue;
        $targets[] = $seed;

        $temp_file_path = $api->getTempStorageFilePath($seed, $session_id);
        if (file_exists($temp_file_path)) {
            $mined = $api->readKeywordsFromTempFile($temp_file_path);
            if (!empty($mined)) {
                $all_mined_keywords = array_merge($all_mined_keywords, $mined);
            }
            $api->deleteTempFile($temp_file_path);
        }
    }

    $all_mined_keywords = array_unique(array_filter($all_mined_keywords));

    if (empty($all_mined_keywords)) {
        wp_send_json_error('未能挖掘到相关的扩展长尾词。');
    }

    // Connect to database to check historical usage
    global $wpdb;
    $table_keywords = $wpdb->prefix . 'yali_ai_writer_gsc_used_keywords';
    
    // We check usage based on the first target seed (assuming one seed per pack usually)
    $pack_source_prefix = 'gsc_pack_shorttail_';
    $primary_seed_text = isset($targets[0]) ? $targets[0] : 'unknown';
    $primary_seed = md5(mb_strtolower(trim($primary_seed_text)));
    $source_id = $pack_source_prefix . $primary_seed;
    
    $historical_keywords = $wpdb->get_col($wpdb->prepare(
        "SELECT keyword FROM {$table_keywords} WHERE source = %s",
        $source_id
    ));
    
    $all_used_hashes = $wpdb->get_col("SELECT keyword_hash FROM {$table_keywords}");
    $all_used_hashes_map = array_flip($all_used_hashes);
    $historical_map = array_flip(array_map('mb_strtolower', $historical_keywords));

    $structured_keywords = [];
    foreach ($all_mined_keywords as $kw) {
        $kw_lower = mb_strtolower(trim($kw));
        $hash = md5($kw_lower);
        
        $used_in_this_pack = isset($historical_map[$kw_lower]);
        $used_anywhere = isset($all_used_hashes_map[$hash]);
        
        $structured_keywords[] = [
            'keyword' => $kw,
            'used' => $used_in_this_pack || $used_anywhere,
            'is_historical' => $used_in_this_pack
        ];
    }

    wp_send_json_success([
        'mined_keywords' => $structured_keywords,
        'message' => __('挖掘任务完成。', 'yali-ai-writer')
    ]);
}
/**
 * Discard a GSC Pack - Mark seed as used to prevent further recommendations
 */
function yali_gsc_ajax_discard_pack() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $keyword = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';
    $pack_id = isset($_POST['pack_id']) ? sanitize_text_field($_POST['pack_id']) : '';

    if (empty($keyword)) {
        wp_send_json_error('关键词不能为空');
    }

    global $wpdb;
    $table_keywords = $wpdb->prefix . 'yali_ai_writer_gsc_used_keywords';
    
    $hash = md5(mb_strtolower(trim($keyword)));
    
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$table_keywords} (keyword, keyword_hash, source, pack_type, created_at) VALUES (%s, %s, %s, %s, %s)",
        $keyword, $hash, 'gsc_discarded', $pack_id, current_time('mysql')
    ));

    if ($inserted !== false) {
        wp_send_json_success('推荐已删除，此关键词未来将不再出现在建议中。');
    } else {
        wp_send_json_error('操作失败，数据库异常。');
    }
}
add_action('wp_ajax_gsc_get_ai_roi_data', 'yali_gsc_ajax_get_ai_roi_data');
function yali_gsc_ajax_get_ai_roi_data() {
    check_ajax_referer('yali_gsc_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $client = Yali_AI_Writer_GSC_API_Client::get_instance();
    
    // Support dynamic date range from frontend
    $days = isset($_POST['days']) ? intval($_POST['days']) : 28;
    if ($days <= 0) $days = 28;

    // Fetch top 1000 pages to match
    $data = $client->get_analytics_data('page', $days, 1000);

    if (isset($data['error'])) {
        wp_send_json_error($data['error']['message'] ?? 'API Error');
    }

    $gsc_pages = $data['rows'] ?? [];

    global $wpdb;
    $table_articles = $wpdb->prefix . 'yali_ai_writer_articles';
    $table_keywords = $wpdb->prefix . 'yali_ai_writer_gsc_used_keywords';

    // 1. Fetch AI Posts using the internal tracking table
    $ai_post_ids = $wpdb->get_col("
        SELECT a.post_id 
        FROM {$table_articles} a
        INNER JOIN {$wpdb->posts} p ON a.post_id = p.ID
        WHERE a.status = 'success' AND p.post_status = 'publish' AND p.post_type = 'post'
    ");
    
    $ai_posts = [];
    if (!empty($ai_post_ids)) {
        $ai_posts = get_posts([
            'post__in' => $ai_post_ids,
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => -1
        ]);
    }

    $total_ai_articles = count($ai_posts);

    $summary = [
        'total_ai_articles' => $total_ai_articles,
        'ranking_article_count' => 0,
        'unranked_article_count' => 0,
        'articles_with_clicks' => 0,
        'articles_with_impressions' => 0,
        'total_clicks' => 0,
        'total_impressions' => 0,
        'avg_position' => 0
    ];

    $strategy_summary = [
        'core' => ['name' => '核心爆款', 'clicks' => 0, 'impressions' => 0, 'articles' => 0, 'historical_impressions' => 0],
        'rankup' => ['name' => '冲刺机会', 'clicks' => 0, 'impressions' => 0, 'articles' => 0, 'historical_impressions' => 0],
        'traffic' => ['name' => '点击率优化', 'clicks' => 0, 'impressions' => 0, 'articles' => 0, 'historical_impressions' => 0],
        'contentgap' => ['name' => '流量缺口', 'clicks' => 0, 'impressions' => 0, 'articles' => 0, 'historical_impressions' => 0],
        'shorttail' => ['name' => '挖掘拓展', 'clicks' => 0, 'impressions' => 0, 'articles' => 0, 'historical_impressions' => 0]
    ];

    // Fetch all used keywords with both hash and a slug-friendly identifier for fallback matching
    $used_kws = $wpdb->get_results("SELECT keyword, keyword_hash, pack_type, trend_score, created_at FROM {$table_keywords}", ARRAY_A);
    $kw_map = [];
    if (!empty($used_kws)) {
        foreach ($used_kws as $uk) {
            $kw_map[$uk['keyword_hash']] = $uk;
            // Also store by a slug-friendly key (normalized keyword) to match if title was renamed
            $slug_key = sanitize_title($uk['keyword']);
            if (!isset($kw_map['slug_' . $slug_key])) {
                $kw_map['slug_' . $slug_key] = $uk;
            }
        }
    }

    $details = [];
    $dead_capacity = []; 
    $total_pos = 0;
    
    $current_time = current_time('timestamp');

    foreach ($ai_posts as $post) {
        $permalink = trailingslashit(get_permalink($post->ID));
        $post_title = get_the_title($post->ID);
        $post_slug = $post->post_name;
        
        $title_hash = md5(mb_strtolower(trim($post_title)));
        
        // Strategy matching - Try Hash first, then Slug fallback (if title renamed)
        $strategy_data = null;
        if (isset($kw_map[$title_hash])) {
            $strategy_data = $kw_map[$title_hash];
        } else if (isset($kw_map['slug_' . $post_slug])) {
            $strategy_data = $kw_map['slug_' . $post_slug];
        }

        $matched = false;
        $clicks = 0;
        $impressions = 0;
        $position = 0;
        
        foreach ($gsc_pages as $g_page) {
            $g_url = trailingslashit($g_page['keys'][0]);
            
            if (strcasecmp($g_url, $permalink) === 0) {
                $matched = true;
                $clicks = $g_page['clicks'];
                $impressions = $g_page['impressions'];
                $position = $g_page['position'];

                $summary['ranking_article_count']++;
                $summary['total_clicks'] += $clicks;
                $summary['total_impressions'] += $impressions;
                $total_pos += $position;
                
                if ($clicks > 0) $summary['articles_with_clicks']++;
                if ($impressions > 0) $summary['articles_with_impressions']++;
                
                $details[] = [
                    'id' => $post->ID,
                    'title' => $post_title,
                    'url' => $permalink,
                    'edit_link' => get_edit_post_link($post->ID, ''),
                    'clicks' => $clicks,
                    'impressions' => $impressions,
                    'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0,
                    'position' => round($position, 1),
                    'is_strategy' => ($strategy_data !== null)
                ];
                
                // Aggregate into Strategy Summary 
                if ($strategy_data) {
                    $pack_type = $strategy_data['pack_type'];
                    $base_type = strpos($pack_type, 'shorttail_') === 0 ? 'shorttail' : $pack_type;
                    
                    if (isset($strategy_summary[$base_type])) {
                        $strategy_summary[$base_type]['articles']++;
                        $strategy_summary[$base_type]['clicks'] += $clicks;
                        $strategy_summary[$base_type]['impressions'] += $impressions;
                        $strategy_summary[$base_type]['historical_impressions'] += intval($strategy_data['trend_score']);
                    }
                }
                break;
            }
        }
        
        // Track dead capacity (deployed > 14 days ago, but 0 impressions)
        if ($strategy_data) {
             $created_timestamp = strtotime($strategy_data['created_at']);
             $days_since_deploy = round(($current_time - $created_timestamp) / 86400);
             
             // If not matched (no GSC data found) OR matched but 0 impressions
             if ((!$matched || $impressions == 0) && $days_since_deploy >= 14) {
                 $dead_capacity[] = [
                     'id' => $post->ID,
                     'keyword' => $strategy_data['keyword'],
                     'title' => $post_title,
                     'url' => $permalink,
                     'edit_link' => get_edit_post_link($post->ID, ''),
                     'days_deployed' => $days_since_deploy,
                     'historical_impressions' => $strategy_data['trend_score']
                 ];
             }
        }
    }

    $summary['unranked_article_count'] = $total_ai_articles - $summary['ranking_article_count'];
    if ($summary['ranking_article_count'] > 0) {
        $summary['avg_position'] = round($total_pos / $summary['ranking_article_count'], 1);
    }
    
    // Sort details by clicks (DESC), then impressions (DESC)
    usort($details, function($a, $b) {
        if ($a['clicks'] == $b['clicks']) {
            return $b['impressions'] <=> $a['impressions'];
        }
        return $b['clicks'] <=> $a['clicks'];
    });
    
    // Sort dead capacity by historical potential (DESC)
    usort($dead_capacity, function($a, $b) {
        return $b['historical_impressions'] <=> $a['historical_impressions'];
    });

    wp_send_json_success([
        'summary' => $summary,
        'strategy_summary' => $strategy_summary,
        'dead_capacity' => $dead_capacity,
        'details' => $details
    ]);
}
