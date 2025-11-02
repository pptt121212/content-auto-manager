<?php
/**
 * 免费关键词挖掘和趋势分析API集合
 * 无需授权的标准请求方式
 *
 * 重要说明：
 * - 这些API都是Google公开提供的接口，无需API密钥
 * - 建议请求频率：每秒最多1-2个请求
 * - 这些是非官方但广泛使用的标准接口
 */

// 引入百度API处理类
require_once plugin_dir_path(__FILE__) . 'BaiduSuggestion.php';

class FreeKeywordAPIs {
    
    /**
     * ==========================================
     * 1. Google搜索建议API (无需授权)
     * ==========================================
     * 
     * 官方标准格式：
     * https://suggestqueries.google.com/complete/search?client=chrome&q=关键词
     * 
     * 支持的client参数：
     * - chrome: 返回JSON格式 (推荐)
     * - firefox: 返回JSON格式
     * - toolbar: 返回XML格式
     * - youtube: 返回YouTube相关建议
     * 
     * 完整参数列表：
     * - client: 客户端类型 (必需)
     * - q: 搜索关键词 (必需)
     * - hl: 界面语言 (可选，如: zh-CN, en, ja)
     * - gl: 地理区域 (可选，如: cn, us, uk)
     * - ds: 数据源 (可选，如: '' 或 'yt' for YouTube)
     * - oq: 原始查询 (可选)
     * - gs_rfai: 相关参数 (可选)
     */
    
    /**
     * 获取Google搜索建议 (标准格式)
     * 
     * @param string $keyword 搜索关键词
     * @param string $client 客户端类型 (chrome|firefox|toolbar|youtube)
     * @param string $language 语言代码 (如: zh-CN, en, ja)
     * @param string $country 国家代码 (如: cn, us, uk)
     * @return array 关键词建议列表
     */
    public function getGoogleSuggestions($keyword, $client = 'chrome', $language = 'zh-CN', $country = 'cn') {
        // 标准端点格式
        $endpoint = 'https://suggestqueries.google.com/complete/search';
        
        // 标准请求参数
        $params = [
            'client' => $client,
            'q' => $keyword,
            'hl' => $language,
            'gl' => $country
        ];
        
        $url = $endpoint . '?' . http_build_query($params);
        
        // 标准请求头
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Referer' => 'https://www.google.com/',
            'Origin' => 'https://www.google.com'
        ];
        
        $request = $this->makeRequest($url, $headers);
        
        if ($request['http_code'] === 200 && $request['body']) {
            return $this->parseSuggestionsResponse($request['body'], $client);
        }
        
