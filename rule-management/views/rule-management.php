<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 自定义函数，生成带文章计数的、有层级的分类复选框列表
 *
 * @param int $parent_id 父分类ID
 * @param array $selected_cats 已选择的分类ID数组
 */
function cam_category_checklist_with_count($parent_id = 0, $selected_cats = array()) {
    // 使用分类过滤器获取允许的分类
    if (class_exists('Yali_AI_Writer_Category_Filter')) {
        $categories = Yali_AI_Writer_Category_Filter::get_filtered_categories(array(
            'hide_empty' => 0,
            'parent' => $parent_id,
            'taxonomy' => 'category',
        ));
    } else {
        $categories = get_categories(array(
            'hide_empty' => 0,
            'parent' => $parent_id,
            'taxonomy' => 'category',
        ));
    }

    if ($categories) {
        echo $parent_id == 0 ? '<ul class="category-checklist">': '<ul class="children">';
        foreach ($categories as $category) {
            $checked = in_array($category->term_id, $selected_cats) ? 'checked="checked"' : '';
            echo '<li id="category-' . $category->term_id . '">';
            echo '<label class="selectit"><input value="' . $category->term_id . '" type="checkbox" name="categories[]" id="in-category-' . $category->term_id . '"' . $checked . '> ' . esc_html(__($category->name, 'yali-ai-writer')) . ' (' . $category->count . ')</label>';
            // 递归调用以显示子分类
            // translators: %s category name, %d post count
            // echo '<label class="selectit"><input value="' . $category->term_id . '" type="checkbox" name="categories[]" id="in-category-' . $category->term_id . '"' . $checked . '> ' . sprintf(__('%s (%d)', 'yali-ai-writer'), esc_html($category->name), $category->count) . '</label>';
            cam_category_checklist_with_count($category->term_id, $selected_cats);
            echo '</li>';
        }
        echo '</ul>';
    }
}

/**
 * 显示层级分类选择列表（保持完整层级，未选择的父分类显示为禁用）
 * @param array $selected_cats 已选择的分类ID数组
 */
function cam_hierarchical_category_checklist_with_count($selected_cats = array()) {
    // 获取所有WordPress分类（用于显示完整层级）
    $all_categories = get_categories(array(
        'hide_empty' => 0,
        'taxonomy' => 'category',
        'orderby' => 'name',
        'order' => 'ASC'
    ));

    // 获取过滤后的分类ID（用于确定哪些可以选择）
    $allowed_category_ids = array();
    if (class_exists('Yali_AI_Writer_Category_Filter')) {
        $filtered_categories = Yali_AI_Writer_Category_Filter::get_filtered_categories(array(
            'hide_empty' => 0,
            'taxonomy' => 'category'
        ));
        $allowed_category_ids = wp_list_pluck($filtered_categories, 'term_id');
    } else {
        $allowed_category_ids = wp_list_pluck($all_categories, 'term_id');
    }

    if (empty($all_categories)) {
        echo '<p>' . __('没有可用的分类', 'yali-ai-writer') . '</p>';
        return;
    }

    // 构建完整的分类树结构
    $category_tree = cam_build_hierarchical_category_tree($all_categories, $allowed_category_ids);
    
    echo '<ul class="category-checklist">';
    cam_render_hierarchical_category_tree($category_tree, $selected_cats, $allowed_category_ids, 0);
    echo '</ul>';
}

/**
 * 构建层级分类树（保持完整WordPress分类层级）
 */
function cam_build_hierarchical_category_tree($all_categories, $allowed_category_ids) {
    $tree = array();
    $category_map = array();
    
    // 建立分类映射
    foreach ($all_categories as $category) {
        $category_map[$category->term_id] = $category;
    }
    
    // 构建树结构 - 从顶级分类开始
    foreach ($all_categories as $category) {
        if ($category->parent == 0) {
            $tree[$category->term_id] = array(
                'category' => $category,
                'children' => array(),
                'level' => 0
            );
        }
    }
    
    // 递归添加子分类
    cam_add_children_to_tree($tree, $category_map, $all_categories);
    
    // 按名称排序
    uasort($tree, function($a, $b) {
        return strcmp($a['category']->name, $b['category']->name);
    });
    
    return $tree;
}

/**
 * 递归添加子分类到树结构
 */
