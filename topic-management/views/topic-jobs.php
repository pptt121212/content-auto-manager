<?php
/**
 * 主题任务页面
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。', 'yali-ai-writer'));
}

// 获取筛选参数
$status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : 'all';

// 处理表单提交
if (isset($_POST['submit']) && isset($_POST['content_auto_manager_nonce'])) {
    // 验证nonce
    if (!wp_verify_nonce($_POST['content_auto_manager_nonce'], 'content_auto_manager_topic_jobs')) {
        wp_die(__('安全验证失败。', 'yali-ai-writer'));
    }
    
    // 获取表单数据
    $rule_id = intval($_POST['rule_id']);
    $topic_count = intval($_POST['topic_count']);
    
    // 验证数据
    if (empty($rule_id) || empty($topic_count) || $topic_count <= 0) {
        echo '<script>window.addEventListener("load", function() { window.yaliToast("' . esc_js(__('请填写所有必填字段。', 'yali-ai-writer')) . '", "error"); });</script>';
    } else {
        // 创建主题生成任务
        $topic_task_manager = new ContentAuto_TopicTaskManager();
        $task_id = $topic_task_manager->create_topic_task($rule_id, $topic_count);
        
        if ($task_id) {
            echo '<script>window.addEventListener("load", function() { window.yaliToast("' . esc_js(__('主题生成任务已创建。', 'yali-ai-writer')) . '", "success"); });</script>';
        } else {
            echo '<script>window.addEventListener("load", function() { window.yaliToast("' . esc_js(__('主题生成任务创建失败。', 'yali-ai-writer')) . '", "error"); });</script>';
        }
    }
}



// 获取启用的规则
$rule_manager = new ContentAuto_RuleManager();
$rules = $rule_manager->get_active_rules();

// 获取发布规则配置（用于检查是否启用了插件模式）
$db_access = new ContentAuto_Database();
$publish_rules = $db_access->get_row('content_auto_publish_rules', array('id' => 1));
$extension_rag_global = (isset($publish_rules['material_collection_mode']) && $publish_rules['material_collection_mode'] === 'extension_rag');

// 准备规则类型映射，用于前端 JS 判断
$rule_type_map = [];
foreach ($rules as $r) {
    $rule_type_map[$r->id] = $r->rule_type;
}


// 获取所有主题任务并根据状态筛选
$topic_task_manager = new ContentAuto_TopicTaskManager();

// 构建查询条件
$where_clause = '';
$where_values = array();

if ($status_filter !== 'all') {
    $where_clause = 'WHERE status = %s';
    $where_values = array($status_filter);
}

$tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
$query = "SELECT * FROM $tasks_table $where_clause ORDER BY created_at DESC";

if (!empty($where_values)) {
    $query = $wpdb->prepare($query, $where_values);
}

$tasks = $wpdb->get_results($query, ARRAY_A);

/**
 * 获取任务失败原因
 */
