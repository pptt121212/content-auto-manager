<?php
/**
 * languages/build-mo-v2.php
 * Script to compile JSON sources into .po, .mo, and optimal JED .json files.
 * Reconstructed by AI Assistant with Regex Parsing to prevent FALSE POSITIVES.
 */

$plugin_dir = dirname(__DIR__);
$source_dir = $plugin_dir . '/languages/source';
$lang_dir = $plugin_dir . '/languages';
$domain = 'yali-ai-writer';
$locale = 'en_US';

function get_all_php_files($dir) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            if (strpos($file->getPathname(), '/vendor/') === false && strpos($file->getPathname(), '/node_modules/') === false) {
                $files[] = $file->getPathname();
            }
        }
    }
    return $files;
}

if (!is_dir($source_dir)) {
    mkdir($source_dir, 0755, true);
    file_put_contents($source_dir . '/dummy.json', json_encode(["Dummy" => "Dummy"]));
}

$translations = array();
$duplicate_warnings = array();
$fatal_errors = array();
$json_files = glob($source_dir . '/*.json');

if (!empty($json_files)) {
    foreach ($json_files as $file) {
        $file_name = basename($file);
        $content = json_decode(file_get_contents($file), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $fatal_errors[] = "[致命语法错误] $file_name 无效！JSON 解析失败: " . json_last_error_msg() . " (请检查是否漏了转义双引号、多写了逗号或大括号不匹配)";
            continue;
        }

        if (is_array($content)) {
            foreach ($content as $key => $val) {
                if ($key !== "") { // Prevent duplicate empty message definition
                    if (isset($translations[$key]) && $translations[$key] !== $val) {
                        $duplicate_warnings[] = "Conflict duplicate key: \"$key\" in $file_name (overwriting previous value)";
                    } elseif (isset($translations[$key])) {
                        $duplicate_warnings[] = "Redundant duplicate key: \"$key\" in $file_name (same value)";
                    }
                    $translations[$key] = $val;
                }
            }
        }
    }
}

// 阻断机制：如果发现有任何损坏的 JSON，绝对不能继续编译，否则不仅会丢掉这个损坏文件里的所有翻译，还会错误地生成一堆由于漏失产生的 Warning。
if (!empty($fatal_errors)) {
    echo "\n⛔ ================================================\n";
    echo "⛔ 构建中止！发现损坏的底层 JSON 字典库：\n";
    foreach ($fatal_errors as $err) {
        echo "⛔ - $err\n";
    }
    echo "⛔ ================================================\n";
    echo "💡 请立刻根据上述提示，去修正该 JSON 内的语法格式错误，然后再重新执行本脚本编译。\n\n";
    exit(1);
}

// 2. Generate .po
$po_content = 'msgid ""' . "\n";
$po_content .= 'msgstr ""' . "\n";
$po_content .= '"Project-Id-Version: Yali AI Writer\n"' . "\n";
$po_content .= '"Language: en_US\n"' . "\n";
$po_content .= '"MIME-Version: 1.0\n"' . "\n";
$po_content .= '"Content-Type: text/plain; charset=UTF-8\n"' . "\n";
$po_content .= '"Content-Transfer-Encoding: 8bit\n"' . "\n";
$po_content .= '"Plural-Forms: nplurals=2; plural=(n != 1);\n"' . "\n\n";

foreach ($translations as $msgid => $msgstr) {
    $msgid_escaped = po_escape_string((string)$msgid);
    $msgstr_escaped = po_escape_string((string)$msgstr);
    $po_content .= 'msgid "' . $msgid_escaped . '"' . "\n";
    $po_content .= 'msgstr "' . $msgstr_escaped . '"' . "\n\n";
}

function po_escape_string($str) {
    $str = str_replace('\\', '\\\\', $str);  // 反斜杠
    $str = str_replace('"', '\"', $str);      // 双引号
    $str = str_replace("\t", '\\t', $str);    // 制表符
    $str = str_replace("\n", '\\n', $str);    // 换行符
    $str = str_replace("\r", '', $str);       // 移除回车符
    return $str;
}

$po_file = "$lang_dir/{$domain}-{$locale}.po";
file_put_contents($po_file, $po_content);

// 3. Compile .mo
$mo_file = "$lang_dir/{$domain}-{$locale}.mo";
exec("msgfmt -o " . escapeshellarg($mo_file) . " " . escapeshellarg($po_file), $output, $return_var);
if ($return_var === 0) {
    echo "Compiled $mo_file\n";
} else {
    echo "[Warning] msgfmt failed or is not available. MO compile skipped.\n";
}

// 4. Generate JED via AST-like Regex Parsing
echo "\nGenerating Strict Per-Script JED Files via AST-like Regex...\n\n";