function cam_add_children_to_tree(&$tree, $category_map, $all_categories) {
    foreach ($tree as $cat_id => &$node) {
        // 查找当前分类的子分类
        foreach ($all_categories as $category) {
            if ($category->parent == $cat_id) {
                $node['children'][$category->term_id] = array(
                    'category' => $category,
                    'children' => array(),
                    'level' => $node['level'] + 1
                );
            }
        }
        
        // 如果有子分类，递归处理
        if (!empty($node['children'])) {
            // 按名称排序子分类
            uasort($node['children'], function($a, $b) {
                return strcmp($a['category']->name, $b['category']->name);
            });
            
            // 递归添加更深层的子分类
            cam_add_children_to_tree($node['children'], $category_map, $all_categories);
        }
    }
}

/**
 * 渲染层级分类树
 */
function cam_render_hierarchical_category_tree($tree, $selected_cats, $allowed_category_ids, $level) {
    foreach ($tree as $cat_id => $node) {
        $category = $node['category'];
        $is_allowed = in_array($category->term_id, $allowed_category_ids);
        $checked = in_array($category->term_id, $selected_cats) ? 'checked="checked"' : '';
        $disabled = !$is_allowed ? 'disabled' : '';
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
        
        // 设置样式类
        $label_class = 'selectit';
        if (!$is_allowed) {
            $label_class .= ' disabled-category';
        }
        
        echo '<li id="category-' . $category->term_id . '" class="' . ($is_allowed ? 'allowed' : 'disabled') . '">';
        echo '<label class="' . $label_class . '">';
        echo '<input value="' . $category->term_id . '" type="checkbox" name="categories[]" id="in-category-' . $category->term_id . '"' . $checked . ' ' . $disabled . '> ';
        echo $indent . sprintf(__('%s (%d)', 'yali-ai-writer'), esc_html(__($category->name, 'yali-ai-writer')), $category->count);
        
        // 为禁用的分类添加提示
        if (!$is_allowed) {
            echo ' <span class="disabled-hint">' . __('(未启用)', 'yali-ai-writer') . '</span>';
        }
        
        echo '</label>';
        
        // 递归显示子分类
        if (!empty($node['children'])) {
            echo '<ul class="children">';
            cam_render_hierarchical_category_tree($node['children'], $selected_cats, $allowed_category_ids, $level + 1);
            echo '</ul>';
        }
        
        echo '</li>';
    }
}



// 检查是否是编辑模式
$is_edit_mode = isset($_GET['action']) && $_GET['action'] === 'edit';
$rule = null;
$selected_cats = array();
$selected_random_cats = array();
$selected_articles = array();
$upload_text_content = '';
$keywords_content = '';
$collect_url_content = '';

