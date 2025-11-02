<?php

class BaiduSuggestion {
    private $suggestionUrls = [
        'primary' => 'https://suggestion.baidu.com/su',
        'backup'  => 'https://sp0.baidu.com/5a1Fazu8AA54nxGko9WTAnF6hhy/su'
    ];
    private $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
    private $timeout = 10;

    /**
     * 获取百度搜索联想词
     * @param string $keyword 搜索关键词
     * @return array 联想词数组
     */
    public function getSuggestions($keyword) {
        if (empty($keyword)) {
            return [];
        }

        // 尝试主接口
        try {
            $result = $this->trySuggestionUrl($this->suggestionUrls['primary'], $keyword);
            if (!empty($result)) {
                error_log("使用主接口获取到 " . count($result) . " 个联想词");
                return $result;
            }
        } catch (Exception $e) {
            error_log("主接口获取联想词失败: " . $e->getMessage());
        }

        // 尝试备用接口
        try {
            $result = $this->trySuggestionUrl($this->suggestionUrls['backup'], $keyword);
            if (!empty($result)) {
                error_log("使用备用接口获取到 " . count($result) . " 个联想词");
                return $result;
            }
        } catch (Exception $e) {
            error_log("备用接口获取联想词失败: " . $e->getMessage());
        }

        error_log("所有百度接口都无法获取联想词");
        return [];
    }

    /**
     * 尝试从指定的URL获取联想词
     * @param string $url 接口URL
     * @param string $keyword 搜索关键词
     * @return array 联想词数组
     * @throws Exception
     */
    private function trySuggestionUrl($url, $keyword) {
        $params = [
            'wd' => $keyword,
            'p' => 3,
            'cb' => 'jsonp_callback'
        ];

        $requestUrl = $url . '?' . http_build_query($params);
        $response = $this->makeRequest($requestUrl);
        return $this->parseJsonpResponse($response);
    }

    /**
     * 发送HTTP请求
     * @param string $url 请求URL
     * @return string 响应内容
     * @throws Exception
     */
    private function makeRequest($url) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: {$this->userAgent}\r\n" .
                           "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n" .
                           "Accept-Language: zh-CN,zh;q=0.8,en-US;q=0.5,en;q=0.3\r\n" .
                           "Accept-Encoding: identity\r\n" .  // 不使用压缩，避免编码问题
                           "Connection: keep-alive\r\n",
                'timeout' => $this->timeout
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception("HTTP请求失败");
        }

        // 检查并转换编码
        if (function_exists('mb_check_encoding') && mb_check_encoding($response, 'GBK')) {
            $response = mb_convert_encoding($response, 'UTF-8', 'GBK');
        }

        return $response;
    }

    /**
     * 解析JSONP响应
     * @param string $jsonpText JSONP响应文本
     * @return array 解析后的数组
     */
    private function parseJsonpResponse($jsonpText) {
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

  
    /**
     * 使用CURL发送请求（备选方案）
     * @param string $url 请求URL
     * @return string 响应内容
     * @throws Exception
     */
    private function makeRequestWithCurl($url) {
        if (!function_exists('curl_init')) {
            throw new Exception("CURL扩展未安装");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_ENCODING => '',  // 不使用压缩
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.8,en-US;q=0.5,en;q=0.3',
                'Connection: keep-alive'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new Exception("CURL请求失败，HTTP状态码: " . $httpCode);
        }

        // 检查并转换编码
        if (function_exists('mb_check_encoding') && mb_check_encoding($response, 'GBK')) {
            $response = mb_convert_encoding($response, 'UTF-8', 'GBK');
        }

        return $response;
    }

    /**
     * 设置超时时间
     * @param int $timeout 超时时间（秒）
     */
    public function setTimeout($timeout) {
        $this->timeout = $timeout;
    }

    /**
     * 设置User-Agent
     * @param string $userAgent User-Agent字符串
     */
    public function setUserAgent($userAgent) {
        $this->userAgent = $userAgent;
    }

    }

// 使用示例
if (__FILE__ == realpath($_SERVER['SCRIPT_NAME'])) {
    header('Content-Type: text/html; charset=utf-8');

    $baidu = new BaiduSuggestion();
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : 'VPS';

    echo "<h2>关键词: " . htmlspecialchars($keyword) . "</h2>";

    // 获取联想词
    echo "<h3>联想词:</h3>";
    $suggestions = $baidu->getSuggestions($keyword);
    if (!empty($suggestions)) {
        echo "<ul>";
        foreach ($suggestions as $suggestion) {
            echo "<li>" . htmlspecialchars($suggestion) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>未找到联想词</p>";
    }

}

?>
