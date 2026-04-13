<?php
declare(strict_types=1);

define('ABSPATH', sys_get_temp_dir() . '/deep-writing-inline-attach-' . uniqid('', true) . '/');

final class WP_Error {
    private string $message;

    public function __construct(string $code = '', string $message = '') {
        $this->message = $message;
    }

    public function get_error_message(): string {
        return $this->message;
    }
}

final class FakeWpdb {
    public string $prefix = 'wp_';
    public function get_row($query, $output = ARRAY_A) {
        return ['author_id' => 7];
    }
}

final class Yali_AI_Writer_ContentFilter {
    public function filter_content($content) {
        return $content;
    }
}

final class Yali_AI_Writer_MarkdownConverter {
    public function markdown_to_html($content) {
        return $content;
    }
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['inline_attachment_updates'] = [];
$GLOBALS['inline_attachment_meta'] = [];

function add_action(...$args): void {}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function sanitize_text_field(string $text): string { return $text; }
function get_current_user_id(): int { return 1; }
function wp_insert_post(array $post_data, $wp_error = false) { return 99; }
function wp_set_post_categories(int $post_id, array $category_ids): void {}
function update_post_meta(int $post_id, string $key, $value): bool {
    $GLOBALS['inline_attachment_meta'][] = [$post_id, $key, $value];
    return true;
}
function get_term_by($field, $value, $taxonomy) { return false; }
function sanitize_title(string $title): string { return strtolower($title); }
function attachment_url_to_postid(string $url): int {
    $map = [
        'https://example.com/wp-content/uploads/inline-a.png' => 101,
        'https://example.com/wp-content/uploads/inline-b.png' => 202,
    ];
    return $map[$url] ?? 0;
}
function wp_update_post(array $postarr) {
    $GLOBALS['inline_attachment_updates'][] = $postarr;
    return $postarr['ID'] ?? 0;
}

require_once __DIR__ . '/../../deep-writing/class-deep-writing-handler.php';

$reflection = new ReflectionClass('Yali_AI_Writer_DeepWritingHandler');
$method = $reflection->getMethod('create_draft_post');
$method->setAccessible(true);

$content = '<p>Intro</p><p><img src="https://example.com/wp-content/uploads/inline-a.png" alt="A" /></p><p><img src="https://example.com/wp-content/uploads/inline-b.png" alt="B" /></p>';
$postId = $method->invoke(null, 11, 'Test title', $content, '', ['matched_category' => '']);

if ($postId !== 99) {
    fwrite(STDERR, "Expected draft post 99 to be created\n");
    exit(1);
}

$updates = $GLOBALS['inline_attachment_updates'];
if (count($updates) !== 2) {
    fwrite(STDERR, "Expected two inline attachments to be associated to the post\n");
    exit(1);
}

$ids = array_map(static fn(array $row) => $row['ID'] ?? null, $updates);
$parents = array_map(static fn(array $row) => $row['post_parent'] ?? null, $updates);

sort($ids);
sort($parents);

if ($ids !== [101, 202]) {
    fwrite(STDERR, "Expected inline attachment IDs 101 and 202 to be updated\n");
    exit(1);
}

if ($parents !== [99, 99]) {
    fwrite(STDERR, "Expected inline attachments to be attached to post 99\n");
    exit(1);
}

echo "inline-image-attachment-regression: ok\n";
