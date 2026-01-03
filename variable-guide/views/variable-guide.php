<?php
/**
 * 提示词模板管理与变量说明页面
 */

if (!defined('ABSPATH')) {
    exit;
}

// 检查权限
if (!current_user_can('manage_options')) {
    wp_die(__('抱歉，您没有权限访问此页面。'));
}

// 主题生成变量数据
// 主题生成变量数据
$topic_variables = array(
    // 1. 系统环境与语言
    'CURRENT_DATE' => array(
        'name' => '当前日期',
        'description' => '【数据来源】系统动态生成，格式为YYYY年MM月DD日<br>【更新频率】每次生成时实时获取<br>【业务含义】为AI提供准确的时间上下文',
        'example' => '2025年10月13日',
        'usage' => '【辅助变量】帮助AI了解当前时间背景，用于时效性内容的准确表达'
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

    // 2. 核心输入数据
    'N' => array(
        'name' => '生成数量',
        'description' => '【数据来源】来自任务创建时的topic_count_per_item参数<br>【处理逻辑】在主题生成模板中直接替换为具体数字<br>【业务含义】指定每个规则项目需要生成的主题数量',
        'example' => '5',
        'usage' => '告诉AI需要为当前规则项目生成多少个主题，这个数量由用户在创建主题任务时指定'
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

    // 3. 智能分析与参考
    'REFERENCE_MATERIAL_BLOCK' => array(
        'name' => '参考资料块',
        'description' => '【数据来源】规则级别的参考资料<br>【处理逻辑】调用build_reference_material_block()方法<br>【功能】提供额外的背景资料或指导文档',
        'example' => "<reference_material>\n    <![CDATA[这里是参考资料内容...]]>\n  </reference_material>",
        'usage' => '可选：提供额外的背景知识或参考内容'
    ),
    'INTENT_INFERENCE_BLOCK' => array(
        'name' => '意图推断块',
        'description' => '【数据来源】基于发布规则配置<br>【处理逻辑】调用build_intent_inference_block()方法<br>【功能】如果配置了SEO意图，则生成意图推断指令，指导AI分析用户搜索意图',
        'example' => "<intent_inference>\n    <instruction>根据关键词推断用户搜索意图...</instruction>\n  </intent_inference>",
        'usage' => '可选：指导AI进行搜索意图分析，提高内容匹配度'
    )
);

// 文章生成变量数据 - 按业务分类组织
$article_variables = array(
    // 1. 系统与环境
    'ROLE_DESCRIPTION' => array(
        'name' => '角色描述',
        'description' => '【数据来源】发布规则表的role_description字段（ID=1的记录）<br>【获取方法】get_role_description_from_publish_rules()方法<br>【默认值】"专业内容创作专家..."',
        'example' => '专业内容创作专家，精通SEO文案、用户体验设计、知识传播策略',
        'usage' => '【重要变量】定义AI扮演的专业角色和能力范围，影响创作风格、专业度和表达方式',
        'category' => '系统环境',
        'importance' => 'important',
        'edit_tips' => '💡 建议放在提示词开头，建立AI的角色认知和专业背景'
    ),
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
    'CURRENT_DATE' => array(
        'name' => '当前日期',
        'description' => '【数据来源】系统动态生成，格式为YYYY年MM月DD日<br>【更新频率】每次生成时实时获取<br>【业务含义】为AI提供准确的时间上下文',
        'example' => '2025年01月15日',
        'usage' => '【辅助变量】帮助AI了解当前时间背景，用于时效性内容的准确表达',
        'category' => '系统环境',
        'importance' => 'optional',
        'edit_tips' => '💡 建议与ROLE_DESCRIPTION一起放在开头，提供时间背景'
    ),

    // 2. 核心内容与基础信息
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
    'MATCHED_CATEGORY' => array(
        'name' => '匹配分类',
        'description' => '【数据来源】content_auto_topics表的matched_category字段<br>【业务含义】文章归属的内容分类，影响专业术语和内容深度',
        'example' => '技术分享',
        'usage' => '【重要变量】帮助AI确定内容方向、专业深度和术语使用',
        'category' => '核心内容',
        'importance' => 'important',
        'edit_tips' => '💡 建议放在内容分类或专业要求部分'
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
    'TARGET_LENGTH' => array(
        'name' => '目标字数',
        'description' => '【数据来源】发布规则的target_length设置<br>【验证机制】validate_input_data()方法验证有效性<br>【有效值】300-800, 500-1000, 800-1500, 1000-2000, 1500-3000, 2000-4000<br>【默认值】无效输入时回退到"800-1500"',
        'example' => '800-1500',
        'usage' => '【重要变量】指导AI控制文章篇幅，满足不同场景的长度需求',
        'category' => '发布配置',
        'importance' => 'important',
        'edit_tips' => '💡 建议放在输出约束区域，与格式要求一起使用'
    ),

    // 3. 结构与策略
    'STRUCTURE_BLOCK' => array(
        'name' => '结构块',
        'description' => '【数据来源】content_auto_article_structures表的向量匹配结果<br>【获取方法】get_dynamic_article_structure()方法<br>【匹配逻辑】1)检查主题向量 2)获取同内容角度的候选结构 3)计算余弦相似度 4)取前20个中随机选择 5)更新使用次数<br>【回退机制】向量匹配失败时自动调用get_fallback_structure()，使用预设的专业5段式结构<br>【条件生成】仅在规范化输出启用时生成，保证始终有结构指导',
        'example' => '<source_angle_structures>...</source_angle_structures>',
        'usage' => '【可选变量】为AI提供专业的文章结构框架，优先使用向量匹配结果，失败时使用预设的专业结构，确保内容组织的专业性和逻辑性',
        'category' => '智能功能',
        'importance' => 'optional',
        'edit_tips' => '💡 建议放在结构指导区域，包含智能匹配和专业回退的完整机制'
    ),
    'STRUCTURE_USAGE_GUIDANCE' => array(
        'name' => '结构使用指导',
        'description' => '【关联变量】与STRUCTURE_BLOCK配套使用<br>【业务含义】指导AI如何具体执行结构块中的指令',
        'example' => '<guidance>请严格遵循selected_structure中的章节安排...</guidance>',
        'usage' => '【可选变量】告诉AI在生成文章时必须严格遵循所选结构的章节安排，不随意增减章节',
        'category' => '智能功能',
        'importance' => 'optional',
        'edit_tips' => '💡 建议紧跟在STRUCTURE_BLOCK之后，强化执行力度'
    ),
    'CONTENT_STRATEGY_BLOCK' => array(
        'name' => '内容策略块',
        'description' => '【数据来源】发布规则的内容深度设置<br>【处理逻辑】validate_input_data()验证，"未设置"时为空字符串，其他值转换为完整指令<br>【有效值】未设置、浅层普及、实用指导、深度分析、全面综述<br>【生成机制】包含完整的<content_strategy>标签或为空',
        'example' => '<content_strategy>实用指导...</content_strategy>',
        'usage' => '【可选变量】当用户配置了特定的知识深度时，指导AI采用相应的内容创作策略和深度',
        'category' => '发布配置',
        'importance' => 'optional',
        'edit_tips' => '💡 建议放在写作要求区域，影响内容的深度和表达方式'
    ),
    'TARGET_AUDIENCE_BLOCK' => array(
        'name' => '目标受众块',
        'description' => '【数据来源】发布规则的目标受众设置<br>【处理逻辑】validate_input_data()验证，"未设置"时为空字符串，其他值转换为完整指令<br>【有效值】未设置、潜在客户、现有客户、行业同仁、决策者、泛流量用户<br>【生成机制】包含完整的<target_audience>标签或为空',
        'example' => '<target_audience>潜在客户...</target_audience>',
        'usage' => '【可选变量】当用户配置了特定的读者角色时，指导AI针对该受众群体调整表达方式和内容重点',
        'category' => '发布配置',
        'importance' => 'optional',
        'edit_tips' => '💡 建议与CONTENT_STRATEGY_BLOCK放在同一区域，共同指导写作策略'
    ),

    // 4. 智能功能（参考资料、内链、图片）
    'REFERENCE_MATERIAL_BLOCK' => array(
        'name' => '参考资料块',
        'description' => '【获取方法】get_reference_material()方法，三级优先级<br>【优先级1】主题级：topic_data["reference_material"]字段<br>【优先级2】规则级：content_auto_rules表的reference_material字段<br>【优先级3】品牌资料级：get_brand_profile_reference_material()，从content_auto_brand_profiles表type="reference"记录中向量匹配(相似度≥0.8)<br>【条件生成】仅在存在参考资料时生成，包含htmlspecialchars()转义',
        'example' => '<reference_material>...</reference_material>',
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
    'INTERNAL_LINKING_INSTRUCTIONS' => array(
        'name' => '内链指令',
        'description' => '【数据来源】向量相似度匹配的相关文章 + 内链功能开关<br>【条件生成】仅在内链功能启用且存在相似文章时生成<br>【匹配逻辑】通过向量相似度自动找到相关文章',
        'example' => '<internal_linking_instructions>...</internal_linking_instructions>',
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
    'IMAGE_INSTRUCTIONS' => array(
        'name' => '图片指令',
        'description' => '【数据来源】发布规则的auto_image_insertion开关<br>【条件生成】仅在自动配图功能启用时生成<br>【内容包含】样式要求、放置规则、上下文模板等完整指导',
        'example' => '<image_generation_instructions>包含样式要求...</image_generation_instructions>',
        'usage' => '【可选变量】指导AI在文章适当位置插入HTML注释格式的图片生成提示词，用于后续的图片自动生成',
        'category' => '智能功能',
        'importance' => 'optional',
        'edit_tips' => '💡 建议作为独立功能块放置，避免干扰核心创作指令'
    )
);

function create_variable_card($var_name, $var_info) {
    $importance_classes = array(
        'critical' => 'importance-critical',
        'important' => 'importance-important', 
        'optional' => 'importance-optional'
    );
    
    $importance_labels = array(
        'critical' => '必需',
        'important' => '重要',
        'optional' => '可选'
    );
    
    $importance = isset($var_info['importance']) ? $var_info['importance'] : 'optional';
    
    $html = '<div class="variable-card ' . (isset($importance_classes[$importance]) ? $importance_classes[$importance] : '') . '" onclick="App.insertVariable(\'{{' . esc_js($var_name) . '}}\')" data-var-name="' . esc_attr($var_name) . '">';
    $html .= '<div class="card-header">';
    $html .= '<code>{{' . esc_html($var_name) . '}}</code>';
    $html .= '<div><span class="var-usage-badge">0</span><span class="badge">' . $importance_labels[$importance] . '</span></div>';
    $html .= '</div>';
    $html .= '<h4 class="card-title">' . esc_html($var_info['name']) . '</h4>';
    $html .= '<p class="card-desc">' . $var_info['description'] . '</p>';
    $html .= '</div>';
    return $html;
}
?>

<div class="wrap">
    <div class="page-header">
        <h1>提示词模板管理</h1>
        <div class="header-actions">
            <button class="button button-primary" onclick="App.showEditor()">
                <span class="dashicons dashicons-plus-alt2"></span> 新建模板
            </button>
        </div>
    </div>

    <!-- 列表视图 -->
    <div id="view-list" class="view-section">
        <ul class="subsubsub">
            <li class="all"><a href="javascript:void(0)" class="current" onclick="App.filterList('all', this)">全部</a> |</li>
            <li class="topic"><a href="javascript:void(0)" onclick="App.filterList('topic_generation', this)">主题生成</a> |</li>
            <li class="article"><a href="javascript:void(0)" onclick="App.filterList('article_generation', this)">文章生成</a></li>
        </ul>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="40%">模板名称</th>
                    <th width="20%">类型</th>
                    <th width="15%">状态</th>
                    <th width="25%">操作</th>
                </tr>
            </thead>
            <tbody id="template-table-body">
                <tr><td colspan="4">加载中...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- 编辑器视图 -->
    <div id="view-editor" class="view-section" style="display:none;">
        <div class="editor-container">
            <div class="editor-main">
                <div class="editor-form-row">
                    <input type="text" id="template-name" placeholder="请输入模板名称" class="large-text">
                </div>
                <div class="editor-form-row">
                    <select id="template-type" class="regular-text">
                        <option value="article_generation">文章生成模板</option>
                        <option value="topic_generation">主题生成模板</option>
                    </select>
                    <select id="template-status" class="regular-text">
                        <option value="1">启用</option>
                        <option value="0">禁用</option>
                    </select>
                </div>
                <!-- 文本编辑器 -->
                <div class="editor-wrapper">
                    <div id="highlight-layer" class="highlight-layer"></div>
                    <textarea id="template-content" class="xml-editor" placeholder="在此输入XML提示词模板..."></textarea>
                </div>
                
                <div class="editor-actions">
                    <button class="button button-primary" onclick="App.saveTemplate()">保存模板</button>
                    <button class="button" onclick="App.showList()">取消</button>
                </div>
            </div>
            
            <div class="editor-sidebar">
                <h3 id="sidebar-heading-text">可用变量 <small>(点击插入)</small></h3>
                <div class="sidebar-tabs">
                    <button class="tab-btn active" onclick="App.switchSidebarTab('topic')" id="tab-btn-topic">主题变量</button>
                    <button class="tab-btn" onclick="App.switchSidebarTab('article')" id="tab-btn-article">文章变量</button>
                </div>
                
                <div id="sidebar-topic" class="sidebar-vars active">
                    <?php foreach ($topic_variables as $name => $info): ?>
                        <?php echo create_variable_card($name, $info); ?>
                    <?php endforeach; ?>
                </div>
                
                <div id="sidebar-article" class="sidebar-vars">
                    <?php foreach ($article_variables as $name => $info): ?>
                        <?php echo create_variable_card($name, $info); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* 布局样式 */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    border-bottom: 1px solid #ccc;
    padding-bottom: 10px;
}
.header-actions .button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.editor-container {
    display: flex;
    gap: 20px;
    height: calc(100vh - 150px);
}
.editor-main {
    flex: 1;
    display: flex;
    flex-direction: column;
}
.editor-sidebar {
    width: 320px;
    background: #f0f0f1;
    border: 1px solid #c3c4c7;
    padding: 15px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}

/* 编辑器样式 */
.editor-form-row {
    margin-bottom: 15px;
    display: flex;
    gap: 10px;
}
.editor-wrapper {
    position: relative;
    flex: 1;
    width: 100%;
    margin-bottom: 10px;
    background: #2b2b2b;
    border-radius: 4px;
    overflow: hidden;
}
.xml-editor, .highlight-layer {
    font-family: 'Courier New', Courier, monospace;
    font-size: 14px;
    line-height: 1.5;
    padding: 10px;
    width: 100%;
    height: 100%;
    box-sizing: border-box;
    white-space: pre-wrap;
    word-wrap: break-word;
    border: none;
    margin: 0;
}
.xml-editor {
    position: relative;
    z-index: 2;
    background: transparent;
    color: transparent;
    caret-color: #f8f8f2;
    resize: none;
    display: block;
    -webkit-text-fill-color: transparent;
}
.highlight-layer {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 1;
    background: #2b2b2b;
    color: #f8f8f2;
    pointer-events: none; /* 让点击穿透到textarea */
    overflow: hidden;
}
/* 变量高亮样式 */
.highlight-var {
    color: #ff4444;
    font-weight: bold;
    background: rgba(255, 68, 68, 0.1);
    border-radius: 2px;
    padding: 0 2px;
}
.editor-actions {
    margin-top: 10px;
}

/* 变量卡片样式 */
.sidebar-tabs {
    display: flex;
    margin-bottom: 15px;
    border-bottom: 1px solid #ddd;
}
.tab-btn {
    flex: 1;
    background: none;
    border: none;
    padding: 8px;
    cursor: pointer;
    font-weight: 600;
    color: #666;
}
.tab-btn.active {
    color: #2271b1;
    border-bottom: 2px solid #2271b1;
}
.sidebar-vars {
    display: none;
    overflow-y: auto;
    flex: 1;
}
.sidebar-vars.active {
    display: block;
}
.variable-card {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    padding: 10px;
    margin-bottom: 10px;
    cursor: pointer;
    transition: all 0.2s;
}
.variable-card:hover {
    border-color: #2271b1;
    transform: translateX(-2px);
    box-shadow: 2px 2px 5px rgba(0,0,0,0.05);
}
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px;
}
.card-header code {
    color: #d63638;
    background: #f0f6fc;
    padding: 2px 5px;
    border-radius: 3px;
    font-weight: bold;
}
.card-title {
    margin: 5px 0;
    font-size: 14px;
    color: #1d2327;
}
.card-desc {
    font-size: 12px;
    color: #646970;
    margin: 0;
    line-height: 1.4;
    word-break: break-all;
}
.badge {
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 10px;
    background: #eee;
}
.importance-critical .badge { background: #d63638; color: white; }
.importance-important .badge { background: #dba617; color: white; }
.importance-optional .badge { background: #00a32a; color: white; }

/* 变量使用次数徽章 */
.var-usage-badge {
    background: #2271b1;
    color: white;
    font-size: 10px;
    padding: 2px 6px;
    border-radius: 10px;
    margin-left: 5px;
    display: none; /* 默认隐藏，有次数时显示 */
}
.var-usage-badge.has-count {
    display: inline-block;
}

/* 隐藏手动切换标签，改为自动关联 */
.sidebar-tabs {
    display: none; 
}
</style>

<script>
var App = {
    ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
    nonce: '<?php echo wp_create_nonce('content_auto_template_nonce'); ?>',
    currentId: null,
    
    init: function() {
        this.loadTemplates();
        
        // Link template type to sidebar visibility
        jQuery('#template-type').on('change', function() {
             var type = jQuery(this).val();
             App.syncSidebarWithType(type);
        });
        
        // Editor Bindings
        var $editor = jQuery('#template-content');
        $editor.on('input', function() {
            App.updateHighlight.call(this);
            App.updateVariableCounts();
        });
        $editor.on('scroll', this.syncScroll);
        
        // Initial trigger
        this.updateHighlight.call(document.getElementById('template-content'));
    },
    
    // Sync sidebar with template type
    syncSidebarWithType: function(type) {
        jQuery('.sidebar-vars').removeClass('active');
        if(type && type.includes('topic')) {
            jQuery('#sidebar-topic').addClass('active');
            jQuery('#sidebar-heading-text').text('可用主题变量');
        } else {
            jQuery('#sidebar-article').addClass('active');
             jQuery('#sidebar-heading-text').text('可用文章变量');
        }
    },
    
    // Update variable usage counts
    updateVariableCounts: function() {
        var content = jQuery('#template-content').val() || '';
        
        // Find all {{VAR}} occurrences
        var matches = content.match(/\{\{[A-Z0-9_]+((?:\.)[A-Z0-9_]+)*\}\}/g);
        var counts = {};
        
        if (matches) {
            matches.forEach(function(m) {
                // Remove {{ and }}
                var name = m.replace(/^\{\{|\}\}$/g, '');
                counts[name] = (counts[name] || 0) + 1;
            });
        }
        
        // Update UI
        jQuery('.variable-card').each(function() {
            var varName = jQuery(this).data('var-name');
            var count = counts[varName] || 0;
            var $badge = jQuery(this).find('.var-usage-badge');
            
            if (count > 0) {
                $badge.text(count).addClass('has-count');
                jQuery(this).addClass('is-used'); // Optional styling
            } else {
                $badge.removeClass('has-count');
                jQuery(this).removeClass('is-used');
            }
        });
    },

    // Editor Highlighting Logic
    updateHighlight: function() {
        var text = this.value || '';
        // Escape HTML
        var escaped = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
            
        // Highlight logic for {{VARIABLES}}
        var highlighted = escaped.replace(/(\{\{[^}]+\}\})/g, '<span class="highlight-var">$1</span>');
        
        if (text[text.length - 1] === "\n") {
            highlighted += " "; 
        }
        
        jQuery('#highlight-layer').html(highlighted);
    },
    
    syncScroll: function() {
        jQuery('#highlight-layer').scrollTop(this.scrollTop);
        jQuery('#highlight-layer').scrollLeft(this.scrollLeft);
    },
    
    // Switch between list and editor
    showList: function() {
        jQuery('#view-editor').hide();
        jQuery('#view-list').show();
        this.loadTemplates();
    },
    
    showEditor: function(id = null) {
        jQuery('#view-list').hide();
        jQuery('#view-editor').show();
        
        if (id) {
            this.loadTemplateDetail(id);
        } else {
            this.resetEditor();
        }
        
        // Initialization after show
        setTimeout(function() {
            var el = document.getElementById('template-content');
            if (el) {
                App.updateHighlight.call(el);
                App.updateVariableCounts();
            }
        }, 100);
    },
    
    resetEditor: function() {
        this.currentId = null;
        jQuery('#template-name').val('');
        jQuery('#template-content').val('');
        jQuery('#template-type').val('article_generation').trigger('change'); // This will trigger syncSidebar
        jQuery('#template-status').val('1');
        
        // Manual triggers if necessary mainly handled by change event above
        // But let's ensure counts are reset
        App.updateVariableCounts();
    },
    
    // Load list
    loadTemplates: function(type = null) {
        var data = {
            action: 'content_auto_get_templates',
            type: type
        };
        
        jQuery('#template-table-body').html('<tr><td colspan="4">加载中...</td></tr>');
        
        jQuery.get(this.ajaxUrl, data, function(response) {
            if (response.success) {
                App.renderTable(response.data);
            } else {
                jQuery('#template-table-body').html('<tr><td colspan="4">加载失败: ' + response.data + '</td></tr>');
            }
        });
    },
    
    renderTable: function(items) {
        var html = '';
        if (items.length === 0) {
            html = '<tr><td colspan="4" align="center">暂无模板，请点击新建</td></tr>';
        } else {
            items.forEach(function(item) {
                var typeLabel = item.template_type === 'article_generation' ? '文章生成' : '主题生成';
                var statusLabel = item.is_active == 1 ? '<span style="color:green">启用</span>' : '<span style="color:red">禁用</span>';
                
                html += '<tr>';
                html += '<td><strong>' + item.name + '</strong></td>';
                html += '<td>' + typeLabel + '</td>';
                html += '<td>' + statusLabel + '</td>';
                html += '<td>';
                html += '<button class="button button-small" onclick="App.showEditor(' + item.id + ')">编辑</button> ';
                html += '<button class="button button-small link-delete" onclick="App.deleteTemplate(' + item.id + ')" style="color:#b32d2e">删除</button>';
                html += '</td>';
                html += '</tr>';
            });
        }
        jQuery('#template-table-body').html(html);
    },
    
    filterList: function(type, el) {
        jQuery('.subsubsub a').removeClass('current');
        jQuery(el).addClass('current');
        this.loadTemplates(type === 'all' ? null : type);
    },
    
    // Load Detail
    loadTemplateDetail: function(id) {
        jQuery.get(this.ajaxUrl, { action: 'content_auto_get_templates' }, function(res) {
            var item = res.data.find(t => t.id == id);
            if (item) {
                App.currentId = item.id;
                jQuery('#template-name').val(item.name);
                jQuery('#template-type').val(item.template_type).trigger('change'); // Updates Sidebar
                jQuery('#template-content').val(item.content);
                jQuery('#template-status').val(item.is_active);
                
                // Trigger updates
                App.updateHighlight.call(document.getElementById('template-content'));
                App.updateVariableCounts();
            }
        });
    },
    
    // Save
    saveTemplate: function() {
        var data = {
            action: 'content_auto_save_template',
            security: this.nonce,
            name: jQuery('#template-name').val(),
            template_type: jQuery('#template-type').val(),
            content: jQuery('#template-content').val(),
            is_active: jQuery('#template-status').val(),
            id: this.currentId
        };
        
        if (!data.name || !data.content) {
            alert('请填写名称和内容');
            return;
        }
        
        jQuery.post(this.ajaxUrl, data, function(response) {
            if (response.success) {
                alert('保存成功');
                App.showList();
            } else {
                alert('保存失败: ' + (response.data || 'Unknown error'));
            }
        });
    },
    
    // Delete
    deleteTemplate: function(id) {
        if (!confirm('确定要删除此模板吗？')) return;
        
        jQuery.post(this.ajaxUrl, {
            action: 'content_auto_delete_template',
            security: this.nonce,
            id: id
        }, function(response) {
            if (response.success) {
                App.loadTemplates();
            } else {
                alert('删除失败');
            }
        });
    },
    
    insertVariable: function(text) {
        var el = document.getElementById('template-content');
        var start = el.selectionStart;
        var end = el.selectionEnd;
        var val = el.value;
        
        el.value = val.substring(0, start) + text + val.substring(end);
        el.selectionStart = el.selectionEnd = start + text.length;
        el.focus();
        
        // Trigger input event to update highlight and counts
        jQuery(el).trigger('input');
    }
};

jQuery(document).ready(function() {
    App.init();
});
</script>