        return [];
    }
    
    /**
     * 获取YouTube搜索建议 (标准格式)
     * 
     * @param string $keyword 搜索关键词
     * @param string $language 语言代码
     * @param string $country 国家代码
     * @return array YouTube关键词建议列表
     */
    public function getYouTubeSuggestions($keyword, $language = 'zh-CN', $country = 'cn') {
        // YouTube建议端点格式
        $endpoint = 'https://suggestqueries.google.com/complete/search';
        
        $params = [
            'client' => 'youtube',
            'q' => $keyword,
            'hl' => $language,
            'gl' => $country,
            'ds' => 'yt'  // YouTube数据源
        ];
        
        $url = $endpoint . '?' . http_build_query($params);
        
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Referer' => 'https://www.youtube.com/',
            'Origin' => 'https://www.youtube.com'
        ];
        
        $request = $this->makeRequest($url, $headers);
        
        if ($request['http_code'] === 200 && $request['body']) {
            return $this->parseSuggestionsResponse($request['body'], 'youtube');
        }
        
        return [];
    }
    
    /**
     * 获取Google购物搜索建议
     * 
     * @param string $keyword 搜索关键词
     * @param string $language 语言代码
     * @param string $country 国家代码
     * @return array 购物关键词建议列表
     */
    public function getGoogleShoppingSuggestions($keyword, $language = 'zh-CN', $country = 'cn') {
        // Google购物建议端点格式
        $endpoint = 'https://suggestqueries.google.com/complete/search';
        
        $params = [
            'client' => 'chrome',
            'q' => $keyword,
            'hl' => $language,
            'gl' => $country,
            'ds' => 'sh'  // Shopping数据源
        ];
        
        $url = $endpoint . '?' . http_build_query($params);
        
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Referer' => 'https://www.google.com/shopping',
            'Origin' => 'https://www.google.com'
        ];
        
        $request = $this->makeRequest($url, $headers);
        
        if ($request['http_code'] === 200 && $request['body']) {
            return $this->parseSuggestionsResponse($request['body'], 'chrome');
        }
        
        return [];
    }
    
    /**
     * ==========================================
     * 2. Google Trends 趋势分析 (无需授权)
     * ==========================================
     * 
     * 非官方但标准的端点格式：
     * https://trends.google.com/trends/api/explore
     * https://trends.google.com/trends/api/widgetdata/multiline
     * 
     * 标准参数格式：
     * - hl: 界面语言
     * - tz: 时区偏移
     * - req: JSON格式的请求数据
     * - token: 从explore响应获取的令牌
     */
    
    /**
     * 获取关键词趋势数据
     * 
     * @param string $keyword 关键词
     * @param string $geo 地理区域 (如: CN, US, JP)
     * @param string $timeRange 时间范围 (如: 'today 12-m', 'today 5-y', 'now 7-d')
     * @param string $category 类别 (0表示所有类别)
     * @return array|null 趋势数据
     */
    public function getTrendsData($keyword, $geo = 'CN', $timeRange = 'today 12-m', $category = 0) {
        // 第一步：获取explore数据
        $exploreRequest = $this->getTrendsExploreData($keyword, $geo, $timeRange, $category);
        
        if ($exploreRequest['http_code'] !== 200 || !$exploreRequest['body']) {
            return null;
        }

        $exploreData = json_decode(substr($exploreRequest['body'], 4), true);
        if (!$exploreData || !isset($exploreData['widgets'][0]['token'])) {
            return null;
        }
        
        $widget = $exploreData['widgets'][0];
        $token = $widget['token'];
        $requestData = $widget['request'];
        
        // 第二步：获取具体的趋势数据
        $widgetRequest = $this->getTrendsWidgetData($requestData, $token);
        if ($widgetRequest['http_code'] !== 200 || !$widgetRequest['body']) {
            return null;
        }

        return json_decode(substr($widgetRequest['body'], 4), true);
    }
    
    /**
     * 获取Trends Explore数据
     * 
     * @param string $keyword 关键词
     * @param string $geo 地理区域
     * @param string $timeRange 时间范围
     * @param int $category 类别
     * @return array|null Explore数据
     */
    public function getTrendsExploreData($keyword, $geo, $timeRange, $category) {
        $endpoint = 'https://trends.google.com/trends/api/explore';
        
        // 标准请求数据格式
        $requestData = [
            'comparisonItem' => [
                [
                    'keyword' => $keyword,
                    'geo' => $geo,
                    'time' => $timeRange
                ]
            ],
            'category' => $category,
            'property' => ''
        ];
        
        $params = [
            'hl' => 'zh-CN',
            'tz' => '-480', // 北京时间 UTC+8
            'req' => json_encode($requestData)
        ];
        
        $url = $endpoint . '?' . http_build_query($params);
        
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Referer' => 'https://trends.google.com/',
            'Origin' => 'https://trends.google.com'
        ];
        
        return $this->makeRequest($url, $headers);
    }
    
    /**
     * 获取Trends Widget数据
     * 
     * @param array $requestData 请求数据
     * @param string $token 令牌
     * @return array|null Widget数据
     */
    public function getTrendsWidgetData($requestData, $token) {
        $endpoint = 'https://trends.google.com/trends/api/widgetdata/multiline';
        
        $params = [
            'hl' => 'zh-CN',
            'tz' => '-480',
            'req' => json_encode($requestData),
            'token' => $token
        ];
        
        $url = $endpoint . '?' . http_build_query($params);
        
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Referer' => 'https://trends.google.com/trends/explore',
            'Origin' => 'https://trends.google.com'
        ];
        
        return $this->makeRequest($url, $headers);
    }
    
    /**
     * 获取Google相关搜索查询
     * 
     * @param string $keyword 基础关键词
     * @param string $language 语言代码
     * @param string $country 国家代码
     * @return array 相关搜索查询列表
     */
    public function getRelatedSearches($keyword, $language = 'zh-CN', $country = 'cn') {
        // 使用Google搜索界面获取相关搜索
        $endpoint = 'https://www.google.com/search';
        
        $params = [
            'q' => $keyword,
            'hl' => $language,
            'gl' => $country,
            'num' => '10',
            'ie' => 'utf-8',
            'oe' => 'utf-8'
        ];
        
        $url = $endpoint . '?' . http_build_query($params);
        
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1'
        ];
        
        $request = $this->makeRequest($url, $headers);
        
        if ($request['http_code'] === 200 && $request['body']) {
            return $this->extractRelatedSearches($request['body']);
        }
        
        return [];
    }
    
    /**
     * ==========================================
     * 3. 辅助方法
     * ==========================================
     */
    
    /**
     * 发起HTTP请求
     * 
     * @param string $url 请求URL
     * @param array $headers 请求头
     * @return string|false 响应内容或false
     */
    private function makeRequest($url, $headers) {
        $ch = curl_init();
        
        // 标准curl配置
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->buildHeadersArray($headers));
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        
        // 添加cookie支持
        curl_setopt($ch, CURLOPT_COOKIEJAR, '');
        curl_setopt($ch, CURLOPT_COOKIEFILE, '');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            error_log("API请求失败: cURL Error: $error, URL: $url");
        }
        
        return ['body' => $response, 'http_code' => $httpCode];
    }
    
    /**
     * 解析建议响应数据
     * 
     * @param string $response 响应内容
     * @param string $client 客户端类型
     * @return array 解析后的建议列表
     */
    private function parseSuggestionsResponse($response, $client) {
        // 根据客户端类型解析响应
        switch ($client) {
            case 'chrome':
            case 'firefox':
                return $this->parseJsonSuggestions($response);
                
            case 'toolbar':
                return $this->parseXmlSuggestions($response);
                
            case 'youtube':
                return $this->parseYouTubeSuggestions($response);
                
            default:
                return $this->parseJsonSuggestions($response);
        }
    }
    
    /**
     * 解析JSON格式的建议
     * 
     * @param string $response 响应内容
     * @return array 建议列表
     */
    private function parseJsonSuggestions($response) {
        // 清理可能的HTML实体
        $response = html_entity_decode($response, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        $data = json_decode($response, true);
        
        if (!is_array($data) || !isset($data[1]) || !is_array($data[1])) {
            return [];
        }
        
        $suggestions = [];
        foreach ($data[1] as $suggestion) {
            if (is_string($suggestion) && !empty(trim($suggestion))) {
                $suggestions[] = trim($suggestion);
            } elseif (is_array($suggestion) && isset($suggestion[0])) {
                $suggestions[] = trim($suggestion[0]);
            }
        }
        
        return array_unique($suggestions);
    }
    
    /**
     * 解析XML格式的建议
     * 
     * @param string $response 响应内容
     * @return array 建议列表
     */
    private function parseXmlSuggestions($response) {
        $suggestions = [];
        
        // 简单的XML解析
        if (preg_match_all('/<suggestion data="([^"]*)"/i', $response, $matches)) {
            $suggestions = array_map('trim', $matches[1]);
        }
        
        return array_unique($suggestions);
    }
    
    /**
     * 解析YouTube建议
     * 
     * @param string $response 响应内容
     * @return array 建议列表
     */
    private function parseYouTubeSuggestions($response) {
        // YouTube建议格式与标准JSON类似
        return $this->parseJsonSuggestions($response);
    }
    
    /**
     * 从Google搜索结果中提取相关搜索
     * 
     * @param string $html HTML内容
     * @return array 相关搜索列表
     */
    private function extractRelatedSearches($html) {
        $related = [];
        
        // 提取相关搜索（基于常见的Google搜索结果结构）
        if (preg_match_all('/<div[^>]*class="[^"]*BNeawe[^"]*"[^>]*>([^<]+)<\/div>/i', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $text = trim(strip_tags($match));
                if (!empty($text) && strlen($text) > 2) {
                    $related[] = $text;
                }
            }
        }
        
        // 提取"相关搜索"部分
        if (preg_match('/相关搜索[\s\S]*?<\/div>([\s\S]*?)(?:<div[^>]*class="[^"]*g[\s"]|$)/i', $html, $sectionMatch)) {
            if (preg_match_all('/>([^<]+)</', $sectionMatch[1], $relatedMatches)) {
                foreach ($relatedMatches[1] as $match) {
                    $text = trim($match);
                    if (!empty($text) && strlen($text) > 2 && !is_numeric($text)) {
                        $related[] = $text;
                    }
                }
            }
        }
        
        return array_unique(array_slice($related, 0, 20));
    }
    
    /**
     * 构建请求头数组
     * 
     * @param array $headers 头关联数组
     * @return array 标准格式的头数组
     */
    private function buildHeadersArray($headers) {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = $key . ': ' . $value;
        }
        return $result;
    }
    
    /**
     * ==========================================
     * 4. 高级功能方法
     * ==========================================
     */
    
    /**
     * 深度关键词挖掘
     * 
     * @param string $baseKeyword 基础关键词
     * @param int $depth 挖掘深度 (1-3)
     * @param string $language 语言代码
     * @param string $country 国家代码
     * @param string $dataSource 数据源 (如: '', 'yt', 'sh')
     * @return array 深度挖掘后的关键词列表
     */
    public function deepKeywordMining($baseKeyword, $depth = 1, $language = 'zh-CN', $country = 'cn', $dataSource = '') {
        $allKeywords = [$baseKeyword];
        $currentLevel = [$baseKeyword];
        
        for ($i = 0; $i < $depth; $i++) {
            $nextLevel = [];
            
            foreach ($currentLevel as $keyword) {
                // 1. 基础建议
                $suggestions = $this->getGoogleSuggestionsByDataSource($keyword, 'chrome', $language, $country, $dataSource);
                if(!empty($suggestions)) $nextLevel = array_merge($nextLevel, $suggestions);
                usleep(1500000); // 1.5s delay

                // 2. 空格扩展建议
                $extendedSuggestions = $this->getGoogleSuggestionsByDataSource($keyword . ' ', 'chrome', $language, $country, $dataSource);
                if(!empty($extendedSuggestions)) $nextLevel = array_merge($nextLevel, $extendedSuggestions);
                usleep(1500000); // 1.5s delay

                // 3. 问题和商业前缀扩展
                $questionPrefixes = ['如何', '什么', '为什么', '哪里', '什么时候', '哪个', '最佳', '对比', '价格', '购买', '评测'];
                foreach ($questionPrefixes as $prefix) {
                    $questionSuggestions = $this->getGoogleSuggestionsByDataSource($prefix . ' ' . $keyword, 'chrome', $language, $country, $dataSource);
                    if(!empty($questionSuggestions)) $nextLevel = array_merge($nextLevel, $questionSuggestions);
                    usleep(1500000); // 1.5s delay
                }

                // 4. 字母表后缀扩展
                foreach (range('a', 'z') as $char) {
                    $alphaSuggestions = $this->getGoogleSuggestionsByDataSource($keyword . ' ' . $char, 'chrome', $language, $country, $dataSource);
                    if(!empty($alphaSuggestions)) $nextLevel = array_merge($nextLevel, $alphaSuggestions);
                    usleep(1500000); // 1.5s delay
                }
            }
            
            // 去重并过滤掉已有的关键词
            $nextLevel = array_unique(array_filter($nextLevel));
            $nextLevel = array_diff($nextLevel, $allKeywords);
            
            $allKeywords = array_merge($allKeywords, $nextLevel);
            $currentLevel = $nextLevel;
            
            if (empty($currentLevel)) {
                break;
            }
        }
        
        return array_unique($allKeywords);
    }
    
    /**
     * 根据数据源获取Google搜索建议
     * 
     * @param string $keyword 搜索关键词
     * @param string $client 客户端类型 (chrome|firefox|toolbar|youtube)
     * @param string $language 语言代码 (如: zh-CN, en, ja)
     * @param string $country 国家代码 (如: cn, us, uk)
     * @param string $dataSource 数据源 (如: '', 'yt', 'sh')
     * @return array 关键词建议列表
     */
    public function getGoogleSuggestionsByDataSource($keyword, $client = 'chrome', $language = 'zh-CN', $country = 'cn', $dataSource = '') {
        // 标准端点格式
        $endpoint = 'https://suggestqueries.google.com/complete/search';
        
        // 标准请求参数
        $params = [
            'client' => $client,
            'q' => $keyword,
            'hl' => $language,
            'gl' => $country
        ];
        
        // 添加数据源参数
        if (!empty($dataSource)) {
            $params['ds'] = $dataSource;
        }
        
        $url = $endpoint . '?' . http_build_query($params);
        
        // 标准请求头
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Referer' => 'https://www.google.com/',
            'Origin' => 'https://www.google.com'
        ];
        
        $request = $this->makeRequest($url, $headers);
        
        if ($request['http_code'] === 200 && $request['body']) {
            return $this->parseSuggestionsResponse($request['body'], $client);
        }
        
        return [];
    }
    
    /**
     * 获取临时存储文件路径
     * 
     * @param string $baseKeyword 基础关键词
     * @param string $sessionId 会话ID
     * @return string 临时文件路径
     */
    public function getTempStorageFilePath($baseKeyword, $sessionId) {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/keyword-research-temp';
        
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $filename = sanitize_file_name("keyword_mining_{$sessionId}_" . md5($baseKeyword) . ".txt");
        return $temp_dir . '/' . $filename;
    }
    
    /**
     * 将关键词追加写入临时文件
     * 
     * @param string $filePath 临时文件路径
     * @param array $keywords 关键词数组
     */
    public function appendKeywordsToTempFile($filePath, $keywords) {
        // 过滤空值和重复值
        $filtered_keywords = array_filter(array_map('trim', $keywords), function($kw) {
            return !empty($kw);
        });
        
        if (!empty($filtered_keywords)) {
            $content = implode("\n", $filtered_keywords) . "\n";
            file_put_contents($filePath, $content, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * 读取临时文件中的所有关键词
     * 
     * @param string $filePath 临时文件路径
     * @return array 关键词数组
     */
    public function readKeywordsFromTempFile($filePath) {
        if (!file_exists($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }
        
        $keywords = array_filter(array_map('trim', explode("\n", $content)), function($kw) {
            return !empty($kw);
        });
        return $keywords;
    }
    
    /**
     * 删除临时文件
     * 
     * @param string $filePath 临时文件路径
     */
    public function deleteTempFile($filePath) {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
    
    /**
     * 执行单步分段挖掘
     * 
     * @param string $baseKeyword 基础关键词
     * @param string $dataSource 数据源
     * @param string $stepType 步骤类型：base, space, question, letter
     * @param mixed $stepParam 步骤参数（如问题前缀或字母）
     * @param string $language 语言
     * @param string $country 国家
     * @return array 挖掘结果
     */
    public function performSingleMiningStep($baseKeyword, $dataSource, $stepType, $stepParam, $language = 'zh-CN', $country = 'cn') {
        $searchKeyword = $baseKeyword;
        $description = '';
        
        switch ($stepType) {
            case 'base':
                $searchKeyword = $baseKeyword;
                $description = "基础关键词挖掘: {$baseKeyword}";
                break;
            case 'space':
                $searchKeyword = $baseKeyword . ' ';
                $description = "空格扩展挖掘: {$baseKeyword} ";
                break;
            case 'question':
                $searchKeyword = $stepParam . ' ' . $baseKeyword;
                $description = "问题前缀挖掘: {$stepParam} {$baseKeyword}";
                break;
            case 'letter':
                $searchKeyword = $baseKeyword . ' ' . $stepParam;
                $description = "字母后缀挖掘: {$baseKeyword} {$stepParam}";
                break;
        }
        
        // 正确处理数据源参数
        $ds_param = $dataSource === 'default' ? '' : $dataSource;
        
        // 获取搜索建议
        $suggestions = $this->getGoogleSuggestionsByDataSource($searchKeyword, 'chrome', $language, $country, $ds_param);
        
        return [
            'keywords' => $suggestions,
            'description' => $description,
            'stepType' => $stepType,
            'stepParam' => $stepParam,
            'searchKeyword' => $searchKeyword,
            'ds_param' => $ds_param
        ];
    }
    
    /**
     * 获取关键词趋势对比
     * 
     * @param array $keywords 关键词数组
     * @param string $geo 地理区域
     * @param string $timeRange 时间范围
     * @return array 对比趋势数据
     */
    public function getTrendsComparison($keywords, $geo = 'CN', $timeRange = 'today 12-m') {
        if (count($keywords) > 5) {
            $keywords = array_slice($keywords, 0, 5); // 最多5个关键词对比
        }
        
        $comparisonData = [];
        
        foreach ($keywords as $keyword) {
            $trendData = $this->getTrendsData($keyword, $geo, $timeRange);
            
            if ($trendData && isset($trendData['default']['timelineData'])) {
                $timelineData = $trendData['default']['timelineData'];
                $values = array_column($timelineData, 'value');
                $avgInterest = array_sum($values) / count($values);
                
                $comparisonData[$keyword] = [
                    'average_interest' => $avgInterest,
                    'peak_interest' => max($values),
                    'timeline' => $timelineData
                ];
            }
            
            usleep(1000000); // 1秒延迟，避免过于频繁
        }
        
        return $comparisonData;
    }
    
    /**
     * 获取关键词的搜索意图分类
     * 
     * @param string $keyword 关键词
     * @return array 意图分类结果
     */
    public function getSearchIntent($keyword) {
        $intentTypes = [
            'informational' => ['如何', '什么', '为什么', '哪里', '谁', '什么时候', '怎样', '教程', '方法', '步骤'],
            'navigational' => ['官网', '官方网站', '登录', '下载', '首页', '主页'],
            'commercial' => ['最好的', '推荐', '评测', '对比', '价格', '多少钱', '便宜', '优惠', '促销'],
            'transactional' => ['购买', '订购', '预订', '报名', '申请', '注册']
        ];
        
        $detectedIntents = [];
        $keywordLower = mb_strtolower($keyword);
        
        foreach ($intentTypes as $intent => $indicators) {
            foreach ($indicators as $indicator) {
                if (mb_strpos($keywordLower, $indicator) !== false) {
                    $detectedIntents[] = $intent;
                    break;
                }
            }
        }
        
        // 如果没有明显的意图指标，分析关键词特征
        if (empty($detectedIntents)) {
            if (strlen($keyword) > 15) {
                $detectedIntents[] = 'informational';
            } elseif (preg_match('/\b(\.com|\.net|\.org|官网)\b/i', $keyword)) {
                $detectedIntents[] = 'navigational';
            } elseif (preg_match('/\b(价格|多少钱|便宜|优惠)\b/i', $keyword)) {
                $detectedIntents[] = 'commercial';
            } elseif (preg_match('/\b(购买|订购|预订)\b/i', $keyword)) {
                $detectedIntents[] = 'transactional';
            } else {
                $detectedIntents[] = 'informational'; // 默认
            }
        }
        
        return [
            'primary_intent' => $detectedIntents[0] ?? 'informational',
            'all_intents' => $detectedIntents,
            'confidence' => count($detectedIntents) > 0 ? 'high' : 'medium'
        ];
    }
    
    /**
     * ==========================================
     * 5. 百度搜索API (无需授权)
     * ==========================================
     *
     * 百度联想词API（主接口）：
     * https://suggestion.baidu.com/su?wd=关键词&p=3&cb=回调函数名
     *
     * 百度联想词API（备用接口）：
     * https://sp0.baidu.com/5a1Fazu8AA54nxGko9WTAnF6hhy/su?wd=关键词&cb=回调函数名
     *
     * 百度相关词API：
     * 通过解析百度搜索结果页面提取相关搜索词
     */

    /**
     * 获取百度搜索联想词
     *
     * @param string $keyword 搜索关键词
     * @return array 联想词列表
     */
    public function getBaiduSuggestions($keyword) {
        try {
            $baidu = new BaiduSuggestion();
            return $baidu->getSuggestions($keyword);
        } catch (Exception $e) {
            error_log("百度联想词获取失败: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 根据数据源获取百度关键词建议
     *
     * @param string $keyword 搜索关键词
     * @param string $dataSource 数据源类型 (仅支持联想词)
     * @return array 关键词建议列表
     */
    public function getBaiduSuggestionsByDataSource($keyword, $dataSource = 'suggestions') {
        $baidu = new BaiduSuggestion();
        $keywords = [];

        try {
            // 百度渠道仅支持联想词获取
            $keywords = $baidu->getSuggestions($keyword);
        } catch (Exception $e) {
            error_log("百度关键词获取失败: " . $e->getMessage());
        }

        return $keywords;
    }

    /**
     * 执行百度单步挖掘
     *
     * @param string $baseKeyword 基础关键词
     * @param string $dataSource 数据源类型 (仅支持联想词)
     * @param string $stepType 步骤类型：base, space, question, letter
     * @param mixed $stepParam 步骤参数（如问题前缀或字母）
     * @return array 挖掘结果
     */
    public function performBaiduSingleMiningStep($baseKeyword, $dataSource = 'suggestions', $stepType = 'base', $stepParam = '') {
        $searchKeyword = $baseKeyword;
        $description = '';

        switch ($stepType) {
            case 'base':
                $searchKeyword = $baseKeyword;
                $description = "百度基础关键词挖掘: {$baseKeyword}";
                break;
            case 'space':
                $searchKeyword = $baseKeyword . ' ';
                $description = "百度空格扩展挖掘: {$baseKeyword} ";
                break;
            case 'question':
                $searchKeyword = $stepParam . ' ' . $baseKeyword;
                $description = "百度问题前缀挖掘: {$stepParam} {$baseKeyword}";
                break;
            case 'letter':
                $searchKeyword = $baseKeyword . ' ' . $stepParam;
                $description = "百度字母后缀挖掘: {$baseKeyword} {$stepParam}";
                break;
        }

        // 获取百度搜索建议（仅联想词）
        $suggestions = $this->getBaiduSuggestionsByDataSource($searchKeyword, $dataSource);

        return [
            'keywords' => $suggestions,
            'description' => $description,
            'stepType' => $stepType,
            'stepParam' => $stepParam,
            'searchKeyword' => $searchKeyword,
            'dataSource' => $dataSource
        ];
    }

    /**
     * ==========================================
     * 6. 新增API接口实现
     * ==========================================
     */

    /**
     * 获取DuckDuckGo搜索建议
     *
     * @param string $keyword 搜索关键词
     * @return array 搜索建议列表
     */
    public function getDuckDuckGoSuggestions($keyword) {
        $endpoint = 'https://duckduckgo.com/ac/';

        $params = [
            'q' => $keyword,
            'type' => 'list'
        ];

        $url = $endpoint . '?' . http_build_query($params);

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Referer' => 'https://duckduckgo.com/'
        ];

        $request = $this->makeRequest($url, $headers);

        if ($request['http_code'] === 200 && $request['body']) {
            $suggestions = $this->parseDuckDuckGoResponse($request['body']);
            error_log("DuckDuckGo API: 关键词 '$keyword' 获取到 " . count($suggestions) . " 个建议");
            return $suggestions;
        } else {
            error_log("DuckDuckGo API: 请求失败，HTTP状态码: " . $request['http_code']);
        }

        return [];
    }

    /**
     * 解析DuckDuckGo响应
     *
     * @param string $response 响应内容
     * @return array 解析后的建议列表
     */
    private function parseDuckDuckGoResponse($response) {
        $data = json_decode($response, true);

        if (!is_array($data) || count($data) < 2) {
            return [];
        }

        // DuckDuckGo返回格式: [原始查询, [建议列表]]
        $suggestions = [];
        if (isset($data[1]) && is_array($data[1])) {
            foreach ($data[1] as $item) {
                if (is_string($item) && !empty(trim($item))) {
                    $suggestions[] = trim($item);
                }
            }
        }

        return array_unique($suggestions);
    }

    /**
     * 获取维基百科搜索建议
     *
     * @param string $keyword 搜索关键词
     * @param string $language 语言代码 (如: en, zh)
     * @param int $limit 结果数量限制
     * @return array 搜索建议列表
     */
    public function getWikipediaSuggestions($keyword, $language = 'en', $limit = 10) {
        $endpoint = "https://{$language}.wikipedia.org/w/api.php";

        // 确保关键词正确URL编码
        $encodedKeyword = urlencode($keyword);

        $params = [
            'action' => 'opensearch',
            'format' => 'json',
            'search' => $encodedKeyword,
            'namespace' => 0,
            'limit' => $limit
        ];

        $url = $endpoint . '?' . http_build_query($params);

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive'
        ];

        $request = $this->makeRequest($url, $headers);

        if ($request['http_code'] === 200 && $request['body']) {
            $suggestions = $this->parseWikipediaResponse($request['body']);
            error_log("Wikipedia API: 关键词 '$keyword' (语言: $language) 获取到 " . count($suggestions) . " 个建议");
            return $suggestions;
        } else {
            error_log("Wikipedia API: 请求失败，HTTP状态码: " . $request['http_code']);
        }

        return [];
    }

    /**
     * 解析维基百科响应
     *
     * @param string $response 响应内容
     * @return array 解析后的建议列表
     */
    private function parseWikipediaResponse($response) {
        $data = json_decode($response, true);

        if (!is_array($data) || !isset($data[1]) || !is_array($data[1])) {
            return [];
        }

        $suggestions = [];
        foreach ($data[1] as $suggestion) {
            if (is_string($suggestion) && !empty(trim($suggestion))) {
                $suggestions[] = trim($suggestion);
            }
        }

        return array_unique($suggestions);
    }

    /**
     * 获取淘宝搜索建议
     *
     * @param string $keyword 搜索关键词
     * @return array 搜索建议列表
     */
    public function getTaobaoSuggestions($keyword) {
        $endpoint = 'https://suggest.taobao.com/sug';

        $params = [
            'code' => 'utf-8',
            'q' => $keyword,  // 直接使用keyword，http_build_query会自动编码
            'callback' => 'jsonp_callback'
        ];

        $url = $endpoint . '?' . http_build_query($params);

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Referer' => 'https://www.taobao.com/',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-site'
        ];

        $request = $this->makeRequest($url, $headers);

        if ($request['http_code'] === 200 && $request['body']) {
            $suggestions = $this->parseTaobaoResponse($request['body']);
            error_log("Taobao API: 关键词 '$keyword' 获取到 " . count($suggestions) . " 个建议");
            
            // 如果没有结果，尝试使用英文关键词或拆分关键词
            if (empty($suggestions)) {
                error_log("Taobao API: 关键词 '$keyword' 无建议，尝试替代方案");
                
                // 尝试使用关键词的英文部分
                if (preg_match('/([a-zA-Z]+)/', $keyword, $matches)) {
                    $englishPart = $matches[1];
                    if (!empty($englishPart) && $englishPart !== $keyword) {
                        error_log("Taobao API: 尝试英文关键词 '$englishPart'");
                        $params['q'] = $englishPart;  // 直接使用，让http_build_query编码
                        $url = $endpoint . '?' . http_build_query($params);
                        $englishRequest = $this->makeRequest($url, $headers);
                        
                        if ($englishRequest['http_code'] === 200 && $englishRequest['body']) {
                            $englishSuggestions = $this->parseTaobaoResponse($englishRequest['body']);
                            $suggestions = array_merge($suggestions, $englishSuggestions);
                        }
                    }
                }
            }
            
            return array_unique($suggestions);
        } else {
            error_log("Taobao API: 请求失败，HTTP状态码: " . $request['http_code']);
        }

        return [];
    }

    /**
     * 解析淘宝响应
     *
     * @param string $response 响应内容
     * @return array 解析后的建议列表
     */
    private function parseTaobaoResponse($response) {
        if (empty($response)) {
            return [];
        }

        // 查找JSON数据部分
        if (preg_match('/\((.*)\)/', $response, $matches)) {
            $jsonStr = $matches[1];
            $data = json_decode($jsonStr, true);

            if (json_last_error() === JSON_ERROR_NONE && isset($data['result']) && is_array($data['result'])) {
                $suggestions = [];
                foreach ($data['result'] as $item) {
                    if (is_array($item) && isset($item[0]) && !empty(trim($item[0]))) {
                        $suggestion = trim($item[0]);
                        // 过滤掉太短或无意义的建议
                        if (strlen($suggestion) >= 2) {
                            $suggestions[] = $suggestion;
                        }
                    }
                }
                return array_unique($suggestions);
            } else {
                error_log("Taobao API: JSON解析失败 - " . json_last_error_msg());
            }
        } else {
            error_log("Taobao API: 响应格式不匹配 - " . substr($response, 0, 200));
        }

        return [];
    }

  


    /**
     * 根据数据源获取关键词建议（统一接口）
     *
     * @param string $keyword 搜索关键词
     * @param string $dataSource 数据源类型 (default|yt|sh|baidu|duckduckgo|wikipedia|taobao)
     * @param string $language 语言代码
     * @param string $country 国家代码
     * @return array 关键词建议列表
     */
    public function getKeywordSuggestionsByDataSource($keyword, $dataSource = 'default', $language = 'zh-CN', $country = 'cn') {
        switch ($dataSource) {
            case 'default':
                return $this->getGoogleSuggestions($keyword, 'chrome', $language, $country);

            case 'yt':
                return $this->getYouTubeSuggestions($keyword, $language, $country);

            case 'sh':
                return $this->getGoogleShoppingSuggestions($keyword, $language, $country);

            case 'baidu':
                return $this->getBaiduSuggestions($keyword);

            case 'duckduckgo':
                return $this->getDuckDuckGoSuggestions($keyword);

            case 'wikipedia':
                $wikiLanguage = ($language === 'zh-CN') ? 'zh' : 'en';
                return $this->getWikipediaSuggestions($keyword, $wikiLanguage);

            case 'taobao':
                return $this->getTaobaoSuggestions($keyword);

            default:
                return [];
        }
    }

    /**
     * 执行单步挖掘（支持所有数据源）
     *
     * @param string $baseKeyword 基础关键词
     * @param string $dataSource 数据源类型
     * @param string $stepType 步骤类型：base, space, question, letter
     * @param mixed $stepParam 步骤参数（如问题前缀或字母）
     * @param string $language 语言
     * @param string $country 国家
     * @return array 挖掘结果
     */
    public function performSingleMiningStepByDataSource($baseKeyword, $dataSource, $stepType, $stepParam, $language = 'zh-CN', $country = 'cn') {
        $searchKeyword = $baseKeyword;
        $description = '';

        switch ($stepType) {
            case 'base':
                $searchKeyword = $baseKeyword;
                $description = "基础关键词挖掘: {$baseKeyword}";
                break;
            case 'space':
                $searchKeyword = $baseKeyword . ' ';
                $description = "空格扩展挖掘: {$baseKeyword} ";
                break;
            case 'question':
                $searchKeyword = $stepParam . ' ' . $baseKeyword;
                $description = "问题前缀挖掘: {$stepParam} {$baseKeyword}";
                break;
            case 'letter':
                $searchKeyword = $baseKeyword . ' ' . $stepParam;
                $description = "字母后缀挖掘: {$baseKeyword} {$stepParam}";
                break;
        }

        // 获取搜索建议
        $suggestions = $this->getKeywordSuggestionsByDataSource($searchKeyword, $dataSource, $language, $country);

        return [
            'keywords' => $suggestions,
            'description' => $description,
            'stepType' => $stepType,
            'stepParam' => $stepParam,
            'searchKeyword' => $searchKeyword,
            'dataSource' => $dataSource
        ];
    }

    /**
     * ==========================================
     * 7. 工具方法
     * ==========================================
     */
    
    /**
     * 批量获取关键词数据
     * 
     * @param array $keywords 关键词数组
     * @param bool $includeTrends 是否包含趋势数据
     * @param bool $includeRelated 是否包含相关搜索
     * @return array 批量关键词数据
     */
    public function getBatchKeywordData($keywords, $includeTrends = false, $includeRelated = false) {
        $results = [];
        
        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if (empty($keyword)) continue;
            
            $data = [
                'keyword' => $keyword,
                'suggestions' => $this->getGoogleSuggestions($keyword),
                'intent' => $this->getSearchIntent($keyword),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            if ($includeTrends) {
                $trendData = $this->getTrendsData($keyword);
                if ($trendData) {
                    $data['trends'] = $this->parseTrendsData($trendData);
                }
            }
            
            if ($includeRelated) {
                $data['related_searches'] = $this->getRelatedSearches($keyword);
            }
            
            $results[$keyword] = $data;
            
            // 避免过于频繁的请求
            usleep(800000); // 0.8秒延迟
        }
        
        return $results;
    }
    
    /**
     * 解析趋势数据
     * 
     * @param array $trendData 原始趋势数据
     * @return array 解析后的趋势数据
     */
    private function parseTrendsData($trendData) {
        if (!$trendData || !isset($trendData['default']['timelineData'])) {
            return null;
        }
        
        $timelineData = $trendData['default']['timelineData'];
        $parsedData = [];
        $values = [];
        
        foreach ($timelineData as $point) {
            $value = isset($point['value'][0]) ? $point['value'][0] : 0;
            $parsedData[] = [
                'date' => date('Y-m-d', $point['time'] / 1000),
                'value' => $value
            ];
            $values[] = $value;
        }
        
        return [
            'average_interest' => count($values) > 0 ? array_sum($values) / count($values) : 0,
            'peak_interest' => count($values) > 0 ? max($values) : 0,
            'lowest_interest' => count($values) > 0 ? min($values) : 0,
            'timeline' => $parsedData
        ];
    }
    
    /**
     * 导出数据为CSV格式
     * 
     * @param array $data 关键词数据
     * @param string $filename 文件名
     * @return string CSV内容
     */
    public function exportToCSV($data, $filename = null) {
        if ($filename) {
            $fp = fopen($filename, 'w');
        } else {
            $fp = fopen('php://temp', 'r+');
        }
        
        // 写入标题行
        fputcsv($fp, ['关键词', '搜索意图', '建议数量', '平均兴趣度', '峰值兴趣度', '相关搜索数量', '时间戳']);
        
        foreach ($data as $keyword => $keywordData) {
            $row = [
                $keyword,
                $keywordData['intent']['primary_intent'] ?? 'unknown',
                count($keywordData['suggestions'] ?? []),
                $keywordData['trends']['average_interest'] ?? 0,
                $keywordData['trends']['peak_interest'] ?? 0,
                count($keywordData['related_searches'] ?? []),
                $keywordData['timestamp'] ?? date('Y-m-d H:i:s')
            ];
            
            fputcsv($fp, $row);
        }
        
        if ($filename) {
            fclose($fp);
            return $filename;
        } else {
            rewind($fp);
            $csv = stream_get_contents($fp);
            fclose($fp);
            return $csv;
        }
    }
}

