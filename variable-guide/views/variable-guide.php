<?php
/**
 * 变量说明页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。'));
}

// 主题生成变量数据
$topic_variables = array(
    'CURRENT_DATE' => array(
        'name' => '当前日期',
        'description' => '动态生成当前的真实日期，格式为：YYYY年MM月DD日',
        'example' => '2025年10月13日',
        'usage' => '用于提供时间上下文，帮助AI了解当前时间'
    ),
    'LANGUAGE_INSTRUCTION' => array(
        'name' => '语言指令',
        'description' => '根据发布语言设置生成的语言使用说明',
        'example' => '请使用中文（简体）进行回复，采用适合中国大陆用户的表达方式和文化语境。',
        'usage' => '指导AI使用指定的语言和表达方式'
    ),
    'LANGUAGE_NAME' => array(
        'name' => '语言名称',
        'description' => 'AI识别的语言名称，用于约束输出语言',
        'example' => '中文',
        'usage' => '在输出约束中明确指定使用的语言'
    ),
    'REFERENCE_CONTENT_BLOCK' => array(
        'name' => '参考内容块',
        'description' => '【数据来源】通过RuleManager::get_content_by_rule_item_id()从规则项目获取内容<br>【处理逻辑】调用build_reference_content_block()方法，根据内容类型生成不同的XML结构<br>【支持类型】上传文本(upload_text)、关键词(keyword)、分类名称(category_name)、文章内容(title+content)',
        'example' => "    <reference_content>\n      <upload_text>这是上传的文本内容</upload_text>\n    </reference_content>\n    <reference_content>\n      <keyword>人工智能</keyword>\n      <cycle>第2轮循环</cycle>\n    </reference_content>",
        'usage' => '为AI提供规则项目中的源材料，支持多种内容类型的结构化输入，每种类型都有对应的XML标签格式'
    ),
    'EXISTING_TOPICS_BLOCK' => array(
        'name' => '已存在主题块',
        'description' => '【数据来源】从content_auto_topics表查询状态为unused和queued的主题<br>【处理逻辑】调用get_existing_topics()方法，获取最近的主题（默认限制30个候选，智能去重后返回最多5个）<br>【去重算法】使用向量余弦相似度或字符相似度，阈值0.8<br>【输出格式】每个主题标题占一行，前缀6个空格',
        'example' => "      人工智能发展趋势分析\n      机器学习在教育中的应用\n      大数据处理的最佳实践\n      深度学习算法优化方法\n      AI伦理问题探讨",
        'usage' => '为AI提供现有主题参考，通过智能去重算法确保新生成的主题与现有主题在相似度上有明显差异，避免内容重复'
    ),
    'SITE_CATEGORIES_BLOCK' => array(
        'name' => '网站分类块',
        'description' => '【数据来源】优先使用ContentAuto_Category_Filter::get_filtered_categories()获取发布规则中允许的分类，如分类过滤器不存在则回退到WordPress的get_categories()获取所有分类<br>【处理逻辑】调用build_site_categories_block()方法，每个分类名称占一行，前缀6个空格进行缩进<br>【限制】最多获取50个分类',
        'example' => "      技术分享\n      产品评测\n      行业资讯\n      使用教程\n      开发指南",
        'usage' => '为AI提供网站现有的分类选项，帮助生成主题时选择合适的分类。优先使用发布规则中定义的分类范围'
    ),
    '{N}' => array(
        'name' => '生成数量',
        'description' => '【数据来源】来自任务创建时的topic_count_per_item参数<br>【处理逻辑】在主题生成模板中直接替换为具体数字<br>【业务含义】指定每个规则项目需要生成的主题数量',
        'example' => '5',
        'usage' => '告诉AI需要为当前规则项目生成多少个主题，这个数量由用户在创建主题任务时指定'
    ),
);

// 文章生成变量数据 - 按业务分类组织
$article_variables = array(
    // ==================== 📋 核心内容变量 ====================
    // 数据来源：content_auto_topics 表（AI生成的主题数据）
    // 业务含义：文章创作的基础依据，决定内容方向和价值
    'TITLE' => array(
        'name' => '文章标题',
        'description' => '【数据来源】content_auto_topics表的title字段<br>【业务含义】AI生成的主题标题，是文章创作的核心依据',
        'example' => '人工智能在教育领域的应用前景',
        'usage' => '【必需变量】AI基于此标题创作文章内容，决定文章的方向和价值承诺',
        'category' => '核心内容',
        'importance' => 'critical',
        'edit_tips' => '💡 建议放在输入上下文区域，作为AI创作的首要依据'
    ),
    'SOURCE_ANGLE' => array(
        'name' => '内容角度',
        'description' => '【数据来源】content_auto_topics表的source_angle字段<br>【可选值】基础解析、实操指导、案例研究、对比分析、趋势洞察',
        'example' => '实操指导',
        'usage' => '【必需变量】指导AI从特定角度展开内容，决定文章的结构框架和组织方式',
        'category' => '核心内容',
        'importance' => 'critical',
        'edit_tips' => '💡 建议与TITLE放在同一区域，共同确定文章基调'
    ),
    'USER_VALUE' => array(
        'name' => '用户价值',
        'description' => '【数据来源】content_auto_topics表的user_value字段<br>【业务含义】文章为读者提供的核心价值和收益说明',
        'example' => '为读者提供实用的AI工具使用指导和最佳实践',
        'usage' => '【必需变量】帮助AI明确文章的价值主张，避免空洞内容创作',
        'category' => '核心内容',
        'importance' => 'critical',
        'edit_tips' => '💡 建议放在文章要求部分，确保AI创作有价值的内容'
    ),
    'SEO_KEYWORDS' => array(
        'name' => 'SEO关键词',
        'description' => '【数据来源】content_auto_topics表的seo_keywords字段<br>【处理方法】process_seo_keywords()方法<br>【处理逻辑】1)优先解析JSON格式 2)回退到分隔符分割(支持逗号、顿号、空格) 3)验证质量(≥2字符) 4)最多保留5个有效关键词<br>【输出格式】用顿号(、)连接的字符串',
        'example' => '人工智能、机器学习、教育应用、深度学习、神经网络',
        'usage' => '【重要变量】指导AI在标题、章节标题和正文中自然融入关键词，提升SEO效果',
        'category' => '核心内容',
        'importance' => 'important',
        'edit_tips' => '💡 建议放在SEO优化区域，与内容质量要求一起使用'
    ),
    'MATCHED_CATEGORY' => array(
        'name' => '匹配分类',
        'description' => '【数据来源】content_auto_topics表的matched_category字段<br>【业务含义】文章归属的内容分类，影响专业术语和内容深度',
        'example' => '技术分享',
        'usage' => '【重要变量】帮助AI确定内容方向、专业深度和术语使用',
        'category' => '核心内容',
        'importance' => 'important',
        'edit_tips' => '💡 建议放在内容分类或专业要求部分'
    ),
    
    // ==================== ⚙️ 发布规则配置变量 ====================
    // 数据来源：发布规则配置（用户设置）
    // 业务含义：个性化的创作策略和风格指导
    'TARGET_LENGTH' => array(
        'name' => '目标字数',
        'description' => '【数据来源】发布规则的target_length设置<br>【验证机制】validate_input_data()方法验证有效性<br>【有效值】300-800, 500-1000, 800-1500, 1000-2000, 1500-3000, 2000-4000<br>【默认值】无效输入时回退到"800-1500"',
        'example' => '800-1500',
        'usage' => '【重要变量】指导AI控制文章篇幅，满足不同场景的长度需求',
        'category' => '发布配置',
        'importance' => 'important',
        'edit_tips' => '💡 建议放在输出约束区域，与格式要求一起使用'
    ),
    'CONTENT_STRATEGY_BLOCK' => array(
        'name' => '内容策略块',
        'description' => '【数据来源】发布规则的内容深度设置<br>【处理逻辑】validate_input_data()验证，"未设置"时为空字符串，其他值转换为完整指令<br>【有效值】未设置、浅层普及、实用指导、深度分析、全面综述<br>【生成机制】包含完整的<content_strategy>标签或为空',
        'example' => '<content_strategy>实用指导（内容特点：操作步骤、使用技巧、解决方案。写作要求：提供具体可执行的步骤，使用清晰的指令性语言，包含实际操作中的注意事项和常见问题解决方案。重点在于帮助读者解决实际问题，促进转化。）</content_strategy>',
        'usage' => '【可选变量】当用户配置了特定的知识深度时，指导AI采用相应的内容创作策略和深度',
        'category' => '发布配置',
        'importance' => 'optional',
        'edit_tips' => '💡 建议放在写作要求区域，影响内容的深度和表达方式'
    ),
    'TARGET_AUDIENCE_BLOCK' => array(
        'name' => '目标受众块',
        'description' => '【数据来源】发布规则的目标受众设置<br>【处理逻辑】validate_input_data()验证，"未设置"时为空字符串，其他值转换为完整指令<br>【有效值】未设置、潜在客户、现有客户、行业同仁、决策者、泛流量用户<br>【生成机制】包含完整的<target_audience>标签或为空',
        'example' => '<target_audience>潜在客户（受众特点：对产品/服务有兴趣但尚未购买。写作策略：突出产品价值主张，直接回应受众核心痛点，提供试用或体验机会。语言风格：友好、信任建立、价值导向。）</target_audience>',
        'usage' => '【可选变量】当用户配置了特定的读者角色时，指导AI针对该受众群体调整表达方式和内容重点',
        'category' => '发布配置',
        'importance' => 'optional',
        'edit_tips' => '💡 建议与CONTENT_STRATEGY_BLOCK放在同一区域，共同指导写作策略'
    ),
    
    // ==================== 🎯 智能功能变量 ====================
    // 数据来源：AI向量匹配和功能开关
    // 业务含义：高级功能的智能化指导
    'STRUCTURE_BLOCK' => array(
        'name' => '结构块',
        'description' => '【数据来源】content_auto_article_structures表的向量匹配结果<br>【获取方法】get_dynamic_article_structure()方法<br>【匹配逻辑】1)检查主题向量 2)获取同内容角度的候选结构 3)计算余弦相似度 4)取前20个中随机选择 5)更新使用次数<br>【回退机制】向量匹配失败时自动调用get_fallback_structure()，使用预设的专业5段式结构<br>【条件生成】仅在规范化输出启用时生成，保证始终有结构指导',
        'example' => '<source_angle_structures>\n  <structure name="实操指导">\n    <section>实施前准备条件与环境搭建</section>\n    <section>核心操作流程分步骤详细指导</section>\n    <section>关键技术要点与注意事项分析</section>\n    <section>常见问题诊断与解决方案提供</section>\n    <section>效果评估方法与持续优化建议</section>\n  </structure>\n</source_angle_structures>',
        'usage' => '【可选变量】为AI提供专业的文章结构框架，优先使用向量匹配结果，失败时使用预设的专业结构，确保内容组织的专业性和逻辑性',
        'category' => '智能功能',
        'importance' => 'optional',
        'edit_tips' => '💡 建议放在结构指导区域，包含智能匹配和专业回退的完整机制'
    ),
    'IMAGE_INSTRUCTIONS' => array(
        'name' => '图片指令',
        'description' => '【数据来源】发布规则的auto_image_insertion开关<br>【条件生成】仅在自动配图功能启用时生成<br>【内容包含】样式要求、放置规则、上下文模板等完整指导',
        'example' => '<image_generation_instructions>包含样式要求、放置规则、上下文模板等完整指导</image_generation_instructions>',
        'usage' => '【可选变量】指导AI在文章适当位置插入HTML注释格式的图片生成提示词，用于后续的图片自动生成',
        'category' => '智能功能',
        'importance' => 'optional',
        'edit_tips' => '💡 建议作为独立功能块放置，避免干扰核心创作指令'
    ),
    'INTERNAL_LINKING_INSTRUCTIONS' => array(
        'name' => '内链指令',
        'description' => '【数据来源】向量相似度匹配的相关文章 + 内链功能开关<br>【条件生成】仅在内链功能启用且存在相似文章时生成<br>【匹配逻辑】通过向量相似度自动找到相关文章',
        'example' => '<internal_linking_instructions><instruction>将以下相关文章的标题和链接自然融入文章正文中...</instruction>相关文章列表...</internal_linking_instructions>',
        'usage' => '【可选变量】指导AI将相关文章自然地融入到当前文章中，提升SEO效果和用户体验',
        'category' => '智能功能',
        'importance' => 'optional',
        'edit_tips' => '💡 建议作为独立功能块放置，包含完整的融入策略和文章列表'
    ),
    'INTERNAL_LINKING_STRATEGY' => array(
        'name' => '内链策略',
        'description' => '【关联变量】与INTERNAL_LINKING_INSTRUCTIONS配套使用<br>【业务含义】在写作策略中包含内链融入的具体方法',
        'example' => '<strategy name="内链融入">严格按照internal_linking_instructions的融入方式和语言模式...</strategy>',
        'usage' => '【辅助变量】在写作策略区域补充内链相关的指导原则',
        'category' => '智能功能',
        'importance' => 'optional',
        'edit_tips' => '💡 建议放在写作策略区域，与其他strategy标签一起使用'
    ),
    'INTERNAL_LINKING_STANDARD' => array(
        'name' => '内链标准',
        'description' => '【关联变量】与INTERNAL_LINKING_INSTRUCTIONS配套使用<br>【业务含义】在质量标准中包含内链质量要求',
        'example' => '<standard name="内链质量">链接必须与段落内容高度相关，使用过渡性词汇自然引入...</standard>',
        'usage' => '【辅助变量】在质量标准区域补充内链相关的质量要求',
        'category' => '智能功能',
        'importance' => 'optional',
        'edit_tips' => '💡 建议放在质量标准区域，与其他standard标签一起使用'
    ),
    'REFERENCE_MATERIAL_BLOCK' => array(
        'name' => '参考资料块',
        'description' => '【获取方法】get_reference_material()方法，三级优先级<br>【优先级1】主题级：topic_data["reference_material"]字段<br>【优先级2】规则级：content_auto_rules表的reference_material字段<br>【优先级3】品牌资料级：get_brand_profile_reference_material()，从content_auto_brand_profiles表type="reference"记录中向量匹配(相似度≥0.8)<br>【条件生成】仅在存在参考资料时生成，包含htmlspecialchars()转义',
        'example' => '<reference_material>\n  <reference_content>我们是专业的AI技术服务提供商，拥有5年行业经验...</reference_content>\n</reference_material>',
        'usage' => '【可选变量】为AI提供背景知识和品牌调性指导，确保文章内容的准确性、深度和品牌一致性',
        'category' => '智能功能',
        'importance' => 'optional',
        'edit_tips' => '💡 建议放在输入素材区域，作为创作的重要参考依据'
    ),
    'REFERENCE_MATERIAL_STRATEGY' => array(
        'name' => '参考资料策略',
        'description' => '【关联变量】与REFERENCE_MATERIAL_BLOCK配套使用<br>【业务含义】指导AI如何在文章中合理使用参考资料',
        'example' => '<strategy name="参考资料融合">将reference_material中的关键信息自然融入到相关章节中...</strategy>',
        'usage' => '【辅助变量】在写作策略区域补充参考资料使用的指导原则',
        'category' => '智能功能',
        'importance' => 'optional',
        'edit_tips' => '💡 建议放在写作策略区域，与其他strategy标签一起使用'
    ),
    'REFERENCE_MATERIAL_PRINCIPLE' => array(
        'name' => '参考资料原则',
        'description' => '【关联变量】与REFERENCE_MATERIAL_BLOCK配套使用<br>【业务含义】确保参考资料的合理使用，避免生硬堆砌',
        'example' => '<principle>参考资料运用：合理运用reference_material中的信息，不生硬堆砌...</principle>',
        'usage' => '【辅助变量】在质量原则区域补充参考资料使用的质量要求',
        'category' => '智能功能',
        'importance' => 'optional',
        'edit_tips' => '💡 建议放在质量原则区域，确保参考资料的自然融合'
    ),
    
    // ==================== 🌐 系统环境变量 ====================
    // 数据来源：系统配置和环境设置
    // 业务含义：确保输出的规范性和准确性
    'LANGUAGE_INSTRUCTION' => array(
        'name' => '语言指令',
        'description' => '【数据来源】发布规则的publish_language设置，通过语言映射转换<br>【支持语言】16种语言的详细使用指导<br>【业务含义】指导AI使用正确的语言和文化表达',
        'example' => '请使用中文（简体）进行回复，采用适合中国大陆用户的表达方式和文化语境。',
        'usage' => '【重要变量】指导AI使用指定的语言和表达方式，确保输出符合目标用户的语言习惯',
        'category' => '系统环境',
        'importance' => 'critical',
        'edit_tips' => '💡 建议放在角色定义或语言要求区域，建立基础语言环境'
    ),
    'LANGUAGE_NAME' => array(
        'name' => '语言名称',
        'description' => '【数据来源】与LANGUAGE_INSTRUCTION同源，提取AI识别的语言名称<br>【业务含义】用于输出约束中的语言明确指定',
        'example' => '中文',
        'usage' => '【重要变量】在输出约束中明确指定使用的语言，强化语言要求',
        'category' => '系统环境',
        'importance' => 'critical',
        'edit_tips' => '💡 建议放在输出约束区域，与格式要求一起强化语言约束'
    ),
    'ROLE_DESCRIPTION' => array(
        'name' => '角色描述',
        'description' => '【数据来源】发布规则表的role_description字段（ID=1的记录）<br>【获取方法】get_role_description_from_publish_rules()方法<br>【默认值】"专业内容创作专家，精通SEO文案、用户体验设计、知识传播策略。您的任务是基于提供的文章标题创作正文内容，输出时直接从第一个章节标题开始，无需重复已提供的主标题。"',
        'example' => '专业内容创作专家，精通SEO文案、用户体验设计、知识传播策略',
        'usage' => '【重要变量】定义AI扮演的专业角色和能力范围，影响创作风格、专业度和表达方式',
        'category' => '系统环境',
        'importance' => 'important',
        'edit_tips' => '💡 建议放在提示词开头，建立AI的角色认知和专业背景'
    ),
    'CURRENT_DATE' => array(
        'name' => '当前日期',
        'description' => '【数据来源】系统动态生成，格式为YYYY年MM月DD日<br>【更新频率】每次生成时实时获取<br>【业务含义】为AI提供准确的时间上下文',
        'example' => '2025年01月15日',
        'usage' => '【辅助变量】帮助AI了解当前时间背景，用于时效性内容的准确表达',
        'category' => '系统环境',
        'importance' => 'optional',
        'edit_tips' => '💡 建议与ROLE_DESCRIPTION一起放在开头，提供时间背景'
    )
);

// 创建变量卡片函数
function create_variable_card($var_name, $var_info) {
    // 重要程度样式映射
    $importance_classes = array(
        'critical' => 'importance-critical',
        'important' => 'importance-important', 
        'optional' => 'importance-optional'
    );
    
    $importance_labels = array(
        'critical' => '🔴 必需',
        'important' => '🟡 重要',
        'optional' => '🟢 可选'
    );
    
    $importance = isset($var_info['importance']) ? $var_info['importance'] : 'optional';
    $category = isset($var_info['category']) ? $var_info['category'] : '其他';
    
    $html = '<div class="variable-card ' . (isset($importance_classes[$importance]) ? $importance_classes[$importance] : '') . '">';
    
    // 变量名和重要程度
    $html .= '<div class="variable-header">';
    $html .= '<div class="variable-name">';
    $html .= '<code>{{' . esc_html($var_name) . '}}</code>';
    $html .= '<span class="variable-title">' . esc_html($var_info['name']) . '</span>';
    $html .= '</div>';
    $html .= '<div class="variable-importance">';
    $html .= '<span class="importance-badge">' . $importance_labels[$importance] . '</span>';
    $html .= '<span class="category-badge">' . esc_html($category) . '</span>';
    $html .= '</div>';
    $html .= '</div>';
    
    // 变量描述（支持HTML）
    $html .= '<div class="variable-description">' . $var_info['description'] . '</div>';
    
    // 示例
    $html .= '<div class="variable-example">';
    $html .= '<strong>📝 示例：</strong>';
    $html .= '<code>' . esc_html($var_info['example']) . '</code>';
    $html .= '</div>';
    
    // 用途
    $html .= '<div class="variable-usage">';
    $html .= '<strong>🎯 用途：</strong>' . esc_html($var_info['usage']);
    $html .= '</div>';
    
    // 编辑建议
    if (isset($var_info['edit_tips'])) {
        $html .= '<div class="variable-edit-tips">';
        $html .= '<strong>✏️ 编辑建议：</strong>' . esc_html($var_info['edit_tips']);
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}
?>

<div class="wrap">
    <div class="page-header">
        <h1><?php _e('变量说明指南', 'content-auto-manager'); ?></h1>
        <div class="page-actions">
            <button id="print-variable-guide" class="button">
                <span class="dashicons dashicons-printer"></span>
                <?php _e('打印', 'content-auto-manager'); ?>
            </button>
        </div>
    </div>

    <div class="variable-guide-intro">
        <h3>📚 什么是模板变量？</h3>
        <p>模板变量是用 <code>{{变量名}}</code> 格式标记的占位符，系统会在生成提示词时自动替换为实际内容。</p>
        
        <h3>🎯 为什么需要理解这些变量？</h3>
        <div class="intro-benefits">
            <div class="benefit-item">
                <strong>🔧 自定义提示词</strong>
                <p>手工编辑XML模板时，正确使用变量可以获得动态内容支持</p>
            </div>
            <div class="benefit-item">
                <strong>📊 理解数据流</strong>
                <p>了解每个变量的数据来源，有助于优化整个内容生成流程</p>
            </div>
            <div class="benefit-item">
                <strong>⚙️ 功能配置</strong>
                <p>根据变量的重要程度和条件，合理配置发布规则和功能开关</p>
            </div>
            <div class="benefit-item">
                <strong>🎨 模板设计</strong>
                <p>基于变量的业务含义，设计更有效的提示词结构和逻辑</p>
            </div>
        </div>
        
        <h3>📋 变量分类说明</h3>
        <div class="category-legend">
            <div class="legend-item">
                <span class="category-badge">📋 核心内容</span>
                <span>来自AI生成的主题数据，决定文章的基本方向和价值</span>
            </div>
            <div class="legend-item">
                <span class="category-badge">⚙️ 发布配置</span>
                <span>来自用户的发布规则设置，提供个性化的创作指导</span>
            </div>
            <div class="legend-item">
                <span class="category-badge">🎯 智能功能</span>
                <span>基于AI向量匹配的高级功能，提升内容质量和用户体验</span>
            </div>
            <div class="legend-item">
                <span class="category-badge">🌐 系统环境</span>
                <span>系统配置和环境设置，确保输出的规范性和准确性</span>
            </div>
        </div>
        
        <h3>🚦 重要程度说明</h3>
        <div class="importance-legend">
            <div class="legend-item">
                <span class="importance-badge critical">🔴 必需</span>
                <span>核心变量，必须包含在模板中才能正常工作</span>
            </div>
            <div class="legend-item">
                <span class="importance-badge important">🟡 重要</span>
                <span>重要变量，建议包含以获得更好的效果</span>
            </div>
            <div class="legend-item">
                <span class="importance-badge optional">🟢 可选</span>
                <span>可选变量，根据功能需求和配置条件决定是否包含</span>
            </div>
        </div>
        
        <div class="quick-tips">
            <h3>💡 快速使用指南</h3>
            <ol>
                <li><strong>查看数据来源</strong> - 了解变量从哪里获取数据，有助于理解其业务含义</li>
                <li><strong>注意条件生成</strong> - 部分变量只在特定条件下生成内容，编辑时需要考虑空值情况</li>
                <li><strong>合理分区放置</strong> - 根据编辑建议将变量放在合适的模板区域</li>
                <li><strong>配套变量使用</strong> - 注意关联变量的配套使用，如内链相关的三个变量</li>
                <li><strong>测试验证</strong> - 修改模板后进行测试，确保变量替换正常工作</li>
            </ol>
        </div>
    </div>


    <div class="variable-tabs">
        <h2 class="nav-tab-wrapper">
            <a href="#topic-variables" class="nav-tab nav-tab-active">主题生成变量</a>
            <a href="#article-variables" class="nav-tab">文章生成变量</a>
        </h2>

        <!-- 主题生成变量 -->
        <div id="topic-variables" class="tab-content active">
            <?php
            // 定义主题生成变量的分类配置（仅包含真正用于主题生成模板的变量）
            $topic_category_config = array(
                '系统环境' => array(
                    'icon' => '🌐', 
                    'description' => '系统配置和环境设置，确保主题生成的规范性和语言准确性',
                    'variables' => ['CURRENT_DATE', 'LANGUAGE_INSTRUCTION', 'LANGUAGE_NAME']
                ),
                '内容来源' => array(
                    'icon' => '📋', 
                    'description' => '主题生成时的参考内容和数据来源，为AI提供创作素材和约束条件',
                    'variables' => ['REFERENCE_CONTENT_BLOCK', 'EXISTING_TOPICS_BLOCK', 'SITE_CATEGORIES_BLOCK']
                ),
                '任务配置' => array(
                    'icon' => '⚙️', 
                    'description' => '主题生成任务的基本参数配置',
                    'variables' => ['{N}']
                )
            );
            ?>
            
            <div class="topic-variables-intro">
                <h3>🎯 主题生成变量说明</h3>
                <p>这些变量用于主题生成的提示词模板中，系统会根据生成规则、参考内容和系统配置自动替换相应内容。</p>
            </div>

            <?php foreach ($topic_category_config as $category => $config): ?>
                <div class="variable-section">
                    <h3 class="section-title">
                        <span class="category-icon"><?php echo $config['icon']; ?></span>
                        <?php echo esc_html($category); ?>变量
                        <span class="variable-count">(<?php echo count($config['variables']); ?>个)</span>
                    </h3>
                    <div class="category-description">
                        <p><?php echo esc_html($config['description']); ?></p>
                    </div>
                    <div class="variables-grid">
                        <?php foreach ($config['variables'] as $var_name): ?>
                            <?php if (isset($topic_variables[$var_name])): ?>
                                <?php echo create_variable_card($var_name, $topic_variables[$var_name]); ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- 文章生成变量 -->
        <div id="article-variables" class="tab-content">
            <?php
            // 按分类组织文章生成变量
            $article_variables_by_category = array();
            foreach ($article_variables as $var_name => $var_info) {
                $category = isset($var_info['category']) ? $var_info['category'] : '其他';
                if (!isset($article_variables_by_category[$category])) {
                    $article_variables_by_category[$category] = array();
                }
                $article_variables_by_category[$category][$var_name] = $var_info;
            }
            
            // 定义文章生成变量的分类顺序和配置
            $article_category_config = array(
                '核心内容' => array('icon' => '📋', 'description' => '来自AI生成的主题数据，决定文章的基本方向和价值'),
                '发布配置' => array('icon' => '⚙️', 'description' => '来自用户的发布规则设置，提供个性化的创作指导'), 
                '智能功能' => array('icon' => '🎯', 'description' => '基于AI向量匹配的高级功能，提升内容质量和用户体验'),
                '系统环境' => array('icon' => '🌐', 'description' => '系统配置和环境设置，确保输出的规范性和准确性')
            );
            ?>
            
            <div class="article-variables-intro">
                <h3>📝 文章生成变量说明</h3>
                <p>这些变量用于文章内容生成的提示词模板中，系统会根据主题数据、发布规则和功能配置自动替换相应内容。</p>
            </div>

            <?php foreach ($article_category_config as $category => $config): ?>
                <?php if (isset($article_variables_by_category[$category])): ?>
                    <div class="variable-section">
                        <h3 class="section-title">
                            <span class="category-icon"><?php echo $config['icon']; ?></span>
                            <?php echo esc_html($category); ?>变量
                            <span class="variable-count">(<?php echo count($article_variables_by_category[$category]); ?>个)</span>
                        </h3>
                        <div class="category-description">
                            <p><?php echo esc_html($config['description']); ?></p>
                        </div>
                        <div class="variables-grid">
                            <?php foreach ($article_variables_by_category[$category] as $var_name => $var_info): ?>
                                <?php echo create_variable_card($var_name, $var_info); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="variable-guide-tips">
        <h3><span class="dashicons dashicons-lightbulb"></span> 使用提示</h3>
        <div class="tips-grid">
            <div class="tip-item">
                <h4>自定义模板</h4>
                <p>修改模板时请确保变量名完全正确，包括大小写和格式。</p>
            </div>
            <div class="tip-item">
                <h4>变量依赖</h4>
                <p>某些变量的值依赖于其他设置，如内容策略块需要配置知识深度，参考资料块按主题级→规则级→品牌资料级的优先级获取。</p>
            </div>
            <div class="tip-item">
                <h4>动态生成</h4>
                <p>大部分变量都是动态生成的，每次请求时可能会获取不同的值。</p>
            </div>
            <div class="tip-item">
                <h4>调试模式</h4>
                <p>启用调试模式可以在日志中查看完整的变量替换过程和最终生成的提示词内容。</p>
            </div>
        </div>
    </div>
</div>

<style>
/* 页面头部样式 */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #ddd;
}

