<?php
/**
 * 发布规则页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。', 'yali-ai-writer'));
}

// 检查是否是分类管理页面或向量聚类页面或文章结构页面
$action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
if ($action === 'manage-categories') {
    // 显示分类管理页面
    require_once dirname(__FILE__) . '/category-filter-settings.php';
    return;
} elseif ($action === 'vector-clustering') {
    // 显示向量聚类管理页面
    require_once dirname(dirname(dirname(__FILE__))) . '/admin/class-clustering-admin-page.php';
    $page = new ContentAuto_ClusteringAdminPage();
    $page->render_page();
    return;
} elseif ($action === 'article-structures') {
    // 显示文章结构管理页面
    require_once dirname(dirname(dirname(__FILE__))) . '/article-structures/class-article-structure-admin-page.php';
    $page = new ContentAuto_ArticleStructureAdminPage();
    $page->render_page();
    return;
} elseif ($action === 'editor-assistant-settings') {
    // 显示编辑器AI助手设置页面
    require_once dirname(dirname(dirname(__FILE__))) . '/editor-assistant/class-editor-assistant-admin-page.php';
    $page = new ContentAuto_EditorAssistantAdminPage();
    $page->render_page();
    return;
}

// 初始化通知数组
$yali_notices = array();

// 加载授权管理器
require_once dirname(dirname(dirname(__FILE__))) . '/includes/class-license-manager.php';

// 处理授权码提交
if (isset($_POST['submit_license']) && isset($_POST['content_auto_manager_license_nonce'])) {
    // 验证nonce
    if (!wp_verify_nonce($_POST['content_auto_manager_license_nonce'], 'content_auto_manager_license')) {
        wp_die(__('安全验证失败。', 'yali-ai-writer'));
    }
    
    $license_key = sanitize_text_field($_POST['content_auto_manager_license_key']);
    if (!empty($license_key)) {
        // 先进行基本格式验证
        if (!preg_match('/^CMT-[A-F0-9]{32}$/', $license_key)) {
            $yali_notices[] = array(
                'type' => 'error',
                'message' => __('授权码格式不正确。正确格式：CMT-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX（32位十六进制字符）', 'yali-ai-writer')
            );
        } else {
            // 格式正确，先验证再保存
            $old_license = get_option('content_auto_manager_license_key', '');
            
            // 临时保存以供验证使用
            update_option('content_auto_manager_license_key', $license_key);
            
            // 进行远程验证
            ContentAuto_License_Manager::activate_license($license_key);
            
            // 检查验证结果
            $license_data = get_option(ContentAuto_License_Manager::LICENSE_OPTION, array());
            if (isset($license_data['status']) && $license_data['status'] === 'valid') {
                // 验证成功，保持新授权码
                $yali_notices[] = array(
                    'type' => 'success',
                    'message' => __('授权码验证成功！', 'yali-ai-writer')
                );
            } else {
                // 验证失败，恢复旧授权码
                update_option('content_auto_manager_license_key', $old_license);
                $error_msg = isset($license_data['message']) ? $license_data['message'] : __('授权验证失败', 'yali-ai-writer');
                $yali_notices[] = array(
                    'type' => 'error',
                    'message' => sprintf(__('授权码验证失败：%s', 'yali-ai-writer'), $error_msg)
                );
            }
        }
    } else {
        // 未输入授权码
        $yali_notices[] = array(
            'type' => 'error',
            'message' => __('请输入授权码。', 'yali-ai-writer')
        );
    }
}

// 以前的 PHP 表单处理逻辑已移除，现改用 Universal AJAX Handler 处理 (see admin.js)

// 获取现有发布规则
$database = new ContentAuto_Database();
$publish_rule = $database->get_row('content_auto_publish_rules', array('id' => 1));

// 定义默认值
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'prompt-templating/class-image-prompt-manager.php';
$default_image_prompt = ContentAuto_ImagePromptManager::get_default_template();

// 如果没有规则，使用默认值
if (!$publish_rule) {
    $publish_rule = array(
        'post_status' => CONTENT_AUTO_PUBLISH_STATUS_DRAFT,
        'author_id' => get_current_user_id(),
        'category_mode' => 'manual',
        'category_ids' => array(),
        'fallback_category_ids' => array(),
        'target_length' => '不少于2000字',
        'knowledge_depth' => __('未设置', 'yali-ai-writer'),        // 内容深度 - 默认未设置
        'reader_role' => __('未设置', 'yali-ai-writer'),            // 目标受众 - 默认未设置
        'normalize_output' => 0,
        'structure_mode' => 'generic',
        'auto_image_insertion' => 0,
        'enable_internal_linking' => 0,  // 默认关闭文章内链功能
        'enable_brand_profile_insertion' => 0, // 默认关闭品牌资料植入功能
        'enable_reference_material' => 0, // 默认关闭参考资料功能
        'enable_ai_reference_select' => 0, // 默认关闭大模型精选召回
        'enable_auto_material_search' => 0, // 默认关闭自动素材搜索
        'enable_intent_inference' => 0, // 默认关闭搜索意图推断
        'publish_interval_minutes' => 0,  // 默认立即发布
        'publish_language' => 'zh-CN',    // 默认中文
        'role_description' => __('专业内容创作专家，精通SEO文案、用户体验设计、知识传播策略。您的任务是基于提供的文章标题创作正文内容，输出时直接从第一个章节标题开始，无需重复已提供的主标题。', 'yali-ai-writer'), // 默认角色描述
        'image_prompt_template' => $default_image_prompt
    );
} else {
    $publish_rule['category_ids'] = maybe_unserialize($publish_rule['category_ids']);
    $publish_rule['fallback_category_ids'] = maybe_unserialize($publish_rule['fallback_category_ids']);
    if (empty($publish_rule['image_prompt_template'])) {
        $publish_rule['image_prompt_template'] = $default_image_prompt;
    }
}

// 加载分类过滤器
require_once dirname(__FILE__) . '/../class-category-filter.php';

// 获取用户和过滤后的分类
$users = get_users(array('capability' => 'edit_posts'));
$categories = ContentAuto_Category_Filter::get_filtered_categories();
?>

    <!-- 显示通知 -->
    <?php if (!empty($yali_notices)): ?>
        <?php foreach ($yali_notices as $notice): ?>
            <div class="yali-notice yali-notice-<?php echo esc_attr($notice['type']); ?>">
                <p><?php echo wp_kses_post($notice['message']); ?></p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- 授权设置卡片 -->
    <div class="yali-card">
        <div class="yali-card-header">
            <div class="yali-card-title"><?php _e('插件授权设置', 'yali-ai-writer'); ?></div>
        </div>
        <div class="yali-card-body">
            <form method="post" action="">
                <?php wp_nonce_field('content_auto_manager_license', 'content_auto_manager_license_nonce'); ?>
                
                <table class="form-table">
                    <?php ContentAuto_License_Manager::render_license_field(); ?>
                </table>
                <div style="margin-top: 15px; padding-left: 0;">
                    <?php submit_button(__('验证授权码', 'yali-ai-writer'), 'primary', 'submit_license', false, array('style' => 'padding: 8px 20px; font-size: 14px; height: auto;')); ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 发布规则表单 -->
    <!-- 分类管理快捷入口 -->
    <div class="yali-card">
        <div class="yali-card-header">
            <div class="yali-card-title"><?php _e('分类使用范围', 'yali-ai-writer'); ?></div>
        </div>
        <div class="yali-card-body">
            <?php 
            $filter_stats = ContentAuto_Category_Filter::get_filter_stats();
            if ($filter_stats['is_filtered']): 
            ?>
                <p><span class="dashicons dashicons-filter"></span> <?php printf(__('当前已限制插件使用 %d/%d 个分类（%s%%）', 'yali-ai-writer'), $filter_stats['allowed_categories'], $filter_stats['total_categories'], $filter_stats['filter_percentage']); ?></p>
            <?php else: ?>
                <p><span class="dashicons dashicons-info"></span> <?php _e('当前插件可使用所有分类', 'yali-ai-writer'); ?></p>
            <?php endif; ?>
            
            <div style="margin-top: 15px;">
                <a href="?page=yali-ai-writer-publish-rules&action=manage-categories" class="yali-btn yali-btn-secondary">
                    <span class="dashicons dashicons-admin-settings"></span> <?php _e('管理可用分类', 'yali-ai-writer'); ?>
                </a>
            </div>
            
            <?php if ($filter_stats['is_filtered']): ?>
            <div class="yali-notice yali-notice-info" style="margin-top: 15px;">
                <p><strong><?php _e('分类层级提示：', 'yali-ai-writer'); ?></strong> <?php _e('如果您只选择了子分类而未选择其父分类，在某些功能中可能无法正常显示分类层级结构。建议同时选择相关的父分类以确保最佳体验。', 'yali-ai-writer'); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="yali-card">
        <div class="yali-card-header">
            <div class="yali-card-title"><?php _e('配置发布规则', 'yali-ai-writer'); ?></div>
        </div>
        <div class="yali-card-body">
            <?php if (!ContentAuto_License_Manager::is_license_active()): ?>
                <div class="yali-notice yali-notice-warning" style="margin-bottom: 20px;">
                    <p><strong><?php _e('注意：', 'yali-ai-writer'); ?></strong> <?php _e('授权无效，发布规则将使用默认配置且无法修改。请先输入有效的授权码。', 'yali-ai-writer'); ?></p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="" class="yali-ajax-form" data-action="cam_save_publish_rules" data-nonce="<?php echo wp_create_nonce('cam_save_publish_rules'); ?>">
                <?php wp_nonce_field('content_auto_manager_publish_rules', 'content_auto_manager_nonce'); ?>
                
                <table class="form-table yali-table">
                    <?php $is_licensed = ContentAuto_License_Manager::is_license_active(); ?>
                    <tr>
                        <th scope="row"><?php _e('文章状态', 'yali-ai-writer'); ?></th>
                        <td>
                            <select name="post_status" class="regular-text yali-select" id="post_status" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <option value="publish" <?php selected($publish_rule['post_status'], CONTENT_AUTO_PUBLISH_STATUS_PUBLISH); ?>>
                                    <?php _e('已发布', 'yali-ai-writer'); ?>
                                </option>
                                <option value="draft" <?php selected($publish_rule['post_status'], CONTENT_AUTO_PUBLISH_STATUS_DRAFT); ?>>
                                    <?php _e('草稿', 'yali-ai-writer'); ?>
                                </option>
                            </select>
                            <p class="description"><?php _e('设置自动生成文章的默认发布状态。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr id="publish_interval_row" style="display: <?php echo ($publish_rule['post_status'] === 'publish') ? '' : 'none'; ?>;">
                        <th scope="row"><?php _e('发布时间间隔', 'yali-ai-writer'); ?></th>
                        <td>
                            <input type="number" name="publish_interval_minutes" class="regular-text yali-input" value="<?php echo esc_attr($publish_rule['publish_interval_minutes'] ?? 0); ?>" min="0" max="1440" step="1" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <span class="description"><?php _e('分钟', 'yali-ai-writer'); ?></span>
                            <p class="description">
                                <?php _e('设置文章发布的时间间隔（分钟）。设置为0表示立即发布。', 'yali-ai-writer'); ?><br>
                                <?php _e('系统将根据最新发布文章的时间加上此间隔，作为下一篇文章的发布时间。', 'yali-ai-writer'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('默认作者', 'yali-ai-writer'); ?></th>
                        <td>
                            <select name="author_id" class="regular-text yali-select" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($publish_rule['author_id'], $user->ID); ?>>
                                        <?php echo esc_html($user->display_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('设置自动生成文章的默认作者。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('分类选择模式', 'yali-ai-writer'); ?></th>
                        <td>
                            <select name="category_mode" class="regular-text yali-select" id="category_mode" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <option value="manual" <?php selected($publish_rule['category_mode'], 'manual'); ?>>
                                    <?php _e('手动选择分类', 'yali-ai-writer'); ?>
                                </option>
                                <option value="auto" <?php selected($publish_rule['category_mode'], 'auto'); ?>>
                                    <?php _e('自动选择分类', 'yali-ai-writer'); ?>
                                </option>
                            </select>
                            <p class="description"><?php _e('手动选择：使用预设的分类；自动选择：根据主题的推荐分类自动匹配。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr id="manual_category_row" class="category-row">
                        <th scope="row"><?php _e('手动选择分类', 'yali-ai-writer'); ?></th>
                        <td>
                            <select name="category_ids[]" multiple class="regular-text yali-select" style="height: 150px;">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo esc_attr($category->term_id); ?>" <?php echo (is_array($publish_rule['category_ids']) && in_array($category->term_id, $publish_rule['category_ids'])) ? 'selected' : ''; ?>>
                                        <?php echo esc_html($category->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('设置自动生成文章的默认分类。按住Ctrl键可多选。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr id="auto_category_row" class="category-row" style="display: none;">
                        <th scope="row"><?php _e('备用分类', 'yali-ai-writer'); ?></th>
                        <td>
                            <select name="fallback_category_ids[]" multiple class="regular-text yali-select" style="height: 150px;">
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo esc_attr($category->term_id); ?>" <?php echo (is_array($publish_rule['fallback_category_ids']) && in_array($category->term_id, $publish_rule['fallback_category_ids'])) ? 'selected' : ''; ?>>
                                        <?php echo esc_html($category->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php _e('当自动分类匹配失败时使用的备用分类。按住Ctrl键可多选。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('编辑器AI助手', 'yali-ai-writer'); ?></th>
                        <td>
                            <label class="yali-checkbox-label">
                                <input type="checkbox" name="enable_editor_assistant" id="enable_editor_assistant" value="1" 
                                       <?php checked($publish_rule['enable_editor_assistant'] ?? 0, 1); ?>
                                       <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <?php _e('启用编辑器AI助手', 'yali-ai-writer'); ?>
                            </label>
                            <p class="description">
                                <?php _e('启用后，在WordPress文章编辑器中提供AI写作助手功能，支持段落生成、改写、摘要等智能模板。', 'yali-ai-writer'); ?>
                            </p>
                            <div class="yali-notice yali-notice-info yali-notice-sm" id="editor_assistant_link_container" style="display: <?php echo ($publish_rule['enable_editor_assistant'] ?? 0) ? 'block' : 'none'; ?>; margin-top: 10px; margin-bottom: 0;">
                                <p style="margin: 0; display: flex; align-items: center;">
                                    <span class="dashicons dashicons-admin-settings" style="color: #2271b1;"></span>
                                    <strong><?php _e('功能配置：', 'yali-ai-writer'); ?></strong>
                                    <?php _e('管理和自定义编辑器助手的快捷提示词及菜单。', 'yali-ai-writer'); ?>
                                    <a href="?page=yali-ai-writer-publish-rules&action=editor-assistant-settings" class="yali-btn yali-btn-secondary yali-btn-small" style="margin-left: 10px;"><?php _e('前往助手配置', 'yali-ai-writer'); ?></a>
                                </p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('目标字数', 'yali-ai-writer'); ?></th>
                        <td>
                            <select name="target_length" class="regular-text yali-select" id="target_length" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <option value="不少于500字" <?php selected($publish_rule['target_length'], '不少于500字'); ?>>
                                    <?php _e('短文章（不少于500字）', 'yali-ai-writer'); ?>
                                </option>
                                <option value="不少于800字" <?php selected($publish_rule['target_length'], '不少于800字'); ?>>
                                    <?php _e('简短文章（不少于800字）', 'yali-ai-writer'); ?>
                                </option>
                                <option value="不少于2000字" <?php selected($publish_rule['target_length'], '不少于2000字'); ?>>
                                    <?php _e('标准文章（不少于2000字）', 'yali-ai-writer'); ?>
                                </option>
                                <option value="不少于5000字" <?php selected($publish_rule['target_length'], '不少于5000字'); ?>>
                                    <?php _e('长文章（不少于5000字）', 'yali-ai-writer'); ?>
                                </option>
                                <option value="不少于8000字" <?php selected($publish_rule['target_length'], '不少于8000字'); ?>>
                                    <?php _e('深度长文（不少于8000字）', 'yali-ai-writer'); ?>
                                </option>
                                <option value="不少于12000字" <?php selected($publish_rule['target_length'], '不少于12000字'); ?>>
                                    <?php _e('超长深度文章（不少于12000字）', 'yali-ai-writer'); ?>
                                </option>
                            </select>
                            <p class="description"><?php _e('设置生成文章的最低字数要求。AI将确保文章不少于所选字数，产出充实完整的内容。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('内容深度', 'yali-ai-writer'); ?></th>
                        <td>
                            <select name="knowledge_depth" class="regular-text yali-select" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <option value="<?php echo esc_attr(__('未设置', 'yali-ai-writer')); ?>" <?php selected($publish_rule['knowledge_depth'], __('未设置', 'yali-ai-writer')); ?>>
                                    <?php _e('未设置 - 不指定内容深度，由AI自由发挥', 'yali-ai-writer'); ?>
                                </option>
                                <option value="<?php echo esc_attr(__('浅层普及', 'yali-ai-writer')); ?>" <?php selected($publish_rule['knowledge_depth'], __('浅层普及', 'yali-ai-writer')); ?>>
                                    <?php _e('浅层普及 - 快速了解概念，吸引广泛受众', 'yali-ai-writer'); ?>
                                </option>
                                <option value="<?php echo esc_attr(__('实用指导', 'yali-ai-writer')); ?>" <?php selected($publish_rule['knowledge_depth'], __('实用指导', 'yali-ai-writer')); ?>>
                                    <?php _e('实用指导 - 提供操作方法，满足用户实际需求', 'yali-ai-writer'); ?>
                                </option>
                                <option value="<?php echo esc_attr(__('深度分析', 'yali-ai-writer')); ?>" <?php selected($publish_rule['knowledge_depth'], __('深度分析', 'yali-ai-writer')); ?>>
                                    <?php _e('深度分析 - 专业洞察解读，建立行业权威形象', 'yali-ai-writer'); ?>
                                </option>
                                <option value="<?php echo esc_attr(__('全面综述', 'yali-ai-writer')); ?>" <?php selected($publish_rule['knowledge_depth'], __('全面综述', 'yali-ai-writer')); ?>>
                                    <?php _e('全面综述 - 系统知识梳理，打造专业内容资产', 'yali-ai-writer'); ?>
                                </option>
                            </select>
                            <p class="description"><?php _e('设置内容深度，影响读者对品牌的认知和信任度。选择"未设置"时，AI将自由决定内容深度；其他选项将提供具体的写作指导。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('目标受众', 'yali-ai-writer'); ?></th>
                        <td>
                            <select name="reader_role" class="regular-text yali-select" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <option value="<?php echo esc_attr(__('未设置', 'yali-ai-writer')); ?>" <?php selected($publish_rule['reader_role'], __('未设置', 'yali-ai-writer')); ?>>
                                    <?php _e('未设置 - 不指定目标受众，由AI自由发挥', 'yali-ai-writer'); ?>
                                </option>
                                <option value="<?php echo esc_attr(__('潜在客户', 'yali-ai-writer')); ?>" <?php selected($publish_rule['reader_role'], __('潜在客户', 'yali-ai-writer')); ?>>
                                    <?php _e('潜在客户 - 关注产品价值和解决方案', 'yali-ai-writer'); ?>
                                </option>
                                <option value="<?php echo esc_attr(__('现有客户', 'yali-ai-writer')); ?>" <?php selected($publish_rule['reader_role'], __('现有客户', 'yali-ai-writer')); ?>>
                                    <?php _e('现有客户 - 关注使用技巧和增值服务', 'yali-ai-writer'); ?>
                                </option>
                                <option value="<?php echo esc_attr(__('行业同仁', 'yali-ai-writer')); ?>" <?php selected($publish_rule['reader_role'], __('行业同仁', 'yali-ai-writer')); ?>>
                                    <?php _e('行业同仁 - 关注专业见解和行业趋势', 'yali-ai-writer'); ?>
                                </option>
                                <option value="<?php echo esc_attr(__('决策者', 'yali-ai-writer')); ?>" <?php selected($publish_rule['reader_role'], __('决策者', 'yali-ai-writer')); ?>>
                                    <?php _e('决策者 - 关注商业价值和战略意义', 'yali-ai-writer'); ?>
                                </option>
                                <option value="<?php echo esc_attr(__('泛流量用户', 'yali-ai-writer')); ?>" <?php selected($publish_rule['reader_role'], __('泛流量用户', 'yali-ai-writer')); ?>>
                                    <?php _e('泛流量用户 - 关注热点话题和生活需求', 'yali-ai-writer'); ?>
                                </option>
                            </select>
                            <p class="description"><?php _e('设置目标受众，直接影响内容营销效果。选择"未设置"时，AI将自由决定目标受众；其他选项将提供具体的内容策略指导。', 'yali-ai-writer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('文章结构指导', 'yali-ai-writer'); ?></th>
                        <td>
                            <label class="yali-checkbox-label">
                                <input type="checkbox" id="normalize_output" name="normalize_output" value="1" <?php checked($publish_rule['normalize_output'], 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <?php _e('启用详细结构指导', 'yali-ai-writer'); ?>
                            </label>
                            <p class="description">
                                <?php _e('启用后，系统将在文章生成时引入结构化大纲，引导AI按照预设的章节框架组织内容。', 'yali-ai-writer'); ?>
                            </p>
                            
                            <div id="structure_mode_options" style="display: <?php echo $publish_rule['normalize_output'] ? 'block' : 'none'; ?>; margin-top: 12px;">
                                <?php
                                $structure_mode = isset($publish_rule['structure_mode']) ? $publish_rule['structure_mode'] : 'generic';
                                ?>
                                <div class="yali-notice yali-notice-info yali-notice-sm" style="margin-bottom: 0;">
                                    <p style="margin: 0 0 10px 0;"><strong><?php _e('选择结构生成方式：', 'yali-ai-writer'); ?></strong></p>
                                    
                                    <label class="yali-checkbox-label" style="display: block; margin-bottom: 10px;">
                                        <input type="radio" name="structure_mode" value="personalized" <?php checked($structure_mode, 'personalized'); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                        <strong><?php _e('个性文章结构', 'yali-ai-writer'); ?></strong>
                                    </label>
                                    <p class="description" style="margin: -5px 0 15px 24px;">
                                        <?php _e('每次生成文章前，系统会先调用AI根据当前主题的标题、内容角度、用户价值、SEO关键词、参考资料等信息，为该主题量身定制一个专属文章大纲，再基于该大纲生成文章。', 'yali-ai-writer'); ?>
                                        <br>
                                        <span style="color: #d63638;">
                                            <span class="dashicons dashicons-warning" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                            <?php _e('注意：此模式会额外消耗一次API调用，略微增加生成时间和Token消耗。', 'yali-ai-writer'); ?>
                                        </span>
                                    </p>
                                    
                                    <label class="yali-checkbox-label" style="display: block; margin-bottom: 10px;">
                                        <input type="radio" name="structure_mode" value="generic" <?php checked($structure_mode, 'generic'); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                        <strong><?php _e('通用文章结构', 'yali-ai-writer'); ?></strong>
                                    </label>
                                    <p class="description" style="margin: -5px 0 10px 24px;">
                                        <?php _e('系统从「文章结构管理」页面中已有的结构库中，通过向量相似度智能匹配最适合当前主题的章节模板，直接引入到文章生成提示词中。', 'yali-ai-writer'); ?>
                                    </p>

                                    <div id="article_structure_link_container" style="display: <?php echo ($structure_mode === 'generic') ? 'block' : 'none'; ?>; margin-top: 5px;">
                                        <p style="margin: 0; display: flex; align-items: center;">
                                            <span class="dashicons dashicons-admin-links" style="color: #2271b1;"></span>
                                            <strong><?php _e('前置要求：', 'yali-ai-writer'); ?></strong>
                                            <?php _e('启用此模式前，请确保您已在文章结构管理中创建了相应的章节模板。', 'yali-ai-writer'); ?>
                                            <a href="?page=yali-ai-writer-publish-rules&action=article-structures" class="yali-btn yali-btn-secondary yali-btn-small" style="margin-left: 10px;"><?php _e('前往文章结构管理', 'yali-ai-writer'); ?></a>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <p class="description" style="margin-top: 10px;">
                                <?php _e('适用场景：需要高度结构化、格式统一的专业文章，如企业博客、技术文档等。', 'yali-ai-writer'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('文章自动配图', 'yali-ai-writer'); ?></th>
                        <td>
                            <label class="yali-checkbox-label">
                                <input type="checkbox" name="auto_image_insertion" value="1" <?php checked($publish_rule['auto_image_insertion'], 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?> id="auto_image_insertion">
                                <?php _e('启用文章自动配图', 'yali-ai-writer'); ?>
                            </label>
                            <p class="description"><?php _e('启用后，AI将在文章中自动生成配图占位符，用于插入相关图片。', 'yali-ai-writer'); ?></p>
                            
                            <div id="auto_image_options" style="margin-top: 15px; <?php echo !$publish_rule['auto_image_insertion'] ? 'display: none;' : ''; ?>">
                                <label for="max_auto_images" style="margin-right: 10px;">
                                    <strong><?php _e('最大生成图片数量:', 'yali-ai-writer'); ?></strong>
                                </label>
                                <select name="max_auto_images" id="max_auto_images" class="yali-select" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                    <option value="1" <?php selected($publish_rule['max_auto_images'] ?? '1', '1'); ?>>
                                        <?php _e('1张图片', 'yali-ai-writer'); ?>
                                    </option>
                                    <option value="2" <?php selected($publish_rule['max_auto_images'] ?? '1', '2'); ?>>
                                        <?php _e('2张图片', 'yali-ai-writer'); ?>
                                    </option>
                                    <option value="3" <?php selected($publish_rule['max_auto_images'] ?? '1', '3'); ?>>
                                        <?php _e('3张图片', 'yali-ai-writer'); ?>
                                    </option>
                                    <option value="4" <?php selected($publish_rule['max_auto_images'] ?? '1', '4'); ?>>
                                        <?php _e('4张图片', 'yali-ai-writer'); ?>
                                    </option>
                                    <option value="5" <?php selected($publish_rule['max_auto_images'] ?? '1', '5'); ?>>
                                        <?php _e('5张图片', 'yali-ai-writer'); ?>
                                    </option>
                                </select>
                                <p class="description" style="margin-top: 8px;">
                                    <?php _e('设置一篇文章中最多生成多少张图片。如果文章中有5个图片占位符，但设置为只生成2张图片，则只会替换前2个占位符，其余占位符将被忽略。', 'yali-ai-writer'); ?>
                                </p>
                                
                                <div style="margin-top: 15px;">
                                    <label class="yali-checkbox-label">
                                        <input type="checkbox" name="skip_first_image_placeholder" value="1" <?php checked($publish_rule['skip_first_image_placeholder'] ?? 0, 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                        <strong><?php _e('忽略首段落图片', 'yali-ai-writer'); ?></strong>
                                    </label>
                                    <p class="description" style="margin-top: 8px;">
                                        <?php _e('启用后，系统将跳过文章中的第一个图片占位符，从第二个开始生成图片。这样可以保持首段的纯文字效果，避免首屏被图片占据。例如：文章有3个占位符，设置生成2张图片并启用此选项，将跳过第1个占位符，生成第2、3个占位符的图片。', 'yali-ai-writer'); ?>
                                    </p>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('文章内链功能', 'yali-ai-writer'); ?></th>
                        <td>
                            <label class="yali-checkbox-label">
                                <input type="checkbox" name="enable_internal_linking" id="enable_internal_linking" value="1" <?php checked($publish_rule['enable_internal_linking'], 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <?php _e('启用文章内链功能', 'yali-ai-writer'); ?>
                            </label>
                            <p class="description"><?php _e('启用后，AI将在生成的文章中自然地融入已发布的相关文章标题和链接，提升网站内链建设。', 'yali-ai-writer'); ?></p>
                            
                            <div id="vector_clustering_link_container" class="yali-notice yali-notice-info" style="display: <?php echo !$publish_rule['enable_internal_linking'] ? 'none' : 'block'; ?>;">
                                <p style="margin: 0; display: flex; align-items: center; gap: 8px;">
                                    <span class="dashicons dashicons-admin-links" style="color: #2271b1;"></span>
                                    <strong><?php _e('前置要求：', 'yali-ai-writer'); ?></strong>
                                    <?php _e('高质量的内链推荐依赖于文章向量聚类分析。为了获得最佳内链效果，请定期进行向量聚类。', 'yali-ai-writer'); ?>
                                    <a href="?page=yali-ai-writer-publish-rules&action=vector-clustering" class="yali-btn yali-btn-secondary yali-btn-small" style="margin-left: 10px;"><?php _e('前往向量聚类管理', 'yali-ai-writer'); ?></a>
                                </p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('品牌资料植入', 'yali-ai-writer'); ?></th>
                        <td>
                            <label class="yali-checkbox-label">
                                <input type="checkbox" name="enable_brand_profile_insertion" value="1" <?php checked($publish_rule['enable_brand_profile_insertion'], 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?> id="enable_brand_profile_insertion">
                                <?php _e('启用品牌资料自动植入', 'yali-ai-writer'); ?>
                            </label>
                            <p class="description yali-desc"><?php _e('启用后，系统将根据文章标题，从您的品牌资料库中匹配最相关的一份，并将其自动插入到文章段落中。', 'yali-ai-writer'); ?></p>
                            
                            <div id="brand_profile_options" class="yali-notice yali-notice-info" style="display: <?php echo !$publish_rule['enable_brand_profile_insertion'] ? 'none' : 'block'; ?>;">
                                <label for="brand_profile_position" style="margin-right: 10px;">
                                    <strong><?php _e('品牌资料插入位置:', 'yali-ai-writer'); ?></strong>
                                </label>
                                <div style="margin-top: 8px;">
                                    <label class="yali-checkbox-label" style="display: block; margin-bottom: 8px;">
                                        <input type="radio" name="brand_profile_position" value="before_second_paragraph" <?php checked($publish_rule['brand_profile_position'] ?? 'before_second_paragraph', 'before_second_paragraph'); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                        <?php _e('第二段落前', 'yali-ai-writer'); ?>
                                    </label>
                                    <label class="yali-checkbox-label" style="display: block;">
                                        <input type="radio" name="brand_profile_position" value="article_end" <?php checked($publish_rule['brand_profile_position'] ?? 'before_second_paragraph', 'article_end'); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                        <?php _e('文章结尾', 'yali-ai-writer'); ?>
                                    </label>
                                </div>
                                <p class="description yali-desc">
                                    <?php _e('选择品牌资料在文章中的插入位置。"第二段落前"有助于提升品牌曝光度，"文章结尾"更适合作为补充信息。', 'yali-ai-writer'); ?>
                                </p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('启用参考物料', 'yali-ai-writer'); ?></th>
                        <td>
                            <label class="yali-checkbox-label">
                                <input type="checkbox" name="enable_reference_material" id="enable_reference_material" value="1" <?php checked($publish_rule['enable_reference_material'] ?? 0, 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <?php _e('启用参考资料功能', 'yali-ai-writer'); ?>
                            </label>
                            <p class="description yali-desc">
                                <?php _e('启用后，当主题和规则中都没有参考资料时，系统将从品牌资料中查找物料类型为"参考资料"的内容，按相似度匹配，并将描述内容插入到文章生成的提示词模板中作为参考资料。', 'yali-ai-writer'); ?>
                            </p>
                            
                            <!-- 大模型精选召回选项 -->
                            <div id="ai_reference_select_options" class="yali-notice yali-notice-info" style="display: <?php echo ($publish_rule['enable_reference_material'] ?? 0) ? 'block' : 'none'; ?>;">
                                <label class="yali-checkbox-label">
                                    <input type="checkbox" name="enable_ai_reference_select" id="enable_ai_reference_select" value="1" <?php checked($publish_rule['enable_ai_reference_select'] ?? 0, 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                    <strong><?php _e('启用大模型精选召回', 'yali-ai-writer'); ?></strong>
                                </label>
                                <p class="description yali-desc">
                                    <?php _e('启用后，系统将降低相似度阈值（从0.8降至0.5）召回前10条候选参考资料，然后调用大模型分析每个候选资料与文章主题的相关性，由大模型选择最具参考价值的资料插入到提示词模板中。', 'yali-ai-writer'); ?>
                                </p>
                                <p class="description yali-desc" style="color: #d63638; display: flex; align-items: center; gap: 5px;">
                                    <span class="dashicons dashicons-warning" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                    <?php _e('注意：启用此功能会额外消耗一次API调用，可能增加文章生成时间和成本。', 'yali-ai-writer'); ?>
                                </p>
                                
                                <div class="yali-divider-dashed"></div>
                                
                                <div style="margin-top: 15px;">
                                    <p><strong><?php _e('素材收集方式', 'yali-ai-writer'); ?></strong></p>
                                    <?php
                                    // 从数据库字段读取（material_collection_mode），而非 wp_options
                                    $collection_mode = !empty($publish_rule['material_collection_mode']) ? $publish_rule['material_collection_mode'] : 'none';
                                    // 兼容旧版本迁移：如果字段尚未存在但旧开关开启，默认为 search_engine
                                    if ($collection_mode === 'none' && !empty($publish_rule['enable_auto_material_search'])) {
                                        $collection_mode = 'search_engine';
                                    }
                                    ?>
                                    <label class="yali-checkbox-label" style="display: block; margin-bottom: 5px;">
                                        <input type="radio" name="material_collection_mode" value="none" <?php checked($collection_mode, 'none'); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                        <?php _e('关闭自动收集', 'yali-ai-writer'); ?>
                                    </label>
                                    <label class="yali-checkbox-label" style="display: block; margin-bottom: 5px;">
                                        <input type="radio" name="material_collection_mode" value="search_engine" <?php checked($collection_mode, 'search_engine'); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                        <?php _e('启用网络搜索', 'yali-ai-writer'); ?>
                                    </label>
                                    <label class="yali-checkbox-label" style="display: block; margin-bottom: 5px;">
                                        <input type="radio" name="material_collection_mode" value="extension_rag" <?php checked($collection_mode, 'extension_rag'); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                        <?php _e('启用知识库搜索 (Browser Extension)', 'yali-ai-writer'); ?>
                                    </label>
                                    
                                    <p class="description yali-desc">
                                        <?php _e('选择参考资料的来源方式。', 'yali-ai-writer'); ?>
                                        <br>
                                        <?php _e('• 自动素材搜索：系统后台异步调用搜索引擎API搜集资料。', 'yali-ai-writer'); ?>
                                        <br>
                                        <?php _e('• 插件收集：向浏览器插件发起向量检索请求（需要浏览器保持开启）。', 'yali-ai-writer'); ?>
                                    </p>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('搜索意图推断', 'yali-ai-writer'); ?></th>
                        <td>
                            <label class="yali-checkbox-label">
                                <input type="checkbox" name="enable_intent_inference" id="enable_intent_inference" value="1" <?php checked($publish_rule['enable_intent_inference'] ?? 0, 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <?php _e('启用搜索意图推断', 'yali-ai-writer'); ?>
                            </label>
                            <p class="description yali-desc">
                                <?php _e('启用后，生成主题时AI将先分析源内容背后可能的用户搜索意图（如：想了解概念、想解决问题、想做对比选择等），然后基于不同意图方向生成更符合用户真实搜索习惯的文章标题。', 'yali-ai-writer'); ?>
                            </p>
                            <p class="description yali-desc" style="margin-top: 5px;">
                                <?php _e('适用场景：希望生成的标题更贴近用户在搜索引擎中实际输入的查询词，提升SEO效果和点击率。', 'yali-ai-writer'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('发布语言', 'yali-ai-writer'); ?></th>
                        <td>
                            <select name="publish_language" class="regular-text yali-select" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <option value="zh-CN" <?php selected($publish_rule['publish_language'], 'zh-CN'); ?>>
                                    <?php _e('中文（简体）', 'yali-ai-writer'); ?>
                                </option>
                                <option value="zh-TW" <?php selected($publish_rule['publish_language'], 'zh-TW'); ?>>
                                    <?php _e('中文（繁体）', 'yali-ai-writer'); ?>
                                </option>
                                <option value="en-US" <?php selected($publish_rule['publish_language'], 'en-US'); ?>>
                                    <?php _e('英语（美式）', 'yali-ai-writer'); ?>
                                </option>
                            <option value="en-GB" <?php selected($publish_rule['publish_language'], 'en-GB'); ?>>
                                <?php _e('英语（英式）', 'yali-ai-writer'); ?>
                            </option>
                            <option value="ja-JP" <?php selected($publish_rule['publish_language'], 'ja-JP'); ?>>
                                <?php _e('日语', 'yali-ai-writer'); ?>
                            </option>
                            <option value="ko-KR" <?php selected($publish_rule['publish_language'], 'ko-KR'); ?>>
                                <?php _e('韩语', 'yali-ai-writer'); ?>
                            </option>
                            <option value="fr-FR" <?php selected($publish_rule['publish_language'], 'fr-FR'); ?>>
                                <?php _e('法语', 'yali-ai-writer'); ?>
                            </option>
                            <option value="de-DE" <?php selected($publish_rule['publish_language'], 'de-DE'); ?>>
                                <?php _e('德语', 'yali-ai-writer'); ?>
                            </option>
                            <option value="es-ES" <?php selected($publish_rule['publish_language'], 'es-ES'); ?>>
                                <?php _e('西班牙语', 'yali-ai-writer'); ?>
                            </option>
                            <option value="pt-BR" <?php selected($publish_rule['publish_language'], 'pt-BR'); ?>>
                                <?php _e('葡萄牙语（巴西）', 'yali-ai-writer'); ?>
                            </option>
                            <option value="ru-RU" <?php selected($publish_rule['publish_language'], 'ru-RU'); ?>>
                                <?php _e('俄语', 'yali-ai-writer'); ?>
                            </option>
                            <option value="ar-SA" <?php selected($publish_rule['publish_language'], 'ar-SA'); ?>>
                                <?php _e('阿拉伯语', 'yali-ai-writer'); ?>
                            </option>
                            <option value="hi-IN" <?php selected($publish_rule['publish_language'], 'hi-IN'); ?>>
                                <?php _e('印地语', 'yali-ai-writer'); ?>
                            </option>
                            <option value="th-TH" <?php selected($publish_rule['publish_language'], 'th-TH'); ?>>
                                <?php _e('泰语', 'yali-ai-writer'); ?>
                            </option>
                            <option value="vi-VN" <?php selected($publish_rule['publish_language'], 'vi-VN'); ?>>
                                <?php _e('越南语', 'yali-ai-writer'); ?>
                            </option>
                            <option value="id-ID" <?php selected($publish_rule['publish_language'], 'id-ID'); ?>>
                                <?php _e('印度尼西亚语', 'yali-ai-writer'); ?>
                            </option>
                        </select>
                        <p class="description yali-desc"><?php _e('选择文章生成的目标语言。此设置会影响主题任务、文章任务、文章结构生成时的输出语言。', 'yali-ai-writer'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('AI角色描述', 'yali-ai-writer'); ?></th>
                    <td>
                        <textarea name="role_description" rows="4" class="large-text yali-input" <?php echo !$is_licensed ? 'disabled' : ''; ?> placeholder="<?php echo esc_attr(__('例如：专业内容创作专家，精通SEO文案、用户体验设计、知识传播策略。您的任务是基于提供的文章标题创作正文内容，输出时直接从第一个章节标题开始，无需重复已提供的主标题。', 'yali-ai-writer')); ?>"><?php echo esc_textarea($publish_rule['role_description'] ?? ''); ?></textarea>
                        <p class="description"><?php _e('定义AI在生成文章时的角色和专业能力。这个描述将作为提示词模板中的&lt;role&gt;标签内容，影响AI的写作风格和专业度。留空将使用默认角色描述。', 'yali-ai-writer'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('配图风格模式', 'yali-ai-writer'); ?></th>
                    <td>
                        <select name="image_prompt_mode" id="image_prompt_mode" class="yali-select" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <option value="default" <?php selected($publish_rule['image_prompt_mode'] ?? 'default', 'default'); ?>><?php _e('默认高级商务风 (支持自定义)', 'yali-ai-writer'); ?></option>
                            <option value="flowchart" <?php selected($publish_rule['image_prompt_mode'] ?? 'default', 'flowchart'); ?>><?php _e('结构与流程图为主', 'yali-ai-writer'); ?></option>
                            <option value="content_match" <?php selected($publish_rule['image_prompt_mode'] ?? 'default', 'content_match'); ?>><?php _e('内容高度匹配情境图', 'yali-ai-writer'); ?></option>
                            <option value="text_overlay" <?php selected($publish_rule['image_prompt_mode'] ?? 'default', 'text_overlay'); ?>><?php _e('带自适应文字的配图', 'yali-ai-writer'); ?></option>
                        </select>
                        <p class="description"><?php _e('选择文章自动配图的默认提示词模式。选择非“默认”模式时，将使用系统内置的优质配图提示词，自定义输入框将被隐藏。', 'yali-ai-writer'); ?></p>
                    </td>
                </tr>
                <tr id="custom_image_prompt_row" style="<?php echo (isset($publish_rule['image_prompt_mode']) && $publish_rule['image_prompt_mode'] !== 'default') ? 'display: none;' : ''; ?>">
                    <th scope="row"><?php _e('自动配图提示词', 'yali-ai-writer'); ?></th>
                    <td>
                        <textarea name="image_prompt_template" rows="10" class="large-text yali-input" <?php echo !$is_licensed ? 'disabled' : ''; ?>><?php echo esc_textarea($publish_rule['image_prompt_template'] ?? ''); ?></textarea>
                        <p class="description">
                            <?php _e('定义在启用自动配图时，注入到提示词中的配图指令。如果清空此内容，将自动恢复为默认提示词。', 'yali-ai-writer'); ?><br>
                            <b><?php _e('重要提示：', 'yali-ai-writer'); ?></b> <?php _e('此提示词的顶层必须由 <code>&lt;image_generation_instructions&gt;</code> 标签包裹。', 'yali-ai-writer'); ?><br>
                            <?php _e('最终生成的图片占位符必须是 <code>&lt;!-- image prompt: {图像描述的英文提示词} --&gt;</code> 格式，否则系统无法正确处理并配图。', 'yali-ai-writer'); ?>
                        </p>
                    </td>
                </tr>
                    </table>
                    
                    <div style="margin-top: 30px; padding: 24px; background: #f8fafc; border-radius: 8px; border: 1px solid var(--yali-border); display: flex; align-items: center; justify-content: space-between;">
                        <div class="description yali-desc" style="margin: 0;">
                            <span class="dashicons dashicons-info" style="font-size: 16px; margin-right: 5px;"></span>
                            <?php _e('请确认以上所有规则配置无误后再点击保存。', 'yali-ai-writer'); ?>
                        </div>
                        <?php submit_button(__('保存所有发布规则', 'yali-ai-writer'), 'primary yali-btn yali-btn-primary', 'submit', false, !$is_licensed ? array('disabled' => 'disabled') : array('style' => 'height: 42px; padding: 0 30px; font-size: 15px;')); ?>
                    </div>
                </form>
            </div>
        </div>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    // 授权码实时验证逻辑已经在 License Manager 或 Renderer 中处理，这里只需处理简单的表单交互
    
    // 分类模式切换
    const categoryMode = document.getElementById('category_mode');
    const manualRow = document.getElementById('manual_category_row');
    const autoRow = document.getElementById('auto_category_row');

    if (categoryMode) {
        const toggleCategoryRows = () => {
            if (categoryMode.value === 'manual') {
                manualRow.style.display = 'table-row';
                autoRow.style.display = 'none';
            } else {
                manualRow.style.display = 'none';
                autoRow.style.display = 'table-row';
            }
        };
        categoryMode.addEventListener('change', toggleCategoryRows);
        toggleCategoryRows(); // 初始化
    }

    // 图片提示词模式切换
    const imagePromptMode = document.getElementById('image_prompt_mode');
    const customImagePromptRow = document.getElementById('custom_image_prompt_row');
    
    if (imagePromptMode && customImagePromptRow) {
        const toggleCustomImagePromptRow = () => {
            if (imagePromptMode.value === 'default') {
                customImagePromptRow.style.display = 'table-row';
            } else {
                customImagePromptRow.style.display = 'none';
            }
        };
        imagePromptMode.addEventListener('change', toggleCustomImagePromptRow);
        toggleCustomImagePromptRow(); // 初始化
    }

    // 发布状态切换 (显示/隐藏间隔)
    const postStatus = document.getElementById('post_status');
    const intervalRow = document.getElementById('publish_interval_row');

    if (postStatus) {
        const toggleIntervalRow = () => {
            if (postStatus.value === 'publish') {
                intervalRow.style.display = 'table-row';
            } else {
                intervalRow.style.display = 'none';
            }
        };
        postStatus.addEventListener('change', toggleIntervalRow);
        toggleIntervalRow(); // 初始化
    }

    // 自动配图选项切换 (Slide fixed using CSS transitions if needed, here just basic toggle)
    const autoImageInsertion = document.getElementById('auto_image_insertion');
    const autoImageOptions = document.getElementById('auto_image_options');

    if (autoImageInsertion) {
        const toggleAutoImageOptions = () => {
            autoImageOptions.style.display = autoImageInsertion.checked ? 'block' : 'none';
        };
        autoImageInsertion.addEventListener('change', toggleAutoImageOptions);
        toggleAutoImageOptions();
    }

    // 品牌资料选项切换
    const enableBrandProfile = document.getElementById('enable_brand_profile_insertion');
    const brandProfileOptions = document.getElementById('brand_profile_options');

    if (enableBrandProfile) {
        const toggleBrandProfileOptions = () => {
            brandProfileOptions.style.display = enableBrandProfile.checked ? 'block' : 'none';
        };
        enableBrandProfile.addEventListener('change', toggleBrandProfileOptions);
        toggleBrandProfileOptions();
    }

    // 内链选项与向量聚类入口切换
    const enableInternalLinking = document.getElementById('enable_internal_linking');
    const vectorClusteringLink = document.getElementById('vector_clustering_link_container');

    if (enableInternalLinking && vectorClusteringLink) {
        const toggleVectorClustering = () => {
            vectorClusteringLink.style.display = enableInternalLinking.checked ? 'block' : 'none';
        };
        enableInternalLinking.addEventListener('change', toggleVectorClustering);
        toggleVectorClustering();
    }

    // 编辑器AI助手链接切换
    const enableEditorAssistant = document.getElementById('enable_editor_assistant');
    const editorAssistantLink = document.getElementById('editor_assistant_link_container');

    if (enableEditorAssistant && editorAssistantLink) {
        const toggleEditorAssistant = () => {
            editorAssistantLink.style.display = enableEditorAssistant.checked ? 'block' : 'none';
        };
        enableEditorAssistant.addEventListener('change', toggleEditorAssistant);
        toggleEditorAssistant();
    }

    // 参考资料选项切换
    const enableReferenceMaterial = document.getElementById('enable_reference_material');
    const aiReferenceSelectOptions = document.getElementById('ai_reference_select_options');

    if (enableReferenceMaterial) {
        const toggleAiReferenceSelectOptions = () => {
            if (enableReferenceMaterial.checked) {
                aiReferenceSelectOptions.style.display = 'block';
            } else {
                aiReferenceSelectOptions.style.display = 'none';
                const aiReferenceSelect = document.getElementById('enable_ai_reference_select');
                if (aiReferenceSelect) aiReferenceSelect.checked = false;
            }
        };
        enableReferenceMaterial.addEventListener('change', toggleAiReferenceSelectOptions);
        toggleAiReferenceSelectOptions();
    }

    // 文章结构入口切换
    const normalizeOutput = document.getElementById('normalize_output');
    const structureModeOptions = document.getElementById('structure_mode_options');
    const articleStructureLink = document.getElementById('article_structure_link_container');

    if (normalizeOutput && structureModeOptions) {
        const toggleStructureOptions = () => {
            structureModeOptions.style.display = normalizeOutput.checked ? 'block' : 'none';
        };
        normalizeOutput.addEventListener('change', toggleStructureOptions);
        toggleStructureOptions();
    }

    // 结构模式单选按钮切换 - 控制文章结构管理链接的显示
    const structureModeRadios = document.querySelectorAll('input[name="structure_mode"]');
    if (structureModeRadios.length > 0 && articleStructureLink) {
        const toggleStructureModeLink = () => {
            const selectedMode = document.querySelector('input[name="structure_mode"]:checked');
            articleStructureLink.style.display = (selectedMode && selectedMode.value === 'generic') ? 'block' : 'none';
        };
        structureModeRadios.forEach(radio => {
            radio.addEventListener('change', toggleStructureModeLink);
        });
        toggleStructureModeLink();
    }
});
</script>