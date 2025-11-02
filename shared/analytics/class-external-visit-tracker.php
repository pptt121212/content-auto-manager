<?php
/**
 * 简化的外部访问统计器
 * 统计所有外部来源访问文章的总次数
 * 包括：用户主动访问、搜索引擎、爬虫、引荐网址等
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_ExternalVisitTracker {
    
    private $meta_key = '_external_visit_count';
    private $cookie_name = 'cam_internal_session';
    private $cookie_duration = 1800; // 30分钟会话时间
    
    public function __construct() {
        // 只在前端文章页面启用
        if (!is_admin() && !wp_doing_ajax() && !wp_doing_cron()) {
            add_action('wp', array($this, 'track_visit'), 10);
        }
        
        // 为管理员提供查看统计的钩子
        add_action('wp_ajax_get_external_visit_stats', array($this, 'ajax_get_visit_stats'));
    }
    
    /**
     * 跟踪访问
     */
    public function track_visit() {
        // 只在单篇文章页面统计
        if (!is_single() || !is_main_query()) {
            return;
        }
        
        global $post;
        if (!$post || $post->post_type !== 'post') {
            return;
        }
        
        // 检查是否为外部访问
        if ($this->is_external_visit()) {
            $this->record_external_visit($post->ID);
        } else {
            // 设置内部会话cookie
            $this->set_internal_session_cookie();
        }
    }
    
    /**
     * 判断是否为外部访问（简化版，过滤爬虫）
     */
    private function is_external_visit() {
        // 1. 首先检查是否为爬虫，如果是爬虫则不统计
        if ($this->is_bot_crawler()) {
            return false; // 爬虫不统计
        }
        
        // 2. 检查是否有内部会话cookie
        if (isset($_COOKIE[$this->cookie_name])) {
            return false; // 有内部会话，不是外部访问
        }
        
        // 3. 检查referer
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $current_domain = $this->get_current_domain();
        
        // 4. 没有referer（直接访问、书签、隐私浏览器、noreferrer）- 算作外部访问
        if (empty($referer)) {
            return true;
        }
        
        // 5. 有referer但域名不同 - 算作外部访问
        $referer_domain = $this->get_domain_from_url($referer);
        if ($referer_domain && $referer_domain !== $current_domain) {
            return true;
        }
        
        // 6. 其他情况算作内部访问
        return false;
    }
    
    /**
     * 检查是否为爬虫/蜘蛛
     */
    private function is_bot_crawler() {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : '';
        
        // 如果没有User-Agent，可能是恶意请求，不统计
        if (empty($user_agent)) {
            return true;
        }
        
        // 常见爬虫和蜘蛛的User-Agent关键词
        $bot_keywords = [
            'bot', 'spider', 'crawler', 'crawl', 'scraper', 'scrape',
            'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
            'yandexbot', 'sogou', '360spider', 'bytespider', 'twitterbot',
            'facebookexternalhit', 'linkedinbot', 'whatsapp', 'telegrambot',
            'applebot', 'petalbot', 'ahrefsbot', 'semrushbot', 'mj12bot',
            'dotbot', 'rogerbot', 'exabot', 'facebot', 'ia_archiver',
            'archive.org', 'wayback', 'wget', 'curl', 'python-requests',
            'java/', 'okhttp', 'go-http-client', 'postman', 'insomnia',
            'test', 'monitor', 'check', 'pingdom', 'uptime', 'probe',
            'headless', 'phantom', 'selenium', 'webdriver', 'puppeteer',
            'sitemap', 'feed', 'rss', 'preview', 'validator', 'lighthouse'
        ];
        
        // 检查User-Agent中是否包含爬虫关键词
        foreach ($bot_keywords as $keyword) {
            if (strpos($user_agent, $keyword) !== false) {
                return true; // 是爬虫
            }
        }
        
        // 检查常见的真实浏览器标识
        $real_browser_keywords = [
            'mozilla/', 'chrome/', 'safari/', 'firefox/', 'edge/', 'opera/',
            'chromium/', 'webkit/', 'gecko/', 'presto/', 'trident/'
        ];
        
        $has_browser_signature = false;
        foreach ($real_browser_keywords as $browser) {
            if (strpos($user_agent, $browser) !== false) {
                $has_browser_signature = true;
                break;
            }
        }
        
        // 如果没有真实浏览器标识，可能是爬虫
        if (!$has_browser_signature) {
            return true;
        }
        
        return false; // 不是爬虫
    }
    
    
    /**
     * 记录外部访问
     */
    private function record_external_visit($post_id) {
        // 防止短时间内重复统计（同一IP 5分钟内只统计一次）
        if ($this->is_recent_visit($post_id)) {
            return;
        }
        
        // 获取当前访问次数
        $current_count = (int)get_post_meta($post_id, $this->meta_key, true);
        $new_count = $current_count + 1;
        
        // 更新访问次数
        update_post_meta($post_id, $this->meta_key, $new_count);
        
        // 设置防重复统计的临时标记
        $this->set_visit_flag($post_id);
        
        // 设置内部会话cookie（访问后在站内浏览不再重复统计）
        $this->set_internal_session_cookie();
    }
    
    /**
     * 检查是否为最近的重复访问
     */
    private function is_recent_visit($post_id) {
        $visitor_ip = $this->get_visitor_ip();
        $flag_key = 'cam_visit_' . md5($post_id . '_' . $visitor_ip);
        
        // 检查是否有临时标记（5分钟内）
        $last_visit_time = get_transient($flag_key);
        if ($last_visit_time) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 设置访问标记（防止短时间重复统计）
     */
    private function set_visit_flag($post_id) {
        $visitor_ip = $this->get_visitor_ip();
        $flag_key = 'cam_visit_' . md5($post_id . '_' . $visitor_ip);
        
        // 设置5分钟的临时标记
        set_transient($flag_key, time(), 300);
    }
    
    
    /**
     * 设置内部会话cookie
     */
    private function set_internal_session_cookie() {
        if (!headers_sent()) {
            setcookie(
                $this->cookie_name,
                time(),
                time() + $this->cookie_duration,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true // httponly
            );
        }
    }
    
    /**
     * 获取当前域名
     */
    private function get_current_domain() {
        $parsed_url = parse_url(home_url());
        return isset($parsed_url['host']) ? $parsed_url['host'] : '';
    }
    
    /**
     * 从URL中提取域名
     */
    private function get_domain_from_url($url) {
        if (empty($url) || $url === 'direct') {
            return '';
        }
        
        $parsed = parse_url($url);
        return isset($parsed['host']) ? $parsed['host'] : '';
    }
    
    /**
     * 获取访问者IP
     */
    private function get_visitor_ip() {
        $ip_keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * 获取文章的外部访问统计（简化版）
     */
    public function get_external_visit_stats($post_id) {
        $visit_count = (int)get_post_meta($post_id, $this->meta_key, true);
        
        return array(
            'total_visits' => $visit_count
        );
    }
    
    /**
     * 获取文章的外部访问次数
     */
    public function get_visit_count($post_id) {
        return (int)get_post_meta($post_id, $this->meta_key, true);
    }
    
    /**
     * 获取所有文章的外部访问统计概览（简化版）
     */
    public function get_global_stats($limit = 20) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, pm.meta_value as visit_count
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = %s)
            WHERE p.post_type = 'post' 
            AND p.post_status = 'publish'
            AND pm.meta_value IS NOT NULL
            AND pm.meta_value > 0
            ORDER BY CAST(pm.meta_value AS UNSIGNED) DESC
            LIMIT %d
        ", $this->meta_key, $limit), ARRAY_A);
        
        return $results;
    }
    
    /**
     * 重置文章的外部访问统计
     */
    public function reset_stats($post_id) {
        delete_post_meta($post_id, $this->meta_key);
    }
}

// 全局实例
global $content_auto_external_visit_tracker;
$content_auto_external_visit_tracker = new ContentAuto_ExternalVisitTracker();