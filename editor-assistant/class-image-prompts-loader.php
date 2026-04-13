<?php
/**
 * 图像生成提示词加载器
 * 从JSON配置文件加载图像生成提示词
 */

if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_Image_Prompts_Loader {
    
    /**
     * 获取配置文件路径
     * @return string 配置文件完整路径
     */
    private static function get_config_file_path() {
        return YALI_AI_WRITER_PLUGIN_DIR . 'editor-assistant/config/image-prompts.json';
    }
    
    /**
     * 从JSON配置文件加载图像生成提示词
     * @return array 图像生成提示词数组
     */
    public static function load_image_prompts() {

        // 优先从数据库读取

        $saved_config = get_option('yali_image_prompts_config', '');

        

        if (!empty($saved_config)) {

            $config = json_decode($saved_config, true);

            if (json_last_error() === JSON_ERROR_NONE && !empty($config)) {

                return self::translate_prompts($config);

            }

        }

        

        // 如果数据库没有，从JSON文件读取

        $config_file = self::get_config_file_path();

        

        if (!file_exists($config_file)) {

            return array();

        }

        

        $json_content = file_get_contents($config_file);

        $config = json_decode($json_content, true);

        

        if (json_last_error() !== JSON_ERROR_NONE) {

            error_log('Yali AI Writer: 图像提示词JSON解析错误 - ' . json_last_error_msg());

            return array();

        }

        

        return self::translate_prompts($config);

    }

    

        /**

    

         * 翻译提示词

    

         * @param array $config 可能是直接的提示词数组，也可能是包含 image_prompts 键的配置数组

    

         * @return array 翻译后的提示词数组

    

         */

    

        private static function translate_prompts($config) {

    

            // 如果是配置数组（包含 image_prompts 键），提取实际的提示词数组

    

            if (isset($config['image_prompts']) && is_array($config['image_prompts'])) {

    

                $prompts = $config['image_prompts'];

    

            } else {

    

                $prompts = $config;

    

            }

    

            

    

            // 确保是数组

    

            if (!is_array($prompts)) {

    

                return array();

    

            }

    

            

    

            foreach ($prompts as &$prompt) {

    

                if (isset($prompt['prompt_title'])) {

    

                    $prompt['prompt_title'] = __($prompt['prompt_title'], 'yali-ai-writer');

    

                }

    

                if (isset($prompt['prompt_desc'])) {

    

                    $prompt['prompt_desc'] = __($prompt['prompt_desc'], 'yali-ai-writer');

    

                }

    

            }

    

            unset($prompt);

    

            

    

            return $prompts;

    

        }
}