$script_handles = array(
    'yali-ai-writer-admin-js' => array('shared/assets/js/admin.js'),
    'yali-ai-writer-api-js' => array('api-settings/assets/js/api-settings.js'),
    'yali-ai-writer-api-config-inline-js' => array('api-settings/assets/js/api-config-form-inline.js'),
    'yali-ai-writer-article-structures-js' => array('article-structures/assets/js/article-structure-management.js'),
    'yali-ai-writer-article-tasks-inline-js' => array('article-tasks/assets/js/article-tasks-list-inline.js'),
    'yali-ai-writer-debug-js' => array('debug-tools/assets/js/debug-tools.js'),
    'yali-ai-writer-debug-tools-inline-js' => array('debug-tools/assets/js/debug-tools-inline.js'),
    'yali-ai-writer-rules-js' => array('rule-management/assets/js/rule-management.js'),
    'yali-ai-writer-rules-list-js' => array('rule-management/assets/js/rules-list.js'),
    'yali-ai-writer-rule-management-inline-js' => array('rule-management/assets/js/rule-management-inline.js'),
    'yali-ai-writer-rules-list-inline-js' => array('rule-management/assets/js/rules-list-inline.js'),
    'yali-ai-writer-smart-optimization-js' => array('article-structures/assets/js/smart-optimization-settings.js'),
    'yali-ai-writer-dashboard-inline-js' => array('dashboard/assets/js/enhanced-dashboard-inline.js'),
    'yali-ai-writer-clustering-admin-inline-js' => array('admin/assets/js/clustering-admin-inline.js'),
    'yali-ai-writer-extension-api-key-admin-inline-js' => array('admin/assets/js/extension-api-key-admin-inline.js'),
    'yali-ai-writer-topic-js' => array('topic-management/assets/js/topic-management.js'),
    'yali-ai-writer-topics-list-inline-js' => array('topic-management/assets/js/topics-list-inline.js'),
    'yali-ai-writer-topic-jobs-inline-js' => array('topic-management/assets/js/topic-jobs-inline.js'),
    'yali-ai-writer-variable-guide-js' => array('variable-guide/assets/js/variable-guide.js'),
    'yali-ai-writer-variable-guide-inline-js' => array('variable-guide/assets/js/variable-guide-inline.js'),
    'yali-brand-profiles-js' => array('brand-profiles/assets/js/brand-profiles.js'),
    'cam-image-api-settings' => array(
        'image-api-settings/assets/js/image-api-settings.js',
        'image-api-settings/views/provider-modelscope.php',
        'image-api-settings/views/provider-pollinations.php',
        'image-api-settings/views/provider-openai.php',
        'image-api-settings/views/provider-siliconflow.php',
        'image-api-settings/views/provider-volcengine.php',
        'image-api-settings/views/provider-custom.php',
        'image-api-settings/views/image-api-config-form.php'
    ),
    'keyword-research-tool-js' => array('keyword-research-tool/assets/js/keyword-research.js'),
    'content-auto-editor-assistant-block' => array('editor-assistant/classic-editor.js', 'editor-assistant/gutenberg/index.js'),
    'yali-gsc-dashboard' => array('gsc-auth/assets/js/gsc-dashboard.js'),
);

$force_include_translations = array(
    'yali-ai-writer-article-structures-js' => array(
        '知识科普', '实操指导', '问题解决', '案例与场景', '对比分析', 
        '资源工具', '趋势洞察', '观点评论', '情感共鸣', '创新启发',
    ),
    'yali-ai-writer-smart-optimization-js' => array(
        '知识科普', '实操指导', '问题解决', '案例与场景', '对比分析', 
        '资源工具', '趋势洞察', '观点评论', '情感共鸣', '创新启发',
    ),
    'yali-ai-writer-topics-list-inline-js' => array(
        '深度写作需要打开鸭梨AI浏览器扩展，每篇文章约执行5~30分钟',
        '在此期间不要关闭鸭梨AI浏览器扩展。',
        '完成后会将文章发布到文章列表，转为草稿，请校对没问题后发布。',
        '完成后会将文章发布到文章列表，转来为草稿，请校对没问题后发布。',
        '文章中会自动配图，请提前配置好图像API。',
        '鸭梨AI浏览器扩展',
        '确认写作',
    ),
);

$total_strings = count($translations);
$missing_warnings = array();
$php_missing_warnings = array();
$raw_missing_keys = array();

// >>>>> PHP 源码全量解析 (Backend Missing Catch) <<<<<
echo "Scanning PHP backend files for I18N integrity...\n";
$php_files = get_all_php_files($plugin_dir);
// 匹配 __( , _e( , esc_html__( 等 PHP WordPress 全家桶翻译函数
$php_pattern = '/(?:esc_html__|esc_html_e|esc_attr__|esc_attr_e|__|_e|_x)\(\s*(["\'])((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*,\s*(["\'])yali-ai-writer\3/is';

foreach ($php_files as $php_file) {
    $php_content = file_get_contents($php_file);
    if (preg_match_all($php_pattern, $php_content, $matches)) {
        $raw_strings = array_unique($matches[2]);
        foreach ($raw_strings as $raw_str) {
            $clean_str = stripcslashes($raw_str);
            if (!isset($translations[$clean_str])) {
                $php_missing_warnings[] = "[PHP -> ".basename($php_file)."] 漏翻预警: \"$clean_str\"";
                $raw_missing_keys[$clean_str] = "";
            }
        }
    }
}

