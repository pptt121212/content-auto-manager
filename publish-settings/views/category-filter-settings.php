<?php
/**
 * 分类过滤设置页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。'));
}

// 处理分类过滤设置表单提交
if (isset($_POST['submit_category_filter']) && isset($_POST['content_auto_manager_category_filter_nonce'])) {
    // 验证nonce
    if (!wp_verify_nonce($_POST['content_auto_manager_category_filter_nonce'], 'content_auto_manager_category_filter')) {
        wp_die(__('安全验证失败。'));
    }
    
    // 获取选中的分类ID
    $allowed_category_ids = isset($_POST['allowed_category_ids']) ? array_map('intval', $_POST['allowed_category_ids']) : array();
    
    // 保存设置
    update_option('content_auto_manager_allowed_categories', $allowed_category_ids);
    
    echo '<div class="notice notice-success"><p>' . __('分类过滤设置已保存。', 'content-auto-manager') . '</p></div>';
}

// 获取当前设置
$allowed_categories = get_option('content_auto_manager_allowed_categories', array());

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
        echo '<label>';
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

<div class="wrap">
    <h1><?php _e('分类使用范围设置', 'content-auto-manager'); ?></h1>
    
    <p>
        <a href="?page=content-auto-manager-publish-rules" class="button button-secondary">
            <span class="dashicons dashicons-arrow-left-alt"></span> <?php _e('返回发布规则', 'content-auto-manager'); ?>
        </a>
    </p>
    
    <div class="content-auto-section">
        <h2><?php _e('可用分类管理', 'content-auto-manager'); ?></h2>
        <p><?php _e('选择插件可以使用的分类。只有勾选的分类才会在发布规则、主题管理等功能中出现。', 'content-auto-manager'); ?></p>
        
        <form method="post" action="">
            <?php wp_nonce_field('content_auto_manager_category_filter', 'content_auto_manager_category_filter_nonce'); ?>
            
            <div class="category-filter-controls" style="margin-bottom: 20px;">
                <button type="button" id="select-all-categories" class="button"><?php _e('全选', 'content-auto-manager'); ?></button>
                <button type="button" id="deselect-all-categories" class="button"><?php _e('全不选', 'content-auto-manager'); ?></button>
                <button type="button" id="toggle-parent-categories" class="button"><?php _e('只选择父分类', 'content-auto-manager'); ?></button>
                
                <div style="float: right;">
                    <input type="text" id="category-search" placeholder="<?php _e('搜索分类...', 'content-auto-manager'); ?>" class="regular-text">
                </div>
                <div style="clear: both;"></div>
            </div>
            
            <div class="category-list-container" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; background: #f9f9f9;">
                <?php if (empty($category_tree)): ?>
                    <p><?php _e('暂无分类。', 'content-auto-manager'); ?></p>
                <?php else: ?>
                    <div class="category-tree">
                        <?php render_category_tree($category_tree, $allowed_categories); ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div style="margin-top: 20px;">
                <p class="description">
                    <strong><?php _e('说明：', 'content-auto-manager'); ?></strong><br>
                    • <?php _e('未选择任何分类时，插件将使用所有分类', 'content-auto-manager'); ?><br>
                    • <?php _e('选择分类后，插件的所有功能（发布规则、主题管理等）都只会显示和使用这些分类', 'content-auto-manager'); ?><br>
                    • <?php _e('分类后的数字表示该分类下的文章数量', 'content-auto-manager'); ?>
                </p>
            </div>
            
            <?php submit_button(__('保存分类设置', 'content-auto-manager'), 'primary', 'submit_category_filter'); ?>
        </form>
    </div>
    
    <!-- 当前设置状态 -->
    <div class="content-auto-section">
        <h2><?php _e('当前设置状态', 'content-auto-manager'); ?></h2>
        <?php if (empty($allowed_categories)): ?>
            <p><span class="dashicons dashicons-info"></span> <?php _e('当前未限制分类使用范围，插件将使用所有分类。', 'content-auto-manager'); ?></p>
        <?php else: ?>
            <p><span class="dashicons dashicons-yes-alt"></span> <?php printf(__('当前已选择 %d 个分类供插件使用：', 'content-auto-manager'), count($allowed_categories)); ?></p>
            <div style="margin-top: 10px;">
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 全选功能
    document.getElementById('select-all-categories').addEventListener('click', function() {
        var checkboxes = document.querySelectorAll('.category-checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.checked = true;
        });
    });
    
    // 全不选功能
    document.getElementById('deselect-all-categories').addEventListener('click', function() {
        var checkboxes = document.querySelectorAll('.category-checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.checked = false;
        });
    });
    
    // 只选择父分类
    document.getElementById('toggle-parent-categories').addEventListener('click', function() {
        var checkboxes = document.querySelectorAll('.category-checkbox');
        checkboxes.forEach(function(checkbox) {
            // 只选择顶级分类（level=0）
            checkbox.checked = checkbox.getAttribute('data-level') === '0';
        });
    });
    
    // 搜索功能
    document.getElementById('category-search').addEventListener('input', function() {
        var searchTerm = this.value.toLowerCase();
        var categoryItems = document.querySelectorAll('.category-item');
        
        categoryItems.forEach(function(item) {
            var categoryName = item.textContent.toLowerCase();
            if (categoryName.includes(searchTerm)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    });
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

.category-item {
    padding: 5px 0;
    border-bottom: 1px solid #eee;
}

.category-item:last-child {
    border-bottom: none;
}

.category-item label {
    cursor: pointer;
    display: block;
    padding: 5px;
}

.category-item label:hover {
    background-color: #f0f0f0;
}

.category-count {
    color: #666;
    font-size: 0.9em;
}

.category-filter-controls {
    padding: 10px;
    background: #f0f0f0;
    border-radius: 3px;
}

.category-filter-controls .button {
    margin-right: 10px;
}
</style>