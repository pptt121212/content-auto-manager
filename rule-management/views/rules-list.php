<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$rules_table = $wpdb->prefix . 'yali_ai_writer_rules';
$items_table = $wpdb->prefix . 'yali_ai_writer_rule_items';

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
$rule_manager = new Yali_AI_Writer_RuleManager();
foreach ($rules as $index => $rule) {
    $usage_details = $rule_manager->get_rule_usage_details($rule->id);
    $rules[$index]->in_use = $usage_details['in_use'];
    $rules[$index]->usage_topic_tasks = $usage_details['topic_tasks'];
    $rules[$index]->usage_details = $usage_details['task_details'];
}

// 检查是否有成功消息
$message = isset($_GET['message']) && $_GET['message'] == '1' ? __('新规则已成功添加。', 'yali-ai-writer') : '';
?>

<div class="wrap yali-plugin-wrapper">
    <h1 class="yali-page-title"><span class="dashicons dashicons-list-view"></span> <?php _e('规则管理', 'yali-ai-writer'); ?></h1>

    <div class="yali-notice yali-notice-info">
        <p><?php _e('注意：修改规则后，正在进行的主题生成任务将使用修改后的规则内容继续执行。请确保规则修改后输出的字段结构保持一致。', 'yali-ai-writer'); ?></p>
    </div>

    <div class="yali-notice yali-notice-warning">
        <p><?php _e('注意：正在被主题任务使用的规则无法进行编辑或删除操作。请等待所有相关主题任务完成后再进行修改。文章任务不受规则变更影响。', 'yali-ai-writer'); ?></p>
    </div>

    <?php 
    // 检查是否有 URL 去重信息需要显示 (保留原有逻辑，仅移除普通成功/失败提示)
    if (isset($_GET['message'])) {
        $message_code = intval($_GET['message']);
        
        // 检查是否有 URL 去重信息需要显示
        $dedup_info = get_transient('cam_url_dedup_info_' . get_current_user_id());
        if ($dedup_info && ($message_code === 1 || $message_code === 3)) {
            $count_input = isset($dedup_info['count_in_input']) ? intval($dedup_info['count_in_input']) : 0;
            $count_rules = isset($dedup_info['count_in_rules']) ? intval($dedup_info['count_in_rules']) : 0;
            $count_topics = isset($dedup_info['count_in_topics']) ? intval($dedup_info['count_in_topics']) : 0;
            $total = isset($dedup_info['total_filtered']) ? intval($dedup_info['total_filtered']) : 0;
            
            if ($total > 0) {
                echo '<div class="yali-notice yali-notice-warning is-dismissible">';
                echo '<p><strong>' . esc_html__('⚠️ 网址去重提示：', 'yali-ai-writer') . '</strong>' . sprintf( esc_html__('共过滤了 %s 条重复网址', 'yali-ai-writer'), '<strong>' . intval($total) . '</strong>' ) . '</p>';
                
                // 1. 输入中的重复
                if ($count_input > 0) {
                    echo '<div style="margin: 10px 0; padding: 10px; background: #fff3cd; border-radius: 4px;">';
                    echo '<strong>' . sprintf( esc_html__('📋 输入重复（%d 条）', 'yali-ai-writer'), intval($count_input) ) . '</strong>';
                    echo '<p style="margin: 5px 0 0; color: #856404; font-size: 12px;">' . esc_html__('在您输入的网址中发现重复项，已自动保留第一条', 'yali-ai-writer') . '</p>';
                    if (!empty($dedup_info['urls_in_input'])) {
                        echo '<ul style="margin: 5px 0 0 20px; font-size: 12px; color: #666;">';
                        foreach ($dedup_info['urls_in_input'] as $url) {
                            echo '<li>' . esc_html(mb_substr($url, 0, 80)) . (mb_strlen($url) > 80 ? '...' : '') . '</li>';
                        }
                        if ($count_input > 10) {
                            echo '<li>' . sprintf( esc_html__('...还有 %d 条', 'yali-ai-writer'), (intval($count_input) - 10) ) . '</li>';
                        }
                        echo '</ul>';
                    }
                    echo '</div>';
                }
                
                // 2. 其他待处理规则中的重复
                if ($count_rules > 0) {
                    echo '<div style="margin: 10px 0; padding: 10px; background: #cce5ff; border-radius: 4px;">';
                    echo '<strong>' . sprintf( esc_html__('📁 其他规则中已存在（%d 条）', 'yali-ai-writer'), intval($count_rules) ) . '</strong>';
                    echo '<p style="margin: 5px 0 0; color: #004085; font-size: 12px;">' . esc_html__('这些网址已在其他规则中等待处理，无需重复添加', 'yali-ai-writer') . '</p>';
                    if (!empty($dedup_info['urls_in_rules'])) {
                        echo '<ul style="margin: 5px 0 0 20px; font-size: 12px; color: #666;">';
                        foreach ($dedup_info['urls_in_rules'] as $url) {
                            echo '<li>' . esc_html(mb_substr($url, 0, 80)) . (mb_strlen($url) > 80 ? '...' : '') . '</li>';
                        }
                        if ($count_rules > 10) {
                            echo '<li>' . sprintf( esc_html__('...还有 %d 条', 'yali-ai-writer'), (intval($count_rules) - 10) ) . '</li>';
                        }
                        echo '</ul>';
                    }
                    echo '</div>';
                }
                
                // 3. 已成功生成主题的重复
                if ($count_topics > 0) {
                    echo '<div style="margin: 10px 0; padding: 10px; background: #d4edda; border-radius: 4px;">';
                    echo '<strong>' . sprintf( esc_html__('✅ 已生成过主题（%d 条）', 'yali-ai-writer'), intval($count_topics) ) . '</strong>';
                    echo '<p style="margin: 5px 0 0; color: #155724; font-size: 12px;">' . esc_html__('这些网址之前已成功生成过主题内容，无需重复采集', 'yali-ai-writer') . '</p>';
                    if (!empty($dedup_info['urls_in_topics'])) {
                        echo '<ul style="margin: 5px 0 0 20px; font-size: 12px; color: #666;">';
                        foreach ($dedup_info['urls_in_topics'] as $url) {
                            echo '<li>' . esc_html(mb_substr($url, 0, 80)) . (mb_strlen($url) > 80 ? '...' : '') . '</li>';
                        }
                        if ($count_topics > 10) {
                            echo '<li>' . sprintf( esc_html__('...还有 %d 条', 'yali-ai-writer'), (intval($count_topics) - 10) ) . '</li>';
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

    <div class="yali-card">
        <div class="yali-card-header">
            <div class="yali-card-title"><?php _e('规则列表', 'yali-ai-writer'); ?></div>
            <div class="yali-card-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=yali-ai-writer-rules&action=add')); ?>" class="yali-btn yali-btn-primary">
                    <span class="dashicons dashicons-plus-alt2"></span> <?php _e('添加新规则', 'yali-ai-writer'); ?>
                </a>
            </div>
        </div>
        <div class="yali-table-responsive">
            <table class="wp-list-table widefat fixed striped yali-table">
            <thead>
                <tr>
                    <th><?php _e('规则名称', 'yali-ai-writer'); ?></th>
                    <th><?php _e('规则任务ID', 'yali-ai-writer'); ?></th>
                    <th><?php _e('规则类型', 'yali-ai-writer'); ?></th>
                    <th><?php _e('循环次数', 'yali-ai-writer'); ?></th>
                    <th><?php _e('子任务数量', 'yali-ai-writer'); ?></th>
                    <th><?php _e('状态', 'yali-ai-writer'); ?></th>
                    <th><?php _e('使用状态', 'yali-ai-writer'); ?></th>
                    <th><?php _e('更新时间', 'yali-ai-writer'); ?></th>
                    <th><?php _e('操作', 'yali-ai-writer'); ?></th>
                </tr>
            </thead>
            <tbody id="the-list">
                <?php if (empty($rules)) : ?>
                    <tr class="no-items">
                        <td class="colspanchange" colspan="9"><?php _e('没有找到任何规则。', 'yali-ai-writer'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($rules as $rule) : ?>
                        <tr<?php echo $rule->in_use ? ' style="' . esc_attr('background-color: #fff3cd;') . '"' : ''; ?>>
                            <td><strong><?php echo esc_html($rule->rule_name); ?></strong></td>
                            <td><?php echo esc_html($rule->rule_task_id); ?></td>
                            <td>
                                <?php echo esc_html(Yali_AI_Writer_RuleManager::get_rule_type_label($rule->rule_type)); ?>
                            </td>
                            <td><?php echo esc_html($rule->item_count); ?></td>
                            <td><?php echo esc_html($rule->sub_task_count); ?></td>
                            <td><?php echo $rule->status == 1 ? '<span class="yali-badge yali-badge-success">' . esc_html__('启用', 'yali-ai-writer') . '</span>' : '<span class="yali-badge yali-badge-neutral">' . esc_html__('禁用', 'yali-ai-writer') . '</span>'; ?></td>
                            <td>
                                <?php if ($rule->in_use): ?>
                                    <span class="yali-badge yali-badge-warning"><?php _e('使用中', 'yali-ai-writer'); ?></span>
                                    <div style="font-size: 11px; color: #666; margin-top: 4px;">
                                        <?php printf( esc_html__( '主题任务: %d 个', 'yali-ai-writer' ), intval( $rule->usage_topic_tasks ) ); ?>
                                    </div>
                                <?php else: ?>
                                    <span class="yali-badge yali-badge-neutral"><?php _e('空闲', 'yali-ai-writer'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($rule->updated_at); ?></td>
                            <td>
                                <?php if ($rule->in_use): ?>
                                    <button class="yali-btn yali-btn-small" disabled title="<?php esc_attr_e('规则正在使用中，无法编辑', 'yali-ai-writer'); ?>" style="opacity: 0.5; cursor: not-allowed;"><?php _e('编辑', 'yali-ai-writer'); ?></button>
                                    <button class="yali-btn yali-btn-small yali-btn-danger" disabled title="<?php esc_attr_e('规则正在使用中，无法删除', 'yali-ai-writer'); ?>" style="opacity: 0.5; cursor: not-allowed;"><?php _e('删除', 'yali-ai-writer'); ?></button>
                                <?php else: ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=yali-ai-writer-rules&action=edit&id=' . intval($rule->id))); ?>" class="yali-btn yali-btn-small"><?php _e('编辑', 'yali-ai-writer'); ?></a>
                                    <button type="button" class="yali-btn yali-btn-small yali-btn-danger" 
                                            data-yali-action="delete" 
                                            data-yali-ajax-action="yali_ai_writer_delete_rule" 
                                            data-yali-id="<?php echo esc_attr($rule->id); ?>" 
                                            data-yali-id-param="rule_id" 
                                            data-yali-confirm="<?php echo esc_attr(sprintf(__('确定要删除规则 "%s" 吗？此操作不可撤销。', 'yali-ai-writer'), $rule->rule_name)); ?>">
                                        <?php _e('删除', 'yali-ai-writer'); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

