<?php
/**
 * 混合方案: WordPress HTTP API 为主，cURL为备用
 * 趋势数据说明:
 * - 数据类型: 相对热度值 (0-100)
 * - 含义: 相对于时间段内最高搜索量的百分比
 * - 100 = 该时间段内的峰值
 * - timeRange参数控制时间范围:
 *   - 'today 12-m': 过去12个月(每周数据点) ← 默认
 *   - 'today 3-m': 过去3个月(每天数据点)
 *   - 'today 5-y': 过去5年(每月数据点)
 *   - 'now 7-d': 过去7天(每小时数据点)
 */

if (!class_exists('FreeKeywordAPIs')) {
    require_once plugin_dir_path(__FILE__) . 'free_keyword_apis.php';
}

class FreeKeywordAPIs_Hybrid extends FreeKeywordAPIs {

    private $cookie_jar = [];
    private $last_request_time = 0;
    private $use_wp_http = true; // 默认使用WP HTTP API
    private $fallback_to_curl = false; // 是否已降级到cURL
    private $hybrid_cookie_file; // 子类自己的cookie文件路径

    /**
     * 构造函数
     */
    public function __construct() {
        // 调用父类构造函数
        parent::__construct();
        
        // 尝试使用WordPress上传目录存储cookie
        $upload_dir = wp_upload_dir();
        $cookie_dir = $upload_dir['basedir'] . '/yali-trends-cookies';
        
        // 确保目录存在并设置正确权限
        if (!file_exists($cookie_dir)) {
            wp_mkdir_p($cookie_dir);
            // 尝试设置权限（不报错，因为某些主机可能不允许）
            @chmod($cookie_dir, 0755);
            // 创建保护文件防止直接访问
            @file_put_contents($cookie_dir . '/.htaccess', "Options -Indexes\ndeny from all\n");
            @file_put_contents($cookie_dir . '/index.php', '<?php // Silence is golden');
        } else {
            // 目录已存在，尝试修复权限
            @chmod($cookie_dir, 0755);
        }
        
        // 检查目录是否可写
        if (!is_writable($cookie_dir)) {
            // 如果不可写，尝试使用系统临时目录
            $temp_dir = sys_get_temp_dir() . '/yali-trends-' . md5(ABSPATH);
            if (!file_exists($temp_dir)) {
                @mkdir($temp_dir, 0755, true);
            }
            if (is_writable($temp_dir)) {
                $cookie_dir = $temp_dir;
                error_log('Google Trends: 使用系统临时目录存储cookie: ' . $cookie_dir);
            } else {
                error_log('Google Trends: 警告 - 无法找到可写的cookie存储目录');
            }
        }

        $this->hybrid_cookie_file = $cookie_dir . '/trends_' . substr(md5(get_current_user_id()), 0, 8) . '.txt';
    }

    /**
     * 获取关键词趋势数据 (混合方案)
     *
     * @param string $keyword 关键词
     * @param string $geo 地理区域 (如: CN, US, JP)
     * @param string $timeRange 时间范围:
     *                          - 'now 1-H': 过去1小时(每小时)
     *                          - 'now 4-H': 过去4小时(每小时)
     *                          - 'now 1-d': 过去1天(每小时)
     *                          - 'now 7-d': 过去7天(每天)
     *                          - 'today 1-m': 过去1个月(每天)
     *                          - 'today 3-m': 过去3个月(每周)
     *                          - 'today 12-m': 过去12个月(每周) ← 默认
     *                          - 'today 5-y': 过去5年(每月)
     *                          - 'all': 全部可用数据(每年)
     * @param int $category 类别 (0表示所有类别)
     * @return array|null 趋势数据
     */
    public function getTrendsData_Hybrid($keyword, $geo = 'CN', $timeRange = 'today 12-m', $category = 0) {
        $this->fallback_to_curl = false;

        // 步骤-1: 检查 Transient 缓存 (有效期24小时)
        $cache_key = 'yali_trends_' . md5($keyword . '_' . $geo . '_' . $timeRange);
        $cached_data = get_transient($cache_key);
        if ($cached_data !== false) {
            $this->logDebug("Google Trends: 命中缓存对于关键词 -> {$keyword}");
            return $cached_data;
        }

        // 步骤0: 预热Session
        if (!$this->warmUpSession_Hybrid()) {
            error_log('Google Trends: Session预热失败');
            return null;
        }

        usleep(500000); // 0.5秒延迟 (从1.5s大幅减少)

        // 步骤1: 获取Explore数据
        $exploreData = $this->getTrendsExploreData_Hybrid($keyword, $geo, $timeRange, $category);
        if (!$exploreData) {
            error_log('Google Trends: 获取Explore数据失败');
            return null;
        }

        usleep(300000); // 0.3秒延迟 (从1s大幅减少)

        // 步骤2: 获取Widget趋势数据
        $widgetData = $this->getTrendsWidgetData_Hybrid($exploreData['request'], $exploreData['token']);
        if (!$widgetData) {
            error_log('Google Trends: 获取Widget数据失败');
            return null;
        }

        // 验证数据结构
        if (!isset($widgetData['default']['timelineData']) || empty($widgetData['default']['timelineData'])) {
            $this->last_error = "Google Trends返回成功，但该关键词在指定区域/时间内没有足够的数据显示。";
            return null;
        }

        // 步骤3: 写入缓存 (1天有效期)
        set_transient($cache_key, $widgetData, DAY_IN_SECONDS);

        return $widgetData;
    }