.page-header h1 {
    margin: 0;
    font-size: 23px;
    font-weight: 400;
    line-height: 1.3;
}

.page-actions {
    display: flex;
    gap: 10px;
}

.page-actions .button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}


/* 变量说明页面样式 */
.variable-guide-intro {
    background: #f8f9fa;
    border-left: 4px solid #0073aa;
    padding: 20px;
    margin-bottom: 30px;
    border-radius: 4px;
}

.variable-guide-intro h3 {
    margin-top: 0;
    color: #333;
    font-size: 18px;
}

.variable-guide-intro p {
    margin-bottom: 15px;
    color: #555;
}

.variable-guide-intro ul {
    margin: 0;
    padding-left: 20px;
}

.variable-guide-intro li {
    margin-bottom: 8px;
    color: #333;
}

.variable-guide-intro li:last-child {
    margin-bottom: 0;
}

/* 标签页样式 */
.nav-tab-wrapper {
    margin-bottom: 20px;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* 分类部分 */
.variable-categories h3 {
    color: #333;
    font-size: 16px;
    margin-bottom: 20px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e0e0e0;
}

.variable-category {
    margin-bottom: 30px;
}

.variable-category h4 {
    color: #333;
    font-size: 15px;
    margin-bottom: 15px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.variable-category h4 .dashicons {
    font-size: 18px;
    color: #0073aa;
}

.variable-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 15px;
}

/* 变量卡片 */
.variable-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 15px;
    transition: all 0.2s ease;
    position: relative;
}

