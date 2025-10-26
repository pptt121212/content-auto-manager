<?php
/**
 * 发布规则页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。'));
}

// 检查是否是分类管理页面
$action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
if ($action === 'manage-categories') {
    // 显示分类管理页面
    require_once dirname(__FILE__) . '/category-filter-settings.php';
    return;
}

// 加载授权管理器
require_once dirname(dirname(dirname(__FILE__))) . '/includes/class-license-manager.php';

// 处理授权码提交
if (isset($_POST['submit_license']) && isset($_POST['content_auto_manager_license_nonce'])) {
    // 验证nonce
    if (!wp_verify_nonce($_POST['content_auto_manager_license_nonce'], 'content_auto_manager_license')) {
        wp_die(__('安全验证失败。'));
    }
    
    $license_key = sanitize_text_field($_POST['content_auto_manager_license_key']);
    if (!empty($license_key)) {
        update_option('content_auto_manager_license_key', $license_key);
        ContentAuto_License_Manager::activate_license($license_key);
    }
}

// 处理发布规则表单提交
if (isset($_POST['submit']) && isset($_POST['content_auto_manager_nonce'])) {
    // 验证nonce
    if (!wp_verify_nonce($_POST['content_auto_manager_nonce'], 'content_auto_manager_publish_rules')) {
        wp_die(__('安全验证失败。'));
    }
    
    // 检查授权状态
    if (!ContentAuto_License_Manager::is_license_active()) {
        echo '<div class="notice notice-error"><p>' . __('授权无效，无法修改发布规则。请先输入有效的授权码。', 'content-auto-manager') . '</p></div>';
    } else {
    
    // 获取表单数据
    $data = array(
        'post_status' => sanitize_text_field($_POST['post_status']),
        'author_id' => intval($_POST['author_id']),
        'category_mode' => sanitize_text_field($_POST['category_mode']),
        'category_ids' => isset($_POST['category_ids']) ? maybe_serialize($_POST['category_ids']) : '',
        'fallback_category_ids' => isset($_POST['fallback_category_ids']) ? maybe_serialize($_POST['fallback_category_ids']) : '',
        'target_length' => sanitize_text_field($_POST['target_length']),
        'knowledge_depth' => sanitize_text_field($_POST['knowledge_depth']),
        'reader_role' => sanitize_text_field($_POST['reader_role']),
        'normalize_output' => isset($_POST['normalize_output']) ? 1 : 0,
        'auto_image_insertion' => isset($_POST['auto_image_insertion']) ? 1 : 0,
        'max_auto_images' => isset($_POST['max_auto_images']) ? intval($_POST['max_auto_images']) : 1,
        'skip_first_image_placeholder' => isset($_POST['skip_first_image_placeholder']) ? 1 : 0,
        'enable_internal_linking' => isset($_POST['enable_internal_linking']) ? 1 : 0,
        'enable_brand_profile_insertion' => isset($_POST['enable_brand_profile_insertion']) ? 1 : 0,
        'brand_profile_position' => isset($_POST['brand_profile_position']) ? sanitize_text_field($_POST['brand_profile_position']) : 'before_second_paragraph',
        'enable_reference_material' => isset($_POST['enable_reference_material']) ? 1 : 0,
        'publish_interval_minutes' => intval($_POST['publish_interval_minutes']),
        'publish_language' => sanitize_text_field($_POST['publish_language']),
        'role_description' => sanitize_textarea_field($_POST['role_description'])
    );
    
    // 保存数据
    $database = new ContentAuto_Database();
    
    // 检查是否已存在发布规则
    $existing_rule = $database->get_row('content_auto_publish_rules', array('id' => 1));
    
    if ($existing_rule) {
        // 更新现有规则
        $result = $database->update('content_auto_publish_rules', $data, array('id' => 1));
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>' . __('发布规则已更新。', 'content-auto-manager') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('发布规则更新失败。', 'content-auto-manager') . '</p></div>';
        }
    } else {
        // 创建新规则
        $data['id'] = 1; // 固定ID为1
        $rule_id = $database->insert('content_auto_publish_rules', $data);
        if ($rule_id) {
            echo '<div class="notice notice-success"><p>' . __('发布规则已创建。', 'content-auto-manager') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('发布规则创建失败。', 'content-auto-manager') . '</p></div>';
        }
    }
    } // 结束授权检查
}

// 获取现有发布规则
$database = new ContentAuto_Database();
$publish_rule = $database->get_row('content_auto_publish_rules', array('id' => 1));

// 如果没有规则，使用默认值
if (!$publish_rule) {
    $publish_rule = array(
        'post_status' => CONTENT_AUTO_PUBLISH_STATUS_DRAFT,
        'author_id' => get_current_user_id(),
        'category_mode' => 'manual',
        'category_ids' => array(),
        'fallback_category_ids' => array(),
        'target_length' => '800-1500',
        'knowledge_depth' => '未设置',        // 内容深度 - 默认未设置
        'reader_role' => '未设置',            // 目标受众 - 默认未设置
        'normalize_output' => 0,
        'auto_image_insertion' => 0,
        'enable_internal_linking' => 0,  // 默认关闭文章内链功能
        'enable_brand_profile_insertion' => 0, // 默认关闭品牌资料植入功能
        'enable_reference_material' => 0, // 默认关闭参考资料功能
        'publish_interval_minutes' => 0,  // 默认立即发布
        'publish_language' => 'zh-CN',    // 默认中文
        'role_description' => '专业内容创作专家，精通SEO文案、用户体验设计、知识传播策略。您的任务是基于提供的文章标题创作正文内容，输出时直接从第一个章节标题开始，无需重复已提供的主标题。' // 默认角色描述
    );
} else {
    $publish_rule['category_ids'] = maybe_unserialize($publish_rule['category_ids']);
    $publish_rule['fallback_category_ids'] = maybe_unserialize($publish_rule['fallback_category_ids']);
}

// 加载分类过滤器
require_once dirname(__FILE__) . '/../class-category-filter.php';

// 获取用户和过滤后的分类
$users = get_users(array('who' => 'authors'));
$categories = ContentAuto_Category_Filter::get_filtered_categories();
?>

<div class="wrap">
    <h1><?php _e('发布规则', 'content-auto-manager'); ?></h1>
    
    <!-- 授权码设置区域 -->
    <div class="content-auto-section">
        <h2><?php _e('插件授权设置', 'content-auto-manager'); ?></h2>
        <p><?php _e('请输入有效的授权码以解锁发布规则配置功能。', 'content-auto-manager'); ?></p>
        
        <form method="post" action="">
            <?php wp_nonce_field('content_auto_manager_license', 'content_auto_manager_license_nonce'); ?>
            
            <table class="form-table">
                <?php ContentAuto_License_Manager::render_license_field(); ?>
            </table>
            
            <?php submit_button(__('验证授权码', 'content-auto-manager'), 'secondary', 'submit_license'); ?>
        </form>
    </div>
    
    <!-- 发布规则表单 -->
    <!-- 分类管理快捷入口 -->
    <div class="content-auto-section">
        <h2><?php _e('分类使用范围', 'content-auto-manager'); ?></h2>
        <?php 
        $filter_stats = ContentAuto_Category_Filter::get_filter_stats();
        if ($filter_stats['is_filtered']): 
        ?>
            <p><span class="dashicons dashicons-filter"></span> <?php printf(__('当前已限制插件使用 %d/%d 个分类（%s%%）', 'content-auto-manager'), $filter_stats['allowed_categories'], $filter_stats['total_categories'], $filter_stats['filter_percentage']); ?></p>
        <?php else: ?>
            <p><span class="dashicons dashicons-info"></span> <?php _e('当前插件可使用所有分类', 'content-auto-manager'); ?></p>
        <?php endif; ?>
        
        <p>
            <a href="?page=content-auto-manager-publish-rules&action=manage-categories" class="button button-secondary">
                <span class="dashicons dashicons-admin-settings"></span> <?php _e('管理可用分类', 'content-auto-manager'); ?>
            </a>
        </p>
        
        <?php if ($filter_stats['is_filtered']): ?>
        <div class="notice notice-info inline" style="margin-top: 10px;">
            <p><strong><?php _e('分类层级提示：', 'content-auto-manager'); ?></strong> <?php _e('如果您只选择了子分类而未选择其父分类，在某些功能中可能无法正常显示分类层级结构。建议同时选择相关的父分类以确保最佳体验。', 'content-auto-manager'); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <div class="content-auto-section">
        <h2><?php _e('配置发布规则', 'content-auto-manager'); ?></h2>
        
        <?php if (!ContentAuto_License_Manager::is_license_active()): ?>
            <div class="notice notice-warning inline">
                <p><strong><?php _e('注意：', 'content-auto-manager'); ?></strong> <?php _e('授权无效，发布规则将使用默认配置且无法修改。请先输入有效的授权码。', 'content-auto-manager'); ?></p>
            </div>
        <?php endif; ?>
        
        <form method="post" action="">
            <?php wp_nonce_field('content_auto_manager_publish_rules', 'content_auto_manager_nonce'); ?>
            
            <table class="form-table">
                <?php $is_licensed = ContentAuto_License_Manager::is_license_active(); ?>
                <tr>
                    <th scope="row"><?php _e('文章状态', 'content-auto-manager'); ?></th>
                    <td>
                        <select name="post_status" class="regular-text" id="post_status" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <option value="publish" <?php selected($publish_rule['post_status'], CONTENT_AUTO_PUBLISH_STATUS_PUBLISH); ?>>
                                <?php _e('已发布', 'content-auto-manager'); ?>
                            </option>
                            <option value="draft" <?php selected($publish_rule['post_status'], CONTENT_AUTO_PUBLISH_STATUS_DRAFT); ?>>
                                <?php _e('草稿', 'content-auto-manager'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('设置自动生成文章的默认发布状态。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr id="publish_interval_row" style="display: <?php echo ($publish_rule['post_status'] === 'publish') ? '' : 'none'; ?>;">
                    <th scope="row"><?php _e('发布时间间隔', 'content-auto-manager'); ?></th>
                    <td>
                        <input type="number" name="publish_interval_minutes" class="regular-text" value="<?php echo esc_attr($publish_rule['publish_interval_minutes'] ?? 0); ?>" min="0" max="1440" step="1" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                        <span class="description"><?php _e('分钟', 'content-auto-manager'); ?></span>
                        <p class="description">
                            <?php _e('设置文章发布的时间间隔（分钟）。设置为0表示立即发布。', 'content-auto-manager'); ?><br>
                            <?php _e('系统将根据最新发布文章的时间加上此间隔，作为下一篇文章的发布时间。', 'content-auto-manager'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('默认作者', 'content-auto-manager'); ?></th>
                    <td>
                        <select name="author_id" class="regular-text" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($publish_rule['author_id'], $user->ID); ?>>
                                    <?php echo esc_html($user->display_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('设置自动生成文章的默认作者。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('分类选择模式', 'content-auto-manager'); ?></th>
                    <td>
                        <select name="category_mode" class="regular-text" id="category_mode" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <option value="manual" <?php selected($publish_rule['category_mode'], 'manual'); ?>>
                                <?php _e('手动选择分类', 'content-auto-manager'); ?>
                            </option>
                            <option value="auto" <?php selected($publish_rule['category_mode'], 'auto'); ?>>
                                <?php _e('自动选择分类', 'content-auto-manager'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('手动选择：使用预设的分类；自动选择：根据主题的推荐分类自动匹配。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr id="manual_category_row" class="category-row">
                    <th scope="row"><?php _e('手动选择分类', 'content-auto-manager'); ?></th>
                    <td>
                        <select name="category_ids[]" multiple class="regular-text" style="height: 150px;">
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo esc_attr($category->term_id); ?>" <?php echo (is_array($publish_rule['category_ids']) && in_array($category->term_id, $publish_rule['category_ids'])) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($category->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('设置自动生成文章的默认分类。按住Ctrl键可多选。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr id="auto_category_row" class="category-row" style="display: none;">
                    <th scope="row"><?php _e('备用分类', 'content-auto-manager'); ?></th>
                    <td>
                        <select name="fallback_category_ids[]" multiple class="regular-text" style="height: 150px;">
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo esc_attr($category->term_id); ?>" <?php echo (is_array($publish_rule['fallback_category_ids']) && in_array($category->term_id, $publish_rule['fallback_category_ids'])) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($category->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php _e('当自动分类匹配失败时使用的备用分类。按住Ctrl键可多选。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('目标字数', 'content-auto-manager'); ?></th>
                    <td>
                        <select name="target_length" class="regular-text" id="target_length" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <option value="300-800" <?php selected($publish_rule['target_length'], '300-800'); ?>>
                                <?php _e('短文章 (300-800字)', 'content-auto-manager'); ?>
                            </option>
                            <option value="500-1000" <?php selected($publish_rule['target_length'], '500-1000'); ?>>
                                <?php _e('简短文章 (500-1000字)', 'content-auto-manager'); ?>
                            </option>
                            <option value="800-1500" <?php selected($publish_rule['target_length'], '800-1500'); ?>>
                                <?php _e('标准文章 (800-1500字)', 'content-auto-manager'); ?>
                            </option>
                            <option value="1000-2000" <?php selected($publish_rule['target_length'], '1000-2000'); ?>>
                                <?php _e('中等文章 (1000-2000字)', 'content-auto-manager'); ?>
                            </option>
                            <option value="1500-3000" <?php selected($publish_rule['target_length'], '1500-3000'); ?>>
                                <?php _e('长文章 (1500-3000字)', 'content-auto-manager'); ?>
                            </option>
                            <option value="2000-4000" <?php selected($publish_rule['target_length'], '2000-4000'); ?>>
                                <?php _e('长篇深度文章 (2000-4000字)', 'content-auto-manager'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('设置生成文章的目标字数范围。AI将根据此要求控制文章长度。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('内容深度', 'content-auto-manager'); ?></th>
                    <td>
                        <select name="knowledge_depth" class="regular-text" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <option value="未设置" <?php selected($publish_rule['knowledge_depth'], '未设置'); ?>>
                                <?php _e('未设置 - 不指定内容深度，由AI自由发挥', 'content-auto-manager'); ?>
                            </option>
                            <option value="浅层普及" <?php selected($publish_rule['knowledge_depth'], '浅层普及'); ?>>
                                <?php _e('浅层普及 - 快速了解概念，吸引广泛受众', 'content-auto-manager'); ?>
                            </option>
                            <option value="实用指导" <?php selected($publish_rule['knowledge_depth'], '实用指导'); ?>>
                                <?php _e('实用指导 - 提供操作方法，满足用户实际需求', 'content-auto-manager'); ?>
                            </option>
                            <option value="深度分析" <?php selected($publish_rule['knowledge_depth'], '深度分析'); ?>>
                                <?php _e('深度分析 - 专业洞察解读，建立行业权威形象', 'content-auto-manager'); ?>
                            </option>
                            <option value="全面综述" <?php selected($publish_rule['knowledge_depth'], '全面综述'); ?>>
                                <?php _e('全面综述 - 系统知识梳理，打造专业内容资产', 'content-auto-manager'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('设置内容深度，影响读者对品牌的认知和信任度。选择"未设置"时，AI将自由决定内容深度；其他选项将提供具体的写作指导。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('目标受众', 'content-auto-manager'); ?></th>
                    <td>
                        <select name="reader_role" class="regular-text" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <option value="未设置" <?php selected($publish_rule['reader_role'], '未设置'); ?>>
                                <?php _e('未设置 - 不指定目标受众，由AI自由发挥', 'content-auto-manager'); ?>
                            </option>
                            <option value="潜在客户" <?php selected($publish_rule['reader_role'], '潜在客户'); ?>>
                                <?php _e('潜在客户 - 关注产品价值和解决方案', 'content-auto-manager'); ?>
                            </option>
                            <option value="现有客户" <?php selected($publish_rule['reader_role'], '现有客户'); ?>>
                                <?php _e('现有客户 - 关注使用技巧和增值服务', 'content-auto-manager'); ?>
                            </option>
                            <option value="行业同仁" <?php selected($publish_rule['reader_role'], '行业同仁'); ?>>
                                <?php _e('行业同仁 - 关注专业见解和行业趋势', 'content-auto-manager'); ?>
                            </option>
                            <option value="决策者" <?php selected($publish_rule['reader_role'], '决策者'); ?>>
                                <?php _e('决策者 - 关注商业价值和战略意义', 'content-auto-manager'); ?>
                            </option>
                            <option value="泛流量用户" <?php selected($publish_rule['reader_role'], '泛流量用户'); ?>>
                                <?php _e('泛流量用户 - 关注热点话题和生活需求', 'content-auto-manager'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('设置目标受众，直接影响内容营销效果。选择"未设置"时，AI将自由决定目标受众；其他选项将提供具体的内容策略指导。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('文章结构指导', 'content-auto-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="normalize_output" value="1" <?php checked($publish_rule['normalize_output'], 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <?php _e('启用详细结构指导', 'content-auto-manager'); ?>
                        </label>
                        <p class="description">
                            <?php _e('启用后，系统将从"文章结构管理"页面生成的结构库中智能匹配最适合的4-7个章节模板，确保文章按照预定义的内容角度组织内容。', 'content-auto-manager'); ?>
                        </p>
                        <p class="description">
                            <?php _e('具体影响：AI将严格按照预定义的章节结构生成内容，每个章节都有明确的标题和内容要求。', 'content-auto-manager'); ?>
                        </p>
                        <p class="description">
                            <?php _e('适用场景：需要高度结构化、格式统一的专业文章，如企业博客、技术文档等。', 'content-auto-manager'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('文章自动配图', 'content-auto-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_image_insertion" value="1" <?php checked($publish_rule['auto_image_insertion'], 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?> id="auto_image_insertion">
                            <?php _e('启用文章自动配图', 'content-auto-manager'); ?>
                        </label>
                        <p class="description"><?php _e('启用后，AI将在文章中自动生成配图占位符，用于插入相关图片。', 'content-auto-manager'); ?></p>
                        
                        <div id="auto_image_options" style="margin-top: 15px; <?php echo !$publish_rule['auto_image_insertion'] ? 'display: none;' : ''; ?>">
                            <label for="max_auto_images" style="margin-right: 10px;">
                                <strong><?php _e('最大生成图片数量:', 'content-auto-manager'); ?></strong>
                            </label>
                            <select name="max_auto_images" id="max_auto_images" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                <option value="1" <?php selected($publish_rule['max_auto_images'] ?? '1', '1'); ?>>
                                    <?php _e('1张图片', 'content-auto-manager'); ?>
                                </option>
                                <option value="2" <?php selected($publish_rule['max_auto_images'] ?? '1', '2'); ?>>
                                    <?php _e('2张图片', 'content-auto-manager'); ?>
                                </option>
                                <option value="3" <?php selected($publish_rule['max_auto_images'] ?? '1', '3'); ?>>
                                    <?php _e('3张图片', 'content-auto-manager'); ?>
                                </option>
                                <option value="4" <?php selected($publish_rule['max_auto_images'] ?? '1', '4'); ?>>
                                    <?php _e('4张图片', 'content-auto-manager'); ?>
                                </option>
                                <option value="5" <?php selected($publish_rule['max_auto_images'] ?? '1', '5'); ?>>
                                    <?php _e('5张图片', 'content-auto-manager'); ?>
                                </option>
                            </select>
                            <p class="description" style="margin-top: 8px;">
                                <?php _e('设置一篇文章中最多生成多少张图片。如果文章中有5个图片占位符，但设置为只生成2张图片，则只会替换前2个占位符，其余占位符将被忽略。', 'content-auto-manager'); ?>
                            </p>
                            
                            <div style="margin-top: 15px;">
                                <label>
                                    <input type="checkbox" name="skip_first_image_placeholder" value="1" <?php checked($publish_rule['skip_first_image_placeholder'] ?? 0, 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                    <strong><?php _e('忽略首段落图片', 'content-auto-manager'); ?></strong>
                                </label>
                                <p class="description" style="margin-top: 8px;">
                                    <?php _e('启用后，系统将跳过文章中的第一个图片占位符，从第二个开始生成图片。这样可以保持首段的纯文字效果，避免首屏被图片占据。例如：文章有3个占位符，设置生成2张图片并启用此选项，将跳过第1个占位符，生成第2、3个占位符的图片。', 'content-auto-manager'); ?>
                                </p>
                            </div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('文章内链功能', 'content-auto-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_internal_linking" value="1" <?php checked($publish_rule['enable_internal_linking'], 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <?php _e('启用文章内链功能', 'content-auto-manager'); ?>
                        </label>
                        <p class="description"><?php _e('启用后，AI将在生成的文章中自然地融入已发布的相关文章标题和链接，提升网站内链建设。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('品牌资料植入', 'content-auto-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_brand_profile_insertion" value="1" <?php checked($publish_rule['enable_brand_profile_insertion'], 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?> id="enable_brand_profile_insertion">
                            <?php _e('启用品牌资料自动植入', 'content-auto-manager'); ?>
                        </label>
                        <p class="description"><?php _e('启用后，系统将根据文章标题，从您的品牌资料库中匹配最相关的一份，并将其自动插入到文章段落中。', 'content-auto-manager'); ?></p>
                        
                        <div id="brand_profile_options" style="margin-top: 15px; <?php echo !$publish_rule['enable_brand_profile_insertion'] ? 'display: none;' : ''; ?>">
                            <label for="brand_profile_position" style="margin-right: 10px;">
                                <strong><?php _e('品牌资料插入位置:', 'content-auto-manager'); ?></strong>
                            </label>
                            <div style="margin-top: 8px;">
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="radio" name="brand_profile_position" value="before_second_paragraph" <?php checked($publish_rule['brand_profile_position'] ?? 'before_second_paragraph', 'before_second_paragraph'); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                    <?php _e('第二段落前', 'content-auto-manager'); ?>
                                </label>
                                <label style="display: block;">
                                    <input type="radio" name="brand_profile_position" value="article_end" <?php checked($publish_rule['brand_profile_position'] ?? 'before_second_paragraph', 'article_end'); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                                    <?php _e('文章结尾', 'content-auto-manager'); ?>
                                </label>
                            </div>
                            <p class="description" style="margin-top: 8px;">
                                <?php _e('选择品牌资料在文章中的插入位置。"第二段落前"有助于提升品牌曝光度，"文章结尾"更适合作为补充信息。', 'content-auto-manager'); ?>
                            </p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('启用参考物料', 'content-auto-manager'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_reference_material" value="1" <?php checked($publish_rule['enable_reference_material'] ?? 0, 1); ?> <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <?php _e('启用参考资料功能', 'content-auto-manager'); ?>
                        </label>
                        <p class="description">
                            <?php _e('启用后，当主题和规则中都没有参考资料时，系统将从品牌资料中查找物料类型为"参考资料"的内容，按相似度匹配（相似度不低于0.8），并将描述内容插入到文章生成的提示词模板中作为参考资料。', 'content-auto-manager'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('发布语言', 'content-auto-manager'); ?></th>
                    <td>
                        <select name="publish_language" class="regular-text" <?php echo !$is_licensed ? 'disabled' : ''; ?>>
                            <option value="zh-CN" <?php selected($publish_rule['publish_language'], 'zh-CN'); ?>>
                                <?php _e('中文（简体）', 'content-auto-manager'); ?>
                            </option>
                            <option value="zh-TW" <?php selected($publish_rule['publish_language'], 'zh-TW'); ?>>
                                <?php _e('中文（繁体）', 'content-auto-manager'); ?>
                            </option>
                            <option value="en-US" <?php selected($publish_rule['publish_language'], 'en-US'); ?>>
                                <?php _e('英语（美式）', 'content-auto-manager'); ?>
                            </option>
                            <option value="en-GB" <?php selected($publish_rule['publish_language'], 'en-GB'); ?>>
                                <?php _e('英语（英式）', 'content-auto-manager'); ?>
                            </option>
                            <option value="ja-JP" <?php selected($publish_rule['publish_language'], 'ja-JP'); ?>>
                                <?php _e('日语', 'content-auto-manager'); ?>
                            </option>
                            <option value="ko-KR" <?php selected($publish_rule['publish_language'], 'ko-KR'); ?>>
                                <?php _e('韩语', 'content-auto-manager'); ?>
                            </option>
                            <option value="fr-FR" <?php selected($publish_rule['publish_language'], 'fr-FR'); ?>>
                                <?php _e('法语', 'content-auto-manager'); ?>
                            </option>
                            <option value="de-DE" <?php selected($publish_rule['publish_language'], 'de-DE'); ?>>
                                <?php _e('德语', 'content-auto-manager'); ?>
                            </option>
                            <option value="es-ES" <?php selected($publish_rule['publish_language'], 'es-ES'); ?>>
                                <?php _e('西班牙语', 'content-auto-manager'); ?>
                            </option>
                            <option value="pt-BR" <?php selected($publish_rule['publish_language'], 'pt-BR'); ?>>
                                <?php _e('葡萄牙语（巴西）', 'content-auto-manager'); ?>
                            </option>
                            <option value="ru-RU" <?php selected($publish_rule['publish_language'], 'ru-RU'); ?>>
                                <?php _e('俄语', 'content-auto-manager'); ?>
                            </option>
                            <option value="ar-SA" <?php selected($publish_rule['publish_language'], 'ar-SA'); ?>>
                                <?php _e('阿拉伯语', 'content-auto-manager'); ?>
                            </option>
                            <option value="hi-IN" <?php selected($publish_rule['publish_language'], 'hi-IN'); ?>>
                                <?php _e('印地语', 'content-auto-manager'); ?>
                            </option>
                            <option value="th-TH" <?php selected($publish_rule['publish_language'], 'th-TH'); ?>>
                                <?php _e('泰语', 'content-auto-manager'); ?>
                            </option>
                            <option value="vi-VN" <?php selected($publish_rule['publish_language'], 'vi-VN'); ?>>
                                <?php _e('越南语', 'content-auto-manager'); ?>
                            </option>
                            <option value="id-ID" <?php selected($publish_rule['publish_language'], 'id-ID'); ?>>
                                <?php _e('印尼语', 'content-auto-manager'); ?>
                            </option>
                        </select>
                        <p class="description"><?php _e('选择文章生成的目标语言。此设置会影响主题任务、文章任务、文章结构生成时的输出语言。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('AI角色描述', 'content-auto-manager'); ?></th>
                    <td>
                        <textarea name="role_description" rows="4" class="large-text" <?php echo !$is_licensed ? 'disabled' : ''; ?> placeholder="<?php _e('例如：专业内容创作专家，精通SEO文案、用户体验设计、知识传播策略。您的任务是基于提供的文章标题创作正文内容，输出时直接从第一个章节标题开始，无需重复已提供的主标题。', 'content-auto-manager'); ?>"><?php echo esc_textarea($publish_rule['role_description'] ?? ''); ?></textarea>
                        <p class="description"><?php _e('定义AI在生成文章时的角色和专业能力。这个描述将作为提示词模板中的&lt;role&gt;标签内容，影响AI的写作风格和专业度。留空将使用默认角色描述。', 'content-auto-manager'); ?></p>
                    </td>
                </tr>
            </table>
            
            <?php 
            if ($is_licensed) {
                submit_button(__('保存规则', 'content-auto-manager'));
            } else {
                echo '<p class="description"><strong>' . __('注意：需要有效授权码才能保存发布规则配置。', 'content-auto-manager') . '</strong></p>';
            }
            ?>
        </form>
    </div>
    
    <!-- 规则说明 -->
    <div class="content-auto-section">
        <h2><?php _e('规则说明', 'content-auto-manager'); ?></h2>
        
        <p><?php _e('发布规则用于定义自动生成文章的默认设置：', 'content-auto-manager'); ?></p>
        
        <ul>
            <li><strong><?php _e('品牌资料植入', 'content-auto-manager'); ?></strong>: <?php _e('启用后，系统将根据文章标题，从您的品牌资料库中匹配最相关的一份，并将其自动插入到文章中。可以选择插入位置："第二段落前"有助于提升品牌曝光度，"文章结尾"更适合作为补充信息。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('发布时间间隔', 'content-auto-manager'); ?></strong>: <?php _e('当选择"已发布"状态时，可以设置文章发布的时间间隔（分钟）。系统会根据最新发布文章的时间加上设置的间隔，作为下一篇文章的发布时间。设置为0表示立即发布。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('默认作者', 'content-auto-manager'); ?></strong>: <?php _e('设置文章的默认作者。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('分类选择模式', 'content-auto-manager'); ?></strong>: <?php _e('手动选择：使用预设的分类；自动选择：根据主题的推荐分类自动匹配。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('手动选择分类', 'content-auto-manager'); ?></strong>: <?php _e('手动模式下使用的预设分类。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('备用分类', 'content-auto-manager'); ?></strong>: <?php _e('自动分类匹配失败时使用的备用分类。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('目标字数', 'content-auto-manager'); ?></strong>: <?php _e('设置生成文章的目标字数范围。AI将根据此要求控制文章长度，确保内容符合预期。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('内容深度', 'content-auto-manager'); ?></strong>: <?php _e('设置内容深度，影响读者对品牌的认知和信任度。浅层普及吸引流量，实用指导促进转化，深度分析建立权威，全面综述沉淀资产。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('目标受众', 'content-auto-manager'); ?></strong>: <?php _e('设置目标受众，直接影响内容营销效果。不同受众需要不同的内容策略和表达方式。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('文章结构指导', 'content-auto-manager'); ?></strong>: <?php _e('启用后，系统将从"文章结构管理"页面生成的结构库中智能匹配最适合的4-7个章节模板，确保文章按照预定义的内容角度组织内容。具体影响：AI将按照预定义的章节结构生成内容，每个章节都有明确的标题和内容要求。适用场景：需要结构化、格式统一的专业文章，如企业博客、技术文档等。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('文章自动配图', 'content-auto-manager'); ?></strong>: <?php _e('启用后，AI将在文章中自动生成配图占位符，用于插入相关图片。可以设置最大生成图片数量（1-5张），如果文章中有多个图片占位符，系统将按顺序处理指定数量的占位符，其余占位符将被忽略。支持"忽略首段落图片"选项，启用后将跳过第一个占位符，从第二个开始生成，保持首段纯文字效果。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('文章内链功能', 'content-auto-manager'); ?></strong>: <?php _e('启用后，AI将在生成的文章中自然地融入已发布的相关文章标题和链接，提升网站内链建设。', 'content-auto-manager'); ?></li>
            <li><strong><?php _e('发布语言', 'content-auto-manager'); ?></strong>: <?php _e('选择文章生成的目标语言。此设置会影响主题任务、文章任务、文章结构生成时的输出语言，支持全球主流语言包括中文、英语、日语、韩语、法语、德语、西班牙语、葡萄牙语、俄语、阿拉伯语、印地语、泰语、越南语、印尼语等。', 'content-auto-manager'); ?></li>
        </ul>
        
        <p><?php _e('这些规则将应用于所有通过插件自动生成的文章。', 'content-auto-manager'); ?></p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 分类模式切换
    var categoryMode = document.getElementById('category_mode');
    var manualRow = document.getElementById('manual_category_row');
    var autoRow = document.getElementById('auto_category_row');

    function toggleCategoryRows() {
        if (categoryMode.value === 'manual') {
            manualRow.style.display = '';
            autoRow.style.display = 'none';
        } else {
            manualRow.style.display = 'none';
            autoRow.style.display = '';
        }
    }

    // 发布状态切换
    var postStatus = document.getElementById('post_status');
    var intervalRow = document.getElementById('publish_interval_row');

    function toggleIntervalRow() {
        if (postStatus.value === 'publish') {
            intervalRow.style.display = '';
        } else {
            intervalRow.style.display = 'none';
        }
    }

    // 自动配图选项切换
    var autoImageInsertion = document.getElementById('auto_image_insertion');
    var autoImageOptions = document.getElementById('auto_image_options');

    function toggleAutoImageOptions() {
        if (autoImageInsertion.checked) {
            autoImageOptions.style.display = '';
        } else {
            autoImageOptions.style.display = 'none';
        }
    }

    // 品牌资料选项切换
    var enableBrandProfile = document.getElementById('enable_brand_profile_insertion');
    var brandProfileOptions = document.getElementById('brand_profile_options');

    function toggleBrandProfileOptions() {
        if (enableBrandProfile.checked) {
            brandProfileOptions.style.display = '';
        } else {
            brandProfileOptions.style.display = 'none';
        }
    }

    // 初始化显示状态
    toggleCategoryRows();
    toggleIntervalRow();
    toggleAutoImageOptions();
    toggleBrandProfileOptions();

    // 监听模式变化
    categoryMode.addEventListener('change', toggleCategoryRows);
    postStatus.addEventListener('change', toggleIntervalRow);
    autoImageInsertion.addEventListener('change', toggleAutoImageOptions);
    enableBrandProfile.addEventListener('change', toggleBrandProfileOptions);
});
</script>

<style>
.content-auto-section {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
</style>