    /**
     * 预热Session
     */
    private function warmUpSession_Hybrid() {
        // 获取cookie目录
        $cookie_dir = dirname($this->hybrid_cookie_file);
        
        // 确保cookie目录存在且可写
        if (!file_exists($cookie_dir)) {
            if (!wp_mkdir_p($cookie_dir)) {
                error_log('Google Trends: 无法创建cookie目录: ' . $cookie_dir);
                return false;
            }
            @chmod($cookie_dir, 0755);
        }

        // 检查目录是否可写
        if (!is_writable($cookie_dir)) {
            error_log('Google Trends: cookie目录不可写: ' . $cookie_dir);
            @chmod($cookie_dir, 0755);
            if (!is_writable($cookie_dir)) {
                error_log('Google Trends: 无法修复目录权限');
                return false;
            }
        }

        // 检查cookie文件是否存在且未过期(1小时)
        if (file_exists($this->hybrid_cookie_file)) {
            $file_age = time() - filemtime($this->hybrid_cookie_file);
            if ($file_age < 3600) {
                // 读取已有cookie
                $cookie_content = file_get_contents($this->hybrid_cookie_file);
                if (!empty($cookie_content)) {
                    parse_str(str_replace('; ', '&', $cookie_content), $this->cookie_jar);
                    return true;
                }
            }
        }

        $url = 'https://trends.google.com/trends/';
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Cache-Control' => 'no-cache'
        ];

        $result = $this->makeHybridRequest($url, $headers);

        if ($result['success'] && $result['http_code'] === 200) {
            // 保存cookie到文件
            $cookie_content = '';
            foreach ($this->cookie_jar as $name => $value) {
                $cookie_content .= "{$name}={$value}; ";
            }

            // 使用LOCK_EX防止并发写入，并检查写入结果
            $write_result = file_put_contents($this->hybrid_cookie_file, $cookie_content, LOCK_EX);

            if ($write_result === false) {
                error_log('Google Trends: 无法写入cookie文件: ' . $this->hybrid_cookie_file);
                return false;
            }

            // 设置文件权限为644（所有者可读写，其他人只读）
            @chmod($this->hybrid_cookie_file, 0644);

            return true;
        }

