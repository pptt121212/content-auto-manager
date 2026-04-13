<?php
declare(strict_types=1);

$tmpRoot = sys_get_temp_dir() . '/deep-writing-sync-regression-' . uniqid('', true);
$includesDir = $tmpRoot . '/wp-admin/includes';
$uploadsDir = $tmpRoot . '/uploads';

if (!is_dir($includesDir) && !mkdir($includesDir, 0777, true) && !is_dir($includesDir)) {
    fwrite(STDERR, "Failed to create include directory\n");
    exit(1);
}

if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
    fwrite(STDERR, "Failed to create uploads directory\n");
    exit(1);
}

file_put_contents($includesDir . '/file.php', <<<'PHP'
<?php
function download_url($url, $timeout = 30) {
    $tmp = tempnam(sys_get_temp_dir(), 'dw_sync_');
    file_put_contents($tmp, 'fake image bytes');
    return $tmp;
}
PHP);

file_put_contents($includesDir . '/image.php', <<<'PHP'
<?php
function wp_generate_attachment_metadata($attachment_id, $file_path) {
    return array(
        'attachment_id' => $attachment_id,
        'file' => basename($file_path),
    );
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
function sanitize_file_name(string $name): string { return $name; }
function wp_attachment_is_image(int $attachment_id): bool { return true; }
function set_post_thumbnail(int $post_id, int $attachment_id): bool {
    $GLOBALS['dw_sync_thumbnail'] = array($post_id, $attachment_id);
    return true;
}
function wp_check_filetype(string $filename, $mimes = null): array { return array('type' => 'image/png'); }
function wp_upload_dir(): array { return array('path' => $GLOBALS['dw_sync_upload_dir']); }
function wp_unique_filename(string $dir, string $filename): string { return 'copied-' . basename($filename); }
function wp_insert_attachment(array $attachment_data, string $file_path, int $post_id) {
    return 321;
}
function wp_update_attachment_metadata(int $attachment_id, array $metadata): bool {
    $GLOBALS['dw_sync_attachment_meta'] = array($attachment_id, $metadata);
    return true;
}
function update_post_meta(int $post_id, string $key, $value): bool {
    $GLOBALS['dw_sync_post_meta'][] = array($post_id, $key, $value);
    return true;
}

$GLOBALS['dw_sync_upload_dir'] = $uploadsDir;
$GLOBALS['dw_sync_post_meta'] = array();

require_once __DIR__ . '/../../deep-writing/class-deep-writing-handler.php';

$reflection = new ReflectionClass('Yali_AI_Writer_DeepWritingHandler');
$method = $reflection->getMethod('attach_featured_image');
$method->setAccessible(true);

$result = $method->invoke(null, 55, 'https://example.com/cover.png');

if ($result !== true) {
    fwrite(STDERR, "Expected featured image attachment to succeed, got failure\n");
    exit(1);
}

if (!function_exists('download_url')) {
    fwrite(STDERR, "Expected download_url to be loaded during attachment\n");
    exit(1);
}

if (!file_exists($uploadsDir . '/copied-cover.png')) {
    fwrite(STDERR, "Expected copied image file to exist in uploads dir\n");
    exit(1);
}

if (($GLOBALS['dw_sync_thumbnail'][0] ?? null) !== 55 || ($GLOBALS['dw_sync_thumbnail'][1] ?? null) !== 321) {
    fwrite(STDERR, "Expected post thumbnail to be assigned\n");
    exit(1);
}

if (($GLOBALS['dw_sync_attachment_meta'][0] ?? null) !== 321) {
    fwrite(STDERR, "Expected attachment metadata to be generated\n");
    exit(1);
}

echo "featured-image-sync-regression: ok\n";