.variable-card:hover {
    border-color: #0073aa;
    box-shadow: 0 2px 8px rgba(0, 115, 170, 0.1);
    transform: translateY(-1px);
}

.variable-name {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.variable-name code {
    background: #f1f1f1;
    color: #d63638;
    padding: 3px 8px;
    border-radius: 3px;
    font-family: 'Courier New', Courier, monospace;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid #ddd;
}

.variable-title {
    font-weight: 600;
    color: #333;
    font-size: 13px;
}

.variable-description {
    color: #555;
    margin-bottom: 10px;
    font-size: 13px;
}

.variable-example {
    margin-bottom: 8px;
}

.variable-example strong {
    color: #333;
    font-size: 12px;
}

.variable-example code {
    background: #f8f9fa;
    color: #0073aa;
    padding: 2px 6px;
    border-radius: 3px;
    font-family: 'Courier New', Courier, monospace;
    font-size: 11px;
    border: 1px solid #e0e0e0;
    word-break: break-all;
    display: block;
    margin-top: 4px;
    max-height: 60px;
    overflow-y: auto;
}

.variable-usage {
    color: #666;
    font-size: 12px;
}

.variable-usage strong {
    color: #333;
}

/* 提示部分 */
.variable-guide-tips {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 6px;
    padding: 20px;
    margin-top: 30px;
}

.variable-guide-tips h3 {
    margin-top: 0;
    color: #856404;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 16px;
}

.variable-guide-tips h3 .dashicons {
    color: #ff9800;
}

.tips-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 15px;
}