        return false;
    }

    /**
     * 获取Explore数据
     */
    private function getTrendsExploreData_Hybrid($keyword, $geo, $timeRange, $category) {
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
            'tz' => '-480',
            'req' => json_encode($requestData)
        ];

        $url = 'https://trends.google.com/trends/api/explore?' . http_build_query($params);

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Referer' => 'https://trends.google.com/trends/explore?q=' . urlencode($keyword),
            'X-Requested-With' => 'XMLHttpRequest',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin'
        ];

        $result = $this->makeHybridRequest($url, $headers, 3);

        if (!$result['success'] || $result['http_code'] !== 200) {
            $this->last_error = "Trends Explore Failed: Code " . ($result['http_code'] ?? 0);
            return null;
        }

        $body = $result['body'];
        $jsonStart = strpos($body, '{');
        $exploreData = json_decode($jsonStart !== false ? substr($body, $jsonStart) : $body, true);

        if (!$exploreData || !isset($exploreData['widgets'][0]['token'])) {
            $this->last_error = "Trends Explore Token Missing";
            return null;
        }

        return [
            'token' => $exploreData['widgets'][0]['token'],
            'request' => $exploreData['widgets'][0]['request']
        ];
    }

    /**
     * 获取Widget数据
     */
    private function getTrendsWidgetData_Hybrid($requestData, $token) {
        $params = [
            'hl' => 'zh-CN',
            'tz' => '-480',
            'req' => json_encode($requestData),
            'token' => $token
        ];

        $url = 'https://trends.google.com/trends/api/widgetdata/multiline?' . http_build_query($params);

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Referer' => 'https://trends.google.com/trends/explore',
            'X-Requested-With' => 'XMLHttpRequest',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin'
        ];

        $result = $this->makeHybridRequest($url, $headers, 3);

        if (!$result['success'] || $result['http_code'] !== 200) {
            $this->last_error = "Trends Widget Failed: Code " . ($result['http_code'] ?? 0);
            return null;
        }

        $body = $result['body'];
        $jsonStart = strpos($body, '{');
        $decoded = json_decode($jsonStart !== false ? substr($body, $jsonStart) : $body, true);

        if ($decoded === null) {
            $this->last_error = "Trends Widget JSON Decode Failed: " . json_last_error_msg();
            return null;
        }

        return $decoded;
    }

    /**
     * 混合请求方法: 优先WP HTTP API，失败降级到cURL
     */
    private function makeHybridRequest($url, $headers, $maxRetries = 3) {
        // 首先尝试WordPress HTTP API
        if ($this->use_wp_http && function_exists('wp_remote_get')) {
            $result = $this->makeWPHTTPRequest($url, $headers, $maxRetries);

            if ($result['success']) {
                $this->fallback_to_curl = false;
                return $result;
            }

            // WP HTTP失败，标记降级到cURL
            $this->logDebug('WP HTTP API失败，降级到cURL: ' . ($result['error'] ?? 'Unknown'));
            $this->use_wp_http = false;
            $this->fallback_to_curl = true;
        }

        // 使用cURL作为备选
        return $this->makeCurlRequestWithRetry($url, $headers, $maxRetries);
    }

    /**
     * WordPress HTTP API请求
     */
    private function makeWPHTTPRequest($url, $headers, $maxRetries = 3) {
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;
            $this->rateLimit();

            $args = [
                'headers' => $headers,
                'timeout' => 30,
                'sslverify' => false,
                'redirection' => 5,
                'httpversion' => '1.1',
                'cookies' => $this->cookie_jar
            ];

            $response = wp_remote_get($url, $args);

            // 更新cookie jar
            if (!is_wp_error($response)) {
                $cookies = wp_remote_retrieve_cookies($response);
                foreach ($cookies as $cookie) {
                    $this->cookie_jar[$cookie->name] = $cookie->value;
                }
            }

            // 检查响应
            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                $this->logDebug("WP HTTP尝试 {$attempt}/{$maxRetries} 错误: {$error_msg}");

                if ($attempt < $maxRetries) {
                    usleep(1000000 * $attempt); // 指数退避
                }
                continue;
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            // 处理429限流
            if ($http_code === 429) {
                $this->logDebug("WP HTTP尝试 {$attempt}/{$maxRetries} 遇到429限流");
                $this->last_error = "请求过于频繁，正在重试... ({$attempt}/{$maxRetries})";
                if ($attempt < $maxRetries) {
                    // 增加更长的延迟: 5秒、10秒、15秒 + 随机抖动(0-2秒)
                    $delay = (5000000 * $attempt) + random_int(0, 2000000);
                    $this->logDebug("等待 " . round($delay/1000000, 1) . " 秒后重试...");
                    usleep($delay);
                }
                continue;
            }

            // 成功
            if ($http_code === 200) {
                return [
                    'success' => true,
                    'http_code' => $http_code,
                    'body' => $body
                ];
            }

            // 其他HTTP错误
            $this->logDebug("WP HTTP尝试 {$attempt}/{$maxRetries} HTTP {$http_code}");
            if ($attempt < $maxRetries) {
                usleep(1000000);
            }
        }

        return [
            'success' => false,
            'error' => 'Max retries exceeded with WP HTTP API'
        ];
    }

    /**
     * cURL请求 (降级方案)
     */
    private function makeCurlRequestWithRetry($url, $headers, $maxRetries = 3) {
        $attempt = 0;
        $headerArray = [];
        foreach ($headers as $key => $value) {
            $headerArray[] = "{$key}: {$value}";
        }

        while ($attempt < $maxRetries) {
            $attempt++;
            $this->rateLimit();

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_ENCODING => 'gzip, deflate',
                CURLOPT_HTTPHEADER => $headerArray,
                CURLOPT_COOKIEJAR => $this->hybrid_cookie_file,
                CURLOPT_COOKIEFILE => $this->hybrid_cookie_file,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                $this->logDebug("cURL尝试 {$attempt}/{$maxRetries} 错误: {$curl_error}");
                if ($attempt < $maxRetries) {
                    usleep(1000000 * $attempt);
                }
                continue;
            }

            // 处理429限流
            if ($http_code === 429) {
                $this->logDebug("cURL尝试 {$attempt}/{$maxRetries} 遇到429限流");
                $this->last_error = "请求过于频繁，正在重试... ({$attempt}/{$maxRetries})";
                if ($attempt < $maxRetries) {
                    // 增加更长的延迟: 5秒、10秒、15秒 + 随机抖动(0-2秒)
                    $delay = (5000000 * $attempt) + random_int(0, 2000000);
                    $this->logDebug("等待 " . round($delay/1000000, 1) . " 秒后重试...");
                    usleep($delay);
                }
                continue;
            }

            if ($http_code === 200) {
                return [
                    'success' => true,
                    'http_code' => $http_code,
                    'body' => $response
                ];
            }

            $this->logDebug("cURL尝试 {$attempt}/{$maxRetries} HTTP {$http_code}");
            if ($attempt < $maxRetries) {
                usleep(1000000);
            }
        }

        return [
            'success' => false,
            'error' => 'Max retries exceeded with cURL'
        ];
    }

    /**
     * 请求频率限制
     */
    private function rateLimit($minDelayMs = 1000) {
        $elapsed = (microtime(true) - $this->last_request_time) * 1000;
        if ($elapsed < $minDelayMs) {
            usleep(($minDelayMs - $elapsed) * 1000);
        }
        $this->last_request_time = microtime(true);
    }

    /**
     * 调试日志
     */
    private function logDebug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Google Trends Hybrid: ' . $message);
        }
    }

    /**
     * 检查是否已降级到cURL
     */
    public function isFallbackToCurl() {
        return $this->fallback_to_curl;
    }

    /**
     * 重置为使用WP HTTP API
     */
    public function resetToWPHTTP() {
        $this->use_wp_http = true;
        $this->fallback_to_curl = false;
    }

    /**
     * 解析趋势数据为结构化格式
     *
     * @param array $trendData 原始趋势数据
     * @return array 结构化数据包含:
     *   - average_interest: 平均热度 (0-100)
     *   - peak_interest: 最高热度 (0-100)
     *   - lowest_interest: 最低热度 (0-100)
     *   - data_points: 数据点数量
     *   - granularity: 数据粒度 (hourly/daily/weekly/monthly)
     *   - time_range: 时间范围描述
     *   - timeline: 完整时间线数据
     */
    public function parseTrendsDataEnhanced($trendData) {
        if (!$trendData || !isset($trendData['default']['timelineData'])) {
            return null;
        }

        $timeline = $trendData['default']['timelineData'];
        if (empty($timeline)) {
            return null;
        }

        $values = [];
        $parsedTimeline = [];

        foreach ($timeline as $point) {
            $value = $point['value'][0] ?? 0;
            $values[] = $value;

            // Google Trends返回的时间戳已经是秒级，直接使用
            $timestampSec = intval($point['time']);

            $parsedTimeline[] = [
                'timestamp' => $point['time'],
                'date' => date('Y-m-d', $timestampSec),
                'formatted_time' => $point['formattedTime'] ?? '',
                'value' => $value
            ];
        }

        // 计算数据粒度
        $granularity = $this->calculateGranularity($timeline);

        // 计算时间范围
        $startTime = intval($timeline[0]['time']);
        $endTime = intval($timeline[count($timeline) - 1]['time']);
        $timeRange = $this->calculateTimeRange($startTime, $endTime);

        return [
            'average_interest' => round(array_sum($values) / count($values), 2),
            'peak_interest' => max($values),
            'lowest_interest' => min($values),
            'data_points' => count($values),
            'granularity' => $granularity,
            'time_range' => $timeRange,
            'start_date' => date('Y-m-d', $startTime),
            'end_date' => date('Y-m-d', $endTime),
            'timeline' => $parsedTimeline
        ];
    }

    /**
     * 计算数据粒度
     */
    private function calculateGranularity($timeline) {
        if (count($timeline) < 2) return 'unknown';

        // Google Trends返回的时间戳已经是秒级
        $diff = ($timeline[1]['time'] - $timeline[0]['time']); // 秒

        if ($diff < 3600) {
            return 'hourly';
        } elseif ($diff < 86400) {
            return 'daily';
        } elseif ($diff <= 604800) { // 7天 = 604800秒，使用<=包含边界
            return 'weekly';
        } else {
            return 'monthly';
        }
    }

    /**
     * 计算时间范围描述
     */
    private function calculateTimeRange($start, $end) {
        $diff = $end - $start;
        $days = floor($diff / 86400);

        if ($days >= 365) {
            $years = round($days / 365, 1);
            return $years . '年';
        } elseif ($days >= 30) {
            $months = round($days / 30);
            return $months . '个月';
        } elseif ($days >= 7) {
            $weeks = round($days / 7);
            return $weeks . '周';
        } else {
            return $days . '天';
        }
    }
}
