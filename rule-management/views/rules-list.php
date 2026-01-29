<?php
global $wpdb;
$rules_table = $wpdb->prefix . 'content_auto_rules';
$items_table = $wpdb->prefix . 'content_auto_rule_items';

// 高效的JOIN查询，一次性获取规则和其子任务数量，按最后更新时间排序
$query = "
    SELECT r.*, COUNT(i.id) as sub_task_count
    FROM {$rules_table} as r
    LEFT JOIN {$items_table} as i ON r.id = i.rule_id
    GROUP BY r.id
    ORDER BY r.updated_at DESC
";
$rules = $wpdb->get_results($query);

// 检查每个规则的使用状态
$rule_manager = new ContentAuto_RuleManager();
foreach ($rules as $index => $rule) {
    $usage_details = $rule_manager->get_rule_usage_details($rule->id);
    $rules[$index]->in_use = $usage_details['in_use'];
    $rules[$index]->usage_topic_tasks = $usage_details['topic_tasks'];
    $rules[$index]->usage_details = $usage_details['task_details'];
}

// 检查是否有成功消息
$message = isset($_GET['message']) && $_GET['message'] == '1' ? '新规则已成功添加。' : '';
?>

<div class="wrap">
    <h1 class="wp-heading-inline">规则管理</h1>
    <a href="<?php echo admin_url('admin.php?page=content-auto-manager-rules&action=add'); ?>" class="page-title-action">添加新规则</a>

    <div class="notice notice-info">
        <p><?php _e('注意：修改规则后，正在进行的主题生成任务将使用修改后的规则内容继续执行。请确保规则修改后输出的字段结构保持一致。', 'content-auto-manager'); ?></p>
    </div>

    <div class="notice notice-warning">
        <p><?php _e('注意：正在被主题任务使用的规则无法进行编辑或删除操作。请等待所有相关主题任务完成后再进行修改。文章任务不受规则变更影响。', 'content-auto-manager'); ?></p>
    </div>

    <?php 
    // 根据URL参数显示提示信息
    if (isset($_GET['message'])) {
        $message_code = intval($_GET['message']);
        if ($message_code === 1) {
            echo '<div id="message" class="updated notice is-dismissible"><p>新规则已成功添加。</p></div>';
        } elseif ($message_code === 2) {
            echo '<div id="message" class="error notice is-dismissible"><p>错误：规则保存失败，请重试。</p></div>';
        } elseif ($message_code === 3) {
            echo '<div id="message" class="updated notice is-dismissible"><p>规则已成功更新。</p></div>';
        } elseif ($message_code === 4) {
            echo '<div id="message" class="updated notice is-dismissible"><p>规则已成功删除。</p></div>';
        } elseif ($message_code === 5) {
            echo '<div id="message" class="error notice is-dismissible"><p>删除规则失败，请重试。</p></div>';
        }
        
        // 检查是否有 URL 去重信息需要显示
        $dedup_info = get_transient('cam_url_dedup_info_' . get_current_user_id());
        if ($dedup_info && ($message_code === 1 || $message_code === 3)) {
            $count_input = isset($dedup_info['count_in_input']) ? intval($dedup_info['count_in_input']) : 0;
            $count_rules = isset($dedup_info['count_in_rules']) ? intval($dedup_info['count_in_rules']) : 0;
            $count_topics = isset($dedup_info['count_in_topics']) ? intval($dedup_info['count_in_topics']) : 0;
            $total = isset($dedup_info['total_filtered']) ? intval($dedup_info['total_filtered']) : 0;
            
            if ($total > 0) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>⚠️ 网址去重提示：</strong>共过滤了 <strong>' . $total . '</strong> 条重复网址</p>';
                
                // 1. 输入中的重复
                if ($count_input > 0) {
                    echo '<div style="margin: 10px 0; padding: 10px; background: #fff3cd; border-radius: 4px;">';
                    echo '<strong>📋 输入重复（' . $count_input . ' 条）</strong>';
                    echo '<p style="margin: 5px 0 0; color: #856404; font-size: 12px;">在您输入的网址中发现重复项，已自动保留第一条</p>';
                    if (!empty($dedup_info['urls_in_input'])) {
                        echo '<ul style="margin: 5px 0 0 20px; font-size: 12px; color: #666;">';
                        foreach ($dedup_info['urls_in_input'] as $url) {
                            echo '<li>' . esc_html(mb_substr($url, 0, 80)) . (mb_strlen($url) > 80 ? '...' : '') . '</li>';
                        }
                        if ($count_input > 10) {
                            echo '<li>...还有 ' . ($count_input - 10) . ' 条</li>';
                        }
                        echo '</ul>';
                    }
                    echo '</div>';
                }
                
                // 2. 其他待处理规则中的重复
                if ($count_rules > 0) {
                    echo '<div style="margin: 10px 0; padding: 10px; background: #cce5ff; border-radius: 4px;">';
                    echo '<strong>📁 其他规则中已存在（' . $count_rules . ' 条）</strong>';
                    echo '<p style="margin: 5px 0 0; color: #004085; font-size: 12px;">这些网址已在其他规则中等待处理，无需重复添加</p>';
                    if (!empty($dedup_info['urls_in_rules'])) {
                        echo '<ul style="margin: 5px 0 0 20px; font-size: 12px; color: #666;">';
                        foreach ($dedup_info['urls_in_rules'] as $url) {
                            echo '<li>' . esc_html(mb_substr($url, 0, 80)) . (mb_strlen($url) > 80 ? '...' : '') . '</li>';
                        }
                        if ($count_rules > 10) {
                            echo '<li>...还有 ' . ($count_rules - 10) . ' 条</li>';
                        }
                        echo '</ul>';
                    }
                    echo '</div>';
                }
                
                // 3. 已成功生成主题的重复
                if ($count_topics > 0) {
                    echo '<div style="margin: 10px 0; padding: 10px; background: #d4edda; border-radius: 4px;">';
                    echo '<strong>✅ 已生成过主题（' . $count_topics . ' 条）</strong>';
                    echo '<p style="margin: 5px 0 0; color: #155724; font-size: 12px;">这些网址之前已成功生成过主题内容，无需重复采集</p>';
                    if (!empty($dedup_info['urls_in_topics'])) {
                        echo '<ul style="margin: 5px 0 0 20px; font-size: 12px; color: #666;">';
                        foreach ($dedup_info['urls_in_topics'] as $url) {
                            echo '<li>' . esc_html(mb_substr($url, 0, 80)) . (mb_strlen($url) > 80 ? '...' : '') . '</li>';
                        }
                        if ($count_topics > 10) {
                            echo '<li>...还有 ' . ($count_topics - 10) . ' 条</li>';
                        }
                        echo '</ul>';
                    }
                    echo '</div>';
                }
                
                echo '</div>';
            }
            
            // 清除 transient，避免刷新页面重复显示
            delete_transient('cam_url_dedup_info_' . get_current_user_id());
        }
    }
    ?>

    <hr class="wp-header-end">

    <table class="wp-list-table widefat fixed striped table-view-list">
        <thead>
            <tr>
                <th scope="col" class="manage-column">规则名称</th>
                <th scope="col" class="manage-column">规则任务ID</th>
                <th scope="col" class="manage-column">规则类型</th>
                <th scope="col" class="manage-column">循环次数</th>
                <th scope="col" class="manage-column">子任务数量</th>
                <th scope="col" class="manage-column">状态</th>
                <th scope="col" class="manage-column">使用状态</th>
                <th scope="col" class="manage-column">更新时间</th>
                <th scope="col" class="manage-column">操作</th>
            </tr>
        </thead>
        <tbody id="the-list">
            <?php if (empty($rules)) : ?>
                <tr class="no-items">
                    <td class="colspanchange" colspan="9">没有找到任何规则。</td>
                </tr>
            <?php else : ?>
                <?php foreach ($rules as $rule) : ?>
                    <tr<?php echo $rule->in_use ? ' style="background-color: #fff3cd;"' : ''; ?>>
                        <td><strong><?php echo esc_html($rule->rule_name); ?></strong></td>
                        <td><?php echo esc_html($rule->rule_task_id); ?></td>
                        <td>
                            <?php echo esc_html(ContentAuto_RuleManager::get_rule_type_label($rule->rule_type)); ?>
                        </td>
                        <td><?php echo esc_html($rule->item_count); ?></td>
                        <td><?php echo esc_html($rule->sub_task_count); ?></td>
                        <td><?php echo $rule->status == 1 ? '<span style="color: green;">启用</span>' : '禁用'; ?></td>
                        <td>
                            <?php if ($rule->in_use): ?>
                                <span style="color: #d63638; font-weight: bold;">使用中</span>
                                <div style="font-size: 11px; color: #666;">
                                    主题任务: <?php echo $rule->usage_topic_tasks; ?> 个
                                </div>
                            <?php else: ?>
                                <span style="color: #00a32a;">空闲</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($rule->updated_at); ?></td>
                        <td>
                            <?php if ($rule->in_use): ?>
                                <button class="button button-small" disabled title="规则正在使用中，无法编辑">编辑</button>
                                <button class="button button-small button-link-delete" disabled title="规则正在使用中，无法删除">删除</button>
                            <?php else: ?>
                                <a href="<?php echo admin_url('admin.php?page=content-auto-manager-rules&action=edit&id=' . $rule->id); ?>" class="button button-small">编辑</a>
                                <a href="#" class="button button-small button-link-delete" data-rule-id="<?php echo esc_attr($rule->id); ?>" data-rule-name="<?php echo esc_attr($rule->rule_name); ?>">删除</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 删除规则确认
    const deleteButtons = document.querySelectorAll('a[data-rule-id]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();

            const ruleId = this.getAttribute('data-rule-id');
            const ruleName = this.getAttribute('data-rule-name');

            if (confirm(`确定要删除规则 "${ruleName}" 吗？此操作不可撤销。`)) {
                // 发送AJAX请求删除规则
                const data = new URLSearchParams();
                data.append('action', 'content_auto_delete_rule');
                data.append('nonce', contentAutoManager.nonce);
                data.append('rule_id', ruleId);

                fetch(contentAutoManager.ajaxurl, {
                    method: 'POST',
                    body: data
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        // 重新加载页面显示成功消息
                        window.location.href = '<?php echo admin_url('admin.php?page=content-auto-manager-rules&message=4'); ?>';
                    } else {
                        // 显示详细错误消息
                        const errorMsg = result.data && result.data.message ? result.data.message : '删除规则失败，请重试。';

                        // 如果是规则正在使用中的错误，显示详细信息
                        if (errorMsg.includes('正在被') || errorMsg.includes('使用中')) {
                            if (confirm(`删除失败：${errorMsg}\n\n是否等待任务完成后重试？`)) {
                                window.location.reload(); // 刷新页面以更新状态
                            }
                        } else {
                            alert(errorMsg);
                            window.location.href = '<?php echo admin_url('admin.php?page=content-auto-manager-rules&message=5'); ?>';
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('删除规则时发生错误');
                });
            }
        });
    });

    // 为编辑按钮添加提示（如果被禁用）
    const editButtons = document.querySelectorAll('button:disabled[title*="编辑"]');
    editButtons.forEach(button => {
        button.style.cursor = 'not-allowed';
        button.addEventListener('click', function(e) {
            e.preventDefault();
            alert('此规则正在被使用中，无法编辑。请等待所有相关任务完成后再试。');
        });
    });

    // 为删除按钮添加提示（如果被禁用）
    const disabledDeleteButtons = document.querySelectorAll('button:disabled[title*="删除"]');
    disabledDeleteButtons.forEach(button => {
        button.style.cursor = 'not-allowed';
        button.addEventListener('click', function(e) {
            e.preventDefault();
            alert('此规则正在被使用中，无法删除。请等待所有相关任务完成后再试。');
        });
    });
});
</script>
