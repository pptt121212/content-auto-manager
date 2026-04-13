<?php
declare(strict_types=1);

$tmpRoot = sys_get_temp_dir() . '/deep-writing-featured-existing-' . uniqid('', true);
$includesDir = $tmpRoot . '/wp-admin/includes';

if (!is_dir($includesDir) && !mkdir($includesDir, 0777, true) && !is_dir($includesDir)) {
    fwrite(STDERR, "Failed to create include directory\n");
    exit(1);
}

file_put_contents($includesDir . '/file.php', <<<'PHP'
<?php
function download_url($url, $timeout = 30) {
    $GLOBALS['featured_existing_download_called'] = true;
    return new WP_Error('should_not_download', 'download should not be called for existing local attachment');
}
PHP);

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
    $GLOBALS['featured_existing_thumbnail'] = [$post_id, $attachment_id];
    return true;
}

require_once __DIR__ . '/../../deep-writing/class-deep-writing-handler.php';

$reflection = new ReflectionClass('Yali_AI_Writer_DeepWritingHandler');
$method = $reflection->getMethod('attach_featured_image');
$method->setAccessible(true);

$result = $method->invoke(null, 55, 'https://example.com/wp-content/uploads/existing-cover.png');

if ($result !== true) {
    fwrite(STDERR, "Expected featured image attachment to succeed with existing local attachment\n");
    exit(1);
}

if (($GLOBALS['featured_existing_thumbnail'][0] ?? null) !== 55 || ($GLOBALS['featured_existing_thumbnail'][1] ?? null) !== 888) {
    fwrite(STDERR, "Expected existing attachment 888 to be assigned as thumbnail\n");
    exit(1);
}

if (!empty($GLOBALS['featured_existing_download_called'])) {
    fwrite(STDERR, "Expected featured image flow to reuse existing attachment without download\n");
    exit(1);
}

echo "featured-image-existing-attachment-regression: ok\n";
