<?php
/**
 * 英文内容角度迁移脚本
 * 
 * 将数据库中存储的英文内容角度名称转换为中文标准名称
 * 运行方式：wp eval-file "wp-content/plugins/yali-ai-writer/shared/database/migrate-english-angles.php"
 */

if (!defined('ABSPATH')) {
    exit;
}

// 确保加载了必要的函数
require_once YALI_AI_WRITER_PLUGIN_DIR . 'prompt-templating/language-mappings.php';

echo "=== 开始迁移英文内容角度 ===

";

global $wpdb;
$topics_table = $wpdb->prefix . 'yali_ai_writer_topics';

// 定义英文到中文的映射
$angle_mappings = array(
    'Knowledge Base'        => '知识科普',
    'Practical Guide'       => '实操指导',
    'Problem Solving'       => '问题解决',
    'Cases & Scenarios'     => '案例与场景',
    'Comparison & Analysis' => '对比分析',
    'Resources & Tools'     => '资源工具',
    'Trend Insights'        => '趋势洞察',
    'Opinion & Commentary'  => '观点评论',
    'Emotional Resonance'   => '情感共鸣',
    'Creative Inspiration'  => '创新启发',
    // 添加可能的大小写变体
    'knowledge base'        => '知识科普',
    'practical guide'       => '实操指导',
    'problem solving'       => '问题解决',
    'cases & scenarios'     => '案例与场景',
    'comparison & analysis' => '对比分析',
    'resources & tools'     => '资源工具',
    'trend insights'        => '趋势洞察',
    'opinion & commentary'  => '观点评论',
    'emotional resonance'   => '情感共鸣',
    'creative inspiration'  => '创新启发',
);

$total_updated = 0;

foreach ($angle_mappings as $english => $chinese) {
    // 更新 topics 表
    $result = $wpdb->query($wpdb->prepare(
        "UPDATE {$topics_table} SET source_angle = %s WHERE source_angle = %s",
        $chinese,
        $english
    ));
    
    if ($result > 0) {
        echo "✓ 已转换 {$result} 条记录: {$english} -> {$chinese}
";
        $total_updated += $result;
    }
}

echo "
=== 迁移完成 ===
";
echo "共转换 {$total_updated} 条记录
";

// 检查是否还有剩余的英文角度
echo "
=== 检查剩余的英文角度 ===
";
$remaining = $wpdb->get_results(
    "SELECT DISTINCT source_angle FROM {$topics_table} 
     WHERE source_angle REGEXP '^[A-Za-z]'",
    ARRAY_A
);

if (!empty($remaining)) {
    echo "以下英文角度未能自动转换（可能是自定义角度）:
";
    foreach ($remaining as $row) {
        echo "  - {$row['source_angle']}
";
    }
} else {
    echo "✓ 所有英文角度已成功转换
";
}