// >>>>> JS JED 生成逻辑 (Frontend CI) <<<<<
foreach ($script_handles as $handle => $files) {
    $js_content = '';
    foreach ($files as $file) {
        $path = $plugin_dir . '/' . $file;
        if (file_exists($path)) {
            $js_content .= file_get_contents($path);
        }
    }
    
    $matched_translations = array();
    
    /**
     * Regex Pattern explanation:
     * (?:wp\.i18n\.__|__)  Match translation functions like: wp.i18n.__ or just __
     * \(\s*                Match parenthesis and optional spaces
     * (["'`])             Capture group 1: The opening quote
     * ((?:(?!\1)[^\\\\]|\\\\.)*) Capture group 2: The string content! Match any escaped char (\\.) OR any char that is not the closing quote and not backslash.
     * \1                   The closing quote (must aggressively match group 1)
     * \s*,\s*              Comma separating arguments
     * (["'`])yali-ai-writer\3  The domain arg matching 'yali-ai-writer' wrapped in any quotes
     */
    $pattern = '/(?:wp\.i18n\.__|__)\(\s*(["\'`])((?:(?!\1)[^\\\\]|\\\\.)*)\1\s*,\s*(["\'`])yali-ai-writer\3/s';
    
    if (preg_match_all($pattern, $js_content, $matches)) {
        // $matches[2] contains the raw string contents literal from the JS file
        $raw_strings = array_unique($matches[2]);
        
        foreach ($raw_strings as $raw_str) {
            // Unescape the string content as Javascript normally would
            $clean_str = stripcslashes($raw_str);
            
            if (isset($translations[$clean_str])) {
                $matched_translations[$clean_str] = array($translations[$clean_str]);
            } else {
                $raw_missing_keys[$clean_str] = "";
                $missing_warnings[] = "[$handle] Missing dictionary key for literal: \"$clean_str\"";
            }
        }
    }
    
    // Add Forced Includes
    if (isset($force_include_translations[$handle])) {
        foreach ($force_include_translations[$handle] as $msgid) {
            if (isset($translations[$msgid])) {
                $matched_translations[$msgid] = array($translations[$msgid]);
            }
        }
    }
    
    // Write En_US
    $jed_data = array(
        'translation-revision-date' => date('Y-m-d H:i:sO'),
        'generator' => 'Strict Regex Yali AI Writer Script',
        'domain' => 'messages',
        'locale_data' => array(
            'messages' => array(
                '' => array(
                    'domain' => 'messages',
                    'plural-forms' => 'nplurals=2; plural=(n != 1);',
                    'lang' => 'en_US'
                )
            )
        )
    );
    
    foreach ($matched_translations as $msgid => $msgstr_array) {
        $jed_data['locale_data']['messages'][$msgid] = $msgstr_array;
    }
    file_put_contents("$lang_dir/{$domain}-{$locale}-{$handle}.json", json_encode($jed_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    // Write Zh_CN (Empty Dictionary Fallback)
    $zh_jed_data = array(
        'translation-revision-date' => date('Y-m-d H:i:sO'),
        'generator' => 'Strict Regex Yali AI Writer Script',
        'domain' => 'messages',
        'locale_data' => array(
            'messages' => array(
                '' => array(
                    'domain' => 'messages',
                    'plural-forms' => 'nplurals=1; plural=0;',
                    'lang' => 'zh_CN'
                )
            )
        )
    );
    file_put_contents("$lang_dir/{$domain}-zh_CN-{$handle}.json", json_encode($zh_jed_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    $count = count($matched_translations);
    $size = filesize("$lang_dir/{$domain}-{$locale}-{$handle}.json");
    $size_h = $size > 1024 ? round($size/1024, 1).'K' : $size.'B';
    echo "  ✓ $handle: $count keys exact match ($size_h)\n";
}

echo "\nSummary:\n";
echo "  Total dictionary capacity: $total_strings keys\n";
echo "  JED Generation successful with extreme accuracy.\n";

if (!empty($php_missing_warnings)) {
    echo "\n[WARNING - 后台PHP缺失翻译] Found ".count($php_missing_warnings)." strings in PHP missing from JSON dictionaries:\n";
    $php_missing_warnings = array_unique($php_missing_warnings);
    foreach ($php_missing_warnings as $warn) {
        echo "  - $warn\n";
    }
}

if (!empty($missing_warnings)) {
    echo "\n[WARNING - 前台JS缺失翻译] Found ".count($missing_warnings)." strings in JS missing from JSON dictionaries:\n";
    foreach ($missing_warnings as $warn) {
        echo "  - $warn\n";
    }
}

if (!empty($duplicate_warnings)) {
    echo "\n[WARNING - 重复或冲突翻译] Found ".count($duplicate_warnings)." duplicate translation keys across JSON files:\n";
    foreach ($duplicate_warnings as $warn) {
        echo "  - $warn\n";
    }
}
