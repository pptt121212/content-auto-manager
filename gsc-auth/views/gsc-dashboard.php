<?php
/**
 * GSC Dashboard View - Premium Traffic Insights
 */
if (!defined('ABSPATH')) {
    exit;
}

$text_domain = 'yali-ai-writer';

$is_authorized = Yali_AI_Writer_GSC_API_Client::is_authorized();
$selected_site = Yali_AI_Writer_GSC_API_Client::get_selected_site();
$refresh_token = get_option('yali_gsc_refresh_token');

if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log("GSC Auth Status: " . ($is_authorized ? 'Yes' : 'No'));
    error_log("GSC Refresh Token exists: " . ($refresh_token ? 'Yes' : 'No'));
}
?>

<!-- DEBUG DATA (Only for ADMIN) -->
<?php if (current_user_can('manage_options') && isset($_GET['debug_gsc'])): ?>
<div style="background:#fefce8; border:1px solid #facc15; padding:15px; margin:20px 0; border-radius:8px;">
    <strong>GSC Diagnostics:</strong> Authorized: <?php echo $is_authorized ? 'YES' : 'NO'; ?> | 
    Token Length: <?php echo strlen($refresh_token); ?> | 
    Site: <?php echo $selected_site ? $selected_site : 'NONE'; ?>
</div>
<?php endif; ?>