.tip-item {
    background: rgba(255, 255, 255, 0.8);
    padding: 15px;
    border-radius: 4px;
    border-left: 3px solid #ff9800;
}

.tip-item h4 {
    margin-top: 0;
    margin-bottom: 8px;
    color: #333;
    font-size: 14px;
}

.tip-item p {
    margin: 0;
    color: #666;
    font-size: 12px;
    line-height: 1.5;
}

/* 响应式设计 */
@media screen and (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }

    
    .variable-grid {
        grid-template-columns: 1fr;
        gap: 10px;
    }

    .tips-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }

    .variable-name {
        flex-direction: column;
        align-items: flex-start;
        gap: 5px;
    }

    .variable-name code {
        font-size: 11px;
    }
}

@media screen and (max-width: 480px) {
    .variable-guide-intro {
        padding: 15px;
    }

    .variable-category {
        margin-bottom: 20px;
    }

    .variable-card {
        padding: 12px;
    }

    .variable-guide-tips {
        padding: 15px;
        margin-top: 20px;
    }

    .tips-grid {
        gap: 10px;
    }

    .tip-item {
        padding: 12px;
    }
}

/* 滚动条样式 */
.variable-example::-webkit-scrollbar {
    width: 4px;
}

.variable-example::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 2px;
}

