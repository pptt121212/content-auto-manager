<?php
declare(strict_types=1);

$tmpRoot = sys_get_temp_dir() . '/deep-writing-featured-parent-' . uniqid('', true);
define('ABSPATH', $tmpRoot . '/');

final class WP_Error {
    private string $message;

    public function __construct(string $code = '', string $message = '') {
        $this->message = $message;
    }

    public function get_error_message(): string {
        return $this->message;
    }
}

function add_action(...$args): void {}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_attachment_is_image(int $attachment_id): bool { return true; }
function attachment_url_to_postid(string $url): int {
    return $url === 'https://example.com/wp-content/uploads/existing-cover.png' ? 888 : 0;
}
function set_post_thumbnail(int $post_id, int $attachment_id): bool {
    $GLOBALS['featured_parent_thumbnail'] = [$post_id, $attachment_id];
    return true;
}
function wp_update_post(array $data): void {
    $GLOBALS['featured_parent_updates'][] = $data;
}
function update_post_meta(int $post_id, string $key, $value): void {
    $GLOBALS['featured_parent_meta'][] = [$post_id, $key, $value];
}

require_once __DIR__ . '/../../deep-writing/class-deep-writing-handler.php';

$reflection = new ReflectionClass('Yali_AI_Writer_DeepWritingHandler');
$method = $reflection->getMethod('attach_featured_image');
$method->setAccessible(true);

$result = $method->invoke(null, 55, 'https://example.com/wp-content/uploads/existing-cover.png');

if ($result !== true) {
    fwrite(STDERR, "Expected featured image attachment to succeed\n");
    exit(1);
}

$updates = $GLOBALS['featured_parent_updates'] ?? [];
if (($updates[0]['ID'] ?? null) !== 888 || ($updates[0]['post_parent'] ?? null) !== 55) {
    fwrite(STDERR, "Expected existing featured attachment to be reparented to the post\n");
    exit(1);
}

$metaRows = $GLOBALS['featured_parent_meta'] ?? [];
$matched = false;
foreach ($metaRows as $row) {
    if (($row[0] ?? null) === 888 && ($row[1] ?? null) === '_source_post_id' && ($row[2] ?? null) === 55) {
        $matched = true;
        break;
    }
}

if (!$matched) {
    fwrite(STDERR, "Expected _source_post_id meta for featured image attachment\n");
    exit(1);
}

echo "featured-image-parent-association-regression: ok\n";