function get_topic_task_failure_reason($task_id) {
    global $wpdb;
    
    $tasks_table = $wpdb->prefix . 'content_auto_topic_tasks';
    
    // 支持两种ID格式：数字ID或topic_task_id字符串
    if (is_numeric($task_id)) {
        $task = $wpdb->get_row($wpdb->prepare("SELECT error_message, rule_id FROM {$tasks_table} WHERE id = %d", $task_id));
    } else {
        $task = $wpdb->get_row($wpdb->prepare("SELECT error_message, rule_id FROM {$tasks_table} WHERE topic_task_id = %s", $task_id));
    }
    
    // 如果有明确的错误信息，美化显示
    if ($task && !empty($task->error_message)) {
        $error_msg = $task->error_message;
        
        // 美化常见错误信息，使其更易理解
        if (strpos($error_msg, '主题数据字段不完整') !== false) {
            return __('AI返回数据格式不完整，建议重试', 'yali-ai-writer');
        }
        if (strpos($error_msg, 'JSON') !== false || strpos($error_msg, 'json') !== false) {
            return __('AI返回数据解析失败，建议重试', 'yali-ai-writer');
        }
        if (strpos($error_msg, 'timeout') !== false || strpos($error_msg, '超时') !== false) {
            return __('API请求超时，建议重试', 'yali-ai-writer');
        }
        if (strpos($error_msg, 'rate limit') !== false || strpos($error_msg, '限流') !== false) {
            return __('API请求限流，请稍后重试', 'yali-ai-writer');
        }
        
        // 如果错误信息太长，截断显示
        if (mb_strlen($error_msg) > 50) {
            return mb_substr($error_msg, 0, 47) . '...';
        }
        
        return $error_msg;
    }
    
    // 检查API配置
    $api_configs_table = $wpdb->prefix . 'content_auto_api_configs';
    $active_api = $wpdb->get_var("SELECT COUNT(*) FROM {$api_configs_table} WHERE is_active = 1");
    
    if ($active_api == 0) {
        return __('没有激活的API配置', 'yali-ai-writer');
    }
    
    // 检查规则状态
    if ($task) {
        $rules_table = $wpdb->prefix . 'content_auto_rules';
        $rule = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$rules_table} WHERE id = %d", $task->rule_id));
        if ($rule && $rule->status == 0) {
            return __('关联的规则已被禁用', 'yali-ai-writer');
        }
        
        // 检查规则是否存在
        if (!$rule) {
            return __('关联的规则不存在', 'yali-ai-writer');
        }
    } else {
        return __('无法获取任务详情', 'yali-ai-writer');
    }
    
    return __('处理过程中出现未知错误，可尝试重试', 'yali-ai-writer');
}

/**
 * 获取任务状态标签
 */
function get_topic_job_status_label($status) {
    switch ($status) {
        case 'pending':
            return __('待处理', 'yali-ai-writer');
        case 'running':
            return __('运行中', 'yali-ai-writer');
        case 'processing':
            return __('处理中', 'yali-ai-writer');
        case 'completed':
            return __('已完成', 'yali-ai-writer');
        case 'failed':
            return __('失败', 'yali-ai-writer');
        case 'paused':
            return __('已暂停', 'yali-ai-writer');
        case 'cancelled':
            return __('已取消', 'yali-ai-writer');
        default:
            return $status;
    }
}

/**
 * 获取规则类型的正确名称
 */
function get_rule_type_name($rule) {
    if (!$rule) {
        return __('规则不存在', 'yali-ai-writer');
    }
    
    return ContentAuto_RuleManager::get_rule_type_label($rule->rule_type);
}
?>

