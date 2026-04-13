<?php
/**
 * 异步图片处理器
 * 处理WordPress定时任务中的图片生成
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-auto-image-generator.php';

class Yali_AI_Writer_AsyncImageProcessor {
    
    private $auto_image_generator;
    private $logger;
    
    public function __construct() {
        $this->auto_image_generator = new Yali_AI_Writer_AutoImageGenerator();
        $this->logger = new Yali_AI_Writer_LoggingSystem();
        
        // 注册固定的异步任务处理器（向后兼容旧任务）
        add_action('yali_ai_writer_process_post_images', [$this, 'process_post_images'], 10, 3);
        
        // 【关键修复】使用通配钩子模式捕获所有动态图片任务
        // 这样无论任务使用什么hook名称（只要包含特定前缀），都能被正确处理
        add_action('init', [$this, 'register_dynamic_handlers_on_init'], 99);
    }
    
    /**
     * 在 WordPress init 钩子中注册动态处理器
     * 捕获所有以 'yali_ai_writer_process_post_images_' 开头的任务
     */
    public function register_dynamic_handlers_on_init() {
        global $wp_filter;
        
        // 获取当前所有已注册的 cron 任务
        $cron_array = _get_cron_array();
        if (empty($cron_array)) {
            return;
        }
        
        $registered_count = 0;
        $unique_hooks = [];
        
        foreach ($cron_array as $timestamp => $cronhooks) {
            if (!is_array($cronhooks)) {
                continue;
            }
            
            foreach ($cronhooks as $hook => $events) {
                // 检查是否是图片生成任务的动态 hook（带post_id的唯一hook）
                if (preg_match('/^yali_ai_writer_process_post_images_(\d+)$/', $hook, $matches)) {
                    $post_id = intval($matches[1]);
                    
                    // 避免重复注册同一个 hook
                    if (in_array($hook, $unique_hooks)) {
                        continue;
                    }
                    $unique_hooks[] = $hook;
                    
                    // 如果这个 hook 还没有处理器，注册一个
                    if (!has_action($hook)) {
                        add_action($hook, [$this, 'process_post_images'], 10, 3);
                        $registered_count++;
                        
                        $this->logger->log_debug('DYNAMIC_CRON_HANDLER_REGISTERED', '动态Cron处理器已注册', [
                            'post_id' => $post_id,
                            'hook_name' => $hook
                        ]);
                    }
                }
            }
        }
        
        if ($registered_count > 0) {
            $this->logger->log_info('DYNAMIC_HANDLERS_READY', '动态图片处理器准备就绪', [
                'registered_count' => $registered_count,
                'total_unique_hooks' => count($unique_hooks)
            ]);
        }
    }
    
    /**
     * 处理单篇文章的图片生成
     * WordPress定时任务回调函数
     * 
     * @param int $post_id 文章ID
     * @param string $content 文章内容
     * @param array|null $publish_rules 发布规则（可选）
     */
    public function process_post_images($post_id, $content, $publish_rules = null) {
        $context = [
            'post_id' => $post_id,
            'function' => 'process_post_images',
            'timestamp' => current_time('mysql'),
            'has_publish_rules' => !empty($publish_rules)
        ];
        
        $this->logger->log_info('ASYNC_IMAGE_START', '开始异步处理文章图片', $context);
        
        try {
            // 检查文章是否仍然存在
            $post = get_post($post_id);
            if (!$post) {
                $this->logger->log_error('POST_NOT_FOUND', '文章不存在，跳过图片处理', $context);
                return;
            }
            
            // 检查是否已处理过，避免重复处理
            if ($this->is_post_already_processed($post_id)) {
                $this->logger->log_info('ALREADY_PROCESSED', '文章已处理过图片，跳过', $context);
                return;
            }
            
            // 获取最新的文章内容（可能已被更新）
            $current_content = $post->post_content;
            
            // 如果传入的内容与当前内容不同，使用当前内容
            if ($content !== $current_content) {
                $this->logger->log_info('CONTENT_UPDATED', '检测到文章内容已更新，使用最新内容', $context);
                $content = $current_content;
            }
            
            // 执行图片生成，传递发布规则（如果有的话）
            $options = [];
            if ($publish_rules) {
                $options['publish_rules'] = $publish_rules;
            }
            $result = $this->auto_image_generator->auto_generate_images_for_post($post_id, $content, $options);
            
            if ($result['success']) {
                $this->logger->log_success('ASYNC_IMAGE_SUCCESS', '异步图片处理完成', array_merge($context, [
                    'generated_count' => $result['generated_count'],
                    'failed_count' => $result['failed_count'] ?? 0
                ]));
                
                // 更新文章记录，标记已处理图片
                $this->mark_post_images_processed($post_id, $result['generated_count']);
                
            } else {
                $this->logger->log_error('ASYNC_IMAGE_FAILED', '异步图片处理失败', array_merge($context, [
                    'error' => $result['error']
                ]));
            }
            
        } catch (Exception $e) {
            $this->logger->log_error('ASYNC_IMAGE_EXCEPTION', '异步图片处理异常', array_merge($context, [
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine()
            ]));
        }
    }
    
    /**
     * 标记文章的图片已处理
     * 
     * @param int $post_id 文章ID
     * @param int $generated_count 生成的图片数量
     */
    private function mark_post_images_processed($post_id, $generated_count) {
        // 添加自定义字段标记
        update_post_meta($post_id, '_auto_images_processed', true);
        update_post_meta($post_id, '_auto_images_count', $generated_count);
        update_post_meta($post_id, '_auto_images_processed_time', current_time('mysql'));
        
        // 更新文章数据库记录（如果存在）
        try {
            $database = new Yali_AI_Writer_Database();
            
            // 查找对应的文章记录
            global $wpdb;
            $article_table = $wpdb->prefix . 'yali_ai_writer_articles';
            
            $article = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$article_table} WHERE post_id = %d",
                $post_id
            ));
            
            if ($article) {
                $wpdb->update(
                    $article_table,
                    [
                        'auto_images_processed' => 1,
                        'auto_images_count' => $generated_count,
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $article->id]
                );
            }
            
        } catch (Exception $e) {
            $this->logger->log_warning('UPDATE_ARTICLE_RECORD_FAILED', '更新文章记录失败', [
                'post_id' => $post_id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 检查文章是否已处理过图片
     * 
     * @param int $post_id 文章ID
     * @return bool
     */
    public static function is_post_images_processed($post_id) {
        return get_post_meta($post_id, '_auto_images_processed', true) === '1';
    }
    
    /**
     * 检查文章是否已处理过图片（简化版本，仅供内部使用）
     * 
     * @param int $post_id 文章ID
     * @return bool
     */
    private function is_post_already_processed($post_id) {
        return get_post_meta($post_id, '_auto_images_processed', true) === '1';
    }
}

// 初始化异步图片处理器
new Yali_AI_Writer_AsyncImageProcessor();