if ($is_edit_mode && isset($_GET['id'])) {
    global $wpdb;
    $rules_table = $wpdb->prefix . 'yali_ai_writer_rules';
    $rule_id = intval($_GET['id']);

    // 获取现有规则
    $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$rules_table} WHERE id = %d", $rule_id));

    if ($rule) {
        // 反序列化规则条件
        $conditions = maybe_unserialize($rule->rule_conditions);

        // 根据规则类型设置已选择的值
        if ($rule->rule_type === 'random_selection' && isset($conditions['categories'])) {
            $selected_cats = $conditions['categories'];
        } elseif ($rule->rule_type === 'random_categories' && isset($conditions['categories'])) {
            $selected_random_cats = $conditions['categories'];
        } elseif ($rule->rule_type === 'fixed_articles' && isset($conditions['post_ids'])) {
            $selected_articles = $conditions['post_ids'];
        } elseif ($rule->rule_type === 'upload_text' && isset($conditions['upload_text'])) {
            $upload_text_content = $conditions['upload_text'];
        } elseif ($rule->rule_type === 'import_keywords' && isset($conditions['keywords'])) {
            $keywords_content = implode("\n", $conditions['keywords']);
        } elseif ($rule->rule_type === 'collect_url_rewrite' && isset($conditions['collect_url_content'])) {
            $collect_url_content = implode("\n", $conditions['collect_url_content']);
        }
    }
}
?>
<div class="wrap yali-plugin-wrapper">
    <h1 class="yali-page-title"><span class="dashicons dashicons-edit"></span> <?php echo $is_edit_mode ? __('编辑规则', 'yali-ai-writer') : __('添加新规则', 'yali-ai-writer'); ?></h1>

    <form id="add-rule-form" method="post" action="">
        <div class="yali-card">
        <!-- 安全随机数 -->
        <?php wp_nonce_field('cam_save_rule_action', 'cam_save_rule_nonce'); ?>
        <?php if ($is_edit_mode && $rule): ?>
            <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule->id); ?>">
        <?php endif; ?>

            <!-- 规则名称 -->
            <table class="form-table">
                <tr class="form-field">
                    <th scope="row"><label for="rule_name"><?php _e('规则名称', 'yali-ai-writer'); ?></label></th>
                    <td><input type="text" id="rule_name" name="rule_name" class="regular-text yali-input" value="<?php echo $rule ? esc_attr($rule->rule_name) : ''; ?>" required></td>
                </tr>

            <!-- 规则类型 -->
            <tr class="form-field">
                <th scope="row"><?php _e('规则类型', 'yali-ai-writer'); ?></th>
                <td>
                    <fieldset>
                        <label><input type="radio" name="rule_type" value="random_selection" <?php echo (!$rule || $rule->rule_type === 'random_selection') ? 'checked' : ''; ?>> <?php echo Yali_AI_Writer_RuleManager::get_rule_type_label('random_selection'); ?></label>
                        <br>
                        <label><input type="radio" name="rule_type" value="fixed_articles" <?php echo ($rule && $rule->rule_type === 'fixed_articles') ? 'checked' : ''; ?>> <?php echo Yali_AI_Writer_RuleManager::get_rule_type_label('fixed_articles'); ?></label>
                        <br>
                        <label><input type="radio" name="rule_type" value="upload_text" <?php echo ($rule && $rule->rule_type === 'upload_text') ? 'checked' : ''; ?>> <?php echo Yali_AI_Writer_RuleManager::get_rule_type_label('upload_text'); ?></label>
                        <br>
                        <label><input type="radio" name="rule_type" value="import_keywords" <?php echo ($rule && $rule->rule_type === 'import_keywords') ? 'checked' : ''; ?>> <?php echo Yali_AI_Writer_RuleManager::get_rule_type_label('import_keywords'); ?></label>
                        <br>
                        <label><input type="radio" name="rule_type" value="random_categories" <?php echo ($rule && $rule->rule_type === 'random_categories') ? 'checked' : ''; ?>> <?php echo Yali_AI_Writer_RuleManager::get_rule_type_label('random_categories'); ?></label>
                        <br>
                        <label><input type="radio" name="rule_type" value="collect_url_rewrite" <?php echo ($rule && $rule->rule_type === 'collect_url_rewrite') ? 'checked' : ''; ?>> <?php echo Yali_AI_Writer_RuleManager::get_rule_type_label('collect_url_rewrite'); ?></label>
                    </fieldset>
                </td>
            </tr>

            <!-- 条件：随机选择 -->
            <tr id="condition-random-selection" class="rule-condition-group form-field" style="<?php echo (!$rule || $rule->rule_type === 'random_selection') ? '' : 'display: none;'; ?>">
                <th scope="row"><label><?php _e('文章分类', 'yali-ai-writer'); ?></label></th>
                <td>
                    <div class="category-checklist-actions">
                        <a href="#" id="select-all-cats"><?php _e('全选', 'yali-ai-writer'); ?></a> | <a href="#" id="deselect-all-cats"><?php _e('全不选', 'yali-ai-writer'); ?></a>
                    </div>
                    <div id="category-checklist-container" class="category-checklist-container">
                        <?php
                        // 使用层级分类选择函数，保持完整层级显示
                        cam_hierarchical_category_checklist_with_count($selected_cats);
                        ?>
                    </div>
                    <p class="description yali-desc"><?php _e('从这些分类中随机选择文章。勾选父分类会自动选择所有子分类。', 'yali-ai-writer'); ?></p>
                </td>
            </tr>

            <!-- 条件：固定选择 -->
            <tr id="condition-fixed-articles" class="rule-condition-group form-field" style="<?php echo ($rule && $rule->rule_type === 'fixed_articles') ? '' : 'display: none;'; ?>">
                <th scope="row"><label for="article-search-input"><?php _e('搜索并选择文章', 'yali-ai-writer'); ?></label></th>
                <td>
                    <div class="search-box-wrapper">
                        <input type="text" id="article-search-input" class="regular-text yali-input" placeholder="<?php esc_attr_e('输入文章标题搜索...', 'yali-ai-writer'); ?>">
                        <button type="button" id="article-search-button" class="button yali-btn yali-btn-secondary"><?php _e('搜索文章', 'yali-ai-writer'); ?></button>
                    </div>
                    <div id="search-results" class="search-results"></div>
                    <div id="selected-articles-container" class="selected-articles-container">
                        <p><strong><?php _e('已选文章:', 'yali-ai-writer'); ?></strong></p>
                        <ul id="selected-articles-list"></ul>
                        <input type="hidden" name="selected_articles" id="selected-articles-input" value="<?php echo implode(',', $selected_articles); ?>">
                    </div>
                </td>
            </tr>

            <!-- 条件：上传文本内容 -->
            <tr id="condition-upload-text" class="rule-condition-group form-field" style="<?php echo ($rule && $rule->rule_type === 'upload_text') ? '' : 'display: none;'; ?>">
                <th scope="row"><label for="upload_text_content"><?php _e('文本内容', 'yali-ai-writer'); ?></label></th>
                <td>
                    <!-- 网址采集功能区域 -->
                    <div class="url-fetch-section">
                        <h4><?php _e('网址内容采集', 'yali-ai-writer'); ?></h4>
                        <div class="url-input-group" style="display: flex; gap: 10px; margin-bottom: 10px;">
                            <input type="url" id="content_url" class="yali-input" style="flex: 1;" placeholder="<?php esc_attr_e('请输入网址，例如：https://example.com', 'yali-ai-writer'); ?>" />
                            <button type="button" id="fetch_content_btn" class="button button-secondary yali-btn yali-btn-secondary"><?php _e('采集内容', 'yali-ai-writer'); ?></button>
                        </div>
                        <div id="fetch_status" style="margin-bottom: 10px;"></div>
                    </div>

                    <textarea id="upload_text_content" name="upload_text_content" rows="10" cols="50" class="yali-input" placeholder="<?php esc_attr_e('请输入文本内容，或使用上方网址采集功能（保存时限制3000字符）', 'yali-ai-writer'); ?>"><?php echo esc_textarea($upload_text_content); ?></textarea>
                    <p class="description yali-desc"><?php _e('请输入需要上传的文本内容。您可以使用网址采集功能获取网页全文，然后在此处自由删减编辑。保存时文本内容不能超过3000个字符（包括汉字、英文字母、数字、标点符号等）。', 'yali-ai-writer'); ?></p>
                    <div id="text-count" class="yali-desc"><?php _e('已输入: ', 'yali-ai-writer'); ?><span id="current-count"><?php echo mb_strlen($upload_text_content, 'UTF-8'); ?></span>/<span id="max-count">3000</span> <?php _e(' 字符', 'yali-ai-writer'); ?> <span id="char-limit-warning" style="color: #d63638; display: none;"><?php _e('（超出限制，保存前请删减）', 'yali-ai-writer'); ?></span></div>
                </td>
            </tr>

            <!-- 条件：导入关键词 -->
            <tr id="condition-import-keywords" class="rule-condition-group form-field" style="<?php echo ($rule && $rule->rule_type === 'import_keywords') ? '' : 'display: none;'; ?>">
                <th scope="row"><label for="keywords_content"><?php _e('关键词列表', 'yali-ai-writer'); ?></label></th>
                <td>
                    <textarea id="keywords_content" name="keywords_content" rows="15" cols="50" class="yali-input" placeholder="<?php esc_attr_e('请输入关键词，每行一个关键词，最多200个关键词', 'yali-ai-writer'); ?>"><?php echo esc_textarea($keywords_content); ?></textarea>
                    <p class="description yali-desc"><?php _e('请输入关键词，每行一个关键词，最多允许输入200个关键词。系统将按循环顺序为每个关键词生成主题。', 'yali-ai-writer'); ?></p>
                    <div id="keywords-count" class="yali-desc"><?php _e('已输入: ', 'yali-ai-writer'); ?><span id="current-keywords-count">0</span>/<span id="max-keywords-count">200</span> <?php _e(' 个关键词', 'yali-ai-writer'); ?></div>
                </td>
            </tr>

            <!-- 条件：采集网址仿写 -->
            <tr id="condition-collect-url-rewrite" class="rule-condition-group form-field" style="<?php echo ($rule && $rule->rule_type === 'collect_url_rewrite') ? '' : 'display: none;'; ?>">
                <th scope="row"><label for="collect_url_content"><?php _e('网址列表', 'yali-ai-writer'); ?></label></th>
                <td>
                    <textarea id="collect_url_content" name="collect_url_content" rows="15" cols="50" class="yali-input" placeholder="<?php esc_attr_e('请输入网址，每行一条，最多500条', 'yali-ai-writer'); ?>"><?php echo isset($collect_url_content) ? esc_textarea($collect_url_content) : ''; ?></textarea>
                    <p class="description yali-desc"><?php _e('系统将抓取每个网址的内容并进行仿写生成。', 'yali-ai-writer'); ?></p>
                    <p class="description yali-desc" style="color: #666;"><strong><?php _e('智能去重：', 'yali-ai-writer'); ?></strong><?php _e('系统会自动过滤重复的网址（包括输入中的重复项和已在历史规则中采集过的网址）。', 'yali-ai-writer'); ?></p>
                    <div id="url-count" class="yali-desc"><?php _e('已输入: ', 'yali-ai-writer'); ?><span id="current-url-count">0</span>/<span id="max-url-count">500</span> <?php _e(' 条网址', 'yali-ai-writer'); ?></div>
                    
                    <!-- 采集选项 -->
                    <div class="collect-options" style="margin-top: 15px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 13px;"><?php _e('采集选项', 'yali-ai-writer'); ?></h4>
                        <?php
                        // 获取已保存的采集选项
                        $collect_options = array('keep_images' => false, 'keep_links' => false);
                        if ($rule && $rule->rule_type === 'collect_url_rewrite') {
                            $conditions = maybe_unserialize($rule->rule_conditions);
                            if (isset($conditions['collect_options'])) {
                                $collect_options = array_merge($collect_options, $conditions['collect_options']);
                            }
                        }
                        ?>
                        <label style="display: block; margin-bottom: 5px;">
                            <input type="checkbox" name="collect_keep_images" value="1" <?php checked($collect_options['keep_images'], true); ?>>
                            <?php _e('保留图片 ', 'yali-ai-writer'); ?> <span style="color: #888; font-size: 12px;"><?php _e('(默认不保留，仿写时通常不需要原文图片)', 'yali-ai-writer'); ?></span>
                        </label>
                        <label style="display: block;">
                            <input type="checkbox" name="collect_keep_links" value="1" <?php checked($collect_options['keep_links'], true); ?>>
                            <?php _e('保留链接 ', 'yali-ai-writer'); ?> <span style="color: #888; font-size: 12px;"><?php _e('(默认不保留，仿写时链接通常会失效)', 'yali-ai-writer'); ?></span>
                        </label>
                    </div>
                </td>
            </tr>

            <!-- 条件：随机分类 -->
            <tr id="condition-random-categories" class="rule-condition-group form-field" style="<?php echo ($rule && $rule->rule_type === 'random_categories') ? '' : 'display: none;'; ?>">
                <th scope="row"><label><?php _e('选择分类', 'yali-ai-writer'); ?></label></th>
                <td>
                    <div class="category-checklist-actions">
                        <a href="#" id="select-all-random-cats"><?php _e('全选', 'yali-ai-writer'); ?></a> | <a href="#" id="deselect-all-random-cats"><?php _e('全不选', 'yali-ai-writer'); ?></a>
                    </div>
                    <div id="random-categories-checklist-container" class="category-checklist-container">
                        <?php
                        // 使用层级分类选择函数，保持完整层级显示
                        cam_hierarchical_category_checklist_with_count($selected_random_cats);
                        ?>
                    </div>
                    <p class="description"><?php _e('请选择用于随机生成主题的分类。系统将完全随机地从选定分类中抽取分类名称和描述来生成主题。', 'yali-ai-writer'); ?></p>
                </td>
            </tr>

            <!-- 目标分类选择 (所有规则通用) -->
            <tr class="form-field">
                <th scope="row"><label for="target_category"><?php _e('目标分类 (可选)', 'yali-ai-writer'); ?></label></th>
                <td>
                    <select name="target_category" id="target_category" class="yali-select">
                        <option value=""><?php _e('智能自动匹配 (默认)', 'yali-ai-writer'); ?></option>
                        <?php
                        $categories = get_categories(array('hide_empty' => false));
                        $rule_conditions = ($rule) ? maybe_unserialize($rule->rule_conditions) : array();
                        $current_target = isset($rule_conditions['target_category']) ? $rule_conditions['target_category'] : '';
                        
                        foreach ($categories as $category) {
                            echo '<option value="' . esc_attr($category->term_id) . '" ' . selected($current_target, $category->term_id, false) . '>' . esc_html(__($category->name, 'yali-ai-writer')) . '</option>';
                        }
                        ?>
                    </select>
                    <p class="description yali-desc">
                        <?php _e('如果不选择，AI 将根据内容智能匹配最合适的分类。', 'yali-ai-writer'); ?><br>
                        <?php _e('如果选择了具体分类，则生成的所有主题都将强制归类到该分类下，忽略 AI 的分类建议。', 'yali-ai-writer'); ?>
                    </p>
                </td>
            </tr>

            <!-- 规则循环次数 -->
            <tr id="row-item-count" class="form-field">
                <th scope="row"><label for="item_count"><?php _e('规则循环次数', 'yali-ai-writer'); ?></label></th>
                <td>
                    <input type="number" id="item_count" name="item_count" class="small-text yali-input" value="<?php echo $rule ? esc_attr($rule->item_count) : '1'; ?>" min="1" required>
                    <p class="description yali-desc">
                        <?php _e('对于"随机选择"，表示从选定分类中完全随机抽取N篇文章（允许重复抽取同一篇文章）。', 'yali-ai-writer'); ?><br>
                        <?php _e('对于"固定选择"，表示按顺序循环抽取N次已选定的文章', 'yali-ai-writer'); ?>.<br>
                        <?php _e('对于"上传文本内容"，表示生成N个相同的文本内容条目，每个条目最多3000个字符', 'yali-ai-writer'); ?>.<br>
                        <?php _e('对于"导入关键词"，表示循环N轮，每轮为所有关键词各生成一个主题。例如：2个关键词×2次循环=生成4个主题。', 'yali-ai-writer'); ?><br>
                        <?php _e('对于"随机分类"，表示完全随机地从选定分类中抽取N次分类名称和描述来生成主题（允许重复抽取同一分类）。', 'yali-ai-writer'); ?>
                    </p>
                </td>
            </tr>

            <!-- 参考资料 -->
            <tr id="row-reference-material" class="form-field">
                <th scope="row"><label for="reference_material"><?php _e('参考资料', 'yali-ai-writer'); ?></label></th>
                <td>
                    <textarea id="reference_material" name="reference_material" rows="4" class="large-text" maxlength="800" placeholder="<?php esc_attr_e('请输入参考资料，最多800字。此内容将在文章生成时作为参考信息使用，可留空。', 'yali-ai-writer'); ?>"><?php echo $rule ? esc_textarea($rule->reference_material ?? '') : ''; ?></textarea>
                    <p class="description">
                        <?php _e('可选字段。输入的参考资料将在主题生成文章时提供给AI作为背景信息，帮助生成更准确、更有深度的文章内容。最多支持800个字符，可留空。', 'yali-ai-writer'); ?>
                    </p>
                    <p class="description">
                        <strong><?php _e('字符计数：', 'yali-ai-writer'); ?></strong><span id="reference-material-count">0</span>/800
                    </p>
                </td>
            </tr>

            <!-- 状态 -->
            <tr class="form-field">
                <th scope="row"><?php _e('状态', 'yali-ai-writer'); ?></th>
                <td>
                    <label><input type="checkbox" id="status" name="status" value="1" <?php echo (!$rule || $rule->status == 1) ? 'checked' : ''; ?>> <?php _e('启用规则', 'yali-ai-writer'); ?></label>
                </td>
            </tr>
        </table>
        <div class="yali-card-footer">
            <input type="submit" name="submit" id="submit" class="yali-btn yali-btn-primary" value="<?php echo $is_edit_mode ? __('更新规则', 'yali-ai-writer') : __('保存规则', 'yali-ai-writer'); ?>">
            <a href="<?php echo admin_url('admin.php?page=yali-ai-writer-rules'); ?>" class="yali-btn yali-btn-secondary"><?php _e('取消', 'yali-ai-writer'); ?></a>
        </div>
        </div>
    </form>
</div>


