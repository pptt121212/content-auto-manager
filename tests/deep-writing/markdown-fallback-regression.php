<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/tmp-root/');

require_once __DIR__ . '/../../shared/content-processing/class-markdown-converter.php';

$converter = new Yali_AI_Writer_MarkdownConverter();
$reflection = new ReflectionClass($converter);

$converterProp = $reflection->getProperty('converter');
$converterProp->setAccessible(true);
$converterProp->setValue($converter, new class {
    public function markdown_to_html($markdown) {
        return '<p>内容转换失败</p>';
    }
});

$typeProp = $reflection->getProperty('converter_type');
$typeProp->setAccessible(true);
$typeProp->setValue($converter, 'commonmark');

$html = $converter->markdown_to_html("# 标题\n\n- 列表项");

if ($html === '<p>内容转换失败</p>' || $html === '') {
    fwrite(STDERR, "Expected fallback HTML instead of CommonMark failure placeholder\n");
    exit(1);
}

if (strpos($html, '<h1>') === false && strpos($html, '<ul>') === false) {
    fwrite(STDERR, "Expected parsed fallback to generate HTML structure\n");
    exit(1);
}

echo "markdown-fallback-regression: ok\n";
