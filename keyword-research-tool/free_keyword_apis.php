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
        
        // 标准请求头 (将与 BrowserHeaders 合并)
        $headers = [
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
     
    private $cookie_file;
    protected $last_error = '';

    /**
     * 构造函数
     */
    public function __construct() {
        // 使用WordPress上传目录存储cookie，避免插件目录权限问题
        $upload_dir = wp_upload_dir();
        $cookie_dir = $upload_dir['basedir'] . '/yali-trends-cookies';

        // 确保目录存在并设置正确权限
        if (!file_exists($cookie_dir)) {
            if (wp_mkdir_p($cookie_dir)) {
                @chmod($cookie_dir, 0755);
                // 创建保护文件防止直接访问
                @file_put_contents($cookie_dir . '/.htaccess', "Options -Indexes\ndeny from all\n");
                @file_put_contents($cookie_dir . '/index.php', '<?php // Silence is golden');
            } else {
                error_log('Google Trends: 无法创建cookie目录: ' . $cookie_dir);
            }
        } else {
            // 目录已存在，尝试修复权限
            @chmod($cookie_dir, 0755);
        }
        
        // 如果目录不可写，使用系统临时目录作为备选
        if (!is_writable($cookie_dir)) {
            $temp_dir = sys_get_temp_dir() . '/yali-trends-' . md5(ABSPATH);
            if (!file_exists($temp_dir)) {
                @mkdir($temp_dir, 0755, true);
            }
            if (is_writable($temp_dir)) {
                $cookie_dir = $temp_dir;
                error_log('Google Trends: 使用系统临时目录: ' . $cookie_dir);
            }
        }

        $this->cookie_file = $cookie_dir . '/trends_' . substr(md5(get_current_user_id()), 0, 8) . '.txt';
    }

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
        // 步骤 0：访问主页预热 Session 和获取 Cookie (如果文件不存在或已过期)
        if (!file_exists($this->cookie_file) || (time() - filemtime($this->cookie_file) > 3600)) {
            $this->makeRequest('https://trends.google.com/trends/', $this->getBrowserHeaders());
            usleep(1500000); // 1.5s 延迟 - 增加等待时间避免429
        }

        // 第一步：获取explore数据
        $exploreRequest = $this->getTrendsExploreData($keyword, $geo, $timeRange, $category);
        
        if ($exploreRequest['http_code'] !== 200 || !$exploreRequest['body']) {
            $this->last_error = "Trends Explore Failed: Code " . $exploreRequest['http_code'] . ", Body: " . substr($exploreRequest['body'], 0, 200);
            return null;
        }

        $jsonStart = strpos($exploreRequest['body'], '{');
        $exploreData = json_decode($jsonStart !== false ? substr($exploreRequest['body'], $jsonStart) : $exploreRequest['body'], true);

        if (!$exploreData) {
            $this->last_error = "Trends Explore JSON Decode Failed. Raw: " . substr($exploreRequest['body'], 0, 100);
            return null;
        }
        
        if (!isset($exploreData['widgets'][0]['token'])) {
            $this->last_error = "Trends Explore Token Missing. Data: " . substr(json_encode($exploreData), 0, 200);
            return null;
        }
        
        $widget = $exploreData['widgets'][0];
        $token = $widget['token'];
        $requestData = $widget['request'];
        
        // 步骤 1.5: 稍作停顿模拟人工
        usleep(1000000); // 1秒延迟 - 避免触发429限流

        // 第二步：获取具体的趋势数据
        $widgetRequest = $this->getTrendsWidgetData($requestData, $token);
        if ($widgetRequest['http_code'] !== 200 || !$widgetRequest['body']) {
            $this->last_error = "Trends Widget Failed: Code " . $widgetRequest['http_code'] . ", Body: " . substr($widgetRequest['body'], 0, 200);
            return null;
        }

        $jsonStartWidget = strpos($widgetRequest['body'], '{');
        $decoded = json_decode($jsonStartWidget !== false ? substr($widgetRequest['body'], $jsonStartWidget) : $widgetRequest['body'], true);
        
        if ($decoded === null) {
            $this->last_error = "Trends Widget JSON Decode Failed: " . json_last_error_msg();
            return null;
        }

        // 检查是否有数据点
        if (!isset($decoded['default']['timelineData']) || empty($decoded['default']['timelineData'])) {
            $this->last_error = "Google Trends 返回成功，但该关键词在指定区域/时间内没有足够的数据显示。";
            return null;
        }

        return $decoded;
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
        
        $headers = array_merge($this->getBrowserHeaders(), [
            'Referer' => 'https://trends.google.com/trends/explore?q=' . urlencode($keyword),
            'X-Requested-With' => 'XMLHttpRequest'
        ]);
        
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
        
        $headers = array_merge($this->getBrowserHeaders(), [
            'Referer' => 'https://trends.google.com/trends/explore',
            'X-Requested-With' => 'XMLHttpRequest'
        ]);
        
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
     * @return array ['body' => string, 'http_code' => int]
     */
    private function makeRequest($url, $headers = []) {
        $ch = curl_init();
        
        // 合并浏览器标准头
        $standardHeaders = $this->getBrowserHeaders();
        $mergedHeaders = array_merge($standardHeaders, $headers);
        
        // 标准curl配置
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->buildHeadersArray($mergedHeaders));
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate, br'); // 明确支持压缩以提升效率
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        
        // 持久化cookie支持 (模拟浏览器Session)
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookie_file);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookie_file);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            error_log("API请求失败: cURL Error: $error, URL: $url");
            return ['body' => false, 'http_code' => 0];
        }

        // --- 编码自动转换 (UTF-8 强制执行) ---
        // 针对百度、淘宝等可能返回 GBK 的接口进行检测
        $charset = '';
        if (preg_match('/charset=([^;]+)/i', $contentType, $matches)) {
            $charset = strtoupper(trim($matches[1]));
        }

        if ($charset === 'GBK' || $charset === 'GB2312') {
            $response = mb_convert_encoding($response, 'UTF-8', $charset);
        } else if (function_exists('mb_detect_encoding')) {
            // 如果 Header 没写，尝试内容探测 (针对中国常用编码优化)
            $detect = mb_detect_encoding($response, ['UTF-8', 'GBK', 'GB2312', 'BIG5']);
            if ($detect && $detect !== 'UTF-8') {
                $response = mb_convert_encoding($response, 'UTF-8', $detect);
            }
        }
        
        return ['body' => $response, 'http_code' => $httpCode];
    }

    /**
     * 获取现代浏览器标准的请求头 (模拟 Chrome)
     */
    private function getBrowserHeaders() {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8,ja;q=0.7',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Sec-Ch-Ua' => '"Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'Connection' => 'keep-alive'
        ];
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
        if (empty($response)) return [];

        // 清理可能的HTML实体
        $response = html_entity_decode($response, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 有些接口返回可能是 callback([data]) 或 window.google.ac.h([data])，尝试提取内容
        if (preg_match('/^[a-zA-Z0-9_\.]+\((.*)\)$/s', trim($response), $matches)) {
            $response = $matches[1];
        }

        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            // 记录解析失败，便于调试
            error_log("JSON解析失败: " . json_last_error_msg() . " 原始响应预览: " . substr($response, 0, 100));
            return [];
        }
        
        if (!is_array($data) || !isset($data[1]) || !is_array($data[1])) {
            return [];
        }
        
        $suggestions = [];
        foreach ($data[1] as $suggestion) {
            if (is_string($suggestion) && !empty(trim($suggestion))) {
                $suggestions[] = trim($suggestion);
            } elseif (is_array($suggestion) && isset($suggestion[0]) && is_string($suggestion[0])) {
                $suggestions[] = trim($suggestion[0]);
            }
        }
        
        return array_values(array_unique($suggestions));
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
        
        // 多语言挖掘词配置
        $miningTerms = [
            // 中文 (简体)
            'zh' => [
                'questionPrefixes' => ['如何', '什么', '为什么', '哪里', '什么时候', '哪个', '最佳', '对比', '价格', '购买', '评测'],
                'geoQuestions' => ['是什么', '如何', '教程', '原理', '意义', '作用', '步骤', '含义'],
                'intentModifiers' => ['区别', '最佳', '推荐', '对比', 'vs', 'best', 'review', 'checklist']
            ],
            // 英语
            'en' => [
                'questionPrefixes' => ['how to', 'what is', 'why', 'where', 'when', 'which', 'best', 'vs', 'price', 'buy', 'review'],
                'geoQuestions' => ['what is', 'how to', 'tutorial', 'principle', 'meaning', 'guide', 'definition', 'steps', 'basics', 'introduction'],
                'intentModifiers' => ['vs', 'best', 'review', 'checklist', 'comparison', 'alternatives', 'top', 'guide']
            ],
            // 日语
            'ja' => [
                'questionPrefixes' => ['使い方', 'とは', 'なぜ', 'どこ', 'いつ', 'どちら', 'ベスト', '比較', '価格', '購入', 'レビュー'],
                'geoQuestions' => ['とは', '使い方', 'チュートリアル', '原理', '意味', 'ガイド', '定義', '手順', '基本', '紹介'],
                'intentModifiers' => ['違い', 'おすすめ', '比較', 'vs', 'best', 'review', 'checklist']
            ],
            // 韩语
            'ko' => [
                'questionPrefixes' => ['사용법', '란', '왜', '어디', '언제', '어느', '최고', '비교', '가격', '구매', '리뷰'],
                'geoQuestions' => ['란', '사용법', '튜토리얼', '원리', '의미', '가이드', '정의', '단계', '기초', '소개'],
                'intentModifiers' => ['차이', '추천', '비교', 'vs', 'best', 'review', 'checklist']
            ],
            // 德语
            'de' => [
                'questionPrefixes' => ['wie', 'was ist', 'warum', 'wo', 'wann', 'welche', 'beste', 'vs', 'preis', 'kaufen', 'bewertung'],
                'geoQuestions' => ['was ist', 'wie', 'tutorial', 'prinzip', 'bedeutung', 'anleitung', 'definition', 'schritte', 'grundlagen', 'einführung'],
                'intentModifiers' => ['vs', 'beste', 'bewertung', 'checkliste', 'vergleich', 'alternativen', 'top', 'anleitung']
            ],
            // 法语
            'fr' => [
                'questionPrefixes' => ['comment', 'qu\'est-ce que', 'pourquoi', 'où', 'quand', 'quel', 'meilleur', 'vs', 'prix', 'acheter', 'avis'],
                'geoQuestions' => ['qu\'est-ce que', 'comment', 'tutoriel', 'principe', 'signification', 'guide', 'définition', 'étapes', 'bases', 'introduction'],
                'intentModifiers' => ['vs', 'meilleur', 'avis', 'checklist', 'comparaison', 'alternatives', 'top', 'guide']
            ],
            // 西班牙语
            'es' => [
                'questionPrefixes' => ['cómo', 'qué es', 'por qué', 'dónde', 'cuándo', 'cuál', 'mejor', 'vs', 'precio', 'comprar', 'reseña'],
                'geoQuestions' => ['qué es', 'cómo', 'tutorial', 'principio', 'significado', 'guía', 'definición', 'pasos', 'básico', 'introducción'],
                'intentModifiers' => ['vs', 'mejor', 'reseña', 'checklist', 'comparación', 'alternativas', 'top', 'guía']
            ],
            // 意大利语
            'it' => [
                'questionPrefixes' => ['come', 'cosa è', 'perché', 'dove', 'quando', 'quale', 'migliore', 'vs', 'prezzo', 'acquistare', 'recensione'],
                'geoQuestions' => ['cosa è', 'come', 'tutorial', 'principio', 'significato', 'guida', 'definizione', 'passi', 'basi', 'introduzione'],
                'intentModifiers' => ['vs', 'migliore', 'recensione', 'checklist', 'confronto', 'alternative', 'top', 'guida']
            ],
            // 荷兰语
            'nl' => [
                'questionPrefixes' => ['hoe', 'wat is', 'waarom', 'waar', 'wanneer', 'welke', 'beste', 'vs', 'prijs', 'kopen', 'review'],
                'geoQuestions' => ['wat is', 'hoe', 'tutorial', 'principe', 'betekenis', 'gids', 'definitie', 'stappen', 'basis', 'introductie'],
                'intentModifiers' => ['vs', 'beste', 'review', 'checklist', 'vergelijking', 'alternatieven', 'top', 'gids']
            ],
            // 葡萄牙语
            'pt' => [
                'questionPrefixes' => ['como', 'o que é', 'por que', 'onde', 'quando', 'qual', 'melhor', 'vs', 'preço', 'comprar', 'avaliação'],
                'geoQuestions' => ['o que é', 'como', 'tutorial', 'princípio', 'significado', 'guia', 'definição', 'passos', 'básico', 'introdução'],
                'intentModifiers' => ['vs', 'melhor', 'avaliação', 'checklist', 'comparação', 'alternativas', 'top', 'guia']
            ],
            // 阿拉伯语
            'ar' => [
                'questionPrefixes' => ['كيف', 'ما هو', 'لماذا', 'أين', 'متى', 'أي', 'أفضل', 'ضد', 'السعر', 'شراء', 'مراجعة'],
                'geoQuestions' => ['ما هو', 'كيف', 'شرح', 'مبدأ', 'معنى', 'دليل', 'تعريف', 'خطوات', 'أساسيات', 'مقدمة'],
                'intentModifiers' => ['ضد', 'أفضل', 'مراجعة', 'قائمة', 'مقارنة', 'بدائل', 'أفضل', 'دليل']
            ],
            // 印尼语
            'id' => [
                'questionPrefixes' => ['cara', 'apa itu', 'mengapa', 'di mana', 'kapan', 'yang mana', 'terbaik', 'vs', 'harga', 'beli', 'ulasan'],
                'geoQuestions' => ['apa itu', 'cara', 'tutorial', 'prinsip', 'arti', 'panduan', 'definisi', 'langkah', 'dasar', 'pengenalan'],
                'intentModifiers' => ['vs', 'terbaik', 'ulasan', 'checklist', 'perbandingan', 'alternatif', 'top', 'panduan']
            ]
        ];
        
        // 提取语言代码 (如 'zh-CN' -> 'zh')
        $langCode = explode('-', $language)[0];
        $terms = isset($miningTerms[$langCode]) ? $miningTerms[$langCode] : $miningTerms['en'];
        
        // Determine sources: if 'default', we mine from the "Big Three" for maximum long-tail coverage
        $sources = ($dataSource === 'default' || empty($dataSource)) ? ['default', 'baidu', 'duckduckgo'] : [$dataSource];

        for ($i = 0; $i < $depth; $i++) {
            $nextLevel = [];
            
            foreach ($currentLevel as $keyword) {
                foreach ($sources as $source) {
                    // 1. Basic Suggestions
                    $suggestions = $this->getKeywordSuggestionsByDataSource($keyword, $source, $language, $country, $baseKeyword);
                    if(!empty($suggestions)) $nextLevel = array_merge($nextLevel, $suggestions);
                    usleep(300000); // reduced delay as we handle multiple sources

                    // 2. Space Extension
                    $extended = $this->getKeywordSuggestionsByDataSource($keyword . ' ', $source, $language, $country, $baseKeyword);
                    if(!empty($extended)) $nextLevel = array_merge($nextLevel, $extended);
                    usleep(300000);

                    // 3. Question & Business Prefixes
                    foreach ($terms['questionPrefixes'] as $prefix) {
                        $qs = $this->getKeywordSuggestionsByDataSource($prefix . ' ' . $keyword, $source, $language, $country, $baseKeyword);
                        if(!empty($qs)) $nextLevel = array_merge($nextLevel, $qs);
                    }

                    // 4. Alphabet Suffix (a-z)
                    foreach (range('a', 'z') as $char) {
                        $as = $this->getKeywordSuggestionsByDataSource($keyword . ' ' . $char, $source, $language, $country, $baseKeyword);
                        if(!empty($as)) $nextLevel = array_merge($nextLevel, $as);
                    }

                    // 5. Alphabet Prefix (a-z)
                    foreach (range('a', 'z') as $char) {
                        $ps = $this->getKeywordSuggestionsByDataSource($char . ' ' . $keyword, $source, $language, $country, $baseKeyword);
                        if(!empty($ps)) $nextLevel = array_merge($nextLevel, $ps);
                    }

                    // 6. GEO & Intent Modifiers
                    $advanced_modifiers = array_merge($terms['geoQuestions'], $terms['intentModifiers']);
                    foreach ($advanced_modifiers as $mod) {
                        $ms = $this->getKeywordSuggestionsByDataSource($keyword . ' ' . $mod, $source, $language, $country, $baseKeyword);
                        if(!empty($ms)) $nextLevel = array_merge($nextLevel, $ms);
                    }
                }
            }
            
            // Deduplicate and filter existing
            $nextLevel = array_unique(array_filter($nextLevel));
            $nextLevel = array_diff($nextLevel, $allKeywords);
            
            $allKeywords = array_merge($allKeywords, $nextLevel);
            $currentLevel = $nextLevel;
            
            if (empty($currentLevel) || count($allKeywords) > 500) {
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
                $description = sprintf(__('基础关键词挖掘: %s', 'yali-ai-writer'), $baseKeyword);
                break;
            case 'space':
                $searchKeyword = $baseKeyword . ' ';
                $description = sprintf(__('空格扩展挖掘: %s ', 'yali-ai-writer'), $baseKeyword);
                break;
            case 'question':
                $searchKeyword = $stepParam . ' ' . $baseKeyword;
                $description = sprintf(__('问题前缀挖掘: %s %s', 'yali-ai-writer'), $stepParam, $baseKeyword);
                break;
            case 'letter':
                $searchKeyword = $baseKeyword . ' ' . $stepParam;
                $description = sprintf(__('字母后缀挖掘: %s %s', 'yali-ai-writer'), $baseKeyword, $stepParam);
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
        if (empty($keyword)) return [];
        
        $endpoint = 'https://suggestion.baidu.com/su';
        $params = [
            'wd' => $keyword,
            'p' => 3,
            'cb' => 'jsonp_callback'
        ];
        
        $url = $endpoint . '?' . http_build_query($params);
        $headers = [
            'Referer' => 'https://www.baidu.com/',
        ];
        
        $request = $this->makeRequest($url, $headers);
        
        if ($request['http_code'] === 200 && $request['body']) {
            // 借用 BaiduSuggestion 的解析逻辑
            $baiduHelper = new BaiduSuggestion();
            return $baiduHelper->parseJsonpResponse($request['body']);
        }
        
        return [];
    }

    /**
     * 根据数据源获取百度关键词建议
     *
     * @param string $keyword 搜索关键词
     * @param string $dataSource 数据源类型 (仅支持联想词)
     * @return array 关键词建议列表
     */
    public function getBaiduSuggestionsByDataSource($keyword, $dataSource = 'suggestions') {
        return $this->getBaiduSuggestions($keyword);
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

        $params = [
            'action' => 'opensearch',
            'format' => 'json',
            'search' => $keyword,
            'namespace' => 0,
            'limit' => $limit
        ];

        $url = $endpoint . '?' . http_build_query($params);

        $headers = [
            'Referer' => "https://{$language}.wikipedia.org/"
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
     * 对指定数据源进行 Session 预热 (获取 Cookie)
     */
    private function warmUpSource($dataSource) {
        $warmupUrls = [
            'default' => 'https://www.google.com/',
            'yt' => 'https://www.youtube.com/',
            'sh' => 'https://www.google.com/shopping',
            'baidu' => 'https://www.baidu.com/',
            'duckduckgo' => 'https://duckduckgo.com/',
            'wikipedia' => 'https://www.wikipedia.org/',
            'taobao' => 'https://www.taobao.com/'
        ];

        if (isset($warmupUrls[$dataSource])) {
            $url = $warmupUrls[$dataSource];
            // 访问首页获取 Cookie，不关心返回体
            $this->makeRequest($url, [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Dest' => 'document'
            ]);
            // 模拟真实用户，稍作等待
            usleep(150000); 
        }
    }

    /**
     * 根据数据源获取关键词建议（统一接口）
     *
     * @param string $keyword 搜索关键词
     * @param string $dataSource 数据源类型 (default|yt|sh|baidu|duckduckgo|wikipedia|taobao)
     * @param string $language 语言代码
     * @param string $country 国家代码
     * @param string|null $originalBaseForFilter 原始提词根（用于绕过拼接字母带来的重合率过滤污染）
     * @return array 关键词建议列表
     */
    public function getKeywordSuggestionsByDataSource($keyword, $dataSource = 'default', $language = 'zh-CN', $country = 'cn', $originalBaseForFilter = null) {
        $raw_suggestions = [];
        
        // --- Cookie 预热 ---
        $this->warmUpSource($dataSource);

        switch ($dataSource) {
            case 'default':
                $raw_suggestions = $this->getGoogleSuggestions($keyword, 'chrome', $language, $country);
                break;
            case 'yt':
                $raw_suggestions = $this->getYouTubeSuggestions($keyword, $language, $country);
                break;
            case 'sh':
                $raw_suggestions = $this->getGoogleShoppingSuggestions($keyword, $language, $country);
                break;
            case 'baidu':
                $raw_suggestions = $this->getBaiduSuggestions($keyword);
                break;
            case 'duckduckgo':
                $raw_suggestions = $this->getDuckDuckGoSuggestions($keyword);
                break;
            case 'wikipedia':
                $wikiLanguage = ($language === 'zh-CN') ? 'zh' : 'en';
                $raw_suggestions = $this->getWikipediaSuggestions($keyword, $wikiLanguage);
                break;
            case 'taobao':
                $raw_suggestions = $this->getTaobaoSuggestions($keyword);
                break;
        }

        // Apply strict relevancy filtering to eliminate noise and semantic drift
        $filtered_suggestions = [];
        $filterBase = $originalBaseForFilter !== null ? $originalBaseForFilter : $keyword;
        
        foreach ($raw_suggestions as $suggestion) {
            if ($this->isKeywordRelevant($filterBase, $suggestion)) {
                $filtered_suggestions[] = $suggestion;
            }
        }
        
        return array_values(array_unique($filtered_suggestions));
    }

    /**
     * 判断挖掘候选词是否与基础核心词高度相关（15% Unit Coverage 混合过滤算法）
     * 设计哲学：
     * 1. 英文连续单词视为 1 个计算单元（Unit），中文单字视为 1 个计算单元（Unit）。
     * 2. 候选词中如果出现基础词的英文 Unit，必须满足严格的词界（不能粘连如 seo -> seol，否则一票否决）。
     * 3. 汇总命中的 Unit 数量，除以总 Unit 数量，只要覆盖率 >= 15%，即认定为合理的相关长尾词。
     * 
     * @param string $baseKeyword 基础核心词（用户输入的最初始的根词）
     * @param string $candidate 挖掘出的候选长尾词
     * @return bool 是否相关
     */
    public function isKeywordRelevant($baseKeyword, $candidate) {
        $base = trim(mb_strtolower($baseKeyword));
        $cand = trim(mb_strtolower($candidate));
        
        if (empty($base) || empty($cand)) return false;
        if ($base === $cand) return true;

        // --- 解析 Base 的计算单元 (Units) ---
        // 1. 提取英文连续单词
        preg_match_all('/[a-zA-Z0-9]+/', $base, $matches);
        $eng_words = $matches[0];
        
        // 2. 提取中文字符
        $chinese_chars_str = preg_replace('/[a-zA-Z0-9\s\p{P}]/u', '', $base);
        $chinese_chars = [];
        if (!empty($chinese_chars_str)) {
            $chinese_chars = preg_split('//u', $chinese_chars_str, -1, PREG_SPLIT_NO_EMPTY);
        }
        
        $total_units = count($eng_words) + count($chinese_chars);
        if ($total_units === 0) return true; // 保底防御

        // --- NEW RULE: 核心主语连续性校验 (English Subject Anchor) ---
        // 如果基础词包含英文（通常是核心品牌或术语，如 SEO），
        // 候选词必须包含基础词中至少一个英文单词且满足词界，否则视为语义偏离。
        if (!empty($eng_words)) {
            $has_subject_match = false;
            foreach ($eng_words as $word) {
                $escaped = preg_quote($word, '/');
                $pattern = '/(?<![a-zA-Z0-9])' . $escaped . '(?![a-zA-Z0-9])/iu';
                if (preg_match($pattern, $cand)) {
                    $has_subject_match = true;
                    break;
                }
            }
            if (!$has_subject_match) return false; 
        }

        // --- NEW LOGIC: 通用信息熵加权模型 (Universal Information Density Model) ---
        // 核心直觉：不再依赖位置（位置可能会变，如“如何学习SEO”），而是依赖“信息密度”。
        // 1. 连续英文单词通常是核心主语，赋予超高权重。
        // 2. 长单词比单字包含更多信息量，赋予更高权重。
        // 3. 通用结构词（如：如何、教程、为什么）赋予极低权重。
        
        $total_weight = 0;
        $matched_weight = 0;
        
        // 解析所有 Units
        $all_units = [];
        $pattern = '/([a-zA-Z0-9]+)|([\x{4e00}-\x{9fa5}])/u';
        preg_match_all($pattern, $base, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) { $all_units[] = $m[0]; }
        
        if (empty($all_units)) return true;

        // 识别常见的“结构化修饰词”（Intent Modifiers）
        $structural_mods = ['如何', '如何学习', '怎么', '为什么', '哪里', '教程', '指南', '大全', '原理', '意义', '作用', '什么'];
        $base_mods_map = [];
        foreach ($structural_mods as $mod) {
            if (mb_strpos($base, $mod) !== false) {
                // 如果 base 中包含这些词，把组成它们的字标记为低权重
                $chars = preg_split('//u', $mod, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($chars as $c) { $base_mods_map[$c] = true; }
            }
        }

        foreach ($all_units as $unit) {
            $is_eng = preg_match('/^[a-zA-Z0-9]+$/', $unit);
            
            // 计算基础权重
            // 英文权重系数 2.0, 中文权重系数 1.0
            $type_multiplier = $is_eng ? 2.0 : 1.0;
            $unit_len = $is_eng ? 1 : mb_strlen($unit); // 英文按词计，中文按字计
            
            $w = $unit_len * $type_multiplier;

            // 如果是已知的结构修饰词中的字符，权重惩罚 (80% 削减)
            if (!$is_eng && isset($base_mods_map[$unit])) {
                $w *= 0.2;
            }

            $total_weight += $w;

            if ($is_eng) {
                $escaped = preg_quote($unit, '/');
                $pattern_cand = '/(?<![a-zA-Z0-9])' . $escaped . '(?![a-zA-Z0-9])/iu';
                if (preg_match($pattern_cand, $cand)) {
                    $matched_weight += $w;
                }
            } else {
                if (mb_strpos($cand, $unit) !== false) {
                    $matched_weight += $w;
                }
            }
        }

        if ($total_weight == 0) return true;
        $score = $matched_weight / $total_weight;

        // 采用更加合理的阈值 (40%)：
        // 在“如何学习SEO”中，SEO 的权重占比极大。即便匹配了“如何学习”但没中“SEO”，分值也会显著低于 40%。
        // 在“SEO教程”中，SEO 的权重占比极大，有效地拦截了“x-ui教程”。
        return $score >= 0.40;
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
                $description = "问题前缀挖掘: {$stepParam}";
                break;
            case 'letter':
                $searchKeyword = $baseKeyword . ' ' . $stepParam;
                $description = "字母后缀挖掘: {$stepParam}";
                break;
            case 'letter_prefix':
                $searchKeyword = $stepParam . ' ' . $baseKeyword;
                $description = "字母前缀挖掘: {$stepParam}";
                break;
            case 'geo_question':
                $searchKeyword = $baseKeyword . ' ' . $stepParam;
                $description = "GEO挖掘: {$stepParam}";
                break;
            case 'intent_modifier':
                $searchKeyword = $baseKeyword . ' ' . $stepParam;
                $description = "意图挖掘: {$stepParam}";
                break;
        }

        // Support Multi-Source Fusion
        $sources = is_array($dataSource) ? $dataSource : explode(',', $dataSource);
        $all_suggestions = [];

        foreach ($sources as $source) {
            if (empty($source)) continue;
            // 获取搜索建议
            $suggestions = $this->getKeywordSuggestionsByDataSource($searchKeyword, trim($source), $language, $country, $baseKeyword);
            if (!empty($suggestions)) {
                $all_suggestions = array_merge($all_suggestions, $suggestions);
            }
        }

        return [
            'keywords' => array_values(array_unique($all_suggestions)),
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

    /**
     * 获取最后一次错误信息
     */
    public function getLastError() {
        return $this->last_error;
    }
}

