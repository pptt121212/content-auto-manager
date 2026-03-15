<?php
if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_SearchMaterialsAdminPage {
    
    public function __construct() {
        // 移交给 ContentAuto_AdminMenu 统一注册，以便控制顺序
        // add_action('admin_menu', [$this, 'register_menu'], 20);
        add_action('wp_ajax_content_auto_search_material_process', [$this, 'handle_ajax_process']);
        add_action('wp_ajax_content_auto_save_material_result', [$this, 'handle_ajax_save']);
        add_action('wp_ajax_content_auto_extension_material_process', [$this, 'handle_extension_ajax_process']);
        add_action('wp_ajax_content_auto_check_task_result', [$this, 'handle_check_task_result']);
    }

    public function register_menu() {
        add_submenu_page(
            'yali-ai-writer',
            '搜索物料',
            '搜索物料',
            'manage_options',
            'content-auto-search-materials',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        ?>
        <div class="wrap yali-plugin-wrapper">
            <h1 class="yali-page-title"><span class="dashicons dashicons-search"></span> <?php _e('搜索物料 (Search Materials)', 'yali-ai-writer'); ?></h1>
            <p><?php _e('输入主题ID，系统将自动搜索、筛选、抓取并汇总相关资料。', 'yali-ai-writer'); ?></p>
            
            <!-- 标签切换 -->
            <div class="yali-tabs">
                <a href="#" class="yali-tab-item active" data-tab="search-engine"><span class="dashicons dashicons-admin-site-alt3" style="line-height:1.3; margin-right:4px;"></span> <?php _e('网络搜索', 'yali-ai-writer'); ?></a>
                <a href="#" class="yali-tab-item" data-tab="extension-rag"><span class="dashicons dashicons-book-alt" style="line-height:1.3; margin-right:4px;"></span> <?php _e('知识库搜索', 'yali-ai-writer'); ?></a>
            </div>
            
            <!-- 搜索引擎标签内容 -->
            <div id="tab-search-engine" class="tab-content" style="display: block;">
                <div class="content-auto-section yali-card">
                    <h3 style="margin-top: 0;"><?php _e('网络搜索模式', 'yali-ai-writer'); ?></h3>
                    <p class="description yali-desc"><?php _e('通过搜索引擎搜索互联网内容，抓取网页并汇总为参考资料。', 'yali-ai-writer'); ?></p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="topic_id"><?php _e('主题ID', 'yali-ai-writer'); ?></label></th>
                            <td>
                                <input name="topic_id" type="number" id="topic_id" value="" class="regular-text yali-input">
                                <p class="description yali-desc"><?php _e('请输入数据库中存在的主题ID。', 'yali-ai-writer'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <div style="margin-top: 15px;">
                        <button type="button" id="btn_start_material" class="button button-primary button-large yali-btn yali-btn-primary"><?php _e('开始搜集与整理', 'yali-ai-writer'); ?></button>
                        <button type="button" id="btn_save_material" class="button button-secondary button-large yali-btn yali-btn-secondary" style="display:none; margin-left: 10px;"><?php _e('保存为主题参考资料', 'yali-ai-writer'); ?></button>
                        <span class="spinner" id="material_spinner" style="float:none;"></span>
                    </div>
                </div>

                <div id="material_result_area" style="margin-top:20px; display:none; max-width: 1000px;">
                    <div class="content-auto-section yali-card">
                        <h3><?php _e('处理日志 (实时)', 'yali-ai-writer'); ?></h3>
                        <div id="material_log" class="yali-textarea-code" style="height:300px; overflow-y:auto; margin-bottom:20px;"></div>
                        
                        <h3><?php _e('汇总结果', 'yali-ai-writer'); ?></h3>
                        <div id="material_summary" class="yali-panel" style="min-height: 200px;"></div>
                        <textarea id="hidden_summary_data" style="display:none;"></textarea>
                    </div>
                </div>
            </div>
            
            <!-- 知识库搜索标签内容 -->
            <div id="tab-extension-rag" class="tab-content" style="display: none;">
                <div class="content-auto-section yali-card">
                    <h3 style="margin-top: 0;"><?php _e('知识库搜索模式', 'yali-ai-writer'); ?></h3>
                    <p class="description yali-desc"><?php _e('向已连接的浏览器插件发送请求，在本地知识库中搜索并由 AI 生成参考资料。', 'yali-ai-writer'); ?></p>
                    
                    <div class="yali-notice yali-notice-warning">
                        <strong>⚠️ <?php _e('前提条件：', 'yali-ai-writer'); ?></strong>
                        <ul style="margin: 5px 0 0 20px;">
                            <li><?php _e('浏览器插件已安装并运行', 'yali-ai-writer'); ?></li>
                            <li><?php _e('插件已连接到此网站（在插件设置页面完成连接）', 'yali-ai-writer'); ?></li>
                            <li><?php _e('插件中已导入知识库文档', 'yali-ai-writer'); ?></li>
                        </ul>
                    </div>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="ext_topic_id"><?php _e('主题ID', 'yali-ai-writer'); ?></label></th>
                            <td>
                                <input name="ext_topic_id" type="number" id="ext_topic_id" value="" class="regular-text yali-input">
                                <p class="description yali-desc"><?php _e('请输入数据库中存在的主题ID。', 'yali-ai-writer'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <div style="margin-top: 15px;">
                        <button type="button" id="btn_start_extension" class="button button-primary button-large yali-btn yali-btn-primary"><?php _e('开始知识库搜索', 'yali-ai-writer'); ?></button>
                        <button type="button" id="btn_save_ext_material" class="button button-secondary button-large yali-btn yali-btn-secondary" style="display:none; margin-left: 10px;"><?php _e('保存为主题参考资料', 'yali-ai-writer'); ?></button>
                        <span class="spinner" id="ext_spinner" style="float:none;"></span>
                    </div>
                </div>

                <div id="ext_result_area" style="margin-top:20px; display:none; max-width: 1000px;">
                    <div class="content-auto-section yali-card">
                        <h3><?php _e('任务状态', 'yali-ai-writer'); ?></h3>
                        <div id="ext_status" class="yali-panel" style="margin-bottom:20px;">
                            <p style="margin: 0;"><?php _e('等待发起请求...', 'yali-ai-writer'); ?></p>
                        </div>
                        
                        <h3><?php _e('返回结果', 'yali-ai-writer'); ?></h3>
                        <div id="ext_summary" class="yali-textarea-code" style="min-height: 200px; background:#fff;"></div>
                        <textarea id="hidden_ext_summary_data" style="display:none;"></textarea>
                    </div>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                // 标签切换逻辑
                $('.yali-tab-item').on('click', function(e) {
                    e.preventDefault();
                    var tabId = $(this).data('tab');
                    
                    $('.yali-tab-item').removeClass('active');
                    $(this).addClass('active');
                    
                    $('.tab-content').hide();
                    $('#tab-' + tabId).show();
                });
                
                // =============== 搜索引擎模式逻辑 ===============
                var currentSummary = '';

                function appendLog(logs) {
                    if (!logs || !logs.length) return;
                    var logHtml = '';
                    logs.forEach(function(l) {
                        logHtml += l + '<br>';
                    });
                    var $logDiv = $('#material_log');
                    $logDiv.append(logHtml);
                    $logDiv.scrollTop($logDiv[0].scrollHeight);
                }

                function doStep(topicId, step, retryCount) {
                    retryCount = retryCount || 0;
                    var maxRetries = 3;

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'content_auto_search_material_process',
                            topic_id: topicId,
                            step: step,
                            nonce: '<?php echo wp_create_nonce('content_auto_material_nonce'); ?>'
                        },
                        timeout: 180000, 
                        success: function(res) {
                            if (res.success) {
                                appendLog(res.data.log);
                                
                                if (res.data.data && res.data.data.summary) {
                                    currentSummary = res.data.data.summary;
                                    $('#hidden_summary_data').val(currentSummary);
                                    
                                    var summary = currentSummary;
                                    var html = summary
                                        .replace(/\n/g, '<br>')
                                        .replace(/^# (.*$)/gim, '<h1>$1</h1>')
                                        .replace(/^## (.*$)/gim, '<h2>$1</h2>')
                                        .replace(/^### (.*$)/gim, '<h3>$1</h3>')
                                        .replace(/\*\*(.*)\*\*/gim, '<b>$1</b>');

                                    $('#material_summary').html(html);
                                    $('#btn_save_material').show();
                                }

                                if (res.data.next_step && res.data.next_step !== 'done') {
                                    doStep(topicId, res.data.next_step, 0);
                                } else {
                                    $('#material_spinner').removeClass('is-active');
                                    $('#btn_start_material').prop('disabled', false).css('opacity', '');
                                    appendLog(['<strong>====== 流程全部结束 ======</strong>']);
                                }
                            } else {
                                var msg = res.data ? res.data.message : (res.message || 'Unknown Error');
                                appendLog(res.data && res.data.log ? res.data.log : []);
                                appendLog(['<span style="color:red; font-weight:bold;">错误中断: ' + msg + '</span>']);
                                $('#material_spinner').removeClass('is-active');
                                $('#btn_start_material').prop('disabled', false).css('opacity', '');
                            }
                        },
                        error: function(xhr, status, error) {
                            if (retryCount < maxRetries) {
                                var nextRetry = retryCount + 1;
                                appendLog(['<span style="color:#d63638;">请求失败 (' + status + ')，2秒后自动重试 (' + nextRetry + '/' + maxRetries + ')...</span>']);
                                setTimeout(function() {
                                    doStep(topicId, step, nextRetry);
                                }, 2000);
                                return;
                            }

                            $('#material_spinner').removeClass('is-active');
                            $('#btn_start_material').prop('disabled', false);
                            var errorMsg = error;
                            if (status === 'timeout') {
                                errorMsg = '请求超时 (超过180秒)。任务可能仍在后台运行，请刷新页面查看进度或重试。';
                            } else if (xhr.responseText) {
                                var match = xhr.responseText.match(/<b>Fatal error<\/b>:(.*?)<br/);
                                if (match) errorMsg = 'PHP错误: ' + match[1];
                            }
                            appendLog(['<span style="color:red;">❌ 网络请求最终失败: ' + errorMsg + '</span>']);
                            console.error('Task failed:', status, error, xhr.responseText);
                        }
                    });
                }

                $('#btn_start_material').on('click', function() {
                    var topicId = $('#topic_id').val();
                    if (!topicId) {
                        alert('<?php echo esc_js(__("请输入主题ID", "yali-ai-writer")); ?>');
                        return;
                    }
                    
                    $('#material_result_area').show();
                    $('#btn_save_material').hide();
                    $('#material_log').html('');
                    $('#material_summary').html('<p style="color:#666;"><?php echo esc_js(__("等待处理完成...", "yali-ai-writer")); ?></p>');
                    $('#material_spinner').addClass('is-active');
                    $(this).prop('disabled', true).css('opacity', '0.7');
                    
                    appendLog(['<strong><?php echo esc_js(__("开始执行任务...", "yali-ai-writer")); ?></strong>']);
                    doStep(topicId, 'init');
                });

                $('#btn_save_material').on('click', function() {
                    var topicId = $('#topic_id').val();
                    var summary = $('#hidden_summary_data').val();
                    
                    if (!topicId || !summary) {
                        alert('<?php echo esc_js(__("数据也不完整，无法保存", "yali-ai-writer")); ?>');
                        return;
                    }

                    if (!confirm('<?php echo esc_js(__("确定要将此内容保存为该主题的参考资料吗？原有资料将被覆盖。", "yali-ai-writer")); ?>')) {
                        return;
                    }

                    var $btn = $(this);
                    $btn.prop('disabled', true).text('<?php echo esc_js(__("正在保存...", "yali-ai-writer")); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'content_auto_save_material_result',
                            topic_id: topicId,
                            summary: summary,
                            nonce: '<?php echo wp_create_nonce('content_auto_material_nonce'); ?>'
                        },
                        success: function(res) {
                            $btn.prop('disabled', false).text('<?php echo esc_js(__("保存为主题参考资料", "yali-ai-writer")); ?>');
                            if (res.success) {
                                alert('<?php echo esc_js(__("保存成功！", "yali-ai-writer")); ?>');
                            } else {
                                alert('<?php echo esc_js(__("保存失败: ", "yali-ai-writer")); ?>' + (res.data.message || res.message || '<?php echo esc_js(__("未知错误", "yali-ai-writer")); ?>'));
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('<?php echo esc_js(__("保存为主题参考资料", "yali-ai-writer")); ?>');
                            alert('<?php echo esc_js(__("网络错误，保存失败", "yali-ai-writer")); ?>');
                        }
                    });
                });
                
                // =============== 浏览器插件模式逻辑 ===============
                var extSummary = '';
                var pollInterval = null;
                
                function updateExtStatus(status, type) {
                    var icons = {
                        'pending': '⏳',
                        'processing': '🔄',
                        'success': '✅',
                        'error': '❌'
                    };
                    var colors = {
                        'pending': '#666',
                        'processing': '#0073aa',
                        'success': '#46b450',
                        'error': '#dc3232'
                    };
                    $('#ext_status').html('<p style="margin: 0; color: ' + colors[type] + ';">' + icons[type] + ' ' + status + '</p>');
                }
                
                function startExtensionTask(topicId) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'content_auto_extension_material_process',
                            topic_id: topicId,
                            is_test: true, // Enable Test Mode
                            nonce: '<?php echo wp_create_nonce('content_auto_material_nonce'); ?>'
                        },
                        success: function(res) {
                            if (res.success) {
                                var taskId = res.data.task_id; // Capture Task ID
                                updateExtStatus('<?php echo esc_js(__("任务已分发 (ID: ", "yali-ai-writer")); ?>'+taskId.substring(0,8)+')，<?php echo esc_js(__("等待浏览器插件响应...", "yali-ai-writer")); ?>', 'pending');
                                
                                // 开始轮询检查结果
                                pollInterval = setInterval(function() {
                                    checkExtensionResult(taskId); // Pass Task ID instead of Topic ID
                                }, 3000);
                                
                                // 3分钟超时
                                setTimeout(function() {
                                    if (pollInterval) {
                                        clearInterval(pollInterval);
                                        pollInterval = null; // Mark explicitly as stopped
                                        updateExtStatus('<?php echo esc_js(__('请求超时。请确保浏览器插件已运行并连接。', 'yali-ai-writer')); ?>', 'error');
                                        $('#ext_spinner').removeClass('is-active');
                                        $('#btn_start_extension').prop('disabled', false).css('opacity', '');
                                    }
                                }, 180000);
                            } else {
                                updateExtStatus('<?php echo esc_js(__('任务分发失败', 'yali-ai-writer')); ?>: ' + (res.data.message || '<?php echo esc_js(__('未知错误', 'yali-ai-writer')); ?>'), 'error');
                                $('#ext_spinner').removeClass('is-active');
                                $('#btn_start_extension').prop('disabled', false);
                            }
                        },
                        error: function() {
                            updateExtStatus('<?php echo esc_js(__('网络请求失败', 'yali-ai-writer')); ?>', 'error');
                            $('#ext_spinner').removeClass('is-active');
                            $('#btn_start_extension').prop('disabled', false);
                        }
                    });
                }
                
                function checkExtensionResult(topicId) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'content_auto_check_task_result', // Use new check action
                            task_id: topicId, // variable name is topicId but parameter is task_id (passed from caller)
                            nonce: '<?php echo wp_create_nonce('content_auto_material_nonce'); ?>'
                        },
                        success: function(res) {
                            // Guard: If polling stopped (e.g. timeout occurred), ignore this late response
                            if (!pollInterval) return;

                            if (res.success && res.data.status === 'completed') {
                                clearInterval(pollInterval);
                                pollInterval = null;
                                
                                extSummary = res.data.result || '';
                                $('#hidden_ext_summary_data').val(extSummary);
                                
                                var html = extSummary
                                    .replace(/\n/g, '<br>')
                                    .replace(/^# (.*$)/gim, '<h1>$1</h1>')
                                    .replace(/^## (.*$)/gim, '<h2>$1</h2>')
                                    .replace(/^### (.*$)/gim, '<h3>$1</h3>')
                                    .replace(/\*\*(.*)\*\*/gim, '<b>$1</b>');
                                
                                $('#ext_summary').html(html);
                                $('#btn_save_ext_material').show();
                                
                                updateExtStatus('<?php echo esc_js(__('知识库搜索完成', 'yali-ai-writer')); ?>', 'success');
                                $('#ext_spinner').removeClass('is-active');
                                $('#btn_start_extension').prop('disabled', false).css('opacity', '');
                            } else if (res.data && res.data.status === 'waiting_for_extension') {
                                updateExtStatus('<?php echo esc_js(__('等待浏览器插件处理中...', 'yali-ai-writer')); ?>', 'processing');
                            } else if (res.data && res.data.status === 'failed') {
                                clearInterval(pollInterval);
                                pollInterval = null;
                                var errMsg = res.data.error || '<?php echo esc_js(__('未知错误', 'yali-ai-writer')); ?>';
                                updateExtStatus('<?php echo esc_js(__('搜索失败', 'yali-ai-writer')); ?>: ' + errMsg, 'error');
                                $('#ext_spinner').removeClass('is-active');
                                $('#btn_start_extension').prop('disabled', false).css('opacity', '');
                            }
                        }
                    });
                }
                
                $('#btn_start_extension').on('click', function() {
                    var topicId = $('#ext_topic_id').val();
                    if (!topicId) {
                        alert('<?php echo esc_js(__('请输入主题ID', 'yali-ai-writer')); ?>');
                        return;
                    }

                    $('#ext_result_area').show();
                    $('#btn_save_ext_material').hide();
                    $('#ext_summary').html('<p style="color:#666;"><?php echo esc_js(__('正在知识库中搜索...', 'yali-ai-writer')); ?></p>');
                    $('#ext_spinner').addClass('is-active');
                    $(this).prop('disabled', true).css('opacity', '0.7');
                    
                    updateExtStatus('<?php echo esc_js(__("正在连接浏览器插件...", "yali-ai-writer")); ?>', 'processing');
                    startExtensionTask(topicId);
                });
                
                $('#btn_save_ext_material').on('click', function() {
                    var topicId = $('#ext_topic_id').val();
                    var summary = $('#hidden_ext_summary_data').val();
                    
                    if (!topicId || !summary) {
                        alert('<?php echo esc_js(__("数据不完整，无法保存", "yali-ai-writer")); ?>');
                        return;
                    }

                    if (!confirm('<?php echo esc_js(__("确定要将此内容保存为该主题的参考资料吗？原有资料将被覆盖。", "yali-ai-writer")); ?>')) {
                        return;
                    }

                    var $btn = $(this);
                    $btn.prop('disabled', true).text('<?php echo esc_js(__("正在保存...", "yali-ai-writer")); ?>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'content_auto_save_material_result',
                            topic_id: topicId,
                            summary: summary,
                            nonce: '<?php echo wp_create_nonce('content_auto_material_nonce'); ?>'
                        },
                        success: function(res) {
                            $btn.prop('disabled', false).text('<?php echo esc_js(__("保存为主题参考资料", "yali-ai-writer")); ?>');
                            if (res.success) {
                                alert('<?php echo esc_js(__("保存成功！", "yali-ai-writer")); ?>');
                            } else {
                                alert('<?php echo esc_js(__("保存失败: ", "yali-ai-writer")); ?>' + (res.data.message || res.message || '<?php echo esc_js(__("未知错误", "yali-ai-writer")); ?>'));
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('<?php echo esc_js(__("保存为主题参考资料", "yali-ai-writer")); ?>');
                            alert('<?php echo esc_js(__("网络错误，保存失败", "yali-ai-writer")); ?>');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    public function handle_ajax_process() {
        check_ajax_referer('content_auto_material_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'yali-ai-writer')]);
        }
        
        $topic_id = intval($_POST['topic_id']);
        $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : 'init';

        if (!$topic_id) {
            wp_send_json_error(['message' => __('无效的主题ID', 'yali-ai-writer')]);
        }
        
        if (!class_exists('ContentAuto_SearchMaterialsService')) {
            require_once plugin_dir_path(__FILE__) . 'class-search-materials-service.php';
        }
        
        $service = new ContentAuto_SearchMaterialsService();
        $result = $service->process_step($step, $topic_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    public function handle_ajax_save() {
        check_ajax_referer('content_auto_material_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'yali-ai-writer')]);
        }

        $topic_id = intval($_POST['topic_id']);
        $summary = isset($_POST['summary']) ? wp_unslash($_POST['summary']) : '';

        if (!$topic_id || empty($summary)) {
            wp_send_json_error(['message' => __('参数无效', 'yali-ai-writer')]);
        }

        if (!class_exists('ContentAuto_SearchMaterialsService')) {
            require_once plugin_dir_path(__FILE__) . 'class-search-materials-service.php';
        }
        
        $service = new ContentAuto_SearchMaterialsService();
        $result = $service->save_material_to_topic($topic_id, $summary);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success(['message' => __('保存成功', 'yali-ai-writer')]);
        }
    }
    
    /**
     * 处理浏览器插件模式的请求
     */
    public function handle_extension_ajax_process() {
        check_ajax_referer('content_auto_material_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'yali-ai-writer')]);
        }
        
        $topic_id = intval($_POST['topic_id']);
        $check_only = isset($_POST['check_only']) && $_POST['check_only'] === 'true';

        if (!$topic_id) {
            wp_send_json_error(['message' => __('无效的主题ID', 'yali-ai-writer')]);
        }
        
        global $wpdb;
        $topics_table = $wpdb->prefix . 'content_auto_topics';
        
        // 获取主题信息
        $topic = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$topics_table} WHERE id = %d",
            $topic_id
        ), ARRAY_A);
        
        if (!$topic) {
            wp_send_json_error(['message' => __('主题不存在', 'yali-ai-writer')]);
        }
        
        // 如果只是检查状态
        if ($check_only) {
            $status = $topic['material_search_status'] ?? 'none';
            $result = $topic['reference_material'] ?? '';
            
            wp_send_json_success([
                'status' => $status,
                'result' => $result,
                'error'  => $topic['material_search_error'] ?? ''
            ]);
            return;
        }
        
        // 分发任务到浏览器插件
        if (!defined('CONTENT_AUTO_MANAGER_PLUGIN_DIR')) {
            wp_send_json_error(['message' => __('环境错误：PLUGIN_DIR未定义', 'yali-ai-writer')]);
        }
        
        // 加载必需的控制器类（包括父类）
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'rest-api/controllers/class-base-controller.php';
        require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'rest-api/controllers/class-task-controller.php';
        
        $query = $topic['title'];
        
        $payload = array(
            'topic_id' => $topic_id,
            'query' => $query,
            'action' => 'knowledge_search',
            'is_test' => isset($_POST['is_test']) && $_POST['is_test'] === 'true'
        );
        
        // 实例化 Task_Controller 并传递正确的 namespace 参数
        $task_controller = new \ContentAutoManager\RestApi\Controllers\Task_Controller('content-auto-manager/v1');
        $task_id = $task_controller->add_task('knowledge_search', $payload);
        
        // 仅在非测试模式下更新数据库状态
        if (!$payload['is_test']) {
            $wpdb->update($topics_table, [
                'material_search_status' => 'waiting_for_extension'
            ], ['id' => $topic_id]);
        }
        
        wp_send_json_success([
            'message' => __('任务已分发', 'yali-ai-writer'),
            'task_id' => $task_id,
            'status' => 'waiting_for_extension'
        ]);
    }
    public function handle_check_task_result() {
        check_ajax_referer('content_auto_material_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('权限不足', 'yali-ai-writer')]);
        }
        
        $task_id = isset($_POST['task_id']) ? sanitize_text_field($_POST['task_id']) : '';
        if (!$task_id) {
            wp_send_json_error(['message' => 'Task ID required']);
        }

        // Direct check from result storage
        $results = get_option('cam_extension_task_results', array());
        
        if (isset($results[$task_id])) {
             $res = $results[$task_id];
             $content = '';
             $error = '';
             
             if (is_array($res)) {
                 $content = $res['answer'] ?? '';
                 $error = $res['error'] ?? '';
             } else {
                 $content = $res;
             }

             if ($content === 'NO_CONTEXT_ANSWER' || strpos($content, 'NO_CONTEXT_ANSWER') !== false) {
                 wp_send_json_success(['status' => 'failed', 'error' => __('没有找到相关参考资料 (No Context Found)', 'yali-ai-writer')]);
             } elseif ($error) {
                 wp_send_json_success(['status' => 'failed', 'error' => $error]);
             } else {
                 wp_send_json_success(['status' => 'completed', 'result' => $content]);
             }
        } else {
             wp_send_json_success(['status' => 'waiting_for_extension']);
        }
    }
}
