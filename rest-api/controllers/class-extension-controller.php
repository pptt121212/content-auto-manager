<?php
namespace ContentAutoManager\RestApi\Controllers;

/**
 * Extension Controller - 为浏览器扩展提供专用 API
 *
 * 提供五个端点：
 * 1. GET  /image-config    — 返回当前激活的图像 API provider 和非敏感配置
 * 2. GET  /search          — 代理搜索请求，使用站点的 license_key 和 domain
 * 3. POST /generate-image  — 服务端代理图像生成，返回 base64
 * 4. POST /upload-image    — 将 base64 图像上传到 WP 媒体库，返回图片 URL
 * 5. POST /screenshot      — 接收截图 base64，上传到媒体库，返回图片 URL
 */
class Extension_Controller extends Base_Controller {

    public function register_routes() {
        register_rest_route( $this->namespace, '/image-config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_image_config' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );

        register_rest_route( $this->namespace, '/search', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'proxy_search' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );

        register_rest_route( $this->namespace, '/generate-image', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'generate_image' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );

        register_rest_route( $this->namespace, '/upload-image', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'upload_image' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );

        register_rest_route( $this->namespace, '/screenshot', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'upload_screenshot' ),
            'permission_callback' => array( $this, 'check_admin_permission' ),
        ) );
    }

    /**
     * GET /image-config
     * 返回当前激活的图像 provider 名称和非敏感配置（不含 API Key）
     */
    public function get_image_config( $request ) {
        if ( ! class_exists( 'Yali_AI_Writer_Image_API_Admin_Page' ) ) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-api-settings/class-image-api-admin-page.php';
        }

        $settings = \Yali_AI_Writer_Image_API_Admin_Page::get_settings();
        $provider = $settings['provider'] ?? '';

        if ( empty( $provider ) ) {
            return new \WP_REST_Response( array( 'error' => 'No image API provider configured' ), 404 );
        }

        // 只返回非敏感配置（model、base_url），不返回 API Key
        $safe_config = array();
        switch ( $provider ) {
            case 'modelscope':
                $safe_config = array( 'model_id' => $settings['modelscope']['model_id'] ?? '' );
                break;
            case 'openai':
                $safe_config = array( 'model' => $settings['openai']['model'] ?? 'gpt-image-1' );
                break;
            case 'siliconflow':
                $safe_config = array( 'model' => $settings['siliconflow']['model'] ?? 'Qwen/Qwen-Image' );
                break;
            case 'pollinations':
                $safe_config = array(
                    'model'     => $settings['pollinations']['model'] ?? 'flux',
                    'has_token' => ! empty( $settings['pollinations']['token'] ),
                );
                break;
            case 'volcengine':
                $safe_config = array( 'model' => $settings['volcengine']['model'] ?? 'doubao-seedream-5-0-260128' );
                break;
            case 'custom':
                $safe_config = array(
                    'model'    => $settings['custom']['model'] ?? 'dall-e-3',
                    'base_url' => $settings['custom']['base_url'] ?? '',
                );
                break;
        }

        return new \WP_REST_Response( array(
            'provider' => $provider,
            'config'   => $safe_config,
        ), 200 );
    }

    /**
     * GET /search?q=xxx&max_results=10
     * 代理搜索请求，使用站点的 license_key 和 domain
     */
    public function proxy_search( $request ) {
        $query = sanitize_text_field( $request->get_param( 'q' ) );
        if ( empty( $query ) ) {
            return new \WP_REST_Response( array( 'error' => 'Missing q parameter' ), 400 );
        }

        $max_results = intval( $request->get_param( 'max_results' ) ?: 10 );
        $max_results = min( max( $max_results, 1 ), 30 );

        if ( ! function_exists( 'yali_ai_writer_search' ) ) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'shared/common/functions.php';
        }

        $result = yali_ai_writer_search( $query, $max_results );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( array(
                'error'   => $result->get_error_message(),
                'success' => false,
            ), 500 );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    /**
     * POST /generate-image
     * 服务端代理图像生成，返回 base64 编码的图像数据
     * body: { "prompt": "..." }
     */
    public function generate_image( $request ) {
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 360 );
        }

        $params = $request->get_json_params();
        $prompt = isset( $params['prompt'] ) ? sanitize_text_field( $params['prompt'] ) : '';

        if ( empty( $prompt ) ) {
            return new \WP_REST_Response( array( 'error' => 'Missing prompt parameter' ), 400 );
        }

        if ( ! class_exists( 'Yali_AI_Writer_Image_API_Handler' ) ) {
            require_once YALI_AI_WRITER_PLUGIN_DIR . 'image-api-settings/class-image-api-handler.php';
        }

        $result = \Yali_AI_Writer_Image_API_Handler::generate_image_from_saved_settings( $prompt );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
        }

        return new \WP_REST_Response( array( 'base64' => $result ), 200 );
    }

    /**
     * POST /upload-image
     * 将 base64 图像数据上传到 WP 媒体库，返回图片 URL
     * body: { "base64": "...", "filename": "image.png", "alt": "图片描述" }
     */
    public function upload_image( $request ) {
        $params = $request->get_json_params();
        $base64  = isset( $params['base64'] )   ? $params['base64']                       : '';
        $filename = isset( $params['filename'] ) ? sanitize_file_name( $params['filename'] ) : 'rich-content-' . time() . '.png';
        $alt      = isset( $params['alt'] )      ? sanitize_text_field( $params['alt'] )    : '';

        if ( empty( $base64 ) ) {
            return new \WP_REST_Response( array( 'error' => 'Missing base64 parameter' ), 400 );
        }

        // 解码 base64（支持带 data URI 前缀和不带前缀两种格式）
        $base64_data = preg_replace( '/^data:image\/[a-z]+;base64,/', '', $base64 );
        $image_data  = base64_decode( $base64_data );

        if ( $image_data === false || strlen( $image_data ) < 100 ) {
            return new \WP_REST_Response( array( 'error' => 'Invalid base64 image data' ), 400 );
        }

        // 上传到 WP 媒体库
        $upload = wp_upload_bits( $filename, null, $image_data );

        if ( ! empty( $upload['error'] ) ) {
            return new \WP_REST_Response( array( 'error' => $upload['error'] ), 500 );
        }

        // 创建附件
        $attachment = array(
            'post_mime_type' => $upload['type'] ?? 'image/png',
            'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_excerpt'   => $alt,
        );

        $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

        if ( is_wp_error( $attachment_id ) ) {
            return new \WP_REST_Response( array( 'error' => $attachment_id->get_error_message() ), 500 );
        }

        // 生成附件元数据（缩略图等）
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        // 设置 alt 文本
        if ( ! empty( $alt ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
        }

        $url = wp_get_attachment_url( $attachment_id );

        return new \WP_REST_Response( array(
            'url'           => $url,
            'attachment_id' => $attachment_id,
        ), 200 );
    }

    /**
     * POST /screenshot
     * 接收浏览器截图的 base64 数据，上传到媒体库，返回图片 URL
     * body: { "base64": "...", "url": "被截图的网址", "alt": "图片描述" }
     */
    public function upload_screenshot( $request ) {
        $params   = $request->get_json_params();
        $base64   = isset( $params['base64'] ) ? $params['base64'] : '';
        $page_url = isset( $params['url'] )    ? esc_url_raw( $params['url'] ) : '';
        $alt      = isset( $params['alt'] )    ? sanitize_text_field( $params['alt'] ) : '';

        if ( empty( $base64 ) ) {
            return new \WP_REST_Response( array( 'error' => 'Missing base64 parameter' ), 400 );
        }

        // 从 URL 生成文件名
        $domain   = $page_url ? parse_url( $page_url, PHP_URL_HOST ) : 'screenshot';
        $domain   = preg_replace( '/[^a-z0-9\-]/', '-', strtolower( $domain ?? 'screenshot' ) );
        $filename = 'screenshot-' . $domain . '-' . time() . '.png';

        // 复用 upload_image 逻辑
        $upload_request = new \WP_REST_Request( 'POST' );
        $upload_request->set_json_params( array(
            'base64'   => $base64,
            'filename' => $filename,
            'alt'      => $alt ?: ( $page_url ?: '网站截图' ),
        ) );

        return $this->upload_image( $upload_request );
    }
}
