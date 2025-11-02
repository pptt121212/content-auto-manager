<?php
/**
 * 自定义函数，生成带文章计数的、有层级的分类复选框列表
 *
 * @param int $parent_id 父分类ID
 * @param array $selected_cats 已选择的分类ID数组
 */
function cam_category_checklist_with_count($parent_id = 0, $selected_cats = array()) {
    // 使用分类过滤器获取允许的分类
    if (class_exists('ContentAuto_Category_Filter')) {
        $categories = ContentAuto_Category_Filter::get_filtered_categories(array(
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
            echo '<label class="selectit"><input value="' . $category->term_id . '" type="checkbox" name="categories[]" id="in-category-' . $category->term_id . '"' . $checked . '> ' . esc_html($category->name) . ' (' . $category->count . ')</label>';
            // 递归调用以显示子分类
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
    if (class_exists('ContentAuto_Category_Filter')) {
        $filtered_categories = ContentAuto_Category_Filter::get_filtered_categories(array(
            'hide_empty' => 0,
            'taxonomy' => 'category'
        ));
        $allowed_category_ids = wp_list_pluck($filtered_categories, 'term_id');
    } else {
        $allowed_category_ids = wp_list_pluck($all_categories, 'term_id');
    }

    if (empty($all_categories)) {
        echo '<p>没有可用的分类</p>';
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
        echo $indent . esc_html($category->name) . ' (' . $category->count . ')';
        
        // 为禁用的分类添加提示
        if (!$is_allowed) {
            echo ' <span class="disabled-hint">(未启用)</span>';
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
$selected_articles = array();
$upload_text_content = '';
$keywords_content = '';

if ($is_edit_mode && isset($_GET['id'])) {
    global $wpdb;
    $rules_table = $wpdb->prefix . 'content_auto_rules';
    $rule_id = intval($_GET['id']);

    // 获取现有规则
    $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$rules_table} WHERE id = %d", $rule_id));

    if ($rule) {
        // 反序列化规则条件
        $conditions = maybe_unserialize($rule->rule_conditions);

        // 根据规则类型设置已选择的值
        if ($rule->rule_type === 'random_selection' && isset($conditions['categories'])) {
            $selected_cats = $conditions['categories'];
        } elseif ($rule->rule_type === 'fixed_articles' && isset($conditions['post_ids'])) {
            $selected_articles = $conditions['post_ids'];
        } elseif ($rule->rule_type === 'upload_text' && isset($conditions['upload_text'])) {
            $upload_text_content = $conditions['upload_text'];
        } elseif ($rule->rule_type === 'import_keywords' && isset($conditions['keywords'])) {
            $keywords_content = implode("\n", $conditions['keywords']);
        }
    }
}
?>
<div class="wrap">
    <h1><?php echo $is_edit_mode ? '编辑规则' : '添加新规则'; ?></h1>

    <form id="add-rule-form" method="post" action="">
        <!-- 安全随机数 -->
        <?php wp_nonce_field('cam_save_rule_action', 'cam_save_rule_nonce'); ?>
        <?php if ($is_edit_mode && $rule): ?>
            <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule->id); ?>">
        <?php endif; ?>

        <!-- 规则名称 -->
        <table class="form-table">
            <tr class="form-field">
                <th scope="row"><label for="rule_name">规则名称</label></th>
                <td><input type="text" id="rule_name" name="rule_name" class="regular-text" value="<?php echo $rule ? esc_attr($rule->rule_name) : ''; ?>" required></td>
            </tr>

            <!-- 规则类型 -->
            <tr class="form-field">
                <th scope="row">规则类型</th>
                <td>
                    <fieldset>
                        <label><input type="radio" name="rule_type" value="random_selection" <?php echo (!$rule || $rule->rule_type === 'random_selection') ? 'checked' : ''; ?>> 随机选择文章</label>
                        <br>
                        <label><input type="radio" name="rule_type" value="fixed_articles" <?php echo ($rule && $rule->rule_type === 'fixed_articles') ? 'checked' : ''; ?>> 固定选择文章</label>
                        <br>
                        <label><input type="radio" name="rule_type" value="upload_text" <?php echo ($rule && $rule->rule_type === 'upload_text') ? 'checked' : ''; ?>> 上传文本内容</label>
                        <br>
                        <label><input type="radio" name="rule_type" value="import_keywords" <?php echo ($rule && $rule->rule_type === 'import_keywords') ? 'checked' : ''; ?>> 导入关键词</label>
                        <br>
                        <label><input type="radio" name="rule_type" value="random_categories" <?php echo ($rule && $rule->rule_type === 'random_categories') ? 'checked' : ''; ?>> 随机分类</label>
                    </fieldset>
                </td>
            </tr>

            <!-- 条件：随机选择 -->
            <tr id="condition-random-selection" class="rule-condition-group form-field" style="<?php echo (!$rule || $rule->rule_type === 'random_selection') ? '' : 'display: none;'; ?>">
                <th scope="row"><label>文章分类</label></th>
                <td>
                    <div class="category-checklist-actions">
                        <a href="#" id="select-all-cats">全选</a> | <a href="#" id="deselect-all-cats">全不选</a>
                    </div>
                    <div id="category-checklist-container" class="category-checklist-container">
                        <?php
                        // 使用层级分类选择函数，保持完整层级显示
                        cam_hierarchical_category_checklist_with_count($selected_cats);
                        ?>
                    </div>
                    <p class="description">从这些分类中随机选择文章。勾选父分类会自动选择所有子分类。</p>
                </td>
            </tr>

            <!-- 条件：固定选择 -->
            <tr id="condition-fixed-articles" class="rule-condition-group form-field" style="<?php echo ($rule && $rule->rule_type === 'fixed_articles') ? '' : 'display: none;'; ?>">
                <th scope="row"><label for="article-search-input">搜索并选择文章</label></th>
                <td>
                    <div class="search-box-wrapper">
                        <input type="text" id="article-search-input" class="regular-text" placeholder="输入文章标题搜索...">
                        <button type="button" id="article-search-button" class="button">搜索文章</button>
                    </div>
                    <div id="search-results" class="search-results"></div>
                    <div id="selected-articles-container" class="selected-articles-container">
                        <p><strong>已选文章:</strong></p>
                        <ul id="selected-articles-list"></ul>
                        <input type="hidden" name="selected_articles" id="selected-articles-input" value="<?php echo implode(',', $selected_articles); ?>">
                    </div>
                </td>
            </tr>

            <!-- 条件：上传文本内容 -->
            <tr id="condition-upload-text" class="rule-condition-group form-field" style="<?php echo ($rule && $rule->rule_type === 'upload_text') ? '' : 'display: none;'; ?>">
                <th scope="row"><label for="upload_text_content">文本内容</label></th>
                <td>
                    <!-- 网址采集功能区域 -->
                    <div class="url-fetch-section">
                        <h4>网址内容采集</h4>
                        <div class="url-input-group">
                            <input type="url" id="content_url" placeholder="请输入网址，例如：https://example.com" />
                            <button type="button" id="fetch_content_btn" class="button button-secondary">采集内容</button>
                        </div>
                        <div id="fetch_status"></div>
                    </div>

                    <textarea id="upload_text_content" name="upload_text_content" rows="10" cols="50" maxlength="3000" placeholder="请输入文本内容，最多3000个字符，或使用上方网址采集功能"><?php echo esc_textarea($upload_text_content); ?></textarea>
                    <p class="description">请输入需要上传的文本内容，最多允许输入3000个字符（包括汉字、英文字母、数字、标点符号等）。也可以使用上方的网址采集功能自动获取网页内容。</p>
                    <div id="text-count">已输入: <span id="current-count"><?php echo mb_strlen($upload_text_content, 'UTF-8'); ?></span>/<span id="max-count">3000</span> 字符</div>
                </td>
            </tr>

            <!-- 条件：导入关键词 -->
            <tr id="condition-import-keywords" class="rule-condition-group form-field" style="<?php echo ($rule && $rule->rule_type === 'import_keywords') ? '' : 'display: none;'; ?>">
                <th scope="row"><label for="keywords_content">关键词列表</label></th>
                <td>
                    <textarea id="keywords_content" name="keywords_content" rows="15" cols="50" placeholder="请输入关键词，每行一个关键词，最多200个关键词"><?php echo esc_textarea($keywords_content); ?></textarea>
                    <p class="description">请输入关键词，每行一个关键词，最多允许输入200个关键词。系统将按循环顺序为每个关键词生成主题。</p>
                    <div id="keywords-count">已输入: <span id="current-keywords-count">0</span>/<span id="max-keywords-count">200</span> 个关键词</div>
                </td>
            </tr>

            <!-- 条件：随机分类 -->
            <tr id="condition-random-categories" class="rule-condition-group form-field" style="<?php echo ($rule && $rule->rule_type === 'random_categories') ? '' : 'display: none;'; ?>">
                <th scope="row"><label>选择分类</label></th>
                <td>
                    <div class="category-checklist-actions">
                        <a href="#" id="select-all-random-cats">全选</a> | <a href="#" id="deselect-all-random-cats">全不选</a>
                    </div>
                    <div id="random-categories-checklist-container" class="category-checklist-container">
                        <?php
                        // 准备随机分类的已选分类
                        $selected_random_cats = array();
                        if ($rule && $rule->rule_type === 'random_categories') {
                            $conditions = maybe_unserialize($rule->rule_conditions);
                            $selected_random_cats = isset($conditions['categories']) ? $conditions['categories'] : array();
                        }
                        // 使用层级分类选择函数，保持完整层级显示
                        cam_hierarchical_category_checklist_with_count($selected_random_cats);
                        ?>
                    </div>
                    <p class="description">请选择用于随机生成主题的分类。系统将完全随机地从选定分类中抽取分类名称和描述来生成主题。</p>
                </td>
            </tr>

            <!-- 规则循环次数 -->
            <tr class="form-field">
                <th scope="row"><label for="item_count">规则循环次数</label></th>
                <td>
                    <input type="number" id="item_count" name="item_count" class="small-text" value="<?php echo $rule ? esc_attr($rule->item_count) : '1'; ?>" min="1" required>
                    <p class="description">
                        对于"随机选择"，表示从选定分类中完全随机抽取N篇文章（允许重复抽取同一篇文章）。<br>
                        对于"固定选择"，表示按顺序循环抽取N次已选定的文章。<br>
                        对于"上传文本内容"，表示生成N个相同的文本内容条目，每个条目最多3000个字符。<br>
                        对于"导入关键词"，表示循环N轮，每轮为所有关键词各生成一个主题。例如：2个关键词×2次循环=生成4个主题。<br>
                        对于"随机分类"，表示完全随机地从选定分类中抽取N次分类名称和描述来生成主题（允许重复抽取同一分类）。
                    </p>
                </td>
            </tr>

            <!-- 参考资料 -->
            <tr class="form-field">
                <th scope="row"><label for="reference_material">参考资料</label></th>
                <td>
                    <textarea id="reference_material" name="reference_material" rows="4" class="large-text" maxlength="800" placeholder="请输入参考资料，最多800字。此内容将在文章生成时作为参考信息使用，可留空。"><?php echo $rule ? esc_textarea($rule->reference_material ?? '') : ''; ?></textarea>
                    <p class="description">
                        可选字段。输入的参考资料将在主题生成文章时提供给AI作为背景信息，帮助生成更准确、更有深度的文章内容。最多支持800个字符，可留空。
                    </p>
                    <p class="description">
                        <strong>字符计数：</strong><span id="reference_material_count">0</span>/800
                    </p>
                </td>
            </tr>

            <!-- 状态 -->
            <tr class="form-field">
                <th scope="row">状态</th>
                <td>
                    <label><input type="checkbox" id="status" name="status" value="1" <?php echo (!$rule || $rule->status == 1) ? 'checked' : ''; ?>> 启用规则</label>
                </td>
            </tr>
        </table>

        <!-- 提交按钮 -->
        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo $is_edit_mode ? '更新规则' : '保存规则'; ?>">
            <a href="<?php echo admin_url('admin.php?page=content-auto-manager-rules'); ?>" class="button">取消</a>
        </p>
    </form>
</div>

<style>
    .category-checklist-actions {
        margin-bottom: 5px;
    }
    .category-checklist-container {
        max-height: 250px;
        overflow-y: auto;
        border: 1px solid #ddd;
        padding: 10px;
        background-color: #fff;
    }
    .category-checklist-container ul {
        padding-left: 0;
        margin: 0;
    }
    .category-checklist-container ul.children {
        padding-left: 20px; /* 子分类缩进 */
        margin-top: 5px;
    }
    .category-checklist-container li {
        list-style-type: none;
    }

    .search-box-wrapper {
        display: flex;
        gap: 5px;
    }
    .search-results {
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid #ddd;
        margin-top: 5px;
        padding: 5px;
    }
    .search-result-item {
        padding: 5px;
        cursor: pointer;
    }
    .search-result-item:hover {
        background-color: #f0f0f0;
    }
    .search-result-item.selected {
        background-color: #ddd;
        color: #555;
        cursor: not-allowed;
    }
    .selected-articles-container {
        border: 1px solid #ddd;
        padding: 10px;
        margin-top: 10px;
        background-color: #fff;
        max-height: 300px; /* 固定高度 */
        overflow-y: auto; /* 超出时滚动 */
    }
    .selected-articles-container ul { margin: 0; padding-left: 20px; }
    .selected-articles-container li { margin-bottom: 5px; }
    
    #text-count {
        margin-top: 5px;
        font-size: 12px;
        color: #666;
    }
    
    #text-count span {
        font-weight: bold;
    }
    
    /* 层级分类显示样式 */
    .category-checklist {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #ddd;
        padding: 10px;
        background: #f9f9f9;
    }
    
    .category-checklist li {
        margin: 3px 0;
        list-style: none;
    }
    
    .category-checklist .children {
        margin-left: 0;
        padding-left: 0;
        border-left: 2px solid #ddd;
        margin-top: 5px;
    }
    
    .category-checklist .children li {
        margin-left: 15px;
        position: relative;
    }
    
    .category-checklist .children li:before {
        content: '';
        position: absolute;
        left: -15px;
        top: 10px;
        width: 12px;
        height: 1px;
        background: #ddd;
    }
    
    .category-checklist label {
        display: block;
        padding: 2px 5px;
        border-radius: 3px;
        cursor: pointer;
    }
    
    .category-checklist label:hover {
        background-color: #e0e0e0;
    }
    
    .category-checklist input[type="checkbox"] {
        margin-right: 8px;
    }
    
    /* 禁用分类样式 */
    .category-checklist li.disabled {
        /* 不改变整体透明度，只改变文字颜色 */
    }
    
    .category-checklist .disabled-category {
        color: #999 !important;
        cursor: not-allowed;
    }
    
    .category-checklist .disabled-category:hover {
        background-color: transparent !important;
    }
    
    .category-checklist .disabled-category input[type="checkbox"] {
        cursor: not-allowed;
    }
    
    .category-checklist .disabled-hint {
        font-size: 0.85em;
        color: #999;
        font-style: italic;
    }
    
    /* 启用分类样式 - 保持正常黑色 */
    .category-checklist li.allowed {
        /* 不设置透明度，保持正常显示 */
    }
    
    .category-checklist li.allowed label {
        color: #333 !important;
        cursor: pointer;
    }
    
    .category-checklist li.allowed label:hover {
        background-color: #e0e0e0;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- 变量定义 ---
    const ruleTypeRadios = document.querySelectorAll('input[name="rule_type"]');
    const randomSelectionRow = document.getElementById('condition-random-selection');
    const fixedArticlesRow = document.getElementById('condition-fixed-articles');
    const uploadTextRow = document.getElementById('condition-upload-text');
    const importKeywordsRow = document.getElementById('condition-import-keywords');
    const randomCategoriesRow = document.getElementById('condition-random-categories');
    const articleSearchInput = document.getElementById('article-search-input');
    const articleSearchButton = document.getElementById('article-search-button');
    const searchResultsDiv = document.getElementById('search-results');
    const selectedArticlesList = document.getElementById('selected-articles-list');
    const selectedArticlesInput = document.getElementById('selected-articles-input');
    const catChecklistContainer = document.getElementById('category-checklist-container');
    const selectAllCatsBtn = document.getElementById('select-all-cats');
    const deselectAllCatsBtn = document.getElementById('deselect-all-cats');
    const selectAllRandomCatsBtn = document.getElementById('select-all-random-cats');
    const deselectAllRandomCatsBtn = document.getElementById('deselect-all-random-cats');
    const uploadTextInput = document.getElementById('upload_text_content');
    const currentCountSpan = document.getElementById('current-count');
    const keywordsInput = document.getElementById('keywords_content');
    const currentKeywordsCountSpan = document.getElementById('current-keywords-count');
    
    // 初始化已选文章
    let selectedArticles = [];
    const initialSelectedArticles = selectedArticlesInput.value;
    if (initialSelectedArticles) {
        // 在编辑模式下，我们需要从服务器获取文章标题
        const articleIds = initialSelectedArticles.split(',').map(id => parseInt(id));
        articleIds.forEach(id => {
            if (!isNaN(id)) {
                selectedArticles.push({ id: id, title: '文章 #' + id }); // 占位符标题
            }
        });
        renderSelectedArticles();
        
        // 如果在编辑模式，获取实际的文章标题
        if (articleIds.length > 0) {
            fetchArticleTitles(articleIds);
        }
    }

    // --- 功能函数 ---

    // 获取文章标题
    function fetchArticleTitles(articleIds) {
        const data = new URLSearchParams();
        data.append('action', 'content_auto_get_article_titles');
        data.append('nonce', contentAutoManager.nonce);
        data.append('article_ids', articleIds.join(','));

        fetch(contentAutoManager.ajaxurl, {
            method: 'POST',
            body: data
        })
        .then(response => response.json())
        .then(result => {
            if (result.success && result.data.articles) {
                // 更新已选文章的标题
                result.data.articles.forEach(article => {
                    const item = selectedArticles.find(sa => sa.id === article.id);
                    if (item) {
                        item.title = article.title;
                    }
                });
                renderSelectedArticles();
            }
        });
    }

    // 切换规则类型显示
    function toggleConditions() {
        const selectedType = document.querySelector('input[name="rule_type"]:checked').value;

        randomSelectionRow.style.display = 'none';
        fixedArticlesRow.style.display = 'none';
        uploadTextRow.style.display = 'none';
        importKeywordsRow.style.display = 'none';
        randomCategoriesRow.style.display = 'none';

        if (selectedType === 'random_selection') {
            randomSelectionRow.style.display = 'table-row';
        } else if (selectedType === 'fixed_articles') {
            fixedArticlesRow.style.display = 'table-row';
        } else if (selectedType === 'upload_text') {
            uploadTextRow.style.display = 'table-row';
        } else if (selectedType === 'import_keywords') {
            importKeywordsRow.style.display = 'table-row';
        } else if (selectedType === 'random_categories') {
            randomCategoriesRow.style.display = 'table-row';
        }
    }

    // 更新隐藏输入框的值
    function updateSelectedArticlesInput() {
        selectedArticlesInput.value = selectedArticles.map(item => item.id).join(',');
    }

    // 渲染已选文章列表
    function renderSelectedArticles() {
        selectedArticlesList.innerHTML = '';
        selectedArticles.forEach((item, index) => {
            const li = document.createElement('li');
            li.dataset.id = item.id;
            li.innerHTML = `${item.title} <button type="button" class="button-link delete" data-index="${index}">移除</button>`;
            selectedArticlesList.appendChild(li);
        });
        updateSelectedArticlesInput();
        updateSearchResultsState();
    }

    // 更新搜索结果的可选状态
    function updateSearchResultsState() {
        const resultItems = searchResultsDiv.querySelectorAll('.search-result-item');
        resultItems.forEach(item => {
            const id = parseInt(item.dataset.id, 10);
            if (selectedArticles.find(sa => sa.id === id)) {
                item.classList.add('selected');
            } else {
                item.classList.remove('selected');
            }
        });
    }

    // 执行文章搜索
    function searchArticles() {
        const searchTerm = articleSearchInput.value.trim();
        if (searchTerm.length === 0) {
            searchResultsDiv.innerHTML = '';
            return;
        }

        searchResultsDiv.innerHTML = '正在搜索...';

        const data = new URLSearchParams();
        data.append('action', 'content_auto_search_articles');
        data.append('nonce', contentAutoManager.nonce);
        data.append('search_term', searchTerm);

        fetch(contentAutoManager.ajaxurl, {
            method: 'POST',
            body: data
        })
        .then(response => response.json())
        .then(result => {
            searchResultsDiv.innerHTML = '';
            if (result.success && result.data.articles.length > 0) {
                result.data.articles.forEach(article => {
                    const div = document.createElement('div');
                    div.classList.add('search-result-item');
                    div.dataset.id = article.id;
                    div.dataset.title = article.title;
                    div.textContent = article.title;
                    searchResultsDiv.appendChild(div);
                });
                updateSearchResultsState();
            } else {
                searchResultsDiv.innerHTML = '<div class="no-results">未找到文章。</div>';
            }
        });
    }

    // 计算文本字符数（包括汉字、英文字母、数字等所有字符）
    function countAllCharacters(text) {
        // 使用UTF-8编码计算实际字符数，而不是字节数
        return text.length ? [...text].length : 0;
    }

    // 更新文本计数
    function updateTextCount() {
        if (uploadTextInput) {
            const text = uploadTextInput.value;
            const count = countAllCharacters(text);
            currentCountSpan.textContent = count;

            // 如果超过限制，显示警告
            if (count > 3000) {
                currentCountSpan.style.color = 'red';
            } else {
                currentCountSpan.style.color = '';
            }
        }
    }

    // 更新关键词计数
    function updateKeywordsCount() {
        if (keywordsInput) {
            const text = keywordsInput.value.trim();
            const keywords = text.split('\n').filter(keyword => keyword.trim().length > 0);
            const count = keywords.length;
            currentKeywordsCountSpan.textContent = count;

            // 如果超过限制，显示警告
            if (count > 200) {
                currentKeywordsCountSpan.style.color = 'red';
            } else {
                currentKeywordsCountSpan.style.color = '';
            }
        }
    }

    // 更新参考资料字符计数
    function updateReferenceMaterialCount() {
        const referenceMaterialTextarea = document.getElementById('reference_material');
        const referenceMaterialCountSpan = document.getElementById('reference_material_count');

        if (referenceMaterialTextarea && referenceMaterialCountSpan) {
            const text = referenceMaterialTextarea.value;
            const count = text.length;
            referenceMaterialCountSpan.textContent = count;

            // 如果超过限制，显示警告
            if (count > 800) {
                referenceMaterialCountSpan.style.color = 'red';
            } else {
                referenceMaterialCountSpan.style.color = '';
            }
        }
    }

    // --- 事件监听 ---

    // 监听规则类型切换
    ruleTypeRadios.forEach(radio => {
        radio.addEventListener('change', toggleConditions);
    });

    // 监听文章搜索按钮点击
    articleSearchButton.addEventListener('click', searchArticles);

    // 监听文章搜索框回车
    articleSearchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchArticles();
        }
    });

    // 监听文章搜索结果点击
    searchResultsDiv.addEventListener('click', function(e) {
        const target = e.target;
        if (target && target.classList.contains('search-result-item') && !target.classList.contains('selected')) {
            const id = parseInt(target.dataset.id, 10);
            const title = target.dataset.title;
            
            if (!selectedArticles.find(item => item.id === id)) {
                selectedArticles.push({ id, title });
                renderSelectedArticles();
            }
        }
    });

    // 监听已选文章移除按钮点击
    selectedArticlesList.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('delete')) {
            const index = parseInt(e.target.dataset.index, 10);
            selectedArticles.splice(index, 1);
            renderSelectedArticles();
        }
    });

    // 分类选择逻辑
    if (catChecklistContainer) {
        // 全选（只选择启用的分类）
        selectAllCatsBtn.addEventListener('click', function(e) {
            e.preventDefault();
            catChecklistContainer.querySelectorAll('input[type="checkbox"]:not([disabled])').forEach(cb => cb.checked = true);
        });

        // 全不选（只取消选择启用的分类）
        deselectAllCatsBtn.addEventListener('click', function(e) {
            e.preventDefault();
            catChecklistContainer.querySelectorAll('input[type="checkbox"]:not([disabled])').forEach(cb => cb.checked = false);
        });

        // 父子联动（只影响启用的分类）
        catChecklistContainer.addEventListener('change', function(e) {
            if (e.target.type !== 'checkbox') return;

            const parentLi = e.target.closest('li');
            const childrenUl = parentLi.querySelector('ul.children');

            if (childrenUl) {
                const descendantCheckboxes = childrenUl.querySelectorAll('input[type="checkbox"]:not([disabled])');
                descendantCheckboxes.forEach(descendant => {
                    descendant.checked = e.target.checked;
                });
            }
        });
    }

    // 随机分类选择逻辑 - 复用随机选择文章的逻辑
    if (randomCategoriesRow) {
        const randomCategoriesContainer = document.getElementById('random-categories-checklist-container');

        if (randomCategoriesContainer && selectAllRandomCatsBtn && deselectAllRandomCatsBtn) {
            // 全选（只选择启用的分类）
            selectAllRandomCatsBtn.addEventListener('click', function(e) {
                e.preventDefault();
                randomCategoriesContainer.querySelectorAll('input[type="checkbox"]:not([disabled])').forEach(cb => cb.checked = true);
            });

            // 全不选（只取消选择启用的分类）
            deselectAllRandomCatsBtn.addEventListener('click', function(e) {
                e.preventDefault();
                randomCategoriesContainer.querySelectorAll('input[type="checkbox"]:not([disabled])').forEach(cb => cb.checked = false);
            });

            // 父子联动（只影响启用的分类）
            randomCategoriesContainer.addEventListener('change', function(e) {
                if (e.target.type !== 'checkbox') return;

                const parentLi = e.target.closest('li');
                const childrenUl = parentLi.querySelector('ul.children');

                if (childrenUl) {
                    const descendantCheckboxes = childrenUl.querySelectorAll('input[type="checkbox"]:not([disabled])');
                    descendantCheckboxes.forEach(descendant => {
                        descendant.checked = e.target.checked;
                    });
                }
            });
        }
    }

    // 监听文本输入框变化
    if (uploadTextInput) {
        uploadTextInput.addEventListener('input', updateTextCount);
    }

    // 监听关键词输入框变化
    if (keywordsInput) {
        keywordsInput.addEventListener('input', updateKeywordsCount);
    }

    // 监听参考资料输入框变化
    const referenceMaterialTextarea = document.getElementById('reference_material');
    if (referenceMaterialTextarea) {
        referenceMaterialTextarea.addEventListener('input', updateReferenceMaterialCount);
    }

    // 网址内容采集功能
    const fetchContentBtn = document.getElementById('fetch_content_btn');
    const contentUrlInput = document.getElementById('content_url');
    const fetchStatusDiv = document.getElementById('fetch_status');

    if (fetchContentBtn && contentUrlInput && fetchStatusDiv) {
        fetchContentBtn.addEventListener('click', function() {
            const url = contentUrlInput.value.trim();

            // 验证网址
            if (!url) {
                fetchStatusDiv.textContent = '请输入网址';
                fetchStatusDiv.style.color = 'red';
                return;
            }

            // 简单的URL格式验证
            try {
                new URL(url);
            } catch (e) {
                fetchStatusDiv.textContent = '请输入有效的网址';
                fetchStatusDiv.style.color = 'red';
                return;
            }

            // 显示加载状态
            fetchContentBtn.disabled = true;
            fetchContentBtn.textContent = '采集中...';
            fetchStatusDiv.textContent = '正在采集内容，请稍候...';
            fetchStatusDiv.style.color = '#666';

            // 发送AJAX请求
            fetch(contentAutoManager.ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'content_auto_fetch_url_content',
                    url: url,
                    nonce: contentAutoManager.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const content = data.data.content;
                    if (content) {
                        // 填充到文本框
                        uploadTextInput.value = content;
                        updateTextCount();
                        fetchStatusDiv.textContent = '内容采集成功！已截取前3000个字符';
                        fetchStatusDiv.style.color = 'green';
                    } else {
                        fetchStatusDiv.textContent = '采集的内容为空';
                        fetchStatusDiv.style.color = 'orange';
                    }
                } else {
                    fetchStatusDiv.textContent = '采集失败：' + data.data.message;
                    fetchStatusDiv.style.color = 'red';
                }
            })
            .catch(error => {
                console.error('采集错误:', error);
                fetchStatusDiv.textContent = '采集失败：网络错误';
                fetchStatusDiv.style.color = 'red';
            })
            .finally(() => {
                // 恢复按钮状态
                fetchContentBtn.disabled = false;
                fetchContentBtn.textContent = '采集内容';
            });
        });
    }

    // --- 初始化 ---
    toggleConditions();
    updateTextCount();
    updateKeywordsCount();
    updateReferenceMaterialCount();
});
</script>