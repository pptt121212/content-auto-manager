<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/tmp-root/');

function add_action(...$args): void {}

final class FakeWpdbTopicPostId {
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';

    public function prepare(string $query, ...$args): array {
        return array($query, $args);
    }

    public function get_var($prepared) {
        [$query, $args] = $prepared;
        if (str_contains($query, 'yali_ai_writer_articles')) {
            return null;
        }
        if (str_contains($query, 'wp_postmeta')) {
            return 456;
        }
        return null;
    }
}

$GLOBALS['wpdb'] = new FakeWpdbTopicPostId();

require_once __DIR__ . '/../../deep-writing/class-deep-writing-handler.php';

$reflection = new ReflectionClass('Yali_AI_Writer_DeepWritingHandler');
$method = $reflection->getMethod('get_topic_post_id');
$method->setAccessible(true);

$postId = $method->invoke(null, 99);

if ($postId !== 456) {
    fwrite(STDERR, "Expected postmeta fallback to return existing draft post ID\n");
    exit(1);
}

echo "topic-post-id-fallback-regression: ok\n";
