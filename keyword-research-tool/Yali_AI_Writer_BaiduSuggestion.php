<?php
if (!defined('ABSPATH')) {
    exit;
}

class Yali_AI_Writer_BaiduSuggestion {
    private $suggestionUrls = [
        'primary' => 'https://suggestion.baidu.com/su',
        'backup'  => 'https://sp0.baidu.com/5a1Fazu8AA54nxGko9WTAnF6hhy/su'
    ];
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    private $timeout = 10;

    /**
     * Parsing logic remains for use by the parent class
     */

    /**
     * 解析JSONP响应
     * @param string $jsonpText JSONP响应文本
     * @return array 解析后的数组
     */
    public function parseJsonpResponse($jsonpText) {
        // 查找JSON数据部分
        if (preg_match('/\((.*)\)/', $jsonpText, $matches)) {
            $jsonStr = $matches[1];

            // 百度返回的是非标准JSON，需要手动解析
            // 格式如: {q:"关键词",p:false,s:["词1","词2"]}

            // 先提取s数组的内容
            if (preg_match('/s:\[(.*?)(\s*)\]/', $jsonStr, $matches)) {
                $sContent = $matches[1];

                // 分割数组项
                $keywords = [];
                $items = explode(',', $sContent);

                foreach ($items as $item) {
                    // 移除引号并清理
                    $keyword = trim($item, '"');
                    $keyword = stripslashes($keyword);
                    if (!empty($keyword)) {
                        $keywords[] = $keyword;
                    }
                }

                return $keywords;
            }

            // 如果正则解析失败，尝试标准JSON解析（可能百度修复了格式）
            $jsonStr = str_replace('\\"', '"', $jsonStr);
            $data = json_decode($jsonStr, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['s'])) {
                return $data['s'];
            }
        }

        return [];
    }

  

    }


?>
