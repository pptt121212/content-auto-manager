<?php
/**
 * 拼音转换服务类
 * 将中文标题转换为拼音用于URL生成
 * 基于 so-pinyin-slugs 插件的核心功能
 */

if (!defined('ABSPATH')) {
    exit;
}

class ContentAuto_PinyinConverter {
    
    private static $optimized_dictionary = null;
    private $max_length;
    
    public function __construct() {
        $this->load_dictionary();
        $this->load_settings();
    }
    
    /**
     * 加载并优化拼音词典
     * The optimized dictionary is cached in a static variable to improve performance across multiple calls.
     */
    private function load_dictionary() {
        if (self::$optimized_dictionary !== null) {
            return;
        }

        $dictionary_file = CONTENT_AUTO_MANAGER_PLUGIN_DIR . 'pinyin-converter/dictionary.php';
        if (file_exists($dictionary_file)) {
            $dictionary = require $dictionary_file;
        } else {
            $dictionary = $this->get_default_dictionary();
        }

        $optimized_map = [];
        if (!empty($dictionary)) {
            foreach ($dictionary as $pinyin => $characters) {
                $char_array = preg_split('//u', $characters, -1, PREG_SPLIT_NO_EMPTY);
                $lower_pinyin = strtolower($pinyin);
                foreach ($char_array as $char) {
                    if (!isset($optimized_map[$char])) {
                        $optimized_map[$char] = $lower_pinyin;
                    }
                }
            }
        }
        self::$optimized_dictionary = $optimized_map;
    }
    
    /**
     * 加载设置
     */
    private function load_settings() {
        // 默认设置 - URL长度截取20个字符
        $this->max_length = 20;
        
        // 如果有数据库设置，可以在这里加载
        // $options = get_option('content_auto_pinyin_options');
        // if (isset($options['slug_length'])) {
        //     $this->max_length = intval($options['slug_length']);
        // }
    }
    
    /**
     * 将中文标题转换为拼音
     * 
     * @param string $title 原始标题
     * @return string 拼音转换后的字符串
     */
    public function convert_to_pinyin($title) {
        $title = sanitize_text_field($title);
        
        if (empty(self::$optimized_dictionary)) {
            return $this->fallback_conversion($title);
        }
        
        $original_title = $title;
        $result = '';
        
        $charset = get_bloginfo('charset');
        if ($charset && strcasecmp($charset, 'UTF-8') !== 0) {
            $title = iconv($charset, 'UTF-8', $title);
        }
        
        $characters = preg_split('//u', $title, -1, PREG_SPLIT_NO_EMPTY);
        $contains_chinese = false;

        foreach ($characters as $char) {
            if (isset(self::$optimized_dictionary[$char])) {
                $contains_chinese = true;
                $result .= self::$optimized_dictionary[$char];
            } else {
                if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $char)) {
                    $contains_chinese = true;
                    $result .= $this->safe_character_fallback($char);
                } else {
                    $result .= preg_replace('/[^A-Za-z0-9\-]/', '', $char);
                }
            }
        }
        
        if (!$contains_chinese) {
            return $this->fallback_conversion($original_title);
        }
        
        $result = str_replace(' ', '-', $result);
        $result = preg_replace('/[^A-Za-z0-9-_]/', '', $result);
        $result = preg_replace('/-+/', '-', $result);
        $result = trim($result, '-');
        
        if (empty($result)) {
            return $this->generate_fallback_slug($original_title);
        }
        
        if ($this->max_length > 0 && strlen($result) > $this->max_length) {
            $result = substr($result, 0, $this->max_length);
            $result = rtrim($result, '-');
        }
        
        return strtolower($result);
    }
    
    /**
     * 备用转换方法（当词典不可用时）
     */
    private function fallback_conversion($title) {
        // 移除HTML标签
        $title = strip_tags($title);
        
        // 转换为小写
        $title = strtolower($title);
        
        // 替换空格和特殊字符为连字符
        $title = preg_replace('/[^a-z0-9]/i', '-', $title);
        
        // 移除重复的连字符
        $title = preg_replace('/-+/', '-', $title);
        
        // 移除开头和结尾的连字符
        $title = trim($title, '-');
        
        return $title;
    }
    
    /**
     * 安全字符备用处理
     */
    private function safe_character_fallback($char) {
        // 尝试将字符转换为拼音（简化处理）
        $char = trim($char);
        
        // 一些常见中文字符的手动映射
        $common_chars = array(
            '的' => 'de', '一' => 'yi', '是' => 'shi', '不' => 'bu', '了' => 'le',
            '在' => 'zai', '人' => 'ren', '有' => 'you', '我' => 'wo', '他' => 'ta',
            '这' => 'zhe', '个' => 'ge', '们' => 'men', '来' => 'lai', '到' => 'dao',
            '上' => 'shang', '大' => 'da', '为' => 'wei', '和' => 'he', '国' => 'guo',
            '地' => 'di', '以' => 'yi', '时' => 'shi', '要' => 'yao', '就' => 'jiu',
            '出' => 'chu', '会' => 'hui', '可' => 'ke', '也' => 'ye', '你' => 'ni',
            '对' => 'dui', '生' => 'sheng', '能' => 'neng', '而' => 'er', '子' => 'zi'
        );
        
        if (isset($common_chars[$char])) {
            return $common_chars[$char];
        }
        
        // 如果无法转换，返回空字符串（避免破坏URL结构）
        return '';
    }
    
    /**
     * 生成备用别名
     */
    private function generate_fallback_slug($title) {
        // 基于时间戳和标题哈希生成备用别名
        $hash = substr(md5($title . time()), 0, 8);
        return 'post-' . $hash;
    }
    
    /**
     * 获取默认词典（简化版本）
     */
    private function get_default_dictionary() {
        return array(
            'A' => '啊阿',
            'Ai' => '爱埃挨哎哀皑癌蔼矮艾碍',
            'An' => '安按暗岸案鞍氨俺',
            'Ang' => '昂盎',
            'Ao' => '奥澳傲熬凹敖袄奥',
            'Ba' => '八巴吧拔把爸罢霸坝芭扒',
            'Bai' => '白百摆败拜柏佰',
            'Ban' => '办半班般板版搬伴扮颁瓣斑',
            'Bang' => '帮邦棒榜膀傍绑磅蚌',
            'Bao' => '报保包宝暴薄爆胞饱堡剥豹刨雹苞',
            'Bei' => '北被备背贝倍杯辈悲碑卑蓓狈',
            'Ben' => '本奔苯笨',
            'Beng' => '崩绷甭泵蹦',
            'Bi' => '比必币笔闭彼毕鼻避逼鼻笔彼币毕闭毙碧蔽弊壁臂庇',
            'Bian' => '变边便编遍辨贬鞭辫扁卞汴',
            'Biao' => '表标彪膘镖裱飙',
            'Bie' => '别憋鳖瘪',
            'Bin' => '宾滨斌彬濒缤殡鬓',
            'Bing' => '并病兵冰饼炳禀柄摒',
            'Bo' => '波博播伯勃驳薄拨剥搏柏泊舶脖膊菠钵',
            'Bu' => '不部步布补捕卜簿哺埠怖'
        );
    }
    
    /**
     * 检查字符串是否包含中文字符
     */
    public function contains_chinese($string) {
        return preg_match('/[\x{4e00}-\x{9fa5}]/u', $string);
    }
    
    /**
     * 设置最大长度
     */
    public function set_max_length($length) {
        $this->max_length = intval($length);
    }
}