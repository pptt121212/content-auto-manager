<?php
/**
 * 授权管理类
 * 
 * @package ContentAutoManager
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_License_Manager {
    
    const LICENSE_SERVER_URL = 'https://key.kdjingpai.com/api.php';
    const LICENSE_OPTION = 'content_auto_manager_license_data';
    const PUBLIC_KEY_FILE = 'public_key.pem';
    
    /**
     * 初始化授权管理器
     */
    public static function init() {
        add_action('admin_init', array(__CLASS__, 'add_license_settings'));
        add_action('admin_notices', array(__CLASS__, 'license_admin_notice'));
    }
    
    /**
     * 检查授权是否有效
     */
    public static function is_license_active() {
        $license_data = get_option(self::LICENSE_OPTION);
        
        if (!isset($license_data['status']) || $license_data['status'] !== 'valid') {
            return false;
        }
        
        if (!isset($license_data['verified_by_official']) || $license_data['verified_by_official'] !== true) {
            return false;
        }
        
        $required_fields = array('status', 'domain', 'last_validated', 'verified_by_official');
        foreach ($required_fields as $field) {
            if (!isset($license_data[$field])) {
                return false;
            }
        }
        
        $current_time = time();
        $last_validated = isset($license_data['last_validated']) ? $license_data['last_validated'] : 0;
        if ($last_validated > $current_time || ($current_time - $last_validated) > 365 * 24 * 60 * 60) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 验证授权完整性
     */
    public static function verify_license_integrity() {
        $license_data = get_option(self::LICENSE_OPTION);
        
        if (!is_array($license_data) || empty($license_data)) {
            return false;
        }
        
        $required_fields = array('status', 'verified_by_official');
        foreach ($required_fields as $field) {
            if (!isset($license_data[$field])) {
                return false;
            }
        }
        
        if ($license_data['status'] === 'valid' && $license_data['verified_by_official'] !== true) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 规范化域名
     */
    public static function normalize_domain($domain) {
        if (!is_string($domain)) {
            return '';
        }
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/^https?:\/\/(www\.)?/', '', $domain);
        $domain = rtrim($domain, '/');
        
        if (empty($domain) || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return '';
        }
        
        return $domain;
    }
    
    /**
     * 激活授权码
     */
    public static function activate_license($license_key) {
        $url = self::LICENSE_SERVER_URL;
        $domain = self::normalize_domain(home_url());
        
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        
        // 验证服务器域名
        $host = parse_url($url, PHP_URL_HOST);
        $valid_domains = array(base64_decode('a2V5LmtkamluZ3BhaS5jb20='));
        
        $is_valid_host = false;
        foreach ($valid_domains as $valid_domain) {
            if ($host === $valid_domain) {
                $is_valid_host = true;
                break;
            }
        }
        
        if (!$is_valid_host) {
            $error_msg = base64_decode('5omL5bel6aSo5omL6KGM5bqm5Y+w5q2j5paH5pys');
            add_settings_error('content_auto_manager_license', 'license_error', $error_msg);
            update_option(self::LICENSE_OPTION, array(
                'status' => base64_decode('aW52YWxpZF9zZXJ2ZXI='),
                'message' => base64_decode('6aSo5omL6KGM5aSE55CG'),
                'verified_by_official' => false
            ));
            return;
        }
        
        // 发送授权请求
        $response = wp_remote_post($url, array(
            'timeout' => 15,
            'body' => array(
                'license_key' => $license_key,
                'domain'      => $domain,
                'version'     => defined('CONTENT_AUTO_MANAGER_VERSION') ? CONTENT_AUTO_MANAGER_VERSION : '1.0.0',
            ),
        ));
        
        if (is_wp_error($response)) {
            add_settings_error('content_auto_manager_license', 'license_error', __('无法连接到授权服务器: ', 'yali-ai-writer') . $response->get_error_message());
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        if (!$data || !isset($data->payload) || !isset($data->signature)) {
            add_settings_error('content_auto_manager_license', 'license_error', __('授权服务器返回了无效的响应。', 'yali-ai-writer'));
            return;
        }
        
        // 验证签名
        $public_key_path = dirname(__FILE__) . '/' . self::PUBLIC_KEY_FILE;
        if (!file_exists($public_key_path)) {
            add_settings_error('content_auto_manager_license', 'license_error', __('插件文件不完整：缺少 public_key.pem。', 'yali-ai-writer'));
            update_option(self::LICENSE_OPTION, array('status' => 'error', 'message' => __('缺少公钥', 'yali-ai-writer')));
            return;
        }
        
        $public_key = file_get_contents($public_key_path);
        $payload_json = base64_decode($data->payload);
        $signature = base64_decode($data->signature);
        
        $is_valid_signature = openssl_verify($payload_json, $signature, $public_key, OPENSSL_ALGO_SHA256) === 1;
        
        if (!$is_valid_signature) {
            add_settings_error('content_auto_manager_license', 'license_error', __('授权签名验证失败！响应可能被篡改。', 'yali-ai-writer'));
            update_option(self::LICENSE_OPTION, array('status' => 'tampered', 'message' => __('签名验证失败', 'yali-ai-writer')));
            return;
        }
        
        // 保存授权数据
        $payload = json_decode($payload_json, true);
        $payload['last_validated'] = time();
        $payload['verified_by_official'] = true;
        
        // 保存原始凭证，以便第三方（如浏览器插件）可进行离线校验
        $payload['raw_payload'] = $data->payload;
        $payload['signature'] = $data->signature;
        
        update_option(self::LICENSE_OPTION, $payload);
        
        if ($payload['status'] === 'valid') {
            add_settings_error('content_auto_manager_license', 'license_success', __('授权成功！', 'yali-ai-writer') . $payload['message'], 'success');
        } else {
            add_settings_error('content_auto_manager_license', 'license_fail', __('授权失败：', 'yali-ai-writer') . $payload['message'], 'error');
        }
    }
    
    /**
     * 添加授权设置到发布规则页面
     */
    public static function add_license_settings() {
        // 这个方法会在发布规则页面调用
    }
    
    /**
     * 显示授权状态通知
     * 注：发布规则页面已有详细的授权提示，此处不再重复显示
     */
    public static function license_admin_notice() {
        // 发布规则页面已有详细的授权设置卡片，不再显示全局通知
        return;
    }
    
    /**
     * 渲染授权码输入框
     */
    public static function render_license_field() {
        $license_key = get_option('content_auto_manager_license_key', '');
        $license_data = get_option(self::LICENSE_OPTION);
        $is_active = self::is_license_active();
        ?>
        <tr>
            <th scope="row"><?php _e('插件授权码', 'yali-ai-writer'); ?></th>
            <td>
                <div style="display: flex; flex-direction: column; gap: 12px; max-width: 600px;">
                    <input type="text" name="content_auto_manager_license_key" 
                           class="regular-text yali-input" 
                           value="<?php echo esc_attr($license_key); ?>" 
                           placeholder="CMT-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" 
                           style="font-family: monospace; letter-spacing: 0.5px;" />
                    
                    <?php if (empty($license_key)): ?>
                        <!-- 未输入授权码时的提示 -->
                        <div class="yali-license-info-box" style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 12px 16px;">
                            <p style="margin: 0 0 8px 0; color: #0369a1;">
                                <span class="dashicons dashicons-info" style="vertical-align: middle; margin-right: 4px;"></span>
                                <strong><?php _e('还没有授权码？', 'yali-ai-writer'); ?></strong>
                            </p>
                            <p style="margin: 0 0 8px 0; color: #0c4a6e; font-size: 13px;">
                                <?php _e('授权后允许修改发布规则、并使用浏览器扩展进行深度内容创作。请申请授权码：', 'yali-ai-writer'); ?>
                            </p>
                            <p style="margin: 0;">
                                <a href="https://www.yaliai.com/user-center/" target="_blank" class="button button-primary" style="padding: 8px 16px; font-size: 14px; height: auto; line-height: 1.5;">
                                    <span class="dashicons dashicons-external" style="vertical-align: middle; font-size: 16px; margin-right: 4px;"></span>
                                    <?php _e('前往鸭梨AI免费申请授权码（无限续期）', 'yali-ai-writer'); ?>
                                </a>
                            </p>
                            <p style="margin: 8px 0 0 0; color: #64748b; font-size: 12px;">
                                <strong>微信：qn006699</strong>　|　<strong>邮箱：w262533099@gmail.com</strong>
                            </p>
                        </div>
                    <?php else: ?>
                        <p class="description yali-desc" style="margin: 0; color: var(--yali-text-muted);"><?php _e('请输入 32 位授权码以解锁发布规则配置功能。', 'yali-ai-writer'); ?></p>
                    <?php endif; ?>
                    
                    <?php if ($is_active): ?>
                        <div class="yali-license-status-box" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 12px 16px;">
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                <span class="dashicons dashicons-yes-alt" style="color: #16a34a; font-size: 20px;"></span>
                                <strong style="color: #166534;"><?php _e('授权有效', 'yali-ai-writer'); ?></strong>
                            </div>
                            <div style="display: grid; grid-template-columns: 80px 1fr; gap: 4px 12px; font-size: 13px; color: #374151;">
                                <span style="color: #6b7280;"><?php _e('授权类型：', 'yali-ai-writer'); ?></span>
                                <span>
                                    <?php 
                                    // 检查license_type是否存在且非空
                                    $license_type = (!empty($license_data['license_type']) && $license_data['license_type'] !== 'unknown') 
                                        ? $license_data['license_type'] 
                                        : 'unknown';
                                    
                                    // 如果服务器返回空，尝试根据到期时间推断
                                    if ($license_type === 'unknown' && isset($license_data['expires_at'])) {
                                        if (empty($license_data['expires_at']) || $license_data['expires_at'] === '永久') {
                                            $license_type = 'permanent';
                                        } else {
                                            $license_type = 'trial';
                                        }
                                    }
                                    
                                    $type_labels = array(
                                        'trial' => __('免费体验', 'yali-ai-writer'),
                                        'annual' => __('年度授权', 'yali-ai-writer'),
                                        'permanent' => __('永久授权', 'yali-ai-writer'),
                                        'unknown' => __('请重新验证以获取类型', 'yali-ai-writer')
                                    );
                                    $type_colors = array(
                                        'trial' => '#f59e0b',
                                        'annual' => '#6366f1',
                                        'permanent' => '#16a34a',
                                        'unknown' => '#9ca3af'
                                    );
                                    $color = $type_colors[$license_type] ?? $type_colors['unknown'];
                                    echo '<span style="color: ' . $color . '; font-weight: 500;">' . esc_html($type_labels[$license_type] ?? $type_labels['unknown']) . '</span>';
                                    ?>
                                </span>
                                
                                <span style="color: #6b7280;"><?php _e('绑定域名：', 'yali-ai-writer'); ?></span>
                                <span><code style="background: #e5e7eb; padding: 2px 6px; border-radius: 4px;"><?php echo esc_html($license_data['domain']); ?></code></span>
                                
                                <?php 
                                // 只有非永久授权才显示到期时间
                                $license_type_for_expires = (!empty($license_data['license_type']) && $license_data['license_type'] !== 'unknown') 
                                    ? $license_data['license_type'] 
                                    : 'unknown';
                                
                                // 如果类型为空，根据到期时间推断
                                if ($license_type_for_expires === 'unknown' && isset($license_data['expires_at'])) {
                                    if (empty($license_data['expires_at']) || $license_data['expires_at'] === '永久') {
                                        $license_type_for_expires = 'permanent';
                                    }
                                }
                                
                                // 永久授权不显示到期时间
                                if ($license_type_for_expires !== 'permanent'): 
                                    $has_expires = isset($license_data['expires_at']) && !empty($license_data['expires_at']) && $license_data['expires_at'] !== '永久';
                                    if ($has_expires):
                                ?>
                                    <span style="color: #6b7280;"><?php _e('到期时间：', 'yali-ai-writer'); ?></span>
                                    <span>
                                        <?php 
                                        $expires_at = strtotime($license_data['expires_at']);
                                        $now = time();
                                        $days_left = ceil(($expires_at - $now) / 86400);
                                        
                                        if ($days_left <= 0) {
                                            echo '<span style="color: #dc2626;">' . __('已过期', 'yali-ai-writer') . '</span>';
                                        } elseif ($days_left <= 7) {
                                            printf(__(' %s 天后到期（请尽快续期）', 'yali-ai-writer'), '<strong style="color: #ea580c;">' . $days_left . '</strong>');
                                        } else {
                                            printf(__(' %s 天后到期', 'yali-ai-writer'), '<strong>' . $days_left . '</strong>');
                                        }
                                        ?>
                                    </span>
                                <?php 
                                    endif;
                                endif; 
                                ?>
                            </div>
                        </div>
                    <?php elseif (!empty($license_key)): ?>
                        <?php $message = isset($license_data['message']) ? $license_data['message'] : __('未激活或已失效', 'yali-ai-writer'); ?>
                        <div class="yali-license-status invalid">
                            <span class="dashicons dashicons-warning" style="vertical-align: middle; margin-right: 4px;"></span>
                            <?php printf(__('授权状态：无效 (%s)', 'yali-ai-writer'), esc_html($message)); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
    }
}

// 初始化授权管理器
ContentAuto_License_Manager::init();