<div class="wrap yali-plugin-wrapper">
      <h1 class="yali-page-title"><span class="dashicons dashicons-list-view"></span> <?php _e('主题任务', 'yali-ai-writer'); ?></h1>
    
    <!-- 创建任务表单 -->
    <div class="yali-card">
        <h2><?php _e('创建主题生成任务', 'yali-ai-writer'); ?></h2>
        
        <form method="post" action="">
            <?php wp_nonce_field('content_auto_manager_topic_jobs', 'content_auto_manager_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('选择规则', 'yali-ai-writer'); ?></th>
                    <td>
                        <?php if (empty($rules)): ?>
                            <p><?php _e('暂无启用的规则，请先创建并启用规则。', 'yali-ai-writer'); ?></p>
                        <?php else: ?>
                            <select name="rule_id" class="regular-text yali-select" required>
                                <option value=""><?php _e('请选择规则', 'yali-ai-writer'); ?></option>
                                <?php foreach ($rules as $rule): ?>
                                    <option value="<?php echo esc_attr($rule->id); ?>">
                                        <?php echo esc_html($rule->rule_name); ?> (<?php echo esc_html(get_rule_type_name($rule)); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <!-- 插件提醒区块 -->
                            <div id="browser-extension-notice" class="yali-notice yali-notice-info" style="display: none;">
                                <h4>
                                    <span class="dashicons dashicons-external"></span>
                                    <?php _e('需要开启浏览器插件', 'yali-ai-writer'); ?>
                                </h4>
                                <p>
                                    <?php _e('当前任务包含“网址采集”或“知识库搜索”，需要浏览器插件配合执行。', 'yali-ai-writer'); ?><br>
                                    <strong><?php _e('请确保已安装并开启：', 'yali-ai-writer'); ?><a href="https://www.yaliai.com/downs/yali-ai-writer-extension.zip" target="_blank" rel="noopener" class="yali-link"><?php _e('鸭梨AI浏览器扩展', 'yali-ai-writer'); ?></a></strong>
                                </p>
                            </div>

                            <p class="description yali-desc">
                                <?php _e('注意：如果在任务执行过程中修改规则，任务将使用修改后的规则内容继续执行。', 'yali-ai-writer'); ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('生成主题数量', 'yali-ai-writer'); ?></th>
                    <td>
                        <input type="number" name="topic_count" value="<?php echo CONTENT_AUTO_DEFAULT_TOPIC_COUNT; ?>" min="1" max="100" class="small-text yali-input" required <?php echo empty($rules) ? 'disabled' : ''; ?>>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('创建任务', 'yali-ai-writer'), 'primary yali-btn yali-btn-primary'); ?>
        </form>
    </div>
    
    <!-- 任务列表 -->
    <div class="yali-card">
        <h2><?php _e('任务列表', 'yali-ai-writer'); ?></h2>
        
        <!-- 筛选器 -->
        <div class="tablenav top">
            <form method="get" action="" style="display: inline-block;">
                <input type="hidden" name="page" value="yali-ai-writer-topic-jobs">
                <div class="alignleft actions">
                    <div class="yali-filter-group">
                        <select name="status_filter" id="status_filter" class="yali-select">
                            <option value="all"><?php _e('所有状态', 'yali-ai-writer'); ?></option>
                            <option value="failed" <?php selected($status_filter, 'failed'); ?>><?php _e('失败', 'yali-ai-writer'); ?></option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>><?php _e('待处理', 'yali-ai-writer'); ?></option>
                            <option value="processing" <?php selected($status_filter, 'processing'); ?>><?php _e('处理中', 'yali-ai-writer'); ?></option>
                            <option value="completed" <?php selected($status_filter, 'completed'); ?>><?php _e('已完成', 'yali-ai-writer'); ?></option>
                        </select>
                        <input type="submit" class="button yali-btn yali-btn-primary" value="<?php esc_attr_e('筛选', 'yali-ai-writer'); ?>">
                    </div>
                </div>
            </form>
            

        </div>
        
        <?php if (empty($tasks)): ?>
            <p><?php _e('暂无主题生成任务。', 'yali-ai-writer'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat striped yali-table topic-jobs-table">
                <thead>
                    <tr>
                        <th class="check-column">
                            <input type="checkbox" id="select_all_tasks">
                        </th>
                        <th><?php _e('主题任务ID', 'yali-ai-writer'); ?></th>
                        <th><?php _e('规则', 'yali-ai-writer'); ?></th>
                        <th><?php _e('进度', 'yali-ai-writer'); ?></th>
                        <th><?php _e('主题数量', 'yali-ai-writer'); ?></th>
                        <th><?php _e('状态', 'yali-ai-writer'); ?></th>
                        <th><?php _e('创建时间', 'yali-ai-writer'); ?></th>
                        <th><?php _e('操作', 'yali-ai-writer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $task): ?>
                        <?php
                        // 获取规则信息
                        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}content_auto_rules WHERE id = %d", $task['rule_id']));
                        
                        // 获取进度信息
                        $progress = $topic_task_manager->get_task_progress($task['topic_task_id']);
                        ?>
                        <tr class="task-row <?php echo $task['status'] === 'failed' ? 'failed-task' : ''; ?>" data-task-id="<?php echo esc_attr($task['topic_task_id']); ?>">
                            <td>
                                <?php if ($task['status'] === 'failed'): ?>
                                    <input type="checkbox" name="task_ids[]" value="<?php echo esc_attr($task['topic_task_id']); ?>" class="task-checkbox">
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($task['topic_task_id']); ?></td>
                            <td>
                                <?php if ($rule): ?>
                                    <?php echo esc_html($rule->rule_name); ?> 
                                    (<?php echo esc_html(get_rule_type_name($rule)); ?>)
                                <?php else: ?>
                                    <?php _e('规则不存在', 'yali-ai-writer'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($progress): ?>
                                    <div class="progress-info">
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $progress['progress_percentage']; ?>%"></div>
                                        </div>
                                        <div class="progress-topics" style="text-align: center; margin-top: 4px; font-size: 12px; color: #666;">
                                            <?php printf('%d/%d', $progress['generated_topics'], $progress['expected_topics']); ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php _e('无进度信息', 'yali-ai-writer'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php printf(__('每项目%d个 (总计%d个)', 'yali-ai-writer'), $task['topic_count_per_item'], $task['total_expected_topics']); ?>
                            </td>
                            <td>
                                <?php
                                $status_label = get_topic_job_status_label($task['status']);
                                $status_class = 'yali-badge yali-badge-neutral';
                                if ($task['status'] === 'running' || $task['status'] === 'processing') {
                                    $status_class = 'yali-badge yali-badge-warning';
                                } elseif ($task['status'] === 'completed') {
                                    $status_class = 'yali-badge yali-badge-success';
                                } elseif ($task['status'] === 'failed') {
                                    $status_class = 'yali-badge yali-badge-error';
                                }
                                ?>
                                <span class="task-status <?php echo $status_class; ?>" data-status="<?php echo esc_attr($task['status']); ?>">
                                    <?php echo $status_label; ?>
                                </span>
                                <?php if ($task['status'] === CONTENT_AUTO_STATUS_FAILED): ?>
                                    <br><span class="failure-reason task-error"><?php echo esc_html(get_topic_task_failure_reason($task['topic_task_id'])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo content_auto_manager_format_time($task['created_at']); ?></td>
                            <td>
                                <div class="task-controls" data-task-id="<?php echo esc_attr($task['topic_task_id']); ?>">
                                    <?php // 暂停：只在任务是"待处理"、"运行中"或"处理中"时显示。 ?>
                                    <?php if (in_array($task['status'], ['pending', 'running', 'processing'])) : ?>
                                        <button class="yali-btn-small yali-btn-secondary" type="button" 
                                                data-yali-action="ajax" 
                                                data-yali-ajax-action="content_auto_pause_task" 
                                                data-yali-param-task_id="<?php echo esc_attr($task['topic_task_id']); ?>" 
                                                data-yali-reload="true" 
                                                data-yali-confirm="<?php echo esc_attr(__('确定要暂停此任务吗？', 'yali-ai-writer')); ?>">
                                            <?php _e('暂停', 'yali-ai-writer'); ?>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php // 恢复：只在任务是“已暂停”时显示。 ?>
                                    <?php if ($task['status'] === 'paused') : ?>
                                        <button class="yali-btn-small yali-btn-secondary" type="button" 
                                                data-yali-action="ajax" 
                                                data-yali-ajax-action="content_auto_resume_task" 
                                                data-yali-param-task_id="<?php echo esc_attr($task['topic_task_id']); ?>" 
                                                data-yali-reload="true" 
                                                data-yali-confirm="<?php echo esc_attr(__('确定要恢复此任务吗？', 'yali-ai-writer')); ?>">
                                            <?php _e('恢复', 'yali-ai-writer'); ?>
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php // 重试：只在任务是“失败”时显示。 ?>
                                    <?php if ($task['status'] === 'failed') : ?>
                                        <button class="yali-btn-small yali-btn-secondary" type="button" 
                                                data-yali-action="ajax" 
                                                data-yali-ajax-action="content_auto_retry_task" 
                                                data-yali-param-task_id="<?php echo esc_attr($task['topic_task_id']); ?>" 
                                                data-yali-reload="true" 
                                                data-yali-confirm="<?php echo esc_attr(__('确定要重试此任务吗？系统将只重试该任务下所有失败的子任务。', 'yali-ai-writer')); ?>">
                                            <?php _e('重试', 'yali-ai-writer'); ?>
                                        </button>
                                    <?php endif; ?>

                                    <?php // 删除：在任务不是“已完成”的任何状态下，都应该可以删除。 ?>
                                    <?php if ($task['status'] !== 'completed') : ?>
                                        <button class="yali-btn yali-btn-small yali-btn-danger" type="button" 
                                                style="background: transparent !important; color: var(--yali-error) !important; border-color: var(--yali-border) !important;" 
                                                data-yali-action="delete"
                                                data-yali-ajax-action="content_auto_delete_task"
                                                data-yali-id="<?php echo esc_attr($task['topic_task_id']); ?>"
                                                data-yali-id-param="task_id"
                                                data-yali-confirm="<?php echo esc_attr(__('确定要删除此任务吗？注意：任务记录将被删除，但已生成的主题数据仍会保留。', 'yali-ai-writer')); ?>">
                                            <?php _e('删除', 'yali-ai-writer'); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- 批量操作按钮 -->
            <div class="bulk-actions">
                <button class="yali-btn yali-btn-primary bulk-retry-tasks" disabled>
                    <?php _e('批量重试选中任务', 'yali-ai-writer'); ?>
                </button>
                <span class="bulk-actions-info"></span>
            </div>
        <?php endif; ?>
    </div>
</div>



<script>
document.addEventListener('DOMContentLoaded', function() {
    // 注入 i18n
    window.camTopicJobsI18n = {
        selectedTasks: '<?php _e('已选择 %s 个任务', 'yali-ai-writer'); ?>',
        pleaseSelectTask: '<?php _e('请至少选择一个任务进行重试', 'yali-ai-writer'); ?>',
        confirmBulkRetry: '<?php _e('确定要重试选中的 %s 个任务吗？', 'yali-ai-writer'); ?>',
        processing: '<?php _e('处理中...', 'yali-ai-writer'); ?>',
        bulkRetrySubmitted: '<?php _e('批量重试请求已提交，任务将在后台处理。', 'yali-ai-writer'); ?>',
        bulkRetryFailed: '<?php _e('批量重试失败: ', 'yali-ai-writer'); ?>',
        unknownError: '<?php _e('未知错误', 'yali-ai-writer'); ?>',
        serverError: '<?php _e('批量重试失败: 服务器错误', 'yali-ai-writer'); ?>',
        collectUrlRewriteNote: '<?php esc_attr_e('采集网址仿写规则每次仅生成1个主题', 'yali-ai-writer'); ?>'
    };

    // 规则类型映射与插件状态
    const ruleTypeMap = <?php echo json_encode($rule_type_map); ?>;
    
    // 规则是否有参考资料映射
    <?php
    $rule_reference_map = [];
    foreach ($rules as $r) {
        $rule_reference_map[$r->id] = !empty(trim($r->reference_material));
    }
    ?>
    const ruleReferenceMap = <?php echo json_encode($rule_reference_map); ?>;
    
    // 全局是否开启了插件 RAG 模式
    const extensionRagGlobal = <?php echo $extension_rag_global ? 'true' : 'false'; ?>;
    
    // 规则选择交互逻辑
    const ruleSelect = document.querySelector('select[name="rule_id"]');
    const extensionNotice = document.getElementById('browser-extension-notice');
    
    if (ruleSelect && extensionNotice) {
        ruleSelect.addEventListener('change', function() {
            const ruleId = this.value;
            if (!ruleId) {
                extensionNotice.style.display = 'none';
                return;
            }
            
            const ruleType = ruleTypeMap[ruleId] || '';
            const hasRuleReference = ruleReferenceMap[ruleId] || false;
            
            // 锁定生成数量逻辑：如果是采集网址仿写，强制锁定为1
            const topicCountInput = document.querySelector('input[name="topic_count"]');
            if (ruleType === 'collect_url_rewrite') {
                if (topicCountInput) {
                    topicCountInput.value = 1;
                    topicCountInput.readOnly = true;
                    topicCountInput.title = camTopicJobsI18n.collectUrlRewriteNote;
                    topicCountInput.style.backgroundColor = '#f0f0f1';
                }
            } else if (topicCountInput) {
                topicCountInput.readOnly = false;
                topicCountInput.title = '';
                topicCountInput.style.backgroundColor = '';
            }

            // 判断是否需要显示插件提示
            // 1. 如果是采集网址仿写，必须要插件来采集URL内容
            const isCollectUrl = (ruleType === 'collect_url_rewrite');
            
            // 2. 如果全局开启了知识库搜索，且规则自身没有配置参考资料（因为规则参考资料优先级高于全局），则需要插件
            const needsRag = extensionRagGlobal && !hasRuleReference;
            
            if (isCollectUrl || needsRag) {
                extensionNotice.style.display = 'block';
            } else {
                extensionNotice.style.display = 'none';
            }
        });
    }

    // 全选/取消全选功能
    const selectAllCheckbox = document.getElementById('select_all_tasks');
    const taskCheckboxes = document.querySelectorAll('.task-checkbox');
    const bulkRetryButton = document.querySelector('.bulk-retry-tasks');
    const bulkActionsInfo = document.querySelector('.bulk-actions-info');

    if (selectAllCheckbox && taskCheckboxes.length > 0) {
        // 全选功能
        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            taskCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            updateBulkActions();
        });

        // 单个复选框变化时更新全选状态
        taskCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateBulkActions);
        });

        // 更新批量操作按钮状态
        function updateBulkActions() {
            const checkedCount = document.querySelectorAll('.task-checkbox:checked').length;
            
            if (bulkRetryButton) {
                bulkRetryButton.disabled = checkedCount === 0;
            }
            
            if (bulkActionsInfo) {
                bulkActionsInfo.textContent = checkedCount > 0 ? 
                    camTopicJobsI18n.selectedTasks.replace('%s', checkedCount) : '';
            }
        }

        // 批量重试功能
        if (bulkRetryButton) {
            bulkRetryButton.addEventListener('click', function(e) {
                e.preventDefault();
                
                const checkedBoxes = document.querySelectorAll('.task-checkbox:checked');
                if (checkedBoxes.length === 0) {
                    alert(camTopicJobsI18n.pleaseSelectTask);
                    return;
                }
                
                if (!confirm(camTopicJobsI18n.confirmBulkRetry.replace('%s', checkedBoxes.length))) {
                    return;
                }
                
                const taskIds = Array.from(checkedBoxes).map(cb => cb.value);
                
                // 显示加载状态，保留原文字，加上半透明效果
                bulkRetryButton.disabled = true;
                bulkRetryButton.style.opacity = '0.7';
                
                // 发送AJAX请求
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'content_auto_bulk_retry_topic_tasks',
                        task_ids: taskIds,
                        nonce: '<?php echo wp_create_nonce("content_auto_manager_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(camTopicJobsI18n.bulkRetrySubmitted);
                            location.reload();
                        } else {
                            alert(camTopicJobsI18n.bulkRetryFailed + (response.data?.message || camTopicJobsI18n.unknownError));
                        }
                    },
                    error: function() {
                        alert(camTopicJobsI18n.serverError);
                    },
                    complete: function() {
                        bulkRetryButton.disabled = false;
                        bulkRetryButton.style.opacity = '';
                    }
                });
            });
        }
    }
});
</script>

</body>
</html>