<div class="wrap yali-plugin-wrapper yali-gsc-dashboard-wrap">
    
    <!-- Header matching enhanced-dashboard.php -->
    <div class="dashboard-header">
        <div style="display: flex; align-items: center; gap: 20px; position: relative; z-index: 1;">
            <div style="position: relative; display: flex; align-items: center; justify-content: center;">
                <span class="dashicons dashicons-chart-area" style="font-size: 48px; width: 48px; height: 48px; color: #fff;"></span>
            </div>
            <div style="display: flex; flex-direction: column; align-items: flex-start; justify-content: center; text-align: left; padding: 0; margin: 0;">
                <h1 style="margin: 0; padding: 0; line-height: 1.2; font-size: 2.3em; font-weight: 800; color: #ffffff; text-align: left; display: block;">
                    SEO <span class="gradient-text"><?php echo __("增长洞察雷达盘", $text_domain); ?></span>
                </h1>
                <div class="subtitle" style="margin: 10px 0 0 0; padding: 0; color: #ffffff; opacity: 1; font-size: 1.05em; font-weight: 400; text-align: left; display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <span><?php echo __('监测 Google Search Console 流量趋势，发现高潜力"低垂果实"关键词', $text_domain); ?></span>
                </div>
            </div>
        </div>
        
        <div class="yali-gsc-toolbar" style="margin-left: auto; display: flex; align-items: center; gap: 15px;">
            <?php if ($is_authorized): ?>
                <div class="yali-gsc-site-badge" title="<?php echo __("当前绑定的 Google 站点", $text_domain); ?>">
                    <span class="dashicons dashicons-admin-links"></span>
                    <?php echo esc_html($selected_site); ?>
                </div>
                <select id="yali-gsc-date-range" class="yali-form-control yali-btn" style="min-width: 130px; border-radius: 8px;">
                    <option value="7"><?php echo __("过去 7 天", $text_domain); ?></option>
                    <option value="14"><?php echo __("过去 14 天", $text_domain); ?></option>
                    <option value="28" selected><?php echo __("过去 28 天", $text_domain); ?></option>
                    <option value="90"><?php echo __("过去 3 个月", $text_domain); ?></option>
                    <option value="180"><?php echo __("过去 6 个月", $text_domain); ?></option>
                </select>
                <button id="yali-gsc-settings-btn" class="yali-btn yali-btn-secondary" title="<?php echo __("配置排除关键词（品牌词过滤）", $text_domain); ?>">
                    <span class="dashicons dashicons-admin-settings"></span> <?php echo __("排除设置", $text_domain); ?>
                </button>
                <button id="yali-gsc-refresh-btn" class="yali-btn yali-btn-secondary">
                    <span class="dashicons dashicons-update"></span> <?php echo __("刷新数据", $text_domain); ?>
                </button>
                <button id="yali-gsc-disconnect-btn" class="yali-btn yali-btn-danger">
                    <span class="dashicons dashicons-no-alt"></span> <?php echo __("断开连接", $text_domain); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="content-auto-dashboard">
        <?php if (!$is_authorized): ?>
            <div class="yali-gsc-auth-welcome yali-card" style="margin-top: 30px; text-align: center; padding: 60px;">
                <div style="font-size: 64px; margin-bottom: 20px;">⚡</div>
                <h2 style="font-size: 2em; margin-bottom: 20px;"><?php echo __("连接 Google Search Console", $text_domain); ?></h2>
                <p style="max-width: 700px; margin: 0 auto; color: #64748b; line-height: 1.8; font-size: 1.1em;">
                    <?php echo __("整合真实的搜索表现数据，让您的 AI 内容生产拥有\"导航仪\"。", $text_domain); ?>
                    <?php echo __("通过分析点击率和排名，自动识别急需内容补充的潜质页面，将流量转化为实际资产。", $text_domain); ?>
                </p>
                
                <div style="margin-top: 40px;">
                    <a href="<?php echo site_url('/gsc-auth/?action=authorize&return_url=' . urlencode(admin_url('admin.php?page=yali-gsc-dashboard'))); ?>" class="yali-btn yali-btn-primary yali-btn-lg" style="padding: 15px 40px; font-size: 1.25em; border-radius: 30px;">
                        <span class="dashicons dashicons-google" style="margin-right: 8px;"></span> <?php echo __("立即授权 Google 账户", $text_domain); ?>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div id="yali-gsc-auth-success-content">
                <!-- Metrics grid -->
                <div class="yali-gsc-metrics-grid" id="yali-gsc-metrics-container">
                    <div class="metric-card yali-card loading"><p><?php echo __("拉取汇总数据中...", $text_domain); ?></p></div>
                    <div class="metric-card yali-card loading"><p><?php echo __("拉取汇总数据中...", $text_domain); ?></p></div>
                    <div class="metric-card yali-card loading"><p><?php echo __("拉取汇总数据中...", $text_domain); ?></p></div>
                    <div class="metric-card yali-card loading"><p><?php echo __("拉取汇总数据中...", $text_domain); ?></p></div>
                </div>

                <!-- Radar Chart Container -->
                <div class="yali-card" style="padding: 20px; margin-bottom: 25px;">
                    <div style="position: relative; height: 300px; width: 100%;">
                        <canvas id="yali-gsc-radar-chart"></canvas>
                    </div>
                </div>

                <!-- Tabs Section -->
                <div class="yali-gsc-main-tabs yali-card" style="padding: 25px;">
                    <div class="yali-tabs-nav">
                        <div class="yali-tab-item active" data-tab="queries">
                            <span class="dashicons dashicons-search"></span> <?php echo __("关键词表现", $text_domain); ?>
                        </div>
                        <div class="yali-tab-item" data-tab="pages">
                            <span class="dashicons dashicons-admin-page"></span> <?php echo __("页面表现", $text_domain); ?>
                        </div>
                        <div class="yali-tab-item" data-tab="keyword-packs" style="color: #6366f1;">
                            <span class="dashicons dashicons-cloud"></span> <?php echo __("智能词包推荐 (AI-Driven)", $text_domain); ?>
                        </div>
                        <div class="yali-tab-item" data-tab="roi-tracking" style="color: #10b981;">
                            <span class="dashicons dashicons-chart-line"></span> <?php echo __("AI 效果追踪", $text_domain); ?>
                        </div>
                    </div>

                    <div class="yali-tabs-content" style="margin-top: 20px;">
                        <!-- Queries Tab -->
                        <div class="yali-tab-pane active" id="pane-queries">
                            <table class="wp-list-table widefat fixed striped table-view-list" id="table-queries">
                                <thead>
                                    <tr>
                                        <th><?php echo __("关键词 (Query)", $text_domain); ?></th>
                                        <th><?php echo __("点击数", $text_domain); ?></th>
                                        <th><?php echo __("展示数", $text_domain); ?></th>
                                        <th><?php echo __("CTR", $text_domain); ?></th>
                                        <th><?php echo __("平均排名", $text_domain); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="6" style="text-align:center;padding:100px;"><?php echo __("正在获取搜索分析报告...", $text_domain); ?></td></tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pages Tab -->
                        <div class="yali-tab-pane" id="pane-pages">
                            <table class="wp-list-table widefat fixed striped table-view-list" id="table-pages">
                                <thead>
                                    <tr>
                                        <th><?php echo __("页面 URL", $text_domain); ?></th>
                                        <th><?php echo __("点击数", $text_domain); ?></th>
                                        <th><?php echo __("展示数", $text_domain); ?></th>
                                        <th><?php echo __("CTR", $text_domain); ?></th>
                                        <th><?php echo __("平均排名", $text_domain); ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>

                        <!-- Keyword Packs Tab -->
                        <div class="yali-tab-pane" id="pane-keyword-packs">
                            <div class="yali-gsc-packs-grid" id="yali-gsc-packs-container">
                                <div style="text-align:center;padding:100px;">
                                    <span class="dashicons dashicons-update spin" style="font-size: 32px; width: 32px; height: 32px;"></span>
                                    <p style="margin-top: 15px; font-size: 1.1em; color: #64748b;"><?php echo __("正在为您生成量身定制的搜索引擎优化建议，请稍等...", $text_domain); ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- ROI Tracking Tab -->
                        <div class="yali-tab-pane" id="pane-roi-tracking">
                            <div id="yali-gsc-roi-container">
                                <div class="yali-gsc-metrics-grid" id="yali-gsc-roi-summary">
                                    <!-- Summary cards will be injected here -->
                                </div>
                                <div class="yali-card" style="margin-top:20px; padding:20px;">
                                    <h4 style="margin-top:0;"><span class="dashicons dashicons-list-view"></span> <?php echo __("AI 文章流量明细", $text_domain); ?></h4>
                                    <table class="wp-list-table widefat fixed striped table-view-list" id="table-roi-details">
                                        <thead>
                                            <tr>
                                                <th><?php echo __("文章标题", $text_domain); ?></th>
                                                <th><?php echo __("点击数", $text_domain); ?></th>
                                                <th><?php echo __("展示数", $text_domain); ?></th>
                                                <th><?php echo __("CTR", $text_domain); ?></th>
                                                <th><?php echo __("平均排名", $text_domain); ?></th>
                                                <th style="text-align:right;"><?php echo __("操作", $text_domain); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td colspan="6" style="text-align:center;padding:60px;"><?php echo __("点击下方按钮开始分析 AI 文章效果...", $text_domain); ?></td></tr>
                                        </tbody>
                                    </table>
                                    <div style="text-align:center; margin-top:20px;">
                                        <button id="yali-gsc-fetch-roi-btn" class="yali-btn yali-btn-primary">
                                            <span class="dashicons dashicons-performance"></span> <?php echo __("立即同步 AI 效果数据", $text_domain); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
