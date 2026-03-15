(function ($) {
    'use strict';

    // Localize translation function to use plugin's text domain
    const __ = wp.i18n.__;

    var GSC = {
        days: 30,
        currentTab: 'queries',
        metrics: null,
        queries: null,
        pages: null,
        keywordPacks: null,
        roiData: null,
        chartInstance: null
    };

    $(document).ready(function () {
        console.log('GSC Dashboard Ready. SiteUrl:', typeof gscDashboardData !== 'undefined' ? gscDashboardData.siteUrl : 'Undefined');

        if (typeof gscDashboardData === 'undefined') {
            console.error('gscDashboardData is not defined. Asset localization failed.');
            return;
        }

        if ($('#yali-gsc-metrics-container').length > 0) {
            initDashboard();
        }

        bindEvents();
    });

    function initDashboard() {
        if (!gscDashboardData.siteUrl) {
            showSiteSelector();
            return;
        }

        // Initialize Chart
        initChartData();

        loadMetrics();
        loadTabData('queries');
    }

    function showSiteSelector() {
        var $wrap = $('.yali-gsc-dashboard-wrap');
        $wrap.find('.content-auto-dashboard').html('<div class="yali-gsc-site-selector-container">' +
            '<h2><span class="dashicons dashicons-admin-links"></span> ' + wp.i18n.__('选择要管理的 GSC 站点', 'yali-ai-writer') + '</h2>' +
            '<p>' + wp.i18n.__('我们在您的 Google 账号下发现了以下已验证站点，请选择其中一个进行数据分析：', 'yali-ai-writer') + '</p>' +
            '<div id="yali-gsc-sites-list" class="yali-loading-list"><p>' + wp.i18n.__('正在拉取站点列表...', 'yali-ai-writer') + '</p></div>' +
            '</div>');

        $.post(gscDashboardData.ajaxurl, {
            action: 'gsc_get_sites',
            nonce: gscDashboardData.nonce
        }, function (response) {
            if (response.success) {
                var html = '<ul class="yali-site-list">';
                response.data.forEach(function (site) {
                    html += '<li class="site-item" data-site="' + site.siteUrl + '">' +
                        '<span class="site-url">' + site.siteUrl + '</span>' +
                        '<span class="site-perm badge-' + site.permissionLevel + '">' + site.permissionLevel + '</span>' +
                        '</li>';
                });
                html += '</ul>';
                $('#yali-gsc-sites-list').html(html);
            } else {
                $('#yali-gsc-sites-list').html('<div class="error-msg">' + (response.data || wp.i18n.__('获取站点失败', 'yali-ai-writer')) + '</div>');
            }
        }).fail(function () {
            $('#yali-gsc-sites-list').html('<div class="error-msg">' + wp.i18n.__('网络请求失败，请检查网络连接', 'yali-ai-writer') + '</div>');
        });
    }

    // Use event delegation for better reliability
    $(document).on('click', '.site-item', function () {
        var siteUrl = $(this).data('site');
        var $li = $(this);
        $li.addClass('loading').find('.site-url').append(' (' + wp.i18n.__('正在保存...', 'yali-ai-writer') + ')');

        $.post(gscDashboardData.ajaxurl, {
            action: 'gsc_save_site',
            nonce: gscDashboardData.nonce,
            site_url: siteUrl
        }, function (response) {
            if (response.success) {
                window.location.reload();
            } else {
                alert(wp.i18n.__('保存失败: ', 'yali-ai-writer') + response.data);
                $li.removeClass('loading');
            }
        });
    });

    function bindEvents() {
        // Tab switching
        $(document).on('click', '.yali-tab-item', function () {
            var tab = $(this).data('tab');
            if (tab === GSC.currentTab) return;

            GSC.currentTab = tab;

            $('.yali-tab-item').removeClass('active');
            $(this).addClass('active');

            $('.yali-tab-pane').removeClass('active');
            $('#pane-' + tab).addClass('active');

            loadTabData(tab);
        });

        // Refresh data
        $(document).on('click', '#yali-gsc-refresh-btn', function () {
            $(this).find('.dashicons').addClass('spin');
            refreshAllData();
        });

        // Date Range Change
        $(document).on('change', '#yali-gsc-date-range', function () {
            GSC.days = parseInt($(this).val());
            refreshAllData();
        });

        function refreshAllData() {
            GSC.metrics = null;
            GSC.queries = null;
            GSC.pages = null;
            GSC.keywordPacks = null;

            $('.metric-card').addClass('loading').html('<p>' + wp.i18n.__('刷新中...', 'yali-ai-writer') + '</p>');
            initDashboard();
        }

        // Disconnect
        $(document).on('click', '#yali-gsc-disconnect-btn', function () {
            if (confirm(wp.i18n.__('确定要断开 Google Search Console 连接吗？', 'yali-ai-writer'))) {
                $.post(gscDashboardData.ajaxurl, {
                    action: 'gsc_disconnect',
                    nonce: gscDashboardData.nonce
                }, function () {
                    window.location.reload();
                });
            }
        });


        // ROI Tracking Sync Button
        $(document).on('click', '#yali-gsc-fetch-roi-btn', function () {
            loadROIData();
        });

        // Negative Keywords Settings
        $(document).on('click', '#yali-gsc-settings-btn', function () {
            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(gscDashboardData.ajaxurl, {
                action: 'gsc_get_negative_keywords',
                nonce: gscDashboardData.nonce
            }, function (response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    Swal.fire({
                        title: wp.i18n.__('排除关键词设置 (Negative Keywords)', 'yali-ai-writer'),
                        html: '<div style="text-align:left; font-size:14px; color:#64748b; margin-bottom:12px;">' + wp.i18n.__('输入要排除的关键词（如品牌词），每行一个词。包含这些字符的搜索查询将不会被推荐。', 'yali-ai-writer') + '</div>' +
                            '<textarea id="yali-gsc-neg-kws" class="yali-textarea" style="height: 180px; font-family: monospace;" placeholder="' + wp.i18n.__('例如：\n鸭梨\nyali\nkdj', 'yali-ai-writer') + '">' + response.data + '</textarea>',
                        showCancelButton: true,
                        confirmButtonText: wp.i18n.__('保存设置', 'yali-ai-writer'),
                        cancelButtonText: wp.i18n.__('取消', 'yali-ai-writer'),
                        customClass: {
                            container: 'yali-swal-custom',
                            confirmButton: 'yali-btn yali-btn-primary',
                            cancelButton: 'yali-btn yali-btn-secondary'
                        },
                        buttonsStyling: false,
                        preConfirm: function () {
                            return $('#yali-gsc-neg-kws').val();
                        }
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            saveNegativeKeywords(result.value);
                        }
                    });
                }
            });
        });

        function saveNegativeKeywords(keywords) {
            Swal.fire({
                title: wp.i18n.__('正在保存...', 'yali-ai-writer'),
                didOpen: function () {
                    Swal.showLoading();
                },
                allowOutsideClick: false,
                showConfirmButton: false
            });

            $.post(gscDashboardData.ajaxurl, {
                action: 'gsc_save_negative_keywords',
                nonce: gscDashboardData.nonce,
                keywords: keywords
            }, function (response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: wp.i18n.__('保存成功', 'yali-ai-writer'),
                        text: wp.i18n.__('智能词包规则已更新，将在下次刷新数据时生效。', 'yali-ai-writer'),
                        timer: 2000,
                        customClass: {
                            container: 'yali-swal-custom',
                            confirmButton: 'yali-btn yali-btn-primary'
                        },
                        buttonsStyling: false
                    }).then(function () {
                        // Clear cached keyword packs to force re-generation
                        GSC.keywordPacks = null;
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: wp.i18n.__('保存失败', 'yali-ai-writer'),
                        text: response.data,
                        customClass: {
                            container: 'yali-swal-custom',
                            confirmButton: 'yali-btn yali-btn-primary'
                        },
                        buttonsStyling: false
                    });
                }
            });
        }
    }


    function loadMetrics() {
        var $btn = $('#yali-gsc-refresh-btn');
        $btn.prop('disabled', true);

        $.post(gscDashboardData.ajaxurl, {
            action: 'gsc_get_metrics',
            nonce: gscDashboardData.nonce,
            days: GSC.days
        }, function (response) {
            $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
            if (response.success) {
                GSC.metrics = response.data;
                renderMetrics(response.data);
            } else {
                $('#yali-gsc-metrics-container').html('<div class="error-msg" style="grid-column:1/-1">' + (response.data || wp.i18n.__('获取指标失败', 'yali-ai-writer')) + '</div>');
            }
        }).fail(function () {
            $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
            $('#yali-gsc-metrics-container').html('<div class="error-msg" style="grid-column:1/-1">' + wp.i18n.__('获取指标接口异常', 'yali-ai-writer') + '</div>');
        });
    }

    function renderMetrics(data) {
        var $grid = $('#yali-gsc-metrics-container');
        if (!data) return;

        $grid.html([
            renderMetricCard(wp.i18n.__('总点击数', 'yali-ai-writer'), data.total_clicks || 0, '<span class="dashicons dashicons-marker" style="color:#6366f1;"></span>'),
            renderMetricCard(wp.i18n.__('总展示数', 'yali-ai-writer'), formatNumber(data.total_impressions || 0), '<span class="dashicons dashicons-visibility" style="color:#10b981;"></span>'),
            renderMetricCard(wp.i18n.__('平均排名', 'yali-ai-writer'), (data.avg_position || 0).toFixed(1), '<span class="dashicons dashicons-chart-bar" style="color:#f59e0b;"></span>'),
            renderMetricCard(wp.i18n.__('平均点击率', 'yali-ai-writer'), (data.avg_ctr || 0).toFixed(2) + '%', '<span class="dashicons dashicons-chart-line" style="color:#ef4444;"></span>')
        ].join(''));
    }

    function renderMetricCard(label, val, icon) {
        return '<div class="metric-card yali-card">' +
            '<div class="metric-head"><span class="metric-icon">' + icon + '</span> <h3>' + label + '</h3></div>' +
            '<div class="metric-val">' + val + '</div>' +
            '</div>';
    }

    function initChartData() {
        if (typeof Chart === 'undefined') {
            console.error('Chart.js is not loaded.');
            return;
        }

        $.post(gscDashboardData.ajaxurl, {
            action: 'gsc_get_chart_data',
            nonce: gscDashboardData.nonce,
            days: GSC.days
        }, function (response) {
            if (response.success && response.data.length > 0) {
                renderChart(response.data);
            } else {
                $('#yali-gsc-radar-chart').parent().html('<div style="text-align:center; padding: 100px; color: #64748b;">' + wp.i18n.__('暂无可用的趋势数据', 'yali-ai-writer') + '</div>');
            }
        });
    }

    function renderChart(data) {
        var ctx = document.getElementById('yali-gsc-radar-chart');
        if (!ctx) return;

        if (GSC.chartInstance) {
            GSC.chartInstance.destroy();
        }

        var labels = data.map(function (item) {
            return item.keys[0];
        });

        var clicks = data.map(function (item) { return item.clicks; });
        var impressions = data.map(function (item) { return item.impressions; });
        var ctr = data.map(function (item) { return parseFloat((item.ctr * 100).toFixed(2)); });
        var position = data.map(function (item) { return parseFloat(item.position.toFixed(1)); });

        ctx.style.height = '320px';
        ctx.style.width = '100%';

        GSC.chartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: wp.i18n.__('点击量 (Clicks)', 'yali-ai-writer'),
                        data: clicks,
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        yAxisID: 'y'
                    },
                    {
                        label: wp.i18n.__('展示量 (Impressions)', 'yali-ai-writer'),
                        data: impressions,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.05)',
                        borderWidth: 2,
                        tension: 0.4,
                        yAxisID: 'y1'
                    },
                    {
                        label: wp.i18n.__('点击率 (CTR %)', 'yali-ai-writer'),
                        data: ctr,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.05)',
                        borderWidth: 2,
                        tension: 0.4,
                        yAxisID: 'y2'
                    },
                    {
                        label: wp.i18n.__('平均排名 (Position)', 'yali-ai-writer'),
                        data: position,
                        borderColor: '#ec4899',
                        backgroundColor: 'rgba(236, 72, 153, 0.05)',
                        borderWidth: 2,
                        tension: 0.4,
                        yAxisID: 'y3'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        enabled: true
                    }
                },
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: { display: true, text: wp.i18n.__('点击量', 'yali-ai-writer') },
                        grid: { borderDash: [5, 5] },
                        beginAtZero: true
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: { display: true, text: wp.i18n.__('展示量', 'yali-ai-writer') },
                        grid: { display: false },
                        beginAtZero: true
                    },
                    y2: {
                        type: 'linear',
                        display: false,
                        position: 'left',
                        beginAtZero: true
                    },
                    y3: {
                        type: 'linear',
                        display: false,
                        position: 'right',
                        reverse: true
                    }
                }
            }
        });
    }

    function loadTabData(tab) {
        if (tab === 'keyword-packs') {
            loadKeywordPacks();
            return;
        }

        if (tab === 'roi-tracking') {
            if (GSC.roiData) renderROIDashboard(GSC.roiData);
            return;
        }

        if (GSC[tab] !== null) return; // Use cached

        var $tbody = $('#table-' + tab + ' tbody');
        $tbody.html('<tr><td colspan="6" style="text-align:center;padding:100px;"><span class="dashicons dashicons-update spin"></span> ' + wp.i18n.__('正在拉取数据...', 'yali-ai-writer') + '</td></tr>');

        $.post(gscDashboardData.ajaxurl, {
            action: 'gsc_get_data',
            nonce: gscDashboardData.nonce,
            dimension: tab === 'queries' ? 'query' : 'page',
            days: GSC.days
        }, function (response) {
            if (response.success) {
                GSC[tab] = response.data;
                renderTable(tab, response.data);
            } else {
                $tbody.html('<tr><td colspan="6" style="text-align:center;color:#ef4444;padding:40px;">' + (response.data || wp.i18n.__('获取数据失败', 'yali-ai-writer')) + '</td></tr>');
            }
        }).fail(function () {
            $tbody.html('<tr><td colspan="6" style="text-align:center;color:#ef4444;padding:40px;">' + wp.i18n.__('数据分析接口异常', 'yali-ai-writer') + '</td></tr>');
        });
    }

    function renderTable(tab, rows) {
        var $tbody = $('#table-' + tab + ' tbody');
        if (!rows || rows.length === 0) {
            $tbody.html('<tr><td colspan="6" style="text-align:center;padding:100px;">' + wp.i18n.__('该期间内暂无活跃数据', 'yali-ai-writer') + '</td></tr>');
            return;
        }

        var html = '';
        rows.forEach(function (row) {
            var val = row.keys[0];
            var displayContext = '';

            // For queries, display mapped AI article title if available
            if (tab === 'queries') {
                if (row.local_title) {
                    displayContext = '<div style="font-size:0.85em; margin-top:4px;"><span class="dashicons dashicons-admin-post" style="font-size:14px;width:14px;height:14px;"></span> ' + wp.i18n.__('关联AI文章:', 'yali-ai-writer') + ' <a href="' + row.edit_link + '" target="_blank">' + escapeHtml(row.local_title) + '</a></div>';
                } else if (row.url) {
                    displayContext = '<div style="font-size:0.85em; margin-top:4px; color:#94a3b8;"><span class="dashicons dashicons-admin-links" style="font-size:14px;width:14px;height:14px;"></span> ' + row.url + '</div>';
                }
            }

            html += '<tr>' +
                '<td><div style="font-weight:600; color:#1e293b;">' + (tab === 'queries' ? val : '<a href="' + val + '" target="_blank">' + val + '</a>') + '</div>' + displayContext + '</td>' +
                '<td>' + row.clicks + '</td>' +
                '<td>' + row.impressions + '</td>' +
                '<td>' + (row.ctr * 100).toFixed(2) + '%</td>' +
                '<td>' + row.position.toFixed(1) + '</td>' +
                '</tr>';
        });
        $tbody.html(html);
    }

    function loadKeywordPacks() {
        if (GSC.keywordPacks !== null) return;

        var $grid = $('#yali-gsc-packs-container');
        $grid.html('<div style="text-align:center;padding:100px;"><span class="dashicons dashicons-update spin"></span> ' + wp.i18n.__('正在为您生成智能词包建议...', 'yali-ai-writer') + '</div>');

        $.post(gscDashboardData.ajaxurl, {
            action: 'gsc_get_keyword_packs',
            nonce: gscDashboardData.nonce,
            days: GSC.days
        }, function (response) {
            if (response.success) {
                GSC.keywordPacks = response.data;
                renderKeywordPacks(response.data);
            } else {
                $grid.html('<div style="text-align:center;padding:40px;color:#ef4444;">' + wp.i18n.__('词包生成器报错: ', 'yali-ai-writer') + response.data + '</div>');
            }
        }).fail(function () {
            $grid.html('<div style="text-align:center;padding:40px;color:#ef4444;">' + wp.i18n.__('词包生成接口通讯异常', 'yali-ai-writer') + '</div>');
        });
    }

    function renderKeywordPacks(packs) {
        var $grid = $('#yali-gsc-packs-container');
        if (!packs || packs.length === 0) {
            $grid.html('<div style="text-align:center;padding:40px;">' + wp.i18n.__('分析完成，但未发现符合特定优化条件的潜力词包。', 'yali-ai-writer') + '</div>');
            return;
        }

        var html = '';
        // Sorting: Core (1) > Rankup (2) > Traffic (3) > Longtail (4)
        var orderMap = { 'core': 1, 'rankup': 2, 'traffic': 3, 'longtail': 4 };

        // Ensure array for sorting
        if (!Array.isArray(packs)) packs = Object.values(packs);

        packs.sort(function (a, b) {
            var orderA = orderMap[a.id] || 99;
            var orderB = orderMap[b.id] || 99;
            return orderA - orderB;
        });

        packs.forEach(function (pack) {
            if (!pack.keywords || pack.keywords.length === 0) return;

            var mainButton = '';
            var secondaryButton = '';

            if (pack.action === 'mine') {
                var seedKeyword = pack.keywords[0] ? pack.keywords[0].keyword : '';
                mainButton = '<button class="yali-btn yali-btn-secondary yali-gsc-mine-task" data-pack-id="' + (pack.id || '') + '"><span class="dashicons dashicons-search"></span> ' + wp.i18n.__('点击挖掘关键词', 'yali-ai-writer') + '</button>';
                secondaryButton = '<button class="yali-btn yali-btn-primary yali-gsc-discard-pack" data-pack-id="' + (pack.id || '') + '" data-keyword="' + escapeHtml(seedKeyword) + '" style="background:#f1f5f9; color:#ef4444; border-color:#e2e8f0;">' + wp.i18n.__('删除推荐', 'yali-ai-writer') + '</button>';
            } else {
                mainButton = '<button class="yali-btn yali-btn-secondary yali-gsc-create-task" data-pack-id="' + (pack.id || '') + '">' + wp.i18n.__('一键规划任务', 'yali-ai-writer') + '</button>';
                secondaryButton = '<button class="yali-btn yali-btn-primary yali-gsc-view-pack" data-pack-id="' + (pack.id || '') + '">' + wp.i18n.__('查看词单', 'yali-ai-writer') + '</button>';
            }

            html += '<div class="yali-pack-card">' +
                '<div class="pack-badge">' + pack.badge + '</div>' +
                '<div class="pack-head"><span class="pack-icon">' + pack.strategy_icon + '</span> <h3>' + pack.name + '</h3></div>' +
                '<p class="pack-desc">' + pack.desc + '</p>' +
                '<div class="pack-stats">' + wp.i18n.__('包含', 'yali-ai-writer') + ' <strong>' + pack.keywords.length + '</strong> ' + wp.i18n.__('个高潜力词', 'yali-ai-writer') + '</div>' +
                '<div class="pack-footer">' +
                secondaryButton +
                mainButton +
                '</div>' +
                '</div>';
        });

        if (html === '') {
            $grid.html('<div style="text-align:center;padding:40px;">' + wp.i18n.__('暂无可用的推荐词包。', 'yali-ai-writer') + '</div>');
        } else {
            $grid.html(html);
        }
    }

    function loadROIData() {
        var $btn = $('#yali-gsc-fetch-roi-btn');
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + wp.i18n.__('正在对撞分析中...', 'yali-ai-writer') + '');

        $.post(gscDashboardData.ajaxurl, {
            action: 'gsc_get_ai_roi_data',
            nonce: gscDashboardData.nonce,
            days: GSC.days
        }, function (response) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-performance"></span> ' + wp.i18n.__('重新同步 AI 效果数据', 'yali-ai-writer') + '');
            if (response.success) {
                GSC.roiData = response.data;
                renderROIDashboard(response.data);
            } else {
                alert(wp.i18n.__('ROI 分析失败: ', 'yali-ai-writer') + response.data);
            }
        });
    }

    function renderROIDashboard(data) {
        if (!data || !data.summary) return;

        var s = data.summary;
        // Render summary cards
        $('#yali-gsc-roi-summary').html(
            renderMetricCard(wp.i18n.__('已发布 AI 文章', 'yali-ai-writer'), s.total_ai_articles + wp.i18n.__(' 篇', 'yali-ai-writer'), '<span class="dashicons dashicons-edit" style="color:#8b5cf6;"></span>') +
            renderMetricCard(wp.i18n.__('产生排名的文章', 'yali-ai-writer'), s.ranking_article_count + wp.i18n.__(' 篇', 'yali-ai-writer') + ' <span style="font-size:0.5em;color:#64748b;">(' + wp.i18n.__('未排', 'yali-ai-writer') + ':' + s.unranked_article_count + ')</span>', '<span class="dashicons dashicons-yes-alt" style="color:#10b981;"></span>') +
            renderMetricCard(wp.i18n.__('带来点击的文章', 'yali-ai-writer'), s.articles_with_clicks + wp.i18n.__(' 篇', 'yali-ai-writer'), '<span class="dashicons dashicons-marker" style="color:#6366f1;"></span>') +
            renderMetricCard(wp.i18n.__('产生展现的文章', 'yali-ai-writer'), s.articles_with_impressions + wp.i18n.__(' 篇', 'yali-ai-writer'), '<span class="dashicons dashicons-visibility" style="color:#3b82f6;"></span>') +
            renderMetricCard(wp.i18n.__('累计贡献流量', 'yali-ai-writer'), s.total_clicks + wp.i18n.__(' 次点击', 'yali-ai-writer'), '<span class="dashicons dashicons-performance" style="color:#ec4899;"></span>') +
            renderMetricCard(wp.i18n.__('平均搜索排名', 'yali-ai-writer'), s.avg_position > 0 ? s.avg_position : '-', '<span class="dashicons dashicons-chart-bar" style="color:#f59e0b;"></span>')
        );

        // Render Strategy Summary (ROI)
        var strategyHtml = '';
        if (data.strategy_summary) {
            strategyHtml += '<h4 style="margin-top:30px; margin-bottom:15px; font-weight: 600; font-size: 1.1em; color: #1e293b;"><span class="dashicons dashicons-networking"></span> ' + wp.i18n.__('各类词包战略追踪 (ROI)', 'yali-ai-writer') + '</h4>';
            strategyHtml += '<div class="yali-gsc-metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">';

            Object.keys(data.strategy_summary).forEach(function (key) {
                var strat = data.strategy_summary[key];
                if (strat.articles > 0 || strat.historical_impressions > 0) {
                    var title = strat.name;
                    var content = '<div style="font-size:14px; color:#475569; padding-top: 5px;">' +
                        '<div style="margin-bottom: 4px;">' + wp.i18n.__('产出文章', 'yali-ai-writer') + ': <strong style="color:#1e293b;">' + strat.articles + '</strong> ' + wp.i18n.__('篇', 'yali-ai-writer') + '</div>' +
                        '<div style="margin-bottom: 4px;">' + wp.i18n.__('实际展示', 'yali-ai-writer') + ': <strong style="color:#10b981;">' + formatNumber(strat.impressions) + '</strong></div>' +
                        '<div>' + wp.i18n.__('实际点击', 'yali-ai-writer') + ': <strong style="color:#3b82f6;">' + strat.clicks + '</strong></div>' +
                        '</div>';
                    var icon = '<span class="dashicons dashicons-analytics" style="color:#f59e0b;"></span>';
                    strategyHtml += renderMetricCard(title, content, icon);
                }
            });
            strategyHtml += '</div>';

            if ($('#yali-gsc-strategy-roi').length === 0) {
                $('<div id="yali-gsc-strategy-roi"></div>').insertAfter('#yali-gsc-roi-summary');
            }
            $('#yali-gsc-strategy-roi').html(strategyHtml);
        }

        // Render details table
        var $tbody = $('#table-roi-details tbody');
        if (!data.details || data.details.length === 0) {
            $tbody.html('<tr><td colspan="6" style="text-align:center;padding:60px;">' + wp.i18n.__('暂未发现有流量产生的 AI 文章，建议继续保持发文频率。', 'yali-ai-writer') + '</td></tr>');
            return;
        }

        var html = '';
        data.details.forEach(function (row) {
            var titleLink = row.edit_link ? '<a href="' + row.edit_link + '" target="_blank" style="color:#1e293b; text-decoration:none;">' + escapeHtml(row.title) + '</a>' : escapeHtml(row.title);
            var badge = row.is_strategy ? ' <span style="background-color: #dbeafe; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-left: 5px; vertical-align: top;">' + wp.i18n.__('来自词包', 'yali-ai-writer') + '</span>' : '';
            html += '<tr>' +
                '<td>' +
                '<div style="font-weight:600; color:#1e293b; margin-bottom:4px;">' + titleLink + badge + '</div>' +
                '<div style="font-size:0.85em; color:#64748b;"><a href="' + row.url + '" target="_blank" style="color:#64748b;">' + row.url + '</a></div>' +
                '</td>' +
                '<td><strong>' + row.clicks + '</strong></td>' +
                '<td>' + formatNumber(row.impressions) + '</td>' +
                '<td>' + row.ctr + '%</td>' +
                '<td>' + row.position + '</td>' +
                '<td style="text-align:right;">' +
                '<a href="' + row.url + '" target="_blank" class="yali-btn yali-btn-xs yali-btn-secondary">' + wp.i18n.__('查看页面', 'yali-ai-writer') + '</a>' +
                (row.edit_link ? ' <a href="' + row.edit_link + '" target="_blank" class="yali-btn yali-btn-xs yali-btn-primary">' + wp.i18n.__('编辑文章', 'yali-ai-writer') + '</a>' : '') +
                '</td>' +
                '</tr>';
        });
        $tbody.html(html);

        // Render Dead Capacity Section (Proposal 1 Upgrade)
        if (data.dead_capacity && data.dead_capacity.length > 0) {
            var deadHtml = '<h4 style="margin-top:40px; margin-bottom:15px; font-weight: 600; font-size: 1.1em; color: #ef4444;"><span class="dashicons dashicons-warning"></span> ' + wp.i18n.__('落后产能清洗区 (部署超 14 天零流量)', 'yali-ai-writer') + '</h4>';
            deadHtml += '<p style="color:#64748b; font-size:13px; margin-bottom: 15px;">' + wp.i18n.__('以下关键词当时是以高潜力词被部署，但发文超过 14 天后依然获取不到任何 GSC 展示。建议对其进行 <strong>重写</strong> 或 <strong>301 重定向合并</strong> 以回收权重。', 'yali-ai-writer') + '</p>';

            deadHtml += '<table class="yali-table" style="width:100%; border-collapse:collapse; text-align:left; font-size:14px; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.1); border-radius:8px; overflow:hidden;">';
            deadHtml += '<thead><tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">' +
                '<th style="padding:12px; font-weight:600; color:#475569;">' + wp.i18n.__('目标核心词', 'yali-ai-writer') + '</th>' +
                '<th style="padding:12px; font-weight:600; color:#475569;">' + wp.i18n.__('已发文章', 'yali-ai-writer') + '</th>' +
                '<th style="padding:12px; font-weight:600; color:#475569;">' + wp.i18n.__('上线时长', 'yali-ai-writer') + '</th>' +
                '<th style="padding:12px; font-weight:600; color:#475569;">' + wp.i18n.__('预期日均搜索', 'yali-ai-writer') + '</th>' +
                '<th style="padding:12px; font-weight:600; color:#475569; text-align:right;">' + wp.i18n.__('建议动作', 'yali-ai-writer') + '</th>' +
                '</tr></thead><tbody>';

            data.dead_capacity.forEach(function (row) {
                var titleLink = row.edit_link ? '<a href="' + row.edit_link + '" target="_blank" style="color:#1e293b; text-decoration:none;">' + escapeHtml(row.title) + '</a>' : escapeHtml(row.title);
                deadHtml += '<tr style="border-bottom:1px solid #e2e8f0; transition:background 0.2s;">' +
                    '<td style="padding:12px; border-bottom:1px solid #f1f5f9; color:#ef4444; font-weight:600;">' + escapeHtml(row.keyword) + '</td>' +
                    '<td style="padding:12px; border-bottom:1px solid #f1f5f9;"><div style="font-weight:600; color:#1e293b; margin-bottom:4px; max-width:300px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + titleLink + '</div><a href="' + row.url + '" target="_blank" style="color:#64748b; font-size:12px;">' + wp.i18n.__('查看线上', 'yali-ai-writer') + '</a></td>' +
                    '<td style="padding:12px; border-bottom:1px solid #f1f5f9;">' + row.days_deployed + ' ' + wp.i18n.__('天', 'yali-ai-writer') + '</td>' +
                    '<td style="padding:12px; border-bottom:1px solid #f1f5f9;">' + formatNumber(row.historical_impressions) + '</td>' +
                    '<td style="padding:12px; border-bottom:1px solid #f1f5f9; text-align:right;">' +
                    (row.edit_link ? '<a href="' + row.edit_link + '" target="_blank" class="yali-btn yali-btn-xs" style="border-color:#ef4444; color:#ef4444;">' + wp.i18n.__('编辑重写', 'yali-ai-writer') + '</a>' : '') +
                    '</td>' +
                    '</tr>';
            });

            deadHtml += '</tbody></table>';

            if ($('#yali-gsc-dead-capacity').length === 0) {
                $('<div id="yali-gsc-dead-capacity"></div>').insertAfter('#table-roi-details');
            }
            $('#yali-gsc-dead-capacity').html(deadHtml);
        } else {
            if ($('#yali-gsc-dead-capacity').length > 0) {
                $('#yali-gsc-dead-capacity').empty();
            }
        }
    }

    function formatNumber(num) {
        if (num >= 1000) return (num / 1000).toFixed(1) + 'k';
        return num;
    }

    function escapeHtml(text) {
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, function (m) { return map[m]; });
    }

    // Modal behavior for viewing keywords
    $(document).on('click', '.yali-gsc-view-pack', function () {
        var id = $(this).data('pack-id');
        // Find the pack in the array (because packs is an array now)
        var pack = GSC.keywordPacks.find(function (p) { return p.id === id; });
        if (!pack) {
            console.error("Pack ID not found: " + id, GSC.keywordPacks);
            return;
        }

        var list = '<div class="yali-keywords-scroll"><ul>';
        pack.keywords.slice(0, 100).forEach(function (k) {
            list += '<li style="margin-bottom: 6px; padding-bottom: 6px; border-bottom: 1px dashed #cbd5e1;"><span style="color:#3b82f6; font-weight:600;">' + escapeHtml(k.keyword) + '</span> <span style="color:#64748b; font-size:0.85em; margin-left:10px;">(' + wp.i18n.__('曝光:', 'yali-ai-writer') + ' ' + formatNumber(k.impressions) + ', ' + wp.i18n.__('排名:', 'yali-ai-writer') + ' ' + k.position.toFixed(1) + ')</span></li>';
        });
        list += '</ul></div>';

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: pack.name,
                html: list,
                width: 650,
                confirmButtonText: wp.i18n.__('关闭窗口', 'yali-ai-writer'),
                customClass: {
                    container: 'yali-swal-custom',
                    confirmButton: 'yali-btn yali-btn-primary'
                },
                buttonsStyling: false
            });
        } else {
            alert(pack.name + "\n(由于缺少UI库，只能在控制台打印词表)\n请按F12查看。");
            console.log(pack.keywords);
        }
    });

    // Discard Pack Logic
    $(document).on('click', '.yali-gsc-discard-pack', function () {
        var $btn = $(this);
        var keyword = $btn.data('keyword');
        var packId = $btn.data('pack-id');
        var $card = $btn.closest('.yali-pack-card');

        Swal.fire({
            title: wp.i18n.__('确认删除此推荐？', 'yali-ai-writer'),
            text: wp.i18n.__('此操作将把该词加入已处理列表，以后系统会自动过滤该母词及其卡片，不再重复推荐。', 'yali-ai-writer'),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: wp.i18n.__('确定删除', 'yali-ai-writer'),
            cancelButtonText: wp.i18n.__('取消', 'yali-ai-writer'),
            confirmButtonColor: '#ef4444',
            customClass: {
                container: 'yali-swal-custom',
                confirmButton: 'yali-btn yali-btn-primary',
                cancelButton: 'yali-btn yali-btn-secondary'
            },
            buttonsStyling: false
        }).then(function (result) {
            if (result.isConfirmed) {
                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span>');

                $.post(gscDashboardData.ajaxurl, {
                    action: 'gsc_discard_pack',
                    nonce: gscDashboardData.nonce,
                    keyword: keyword,
                    pack_id: packId
                }, function (response) {
                    if (response.success) {
                        $card.fadeOut(300, function () {
                            $(this).remove();
                            if ($('.yali-pack-card').length === 0) {
                                $('#yali-gsc-packs-container').html('<div style="text-align:center;padding:40px;">' + wp.i18n.__('推荐已全部处理。', 'yali-ai-writer') + '</div>');
                            }
                        });

                        Swal.fire({
                            icon: 'success',
                            title: wp.i18n.__('已删除', 'yali-ai-writer'),
                            text: response.data,
                            timer: 2000,
                            showConfirmButton: false,
                            customClass: {
                                container: 'yali-swal-custom'
                            }
                        });
                    } else {
                        $btn.prop('disabled', false).text(wp.i18n.__('删除推荐', 'yali-ai-writer'));
                        Swal.fire({
                            icon: 'error',
                            title: wp.i18n.__('删除失败', 'yali-ai-writer'),
                            text: response.data,
                            customClass: {
                                container: 'yali-swal-custom',
                                confirmButton: 'yali-btn yali-btn-primary'
                            },
                            buttonsStyling: false
                        });
                    }
                });
            }
        });
    });

    // Create Task from Pack
    $(document).on('click', '.yali-gsc-create-task', function () {
        var id = $(this).data('pack-id');
        var pack = GSC.keywordPacks.find(function (p) { return p.id === id; });
        var $btn = $(this);

        if (!pack || !pack.keywords || pack.keywords.length === 0) return;

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: wp.i18n.__('确认', 'yali-ai-writer') + wp.i18n.__('一键规划任务', 'yali-ai-writer'),
                text: wp.i18n.__('将为这 ', 'yali-ai-writer') + pack.keywords.length + wp.i18n.__(' 个关键词自动创建自动写手规则（循环1次）？包含特殊字符的关键词也已被安全兼容。', 'yali-ai-writer'),
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: wp.i18n.__('立即创建', 'yali-ai-writer'),
                cancelButtonText: wp.i18n.__('取消', 'yali-ai-writer'),
                customClass: {
                    container: 'yali-swal-custom',
                    confirmButton: 'yali-btn yali-btn-primary',
                    cancelButton: 'yali-btn yali-btn-secondary'
                },
                buttonsStyling: false
            }).then(function (result) {
                if (result.isConfirmed) {
                    processTaskImport(id, pack.keywords, $btn);
                }
            });
        } else {
            if (confirm('确定要将这些关键词' + wp.i18n.__('一键规划任务', 'yali-ai-writer') + '吗？')) {
                processTaskImport(id, pack.keywords, $btn);
            }
        }
    });

    // Mine Keywords from Short-Tail Pack
    $(document).on('click', '.yali-gsc-mine-task', function () {
        var id = $(this).data('pack-id');
        var pack = GSC.keywordPacks.find(function (p) { return p.id === id; });
        var $btn = $(this);

        if (!pack || !pack.keywords || pack.keywords.length === 0) return;

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: wp.i18n.__('深度挖掘长尾词', 'yali-ai-writer'),
                text: wp.i18n.__('将对 ', 'yali-ai-writer') + pack.keywords.length + wp.i18n.__(' 个大流量短词进行裂变挖掘（百度、谷歌API），请耐心等待。', 'yali-ai-writer'),
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: wp.i18n.__('开始挖掘', 'yali-ai-writer'),
                cancelButtonText: wp.i18n.__('取消', 'yali-ai-writer'),
                customClass: {
                    container: 'yali-swal-custom',
                    confirmButton: 'yali-btn yali-btn-primary',
                    cancelButton: 'yali-btn yali-btn-secondary'
                },
                buttonsStyling: false
            }).then(function (result) {
                if (result.isConfirmed) {
                    processMining(id, pack.keywords, $btn);
                }
            });
        } else {
            if (confirm(wp.i18n.__('将对这些短词进行裂变挖掘，大约需要几十秒，确定开始吗？', 'yali-ai-writer'))) {
                processMining(id, pack.keywords, $btn);
            }
        }
    });

    function processMining(packId, keywords, $btn) {
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + wp.i18n.__('正在初始化...', 'yali-ai-writer') + '');

        const sessionId = Math.random().toString(36).substring(2, 15);
        const sources = ['default', 'baidu', 'duckduckgo'];
        const allTasks = [];

        keywords.forEach(kw => {
            const seed = typeof kw === 'string' ? kw : kw.keyword;
            sources.forEach(src => {
                allTasks.push({
                    keyword: seed,
                    source: src,
                    step_type: 'base',
                    description: (src === 'default' ? wp.i18n.__('谷歌', 'yali-ai-writer') : (src === 'baidu' ? wp.i18n.__('百度', 'yali-ai-writer') : 'DuckDuckGo')) + ': ' + seed
                });
            });
        });

        const totalSteps = allTasks.length;
        let completedSteps = 0;
        let activeTasks = 0;
        let stepPointer = 0;
        const CONCURRENCY_LIMIT = 3;
        let hasError = false;

        // Show Progress Modal
        Swal.fire({
            title: wp.i18n.__('正在深度快挖关键词...', 'yali-ai-writer'),
            html: `
                <div class="yali-progress-container" style="margin-top: 20px;">
                    <div class="yali-progress-bar-wrapper" style="background: #e2e8f0; border-radius: 10px; height: 20px; overflow: hidden; position: relative;">
                        <div id="yali-mining-progress" style="background: linear-gradient(90deg, #3b82f6, #60a5fa); width: 0%; height: 100%; transition: width 0.3s ease;"></div>
                    </div>
                    <div id="yali-mining-status" style="margin-top: 10px; font-size: 13px; color: #64748b; text-align: left;">${wp.i18n.__('准备中...', 'yali-ai-writer')}</div>
                </div>
                <p style="font-size: 12px; color: #94a3b8; margin-top: 15px;">${wp.i18n.__('正在模拟真实用户访问搜寻引擎，请勿关闭窗口。', 'yali-ai-writer')}</p>
            `,
            allowOutsideClick: false,
            showConfirmButton: false,
            width: 500,
            didOpen: () => {
                const progressBar = $('#yali-mining-progress');
                const statusText = $('#yali-mining-status');
                const activeSources = new Set();

                function runNext() {
                    if (hasError || stepPointer >= totalSteps) {
                        if (activeTasks === 0 && !hasError) {
                            finalizeMining();
                        }
                        return;
                    }

                    while (activeTasks < CONCURRENCY_LIMIT && stepPointer < totalSteps) {
                        const task = allTasks[stepPointer++];
                        activeTasks++;
                        activeSources.add(task.source);

                        updateStatus();

                        $.post(gscDashboardData.ajaxurl, {
                            action: 'gsc_segmented_mine',
                            nonce: gscDashboardData.nonce,
                            keyword: task.keyword,
                            session_id: sessionId,
                            data_source: task.source,
                            step_type: task.step_type,
                            lang_specifics: 'cn-zh-CN'
                        }, function (res) {
                            activeTasks--;
                            activeSources.delete(task.source);

                            if (res.success) {
                                completedSteps++;
                                const progress = Math.round((completedSteps / totalSteps) * 100);
                                progressBar.css('width', progress + '%');
                                runNext();
                            } else {
                                hasError = true;
                                Swal.fire({
                                    icon: 'error',
                                    title: wp.i18n.__('挖掘步骤失败', 'yali-ai-writer'),
                                    text: res.data || wp.i18n.__('未知错误', 'yali-ai-writer'),
                                    customClass: {
                                        container: 'yali-swal-custom',
                                        confirmButton: 'yali-btn yali-btn-primary'
                                    },
                                    buttonsStyling: false
                                });
                                $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> ' + wp.i18n.__('点击挖掘关键词', 'yali-ai-writer') + '');
                            }
                        }).fail(function () {
                            activeTasks--;
                            activeSources.delete(task.source);
                            if (hasError) return;
                            hasError = true;
                            Swal.fire({
                                icon: 'error',
                                title: wp.i18n.__('并发请求异常', 'yali-ai-writer'),
                                text: wp.i18n.__('网络连接超时或服务器繁忙，请稍后重试。', 'yali-ai-writer'),
                                customClass: {
                                    container: 'yali-swal-custom',
                                    confirmButton: 'yali-btn yali-btn-primary'
                                },
                                buttonsStyling: false
                            });
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> ' + wp.i18n.__('点击挖掘关键词', 'yali-ai-writer') + '');
                        });
                    }
                }

                function updateStatus() {
                    const engineMap = { 'default': wp.i18n.__('谷歌', 'yali-ai-writer'), 'baidu': wp.i18n.__('百度', 'yali-ai-writer'), 'duckduckgo': 'DuckDuckGo' };
                    const currentEngines = Array.from(activeSources).map(s => engineMap[s] || s).join(', ');
                    statusText.text('[' + currentEngines + '] ' + wp.i18n.__('正在挖掘', 'yali-ai-writer') + ' (' + completedSteps + '/' + totalSteps + ')');
                }

                function finalizeMining() {
                    statusText.text(wp.i18n.__('正在深度查重并合并结果...', 'yali-ai-writer'));
                    progressBar.css('width', '100%');

                    $.post(gscDashboardData.ajaxurl, {
                        action: 'gsc_finalize_mine',
                        nonce: gscDashboardData.nonce,
                        keywords_json: JSON.stringify(keywords),
                        session_id: sessionId
                    }, function (res) {
                        if (res.success && res.data.mined_keywords) {
                            displayResults(res.data.mined_keywords, res.data.message);
                        } else {
                            Swal.fire({
                                icon: 'info',
                                title: wp.i18n.__('挖掘完成', 'yali-ai-writer'),
                                text: res.data || wp.i18n.__('未发现增量新词。', 'yali-ai-writer'),
                                customClass: {
                                    container: 'yali-swal-custom',
                                    confirmButton: 'yali-btn yali-btn-primary'
                                },
                                buttonsStyling: false
                            });
                            $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> ' + wp.i18n.__('点击挖掘关键词', 'yali-ai-writer') + '');
                        }
                    }).fail(function () {
                        Swal.fire({
                            icon: 'error',
                            title: wp.i18n.__('收尾失败', 'yali-ai-writer'),
                            text: wp.i18n.__('合并结果时发生异常，请检查网络。', 'yali-ai-writer'),
                            customClass: {
                                container: 'yali-swal-custom',
                                confirmButton: 'yali-btn yali-btn-primary'
                            },
                            buttonsStyling: false
                        });
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> ' + wp.i18n.__('点击挖掘关键词', 'yali-ai-writer') + '');
                    });
                }

                runNext();
            }
        });

        function displayResults(minedList, message) {
            var htmlContent = '<p>' + message + '</p>';
            htmlContent += '<div class="yali-keywords-scroll">';

            var newCount = 0;
            var usedCount = 0;

            minedList.forEach(function (item, index) {
                var kw = item.keyword || item;
                var isUsed = item.used === true;
                var isHistorical = item.is_historical === true;

                var id = 'mined_kw_gsc_' + index;
                var styleColor = isUsed ? '#94a3b8' : '#3b82f6';
                var badge = isHistorical ? ' <span style="font-size:10px; background:#e2e8f0; color:#475569; padding:2px 6px; border-radius:4px; margin-left:5px;">' + wp.i18n.__('本词包已使用', 'yali-ai-writer') + '</span>' : (isUsed ? ' <span style="font-size:10px; background:#e2e8f0; color:#475569; padding:2px 6px; border-radius:4px; margin-left:5px;">' + wp.i18n.__('已被部署过', 'yali-ai-writer') + '</span>' : '');
                var checkedAttr = isUsed ? 'disabled' : 'checked';

                if (isUsed) { usedCount++; } else { newCount++; }

                htmlContent += '<div style="margin-bottom: 8px; display:flex; align-items:center;">';
                htmlContent += '<input type="checkbox" id="' + id + '" class="yali-mined-kw-cb" value="' + escapeHtml(kw) + '" ' + checkedAttr + ' style="margin-right: 10px;">';
                htmlContent += '<label for="' + id + '" style="color:' + styleColor + '; font-weight:500; cursor:pointer;">' + escapeHtml(kw) + badge + '</label>';
                htmlContent += '</div>';
            });
            htmlContent += '</div>';

            if (newCount === 0) {
                htmlContent += '<p style="color:#ef4444; margin-top:15px; font-weight:bold;">' + wp.i18n.__('所有拓展词均已被部署过，无增量新词！', 'yali-ai-writer') + '</p>';
            } else {
                htmlContent += '<p style="color:#10b981; margin-top:15px; font-weight:bold;">' + wp.i18n.__('发现 ', 'yali-ai-writer') + newCount + wp.i18n.__(' 个全新长尾词！是否立即将这些词导入规则库？', 'yali-ai-writer') + '</p>';
            }

            Swal.fire({
                title: wp.i18n.__('挖掘结果与复用排查', 'yali-ai-writer'),
                html: htmlContent,
                icon: 'success',
                width: 650,
                showCancelButton: true,
                confirmButtonText: newCount > 0 ? wp.i18n.__('一键规划新任务', 'yali-ai-writer') : '关闭',
                cancelButtonText: wp.i18n.__('我再看看', 'yali-ai-writer'),
                showConfirmButton: newCount > 0,
                customClass: {
                    container: 'yali-swal-custom',
                    confirmButton: 'yali-btn yali-btn-primary',
                    cancelButton: 'yali-btn yali-btn-secondary'
                },
                buttonsStyling: false
            }).then(function (modalResult) {
                if (modalResult.isConfirmed) {
                    var selectedKeywords = [];
                    $('.yali-mined-kw-cb:checked').each(function () {
                        selectedKeywords.push($(this).val());
                    });

                    if (selectedKeywords.length > 0) {
                        $btn.html('<span class="dashicons dashicons-update spin"></span> ' + wp.i18n.__('正在导入...', 'yali-ai-writer') + '');
                        processTaskImport(packId, selectedKeywords, $btn);
                    } else {
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> ' + wp.i18n.__('点击挖掘关键词', 'yali-ai-writer') + '');
                    }
                } else {
                    $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> ' + wp.i18n.__('点击挖掘关键词', 'yali-ai-writer') + '');
                }
            });
        }
    }

    function processTaskImport(packId, keywords, $btn) {
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> ' + wp.i18n.__('正在处理...', 'yali-ai-writer') + '');

        $.post(gscDashboardData.ajaxurl, {
            action: 'gsc_create_task',
            nonce: gscDashboardData.nonce,
            pack_id: packId,
            keywords_json: JSON.stringify(keywords) // Passing as strict JSON string to bypass PHP post sanitization stripping slashes
        }, function (response) {
            if (response.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'success',
                        title: wp.i18n.__('导入成功', 'yali-ai-writer'),
                        text: response.data.message,
                        confirmButtonText: wp.i18n.__('前往规则列表', 'yali-ai-writer'),
                        customClass: {
                            container: 'yali-swal-custom',
                            confirmButton: 'yali-btn yali-btn-primary'
                        },
                        buttonsStyling: false
                    }).then(function () {
                        window.location.href = response.data.redirect;
                    });
                } else {
                    alert(response.data.message);
                    window.location.href = response.data.redirect;
                }
            } else {
                Swal.fire({
                    icon: 'error',
                    title: wp.i18n.__('导入失败', 'yali-ai-writer'),
                    text: (response.data || wp.i18n.__('未知错误', 'yali-ai-writer')),
                    customClass: {
                        container: 'yali-swal-custom',
                        confirmButton: 'yali-btn yali-btn-primary'
                    },
                    buttonsStyling: false
                });
                $btn.prop('disabled', false).text(wp.i18n.__('一键规划任务', 'yali-ai-writer'));
            }
        });
    }

})(jQuery);
