<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/tmp-root/');

require_once __DIR__ . '/../../shared/content-processing/class-content-filter.php';

$filter = new Yali_AI_Writer_ContentFilter();
$reflection = new ReflectionClass($filter);
$method = $reflection->getMethod('preprocess_markdown_format');
$method->setAccessible(true);

$input = implode("\n", [
    '## 当每个社区都有了自己的数字店面',
    '',
    'Maria是在Flor的Instagram上发现Dom Plastic的。',
    '----------------------------------------------------------------------------------------------------------------------------------------',
    '接下来的正文不应被包进标题中。',
    '-',
    '单个短分隔符后也不应粘连正文。',
]);

$output = $method->invoke($filter, $input);

if (strpos($output, "Maria是在Flor的Instagram上发现Dom Plastic的。\n\n----------------------------------------------------------------------------------------------------------------------------------------\n\n接下来的正文") === false) {
    fwrite(STDERR, "Expected long separator line to gain blank lines before and after\n");
    exit(1);
}

if (strpos($output, "接下来的正文不应被包进标题中。\n\n-\n\n单个短分隔符后也不应粘连正文。") === false) {
    fwrite(STDERR, "Expected single hyphen separator to gain blank lines before and after\n");
    exit(1);
}

echo "separator-normalization-regression: ok\n";
