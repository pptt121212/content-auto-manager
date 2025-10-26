<?php
/**
 * 自动图片生成器
 * 集成到文章生成流程中，自动处理图片占位符
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'image-api-settings/class-image-api-handler.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/database/class-database.php';
require_once CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'shared/logging/class-logging-system.php';

class ContentAuto_AutoImageGenerator {
    
    private $database;
    private $logger;
    private $image_api_handler;
    
    public function __construct() {
        $this->database = new ContentAuto_Database();
        $this->logger = new ContentAuto_LoggingSystem();
        $this->image_api_handler = new CAM_Image_API_Handler();
    }
    
    /**
     * 为文章内容自动生成图片
     * 在文章生成完成后调用此方法
     * 
     * @param int $post_id WordPress文章ID
     * @param string $content 文章内容
     * @param array $options 处理选项，可包含 'publish_rules' 键
     * @return array 处理结果
     */
    public function auto_generate_images_for_post($post_id, $content, $options = []) {
        $context = [
            'post_id' => $post_id,
            'function' => 'auto_generate_images_for_post'
        ];
        
        $this->logger->log_info('AUTO_IMAGE_START', '开始为文章自动生成图片', $context);
        
        try {
            // 检查图像API是否配置
            if (!$this->is_image_api_configured()) {
                $this->logger->log_warning('IMAGE_API_NOT_CONFIGURED', '图像API未配置，跳过图片生成', $context);
                return [
                    'success' => true,
                    'generated_count' => 0,
                    'message' => '图像API未配置'
                ];
            }
            
            // 提取图片占位符
            $placeholders = $this->extract_image_placeholders($content);
            
            if (empty($placeholders)) {
                $this->logger->log_info('NO_PLACEHOLDERS', '文章中未找到图片占位符', $context);
                return [
                    'success' => true,
                    'generated_count' => 0,
                    'message' => '未找到图片占位符'
                ];
            }
            
            // 获取发布规则中的最大图片数量限制和是否跳过首个占位符
            // 优先使用传入的发布规则，否则从数据库读取
            $publish_rules = isset($options['publish_rules']) ? $options['publish_rules'] : null;
            $max_images = $this->get_max_auto_images_from_publish_rules($publish_rules);
            $skip_first = $this->should_skip_first_image_placeholder($publish_rules);
            $total_found = count($placeholders);
            $original_placeholders = $placeholders;
            
            // 【调试】详细记录配置读取结果
            $this->logger->log_info('CONFIG_DEBUG', '发布规则配置读取结果', array_merge($context, [
                'max_images' => $max_images,
                'skip_first' => $skip_first ? 'true' : 'false',
                'total_found' => $total_found,
                'first_placeholder_prompt' => $total_found > 0 ? $placeholders[0]['prompt'] : 'none',
                'first_placeholder_offset' => $total_found > 0 ? $placeholders[0]['offset'] : 'none'
            ]));
            
            // 【修复】简化逻辑：如果启用了跳过首个占位符，直接移除第一个
            if ($skip_first && $total_found > 0) {
                $skipped_placeholder = $placeholders[0];
                $placeholders = array_slice($placeholders, 1);
                $this->logger->log_info('FIRST_PLACEHOLDER_SKIPPED', '跳过首个图片占位符', array_merge($context, [
                    'total_found' => $total_found,
                    'skipped_prompt' => $skipped_placeholder['prompt'],
                    'skipped_offset' => $skipped_placeholder['offset'],
                    'remaining_count' => count($placeholders)
                ]));
            }
            
            // 限制处理的占位符数量
            if (count($placeholders) > $max_images) {
                $placeholders = array_slice($placeholders, 0, $max_images);
                $this->logger->log_info('PLACEHOLDERS_LIMITED', '图片占位符数量受限', array_merge($context, [
                    'total_found' => $total_found,
                    'after_skip_first' => count($original_placeholders) - ($skip_first ? 1 : 0),
                    'max_allowed' => $max_images,
                    'will_process' => count($placeholders),
                    'skip_first_enabled' => $skip_first
                ]));
            }
            
            $this->logger->log_info('PLACEHOLDERS_FOUND', '找到图片占位符', array_merge($context, [
                'count' => count($placeholders),
                'total_found' => $total_found,
                'max_allowed' => $max_images,
                'skip_first_enabled' => $skip_first,
                'first_skipped' => $skip_first && $total_found > 0
            ]));
            
            // 处理每个占位符，支持重试机制
            $updated_content = $content;
            $generated_count = 0;
            $failed_count = 0;
            $max_retries = 2; // 最大重试次数
            
            foreach ($placeholders as $placeholder) {
                // 仅在调试模式下记录处理占位符的详细信息
                if (defined('CONTENT_AUTO_DEBUG_MODE') && CONTENT_AUTO_DEBUG_MODE) {
                    $this->logger->log_info('PROCESSING_PLACEHOLDER', '处理图片占位符', array_merge($context, ['prompt' => $placeholder['prompt']]));
                }
                
                $success = false;
                $last_error = '';
                
                // 重试机制
                for ($retry = 0; $retry <= $max_retries && !$success; $retry++) {
                    if ($retry > 0) {
                        $this->logger->log_info('RETRYING_PLACEHOLDER', '重试图片生成', array_merge($context, [
                            'prompt' => $placeholder['prompt'],
                            'retry_count' => $retry
                        ]));
                        
                        // 重试前稍等
                        sleep(3);
                    }
                    
                    $result = $this->generate_and_replace_placeholder($placeholder, $updated_content, $post_id);
                    
                    if ($result['success']) {
                        $updated_content = $result['updated_content'];
                        $generated_count++;
                        $success = true;
                        
                        // 【关键修复】每生成一张图片立即更新文章，防止API超时导致全部丢失
                        $this->update_post_content_incrementally($post_id, $updated_content);
                        
                        $this->logger->log_success('IMAGE_GENERATED', '图片生成成功', array_merge($context, [
                            'prompt' => $placeholder['prompt'],
                            'attachment_id' => $result['attachment_id'],
                            'retry_count' => $retry
                        ]));
                    } else {
                        $last_error = $result['error'];
                    }
                }
                
                // 如果所有重试都失败
                if (!$success) {
                    $failed_count++;
                    $this->logger->log_error('IMAGE_GENERATION_FAILED_FINAL', '图片生成最终失败', array_merge($context, [
                        'prompt' => $placeholder['prompt'],
                        'error' => $last_error,
                        'total_retries' => $max_retries + 1
                    ]));
                }
                
                // 添加处理间隔，避免API频率限制
                if ($generated_count > 0) {
                    sleep(2);
                }
            }
            
            // 清理未处理的图片占位符
            $final_content = $this->cleanup_remaining_placeholders($updated_content);
            
            // 如果有占位符被清理，需要最终更新一次文章内容
            if ($final_content !== $updated_content) {
                $this->update_post_content_incrementally($post_id, $final_content);
                $this->logger->log_info('PLACEHOLDERS_CLEANED', '清理未处理的图片占位符', array_merge($context, [
                    'generated_count' => $generated_count,
                    'failed_count' => $failed_count
                ]));
            }
            
            // 最终检查和确认更新（由于使用了增量更新，这里主要是确认和日志记录）
            if ($generated_count > 0) {
                $this->logger->log_success('POST_UPDATED', '文章内容已更新', array_merge($context, [
                    'generated_count' => $generated_count,
                    'failed_count' => $failed_count,
                    'update_mode' => 'incremental'
                ]));
            }
            
            return [
                'success' => true,
                'generated_count' => $generated_count,
                'failed_count' => $failed_count,
                'updated_content' => $updated_content
            ];
            
        } catch (Exception $e) {
            $error_message = '自动图片生成异常: ' . $e->getMessage();
            $this->logger->log_error('AUTO_IMAGE_EXCEPTION', $error_message, array_merge($context, [
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine()
            ]));
            
            return [
                'success' => false,
                'error' => $error_message,
                'generated_count' => 0,
                'failed_count' => 0
            ];
        }
    }
    
    /**
     * 增量更新文章内容（每生成一张图片立即更新）
     * 防止API超时导致所有图片替换丢失
     * 
     * @param int $post_id 文章ID
     * @param string $updated_content 更新后的内容
     * @return bool 更新是否成功
     */
    private function update_post_content_incrementally($post_id, $updated_content) {
        try {
            // 获取文章当前状态，确保更新时不会意外改变状态
            $current_post = get_post($post_id);
            if (!$current_post) {
                $this->logger->log_error('INCREMENTAL_UPDATE_FAILED', '无法获取文章信息', [
                    'post_id' => $post_id,
                    'function' => 'update_post_content_incrementally'
                ]);
                return false;
            }
            
            $update_result = wp_update_post([
                'ID' => $post_id,
                'post_content' => $updated_content,
                'post_status' => $current_post->post_status // 保持当前状态
            ]);
            
            if (is_wp_error($update_result)) {
                $this->logger->log_error('INCREMENTAL_UPDATE_ERROR', '增量更新文章内容失败', [
                    'post_id' => $post_id,
                    'error' => $update_result->get_error_message(),
                    'function' => 'update_post_content_incrementally'
                ]);
                return false;
            }
            
            $this->logger->log_info('INCREMENTAL_UPDATE_SUCCESS', '增量更新文章内容成功', [
                'post_id' => $post_id,
                'function' => 'update_post_content_incrementally'
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->log_error('INCREMENTAL_UPDATE_EXCEPTION', '增量更新文章内容异常', [
                'post_id' => $post_id,
                'error' => $e->getMessage(),
                'function' => 'update_post_content_incrementally'
            ]);
            return false;
        }
    }
    
    /**
     * 获取发布规则中的最大自动图片数量
     * 
     * @param array|null $publish_rules 发布规则数组，如果为null则从数据库读取
     * @return int 最大图片数量，默认为1
     */
    private function get_max_auto_images_from_publish_rules($publish_rules = null) {
        try {
            // 如果没有传递发布规则，从数据库表中获取
            if ($publish_rules === null) {
                $publish_rules = $this->database->get_row('content_auto_publish_rules', array('id' => 1));
            }
            
            // 如果设置了最大图片数量，使用该值，否则默认为1
            $max_images = (!empty($publish_rules) && isset($publish_rules['max_auto_images'])) ? intval($publish_rules['max_auto_images']) : 1;
            
            // 确保值在合理范围内（1-5张）
            if ($max_images < 1) {
                $max_images = 1;
            } elseif ($max_images > 5) {
                $max_images = 5;
            }
            
            return $max_images;
            
        } catch (Exception $e) {
            // 如果获取失败，返回默认值1
            error_log('ContentAuto: 获取最大图片数量设置失败 - ' . $e->getMessage());
            return 1;
        }
    }

    /**
     * 检查是否应该跳过首个图片占位符
     * 
     * @param array|null $publish_rules 发布规则数组，如果为null则从数据库读取
     * @return bool 是否跳过首个占位符
     */
    private function should_skip_first_image_placeholder($publish_rules = null) {
        try {
            // 如果没有传递发布规则，从数据库表中获取
            if ($publish_rules === null) {
                $publish_rules = $this->database->get_row('content_auto_publish_rules', array('id' => 1));
            }
            
            // 检查是否启用跳过首个占位符选项
            return (!empty($publish_rules) && !empty($publish_rules['skip_first_image_placeholder']));
            
        } catch (Exception $e) {
            // 如果获取失败，返回默认值false（不跳过）
            error_log('ContentAuto: 获取跳过首图设置失败 - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 清理文章中剩余的未处理图片占位符
     * 
     * @param string $content 文章内容
     * @return string 清理后的内容
     */
    private function cleanup_remaining_placeholders($content) {
        try {
            // 匹配所有剩余的图片占位符
            $pattern = '/<!--\s*image\s+prompt:\s*.*?-->/is';
            
            // 记录清理前的占位符数量
            $before_count = preg_match_all($pattern, $content);
            
            // 移除所有剩余的占位符
            $cleaned_content = preg_replace($pattern, '', $content);
            
            // 清理可能产生的多余空行和空白
            $cleaned_content = preg_replace('/\n\s*\n\s*\n/', "\n\n", $cleaned_content);
            $cleaned_content = trim($cleaned_content);
            
            // 记录清理结果
            if ($before_count > 0) {
                $this->logger->log_info('PLACEHOLDERS_REMOVED', '移除未处理的图片占位符', [
                    'removed_count' => $before_count,
                    'function' => 'cleanup_remaining_placeholders'
                ]);
            }
            
            return $cleaned_content;
            
        } catch (Exception $e) {
            $this->logger->log_error('PLACEHOLDER_CLEANUP_ERROR', '清理占位符时发生错误', [
                'error' => $e->getMessage(),
                'function' => 'cleanup_remaining_placeholders'
            ]);
            
            // 如果清理失败，返回原内容
            return $content;
        }
    }


    /**
     * 检查图像API是否配置
     */
    private function is_image_api_configured() {
        if (!class_exists('CAM_Image_API_Admin_Page')) {
            return false;
        }
        
        $image_api_settings = CAM_Image_API_Admin_Page::get_settings();
        return !empty($image_api_settings['provider']);
    }
    
    /**
     * 从文章内容中提取图片占位符
     */
    private function extract_image_placeholders($content) {
        $placeholders = [];
        
        // 修复：匹配图片占位符的正则表达式，支持提示词中包含短横线
        $pattern = '/<!--\s*image\s+prompt:\s*(.*?)-->/is';
        
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $placeholders[] = [
                    'full_match' => $match[0][0], // 完整匹配的文本
                    'prompt' => trim($match[1][0]), // 提取的提示词
                    'offset' => $match[0][1] // 在内容中的位置
                ];
            }
        }
        
        return $placeholders;
    }
    
    /**
     * 生成图片并替换占位符
     */
    private function generate_and_replace_placeholder($placeholder, &$content, $post_id) {
        try {
            // 1. 使用图像API生成图片
            $base64_image = $this->image_api_handler->generate_image_from_saved_settings($placeholder['prompt']);
            
            if (is_wp_error($base64_image)) {
                return [
                    'success' => false,
                    'error' => $base64_image->get_error_message()
                ];
            }
            
            // 2. 将Base64图片保存为WordPress附件
            $attachment_result = $this->save_base64_as_attachment($base64_image, $placeholder['prompt'], $post_id);
            
            if (!$attachment_result['success']) {
                return [
                    'success' => false,
                    'error' => $attachment_result['error']
                ];
            }
            
            // 3. 生成图片HTML标签
            $image_html = $this->generate_image_html($attachment_result['attachment_id'], $placeholder['prompt']);
            
            // 4. 替换占位符
            $content = str_replace($placeholder['full_match'], $image_html, $content);
            
            return [
                'success' => true,
                'attachment_id' => $attachment_result['attachment_id'],
                'updated_content' => $content
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => '图片生成异常: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 将Base64图片保存为WordPress附件
     */
    private function save_base64_as_attachment($base64_data, $prompt, $post_id = 0) {
        try {
            // 解码Base64数据
            $image_data = base64_decode($base64_data);
            if ($image_data === false) {
                return [
                    'success' => false,
                    'error' => 'Base64解码失败'
                ];
            }
            
            // 检测图片类型
            $image_info = getimagesizefromstring($image_data);
            if ($image_info === false) {
                return [
                    'success' => false,
                    'error' => '无效的图片数据'
                ];
            }
            
            // 获取文件扩展名
            $mime_type = $image_info['mime'];
            $extension = '';
            switch ($mime_type) {
                case 'image/jpeg':
                    $extension = 'jpg';
                    break;
                case 'image/png':
                    $extension = 'png';
                    break;
                case 'image/webp':
                    $extension = 'webp';
                    break;
                default:
                    $extension = 'jpg'; // 默认使用jpg
            }
            
            // 生成文件名
            $filename = 'auto-generated-' . date('Y-m-d-H-i-s') . '-' . uniqid() . '.' . $extension;
            
            // 获取上传目录
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['path'] . '/' . $filename;
            $file_url = $upload_dir['url'] . '/' . $filename;
            
            // 保存文件
            if (file_put_contents($file_path, $image_data) === false) {
                return [
                    'success' => false,
                    'error' => '文件保存失败'
                ];
            }
            
            // 创建附件记录
            $attachment_data = [
                'guid' => $file_url,
                'post_mime_type' => $mime_type,
                'post_title' => '自动生成图片: ' . $prompt,
                'post_content' => '',
                'post_status' => 'inherit',
                'post_parent' => $post_id // 关联到文章
            ];
            
            $attachment_id = wp_insert_attachment($attachment_data, $file_path, $post_id);
            
            if (is_wp_error($attachment_id)) {
                // 删除已保存的文件
                unlink($file_path);
                return [
                    'success' => false,
                    'error' => '创建附件记录失败: ' . $attachment_id->get_error_message()
                ];
            }
            
            // 生成附件元数据
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attachment_id, $file_path);
            wp_update_attachment_metadata($attachment_id, $attach_data);
            
            // 保存图片生成信息到自定义字段
            update_post_meta($attachment_id, '_ai_generated', true);
            update_post_meta($attachment_id, '_ai_prompt', $prompt);
            update_post_meta($attachment_id, '_generation_date', current_time('mysql'));
            update_post_meta($attachment_id, '_source_post_id', $post_id);
            
            return [
                'success' => true,
                'attachment_id' => $attachment_id,
                'file_path' => $file_path,
                'file_url' => $file_url
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => '保存附件异常: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 生成图片HTML标签
     */
    private function generate_image_html($attachment_id, $alt_text) {
        $image_url = wp_get_attachment_url($attachment_id);
        $image_meta = wp_get_attachment_metadata($attachment_id);
        
        // 生成响应式图片HTML
        $width = isset($image_meta['width']) ? $image_meta['width'] : '';
        $height = isset($image_meta['height']) ? $image_meta['height'] : '';
        
        // 使用WordPress标准的图片格式
        $html = sprintf(
            '<figure class="wp-block-image size-large"><img src="%s" alt="%s" class="wp-image-%d"%s%s /></figure>',
            esc_url($image_url),
            esc_attr($alt_text),
            $attachment_id,
            $width ? ' width="' . $width . '"' : '',
            $height ? ' height="' . $height . '"' : ''
        );
        
        return $html;
    }
    
    /**
     * 生成单个图片并返回HTML（用于预处理）
     * 
     * @param string $prompt 图片提示词
     * @param int $post_id 关联的文章ID（可选）
     * @return string|false 图片HTML或失败时返回false
     */
    public function generate_single_image($prompt, $post_id = 0) {
        try {
            // 检查图像API是否配置
            if (!$this->is_image_api_configured()) {
                error_log('ContentAuto: 图像API未配置，无法生成图片');
                return false;
            }
            
            // 1. 使用图像API生成图片
            $base64_image = $this->image_api_handler->generate_image_from_saved_settings($prompt);
            
            if (is_wp_error($base64_image)) {
                error_log('ContentAuto: 图片生成失败 - ' . $base64_image->get_error_message());
                return false;
            }
            
            // 2. 将Base64图片保存为WordPress附件
            $attachment_result = $this->save_base64_as_attachment($base64_image, $prompt, $post_id);
            
            if (!$attachment_result['success']) {
                error_log('ContentAuto: 保存图片附件失败 - ' . $attachment_result['error']);
                return false;
            }
            
            // 3. 生成图片HTML标签
            $image_html = $this->generate_image_html($attachment_result['attachment_id'], $prompt);
            
            $this->logger->log_success('SINGLE_IMAGE_GENERATED', '单个图片生成成功', [
                'prompt' => $prompt,
                'attachment_id' => $attachment_result['attachment_id'],
                'post_id' => $post_id
            ]);
            
            return $image_html;
            
        } catch (Exception $e) {
            error_log('ContentAuto: 生成单个图片异常 - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 异步处理图片生成（推荐用于生产环境）
     * 避免阻塞文章生成流程
     */
    public function schedule_image_generation($post_id, $content, $publish_rules = null) {
        // 如果传递了发布规则，将其序列化后一起传递给异步任务
        $task_data = [$post_id, $content];
        if ($publish_rules) {
            $task_data[] = $publish_rules;
        }
        
        // 调度一个异步任务
        wp_schedule_single_event(time() + 30, 'content_auto_process_post_images', $task_data);
        
        $this->logger->log_info('IMAGE_GENERATION_SCHEDULED', '图片生成任务已调度', [
            'post_id' => $post_id,
            'scheduled_time' => date('Y-m-d H:i:s', time() + 30),
            'has_publish_rules' => !empty($publish_rules)
        ]);
    }
}