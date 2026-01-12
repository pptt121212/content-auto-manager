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
    }

    public function register_menu() {
        add_submenu_page(
            'content-auto-manager',
            '搜索物料',
            '搜索物料',
            'manage_options',
            'content-auto-search-materials',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>搜索物料 (Search Materials)</h1>
            <p>输入主题ID，系统将自动搜索、筛选、抓取并汇总相关资料。</p>
            
            <div class="card" style="max-width: 800px; padding: 20px;">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="topic_id">主题ID</label></th>
                        <td>
                            <input name="topic_id" type="number" id="topic_id" value="" class="regular-text">
                            <p class="description">请输入数据库中存在的主题ID。</p>
                        </td>
                    </tr>
                </table>
                <div style="margin-top: 15px;">
                    <button type="button" id="btn_start_material" class="button button-primary button-large">开始搜集与整理</button>
                    <button type="button" id="btn_save_material" class="button button-secondary button-large" style="display:none; margin-left: 10px;">保存为主题参考资料</button>
                    <span class="spinner" id="material_spinner" style="float:none;"></span>
                </div>
            </div>

            <div id="material_result_area" style="margin-top:20px; display:none; max-width: 1000px;">
                <div class="card" style="padding:20px;">
                    <h3>处理日志 (实时)</h3>
                    <div id="material_log" style="background:#f6f7f7; padding:10px; border:1px solid #ddd; height:300px; overflow-y:auto; font-family:monospace; margin-bottom:20px;"></div>
                    
                    <h3>汇总结果</h3>
                    <div id="material_summary" style="padding:20px; border:1px solid #e5e5e5; background:#fff; min-height: 200px;"></div>
                    <textarea id="hidden_summary_data" style="display:none;"></textarea>
                </div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                var currentSummary = '';

                function appendLog(logs) {
                    if (!logs || !logs.length) return;
                    var logHtml = '';
                    logs.forEach(function(l) {
                        logHtml += l + '<br>';
                    });
                    var $logDiv = $('#material_log');
                    $logDiv.append(logHtml);
                    $logDiv.scrollTop($logDiv[0].scrollHeight); // Auto scroll
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
                                    // 成功，重置重试计数，继续下一步
                                    doStep(topicId, res.data.next_step, 0);
                                } else {
                                    $('#material_spinner').removeClass('is-active');
                                    $('#btn_start_material').prop('disabled', false).text('重新开始');
                                    appendLog(['<strong>====== 流程全部结束 ======</strong>']);
                                }
                            } else {
                                // 业务逻辑错误（如参数不对），不重试
                                var msg = res.data ? res.data.message : (res.message || 'Unknown Error');
                                appendLog(res.data && res.data.log ? res.data.log : []);
                                appendLog(['<span style="color:red; font-weight:bold;">错误中断: ' + msg + '</span>']);
                                $('#material_spinner').removeClass('is-active');
                                $('#btn_start_material').prop('disabled', false);
                            }
                        },
                        error: function(xhr, status, error) {
                            //如果是网络错误或超时，尝试重试
                            if (retryCount < maxRetries) {
                                var nextRetry = retryCount + 1;
                                appendLog(['<span style="color:#d63638;">请求失败 (' + status + ')，2秒后自动重试 (' + nextRetry + '/' + maxRetries + ')...</span>']);
                                setTimeout(function() {
                                    doStep(topicId, step, nextRetry);
                                }, 2000);
                                return;
                            }

                            // 重试耗尽，显示错误
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
                        alert('请输入主题ID');
                        return;
                    }
                    
                    $('#material_result_area').show();
                    $('#btn_save_material').hide();
                    $('#material_log').html(''); // Clear logs
                    $('#material_summary').html('<p style="color:#666;">等待处理完成...</p>');
                    $('#material_spinner').addClass('is-active');
                    $(this).prop('disabled', true).text('处理中...');
                    
                    appendLog(['<strong>开始执行任务...</strong>']);
                    
                    // Start from 'init'
                    doStep(topicId, 'init');
                });

                // Save Handler
                $('#btn_save_material').on('click', function() {
                    var topicId = $('#topic_id').val();
                    var summary = $('#hidden_summary_data').val();
                    
                    if (!topicId || !summary) {
                        alert('数据也不完整，无法保存');
                        return;
                    }

                    if (!confirm('确定要将此内容保存为该主题的参考资料吗？原有资料将被覆盖。')) {
                        return;
                    }

                    var $btn = $(this);
                    $btn.prop('disabled', true).text('正在保存...');

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
                            $btn.prop('disabled', false).text('保存为主题参考资料');
                            if (res.success) {
                                alert('保存成功！');
                            } else {
                                alert('保存失败: ' + (res.data.message || res.message || '未知错误'));
                            }
                        },
                        error: function() {
                            $btn.prop('disabled', false).text('保存为主题参考资料');
                            alert('网络错误，保存失败');
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
            wp_send_json_error(['message' => '权限不足']);
        }
        
        $topic_id = intval($_POST['topic_id']);
        $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : 'init';

        if (!$topic_id) {
            wp_send_json_error(['message' => '无效的主题ID']);
        }
        
        if (!class_exists('ContentAuto_SearchMaterialsService')) {
            require_once plugin_dir_path(__FILE__) . 'class-search-materials-service.php';
        }
        
        $service = new ContentAuto_SearchMaterialsService();
        // Use the new process_step method
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
            wp_send_json_error(['message' => '权限不足']);
        }

        $topic_id = intval($_POST['topic_id']);
        $summary = isset($_POST['summary']) ? wp_unslash($_POST['summary']) : '';

        if (!$topic_id || empty($summary)) {
            wp_send_json_error(['message' => '参数无效']);
        }

        if (!class_exists('ContentAuto_SearchMaterialsService')) {
            require_once plugin_dir_path(__FILE__) . 'class-search-materials-service.php';
        }
        
        $service = new ContentAuto_SearchMaterialsService();
        $result = $service->save_material_to_topic($topic_id, $summary);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            wp_send_json_success(['message' => '保存成功']);
        }
    }
}
