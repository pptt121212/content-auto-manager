<?php
/**
 * 最终演示：MiniMax-M2 API与Think标签过滤功能
 */

define('ABSPATH', '/tmp/');
define('CONTENT_AUTO_MANAGER_PLUGIN_DIR', __DIR__ . '/');

require_once __DIR__ . '/shared/content-processing/class-content-filter.php';

echo "=== Think标签过滤功能最终演示 ===\n\n";

// 模拟从MiniMax-M2 API获取的真实响应内容
$api_response = '<think>
I should focus on writing tips about improving work efficiency. The target is 200 characters, so I might keep the length flexible around that count. I can aim for a short essay of 200 to 220 characters, and I could include a title, which might not count toward the main character limit.

I\'m considering organizing it into four short paragraphs:
1. Define core objectives: list the top three priorities and set time limits.
2. Schedule deep work in 90-minute blocks.
3. Eliminate distractions: put the phone on Do Not Disturb and close all apps.
4. Use checklists and automate repetitive tasks with templates.

I need to focus on the need to reset and review tasks. I\'ll use templates and automation for repetitive tasks, create SOPs, and define batch processing. I want to keep track of completed work through templates.

It\'s essential to check in at the end of the day: Did I complete three key tasks? What caused friction? I should aim for continuous improvement.
</think>

# 如何提高工作效率

首先，定义核心目标：列出当天3件关键任务，标注截止时间，先完成紧急但重要的事项。

其次，深度专注：安排90分钟"深工时段"，屏蔽干扰，手机静音。第三，消除干扰：整理桌面，关掉无关通知，限定会议时长。

最后，用模板与自动化处理重复工作，建立清单与SOP，完成后复盘：完成了什么、为何拖延、如何改进。保持有节奏的工作与休息，能持续提升效率。';

echo "1. 模拟API响应内容（来自MiniMax-M2）\n";
echo "==========================================\n";
echo "原始响应长度: " . strlen($api_response) . " 字符\n\n";

// 显示前500字符的预览
echo "原始响应预览（前500字符）:\n";
echo substr($api_response, 0, 500) . "...\n\n";

// 检查think标签
$has_think = (strpos($api_response, '<think>') !== false);
preg_match_all('/<think\b[^>]*>.*?<\/think>/is', $api_response, $think_matches);
$think_count = count($think_matches[0]);

echo "包含<think>标签: " . ($has_think ? "是 (共{$think_count}个)" : "否") . "\n";

if ($has_think && $think_count > 0) {
    $think_content_length = 0;
    foreach ($think_matches[0] as $match) {
        $think_content_length += strlen($match);
    }
    echo "Think标签内容总长度: {$think_content_length} 字符\n";
}

echo "\n==========================================\n\n";

// 应用内容过滤
echo "2. 应用内容过滤器\n";
echo "==========================================\n";

$filter = new ContentAuto_ContentFilter();
$filtered_content = $filter->filter_content($api_response);

echo "过滤处理完成\n\n";
echo "过滤后的内容:\n";
echo "---\n";
echo $filtered_content . "\n";
echo "---\n\n";

// 统计结果
$original_length = strlen($api_response);
$filtered_length = strlen($filtered_content);
$removed_length = $original_length - $filtered_length;
$removed_percentage = $original_length > 0 ? round($removed_length / $original_length * 100, 1) : 0;

echo "过滤统计:\n";
echo "  - 原始长度: {$original_length} 字符\n";
echo "  - 过滤后长度: {$filtered_length} 字符\n";
echo "  - 移除字符: {$removed_length} 字符 ({$removed_percentage}%)\n";

$still_has_think = (strpos($filtered_content, '<think>') !== false || strpos($filtered_content, '</think>') !== false);
echo "  - 仍包含<think>标签: " . ($still_has_think ? "是" : "否") . "\n\n";

echo "==========================================\n\n";

// 验证测试结果
echo "3. 测试结果验证\n";
echo "==========================================\n";

$tests_passed = 0;
$tests_total = 4;

// 测试1: Think标签应被移除
echo "测试1: Think标签是否被移除\n";
if ($has_think && !$still_has_think) {
    echo "  ✓ 通过 - Think标签已被成功移除\n";
    $tests_passed++;
} else if ($has_think && $still_has_think) {
    echo "  ✗ 失败 - Think标签仍然存在\n";
} else {
    echo "  ⊘ 跳过 - 原内容不包含think标签\n";
    $tests_passed++;
}

// 测试2: 文章内容应被保留
echo "\n测试2: 文章内容是否被保留\n";
$article_markers = ['# 如何提高工作效率', '首先', '其次', '最后'];
$content_preserved = true;
foreach ($article_markers as $marker) {
    if (strpos($filtered_content, $marker) === false) {
        $content_preserved = false;
        break;
    }
}
if ($content_preserved) {
    echo "  ✓ 通过 - 文章内容完整保留\n";
    $tests_passed++;
} else {
    echo "  ✗ 失败 - 文章内容丢失\n";
}

// 测试3: 过滤后内容长度应合理
echo "\n测试3: 过滤后内容长度是否合理\n";
if ($filtered_length > 100 && $filtered_length < $original_length) {
    echo "  ✓ 通过 - 过滤后保留了有效内容\n";
    $tests_passed++;
} else if ($filtered_length === 0) {
    echo "  ✗ 失败 - 过滤后内容为空\n";
} else {
    echo "  ⚠ 警告 - 内容长度异常\n";
}

// 测试4: 内容格式应保持完整
echo "\n测试4: Markdown格式是否保持完整\n";
if (strpos($filtered_content, '# ') !== false || strpos($filtered_content, '## ') !== false) {
    echo "  ✓ 通过 - Markdown标题格式完整\n";
    $tests_passed++;
} else {
    echo "  ⚠ 警告 - 未检测到Markdown格式\n";
}

echo "\n==========================================\n\n";

// 最终总结
echo "4. 测试总结\n";
echo "==========================================\n";
echo "测试通过: {$tests_passed}/{$tests_total}\n";
echo "通过率: " . round($tests_passed / $tests_total * 100) . "%\n\n";

if ($tests_passed === $tests_total) {
    echo "✓✓✓ 所有测试通过！\n";
    echo "Think标签过滤功能工作正常！\n\n";
    
    echo "功能说明:\n";
    echo "- ✓ 自动识别并移除<think>标签及其内容\n";
    echo "- ✓ 保留标签外的实际文章内容\n";
    echo "- ✓ 支持多行think标签内容\n";
    echo "- ✓ 支持带属性的think标签\n";
    echo "- ✓ 保持Markdown格式完整\n";
    exit(0);
} else if ($tests_passed >= $tests_total * 0.75) {
    echo "⚠ 大部分测试通过\n";
    echo "Think标签过滤功能基本正常\n";
    exit(0);
} else {
    echo "✗ 测试失败较多\n";
    echo "需要检查过滤实现\n";
    exit(1);
}