.variable-example::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 2px;
}

.variable-example::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* 打印样式 */
@media print {
    .page-actions,
    .nav-tab-wrapper {
        display: none !important;
    }

    .page-header {
        border-bottom: 2px solid #000;
        margin-bottom: 30px;
    }

    .variable-guide-intro {
        background: #fff !important;
        border-left: 4px solid #000 !important;
        border: 1px solid #000;
        margin-bottom: 30px;
        padding: 20px;
    }

    .variable-card {
        border: 1px solid #000 !important;
        break-inside: avoid;
        margin-bottom: 15px;
    }

    .variable-guide-tips {
        background: #fff !important;
        border: 1px solid #000 !important;
        margin-top: 30px;
    }

    .tab-content.active {
        display: block !important;
    }

    body {
        font-size: 12pt;
        line-height: 1.4;
    }

    .wrap {
        max-width: 100%;
        margin: 0;
        padding: 0;
    }

    .variable-category {
        page-break-inside: avoid;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // 标签页切换功能
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();

        // 移除所有活跃状态
        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').removeClass('active');

        // 添加当前活跃状态
        $(this).addClass('nav-tab-active');
        var targetId = $(this).attr('href').substring(1);
        $('#' + targetId).addClass('active');
    });

    // 打印功能
    $('#print-variable-guide').on('click', function() {
        // 显示所有标签页内容以便打印
        $('.tab-content').addClass('active');
        
        // 打印
        window.print();
        
        // 恢复标签页状态
        setTimeout(function() {
            $('.tab-content').removeClass('active');
            $('.nav-tab-active').trigger('click');
        }, 100);
    });
});
</script>