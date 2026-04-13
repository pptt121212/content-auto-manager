<?php
/**
 * 分类过滤设置页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。', 'yali-ai-writer'));
}

?>
<?php

// 表单处理已移至 admin/form-handlers.php，在 admin_init 钩子中执行

// 获取当前设置
$allowed_categories = get_option('yali_ai_writer_manager_allowed_categories', array());
$is_enabled = get_option('yali_ai_writer_manager_category_filter_enabled', 0); // 默认不启用

// 获取所有分类（包括层级结构）
$categories = get_categories(array(
    'hide_empty' => false,
    'orderby' => 'name',
    'order' => 'ASC'
));

// 构建分类树
function build_category_tree($categories, $parent_id = 0) {
    $tree = array();
    foreach ($categories as $category) {
        if ($category->parent == $parent_id) {
            $children = build_category_tree($categories, $category->term_id);
            $category->children = $children;
            $tree[] = $category;
        }
    }
    return $tree;
}

$category_tree = build_category_tree($categories);

// 渲染分类树的递归函数
function render_category_tree($categories, $allowed_categories, $level = 0) {
    foreach ($categories as $category) {
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
        $checked = in_array($category->term_id, $allowed_categories) ? 'checked' : '';
        
        echo '<div class="category-item" style="margin-left: ' . ($level * 20) . 'px;">';
        echo '<label class="yali-checkbox-label">';
        echo '<input type="checkbox" name="allowed_category_ids[]" value="' . esc_attr($category->term_id) . '" ' . $checked . ' class="category-checkbox" data-level="' . $level . '">';
        echo $indent . esc_html($category->name) . ' <span class="category-count">(' . $category->count . ')</span>';
        echo '</label>';
        echo '</div>';
        
        if (!empty($category->children)) {
            render_category_tree($category->children, $allowed_categories, $level + 1);
        }
    }
}
?>

<div class="wrap yali-plugin-wrapper" id="yali-category-filter-page">
    <h1 class="yali-page-title">
        <span class="dashicons dashicons-category"></span> <?php _e('管理可用分类', 'yali-ai-writer'); ?>
    </h1>

    <div style="margin-bottom: 20px;">
        <a href="?page=yali-ai-writer-publish-rules" class="yali-btn yali-btn-secondary">
            <span class="dashicons dashicons-arrow-left-alt"></span> <?php _e('返回发布规则', 'yali-ai-writer'); ?>
        </a>
    </div>
    
    <div class="yali-card">
        <div class="yali-card-header">
            <div class="yali-card-title"><?php _e('选择允许插件使用的分类', 'yali-ai-writer'); ?></div>
        </div>
        <div class="yali-card-body">
            <form method="post" action="" id="yali-category-filter-form">
                <?php wp_nonce_field('yali_ai_writer_manager_category_filter', 'yali_ai_writer_manager_category_filter_nonce'); ?>
                
                <div class="yali-form-group">
                    <label class="yali-checkbox-label" style="font-weight: 600; font-size: 15px;">
                        <input type="checkbox" name="yali_enable_category_filter" value="1" <?php checked($is_enabled, 1); ?>>
                        <?php _e('启用分类过滤', 'yali-ai-writer'); ?>
                    </label>
                    <p class="yali-desc"><?php _e('如果勾选此项，则插件的功能（如规则管理、文章发布等）将仅限使用下方选中的分类。如果不勾选，则可以使用系统所有分类。', 'yali-ai-writer'); ?></p>
                </div>

                <div class="yali-form-group" id="category-selection-container" style="margin-top: 30px; <?php echo $is_enabled ? '' : 'display:none;'; ?>">
                    <h3 style="margin-bottom: 10px;"><?php _e('可用分类选择', 'yali-ai-writer'); ?></h3>
                    <p class="yali-desc" style="margin-bottom: 20px;"><?php _e('请选择插件可使用的分类列表。建议同时选中相关的父级分类。', 'yali-ai-writer'); ?></p>

                    <div class="category-filter-controls yali-panel" style="margin-bottom: 20px;">
                        <div class="yali-flex-between">
                            <div class="yali-flex-row">
                                <button type="button" id="select-all-categories" class="yali-btn yali-btn-secondary yali-btn-small"><?php _e('全选', 'yali-ai-writer'); ?></button>
                                <button type="button" id="deselect-all-categories" class="yali-btn yali-btn-secondary yali-btn-small"><?php _e('全不选', 'yali-ai-writer'); ?></button>
                                <button type="button" id="toggle-parent-categories" class="yali-btn yali-btn-secondary yali-btn-small"><?php _e('只选择父分类', 'yali-ai-writer'); ?></button>
                            </div>
                            <div>
                                <input type="text" id="category-search" placeholder="<?php _e('搜索分类...', 'yali-ai-writer'); ?>" class="yali-input" style="width: 200px;">
                            </div>
                        </div>
                    </div>
                    
                    <div class="category-list-container yali-panel" style="max-height: 400px; overflow-y: auto; padding: 15px;">
                        <?php if (empty($category_tree)): ?>
                            <p><?php _e('暂无分类。', 'yali-ai-writer'); ?></p>
                        <?php else: ?>
                            <div class="category-tree">
                                <?php render_category_tree($category_tree, $allowed_categories); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="yali-notice yali-notice-info" style="margin-top: 20px;">
                        <p>
                            <strong><?php _e('说明：', 'yali-ai-writer'); ?></strong><br>
                            • <?php _e('未选择任何分类时，插件将使用所有分类', 'yali-ai-writer'); ?><br>
                            • <?php _e('选择分类后，插件的所有功能（发布规则、主题管理等）都只会显示和使用这些分类', 'yali-ai-writer'); ?><br>
                            • <?php _e('分类后的数字表示该分类下的文章数量', 'yali-ai-writer'); ?>
                        </p>
                    </div>
                </div>
                
                <div class="yali-card-footer" style="padding-left: 0; margin-top: 20px;">
                    <?php submit_button(__('保存设置', 'yali-ai-writer'), 'primary yali-btn yali-btn-primary', 'submit_category_filter', false); ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 当前设置状态 -->
    <div class="yali-card">
        <div class="yali-card-header">
            <div class="yali-card-title"><?php _e('当前设置状态', 'yali-ai-writer'); ?></div>
        </div>
        <div class="yali-card-body">
            <?php if (!$is_enabled): ?>
                <div class="yali-notice yali-notice-info" style="margin: 0;">
                    <p><span class="dashicons dashicons-info"></span> <?php _e('当前未启用分类过滤，插件将使用所有分类。', 'yali-ai-writer'); ?></p>
                </div>
            <?php elseif (empty($allowed_categories)): ?>
                <div class="yali-notice yali-notice-warning" style="margin: 0;">
                    <p><span class="dashicons dashicons-warning"></span> <?php _e('分类过滤已启用，但未选择任何分类。这意味着插件将无法使用任何分类。请至少选择一个分类。', 'yali-ai-writer'); ?></p>
                </div>
            <?php else: ?>
                <div class="yali-notice yali-notice-success" style="margin: 0;">
                    <p><span class="dashicons dashicons-yes-alt"></span> <?php printf(__('当前已选择 %d 个分类供插件使用：', 'yali-ai-writer'), count($allowed_categories)); ?></p>
                </div>
                <div style="margin-top: 15px;">
                    <?php
                    $selected_category_names = array();
                    foreach ($categories as $category) {
                        if (in_array($category->term_id, $allowed_categories)) {
                            $selected_category_names[] = $category->name;
                        }
                    }
                    echo '<code>' . implode('、', $selected_category_names) . '</code>';
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
