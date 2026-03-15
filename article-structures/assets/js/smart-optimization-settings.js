/**
 * 智能结构优化设置页面 JavaScript
 * 
 * 处理配置管理、数据加载和用户交互
 */
jQuery(document).ready(function ($) {

    const __ = wp.i18n.__;

    // --- 全局变量 ---
    let currentConfigs = {};
    let coldStartPhases = {};
    let diversityData = {};

    // --- 初始化 ---
    initPage();

    // --- 初始化函数 ---
    function initPage() {
        loadAllData();
        bindEvents();
    }

    function loadAllData() {
        // 并行加载所有数据
        $.when(
            loadConfigs(),
            loadColdStartPhases(),
            loadDataDrivenStructures(),
            loadDiversityData(),
            loadPerformanceComparison()
        ).done(function () {
            console.log(__('所有数据加载完成', 'yali-ai-writer'));
        });
    }

    // --- 事件绑定 ---
    function bindEvents() {
        // 功能开关
        $('#smart-optimization-enabled').on('change', function () {
            const enabled = $(this).is(':checked');
            saveConfig('smart_optimization_enabled', enabled ? '1' : '0');
            updateToggleStatus(enabled);
        });

        // 保存配置
        $('#optimization-config-form').on('submit', function (e) {
            e.preventDefault();
            saveAllConfigs();
        });

        // 恢复默认
        $('#reset-config-btn').on('click', function () {
            if (confirm(__('确定要恢复所有配置为默认值吗？', 'yali-ai-writer'))) {
                resetConfigs();
            }
        });

        // 手动操作按钮
        $('#manual-analyze-btn').on('click', function () {
            runManualAnalysis();
        });

        $('#manual-update-popularity-btn').on('click', function () {
            updatePopularityIndices();
        });

        $('#clear-cache-btn').on('click', function () {
            clearCaches();
        });
    }

    // --- 配置加载与保存 ---
    function loadConfigs() {
        return $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_optimization_configs',
                nonce: smartOptimization.nonce
            },
            success: function (response) {
                if (response.success) {
                    currentConfigs = response.data;
                    populateConfigForm(currentConfigs);

                    // 更新开关状态
                    const enabled = currentConfigs.smart_optimization_enabled === '1';
                    $('#smart-optimization-enabled').prop('checked', enabled);
                    updateToggleStatus(enabled);
                }
            }
        });
    }

    function populateConfigForm(configs) {
        // 填充表单字段
        const fields = [
            'exploration_rate', 'softmax_temperature',
            'batch_diversity_threshold', 'batch_diversity_penalty',
            'window_diversity_threshold', 'window_diversity_penalty',
            'min_entropy_threshold', 'new_structure_boost', 'new_structure_boost_uses',
            'analysis_schedule_hour', 'min_articles_for_analysis',
            'min_days_published', 'max_articles_per_angle',
            'time_decay_30_days', 'time_decay_30_90_days', 'time_decay_90_plus_days',
            'confidence_min_articles'
        ];

        fields.forEach(function (field) {
            if (configs[field] !== undefined) {
                $('#' + field).val(configs[field]);
            }
        });
    }

    function saveConfig(key, value) {
        $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'save_optimization_config',
                nonce: smartOptimization.nonce,
                key: key,
                value: value
            },
            success: function (response) {
                if (response.success) {
                    showNotice(__('配置已保存', 'yali-ai-writer'), 'success');
                } else {
                    showNotice(__('保存失败: ', 'yali-ai-writer') + response.data.message, 'error');
                }
            }
        });
    }

    function saveAllConfigs() {
        const $btn = $('#save-config-btn');
        const $spinner = $('#config-spinner');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');

        // 收集所有配置值
        const configs = {};
        $('#optimization-config-form').find('input[name]').each(function () {
            configs[$(this).attr('name')] = $(this).val();
        });

        $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'save_optimization_configs',
                nonce: smartOptimization.nonce,
                configs: configs
            },
            success: function (response) {
                if (response.success) {
                    showNotice(__('所有配置已保存', 'yali-ai-writer'), 'success');
                    currentConfigs = Object.assign(currentConfigs, configs);
                } else {
                    showNotice(__('保存失败: ', 'yali-ai-writer') + response.data.message, 'error');
                }
            },
            error: function () {
                showNotice(__('保存失败，请检查网络连接', 'yali-ai-writer'), 'error');
            },
            complete: function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    }

    function resetConfigs() {
        $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'reset_optimization_configs',
                nonce: smartOptimization.nonce
            },
            success: function (response) {
                if (response.success) {
                    showNotice(__('配置已恢复为默认值', 'yali-ai-writer'), 'success');
                    loadConfigs();
                } else {
                    showNotice(__('重置失败: ', 'yali-ai-writer') + response.data.message, 'error');
                }
            }
        });
    }

    function updateToggleStatus(enabled) {
        const $status = $('#toggle-status-text');
        if (enabled) {
            $status.text(__('已启用', 'yali-ai-writer')).removeClass('disabled').addClass('enabled');
        } else {
            $status.text(__('已禁用', 'yali-ai-writer')).removeClass('enabled').addClass('disabled');
        }
    }

    // --- 冷启动阶段 ---
    function loadColdStartPhases() {
        return $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_cold_start_phases',
                nonce: smartOptimization.nonce
            },
            success: function (response) {
                if (response.success) {
                    coldStartPhases = response.data;
                    renderColdStartPhases(coldStartPhases);
                } else {
                    $('#cold-start-phases-container').html(
                        '<p class="notice notice-error">' + __('加载冷启动阶段数据失败', 'yali-ai-writer') + '</p>'
                    );
                }
            }
        });
    }

    function renderColdStartPhases(phases) {
        const $container = $('#cold-start-phases-container');

        if (!phases || Object.keys(phases).length === 0) {
            $container.html('<div class="empty-state"><span class="dashicons dashicons-info"></span><p>' + __('暂无冷启动阶段数据', 'yali-ai-writer') + '</p></div>');
            return;
        }

        // Use 4-column grid
        let html = '<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px;">';

        for (const angle in phases) {
            const data = phases[angle];
            let phaseColor = '#2271b1'; // default
            let phaseLabel = __('未知', 'yali-ai-writer');

            // Map phase index to visual properties
            if (data.phase === 1) {
                phaseColor = '#d63638'; // Red - Random
                phaseLabel = __('随机探索', 'yali-ai-writer');
            } else if (data.phase === 2) {
                phaseColor = '#f0b849'; // Orange - Mixed
                phaseLabel = __('混合过渡', 'yali-ai-writer');
            } else if (data.phase === 3 || data.phase === 4) {
                phaseColor = '#46b450'; // Green - Optimized
                phaseLabel = __('智能优选', 'yali-ai-writer');
            }

            const explorationPct = (data.exploration_rate * 100).toFixed(0);
            // 翻译内容角度名称
            const translatedAngle = __(angle, 'yali-ai-writer');

            html += `
                <div class="yali-card" style="border-top: 3px solid ${phaseColor}; padding: 15px; margin-bottom: 0; display:flex; flex-direction:column; justify-content:space-between;">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <h4 style="margin:0; font-size: 14px; color:#1d2327;">${escapeHtml(translatedAngle)}</h4>
                        </div>
                        
                        <div style="background:#f6f7f7; border-radius:4px; padding:8px; margin-bottom:10px; text-align:center;">
                            <div style="color:${phaseColor}; font-weight:600; font-size:12px; margin-bottom:2px;">${phaseLabel} (${__('阶段', 'yali-ai-writer')} ${data.phase})</div>
                            <div style="font-size:11px; color:#646970;">${__('当前状态', 'yali-ai-writer')}</div>
                        </div>
                    </div>

                    <div class="phase-metrics" style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:12px;">
                        <div style="text-align:center;">
                            <div style="color:#646970; margin-bottom:2px;">${__('文章数', 'yali-ai-writer')}</div>
                            <div style="font-weight:600; font-size:14px;">${data.article_count}</div>
                        </div>
                        <div style="text-align:center;">
                            <div style="color:#646970; margin-bottom:2px;">${__('探索率', 'yali-ai-writer')}</div>
                            <div style="font-weight:600; font-size:14px; color:${phaseColor}">${explorationPct}%</div>
                        </div>
                    </div>
                </div>
            `;
        }

        html += '</div>';
        $container.html(html);
    }

    // --- 数据驱动结构 ---
    function loadDataDrivenStructures() {
        return $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_data_driven_structures',
                nonce: smartOptimization.nonce
            },
            success: function (response) {
                if (response.success) {
                    renderDataDrivenStructures(response.data);
                } else {
                    $('#data-driven-structures-container').html(
                        '<p class="notice notice-error">' + __('加载数据驱动结构失败', 'yali-ai-writer') + '</p>'
                    );
                }
            }
        });
    }

    function renderDataDrivenStructures(structures) {
        const $container = $('#data-driven-structures-container');

        if (!structures || structures.length === 0) {
            $container.html('<div class="empty-state"><span class="dashicons dashicons-format-aside"></span><p>' + __('暂无数据驱动结构。系统会自动从受欢迎文章中提取结构。', 'yali-ai-writer') + '</p></div>');
            return;
        }

        let html = `
            <table class="yali-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">${__('结构标题', 'yali-ai-writer')}</th>
                        <th style="width: 25%;">${__('来源文章', 'yali-ai-writer')}</th>
                        <th style="width: 20%;">${__('受欢迎度', 'yali-ai-writer')}</th>
                        <th style="width: 10%;">${__('使用次数', 'yali-ai-writer')}</th>
                        <th style="width: 15%;">${__('提取时间', 'yali-ai-writer')}</th>
                    </tr>
                </thead>
                <tbody>
        `;

        structures.forEach(function (structure) {
            const popularityColor = getPopularityColor(structure.popularity_index);
            const popVal = structure.popularity_index.toFixed(1);
            // Cap visual width at 100
            const widthVal = Math.min(structure.popularity_index, 100);

            html += `
                <tr>
                    <td>
                        <strong class="structure-title">${escapeHtml(structure.title)}</strong>
                        <br>
                        <span class="description" style="color:#646970; font-size:12px;">${__('角度: ', 'yali-ai-writer')}${escapeHtml(__(structure.content_angle, 'yali-ai-writer'))}</span>
                    </td>
                    <td>
                        ${structure.source_article_title ?
                    `<a href="${structure.source_article_url}" target="_blank" class="yali-link">${escapeHtml(structure.source_article_title)}</a>` :
                    '<span style="color:#999">-</span>'}
                    </td>
                    <td>
                        <div style="display:flex; flex-direction:column; gap:4px;">
                            <span style="font-weight:600; font-size:13px; color:${popularityColor}">${popVal}%</span>
                            <div class="yali-progress-container" style="margin:0; height:6px;">
                                <div class="yali-progress-bar" style="width: ${widthVal}%; background: ${popularityColor};"></div>
                            </div>
                        </div>
                    </td>
                    <td><span style="font-weight:500;">${structure.usage_count}</span></td>
                    <td><span style="color:#646970; font-size:13px;">${structure.extracted_at ? structure.extracted_at.substring(0, 10) : '-'}</span></td>
                </tr>
            `;
        });

        html += '</tbody></table>';
        $container.html(html);
    }


    // --- 多样性数据 ---
    function loadDiversityData() {
        return $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_diversity_overview',
                nonce: smartOptimization.nonce
            },
            success: function (response) {
                if (response.success) {
                    diversityData = response.data;
                    renderEntropyOverview(diversityData.entropy_overview);
                    renderUsageDistribution(diversityData.usage_distribution);
                    renderRecentSelections(diversityData.recent_selections);
                } else {
                    $('#entropy-overview-container').html('<p>' + __('加载失败', 'yali-ai-writer') + '</p>');
                    $('#usage-distribution-container').html('<p>' + __('加载失败', 'yali-ai-writer') + '</p>');
                    $('#recent-selections-container').html('<p>' + __('加载失败', 'yali-ai-writer') + '</p>');
                }
            }
        });
    }

    function renderEntropyOverview(entropyData) {
        const $container = $('#entropy-overview-container');

        if (!entropyData || Object.keys(entropyData).length === 0) {
            $container.html('<div class="empty-state"><p>' + __('暂无熵值数据', 'yali-ai-writer') + '</p></div>');
            return;
        }

        // Grid optimized for density: 4 columns
        let html = '<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">';

        for (const angle in entropyData) {
            const data = entropyData[angle];
            const statusClass = data.is_low ? 'warning' : 'normal';
            const statusIcon = data.is_low ? '⚠️' : '✓';
            // 翻译内容角度名称
            const translatedAngle = __(angle, 'yali-ai-writer');

            html += `
                <div class="entropy-item" style="border: 1px solid #ddd; padding: 12px; border-radius: 4px; background: #fff;">
                    <div style="display:flex; justify-content:space-between; margin-bottom: 5px; align-items:center;">
                        <span class="angle-name" style="font-weight:600; font-size:13px;">${escapeHtml(translatedAngle)}</span>
                        <span style="font-size:12px;">${statusIcon}</span>
                    </div>
                    <span class="entropy-value ${statusClass}" style="display:block; font-size:14px; font-weight:500;">
                        ${data.entropy.toFixed(2)}
                        ${data.is_low ? '<small style="display:block; color:#d63638; font-size:11px; margin-top:2px;">(< ' + data.threshold + ')</small>' : ''}
                    </span>
                </div>
            `;
        }

        html += '</div>';
        $container.html(html);
    }

    function renderUsageDistribution(distributionData) {
        const $container = $('#usage-distribution-container');

        if (!distributionData || distributionData.length === 0) {
            $container.html('<div class="empty-state"><p>' + __('暂无使用分布数据', 'yali-ai-writer') + '</p></div>');
            return;
        }

        // 取前10个最常用的结构
        const topStructures = distributionData.slice(0, 10);
        const maxUsage = Math.max(...topStructures.map(s => s.percentage));

        // Use 2-column grid for usage bars to reduce height and match Entropy card
        let html = '<div class="usage-chart" style="display: grid; grid-template-columns: 1fr 1fr; column-gap: 20px; row-gap: 10px;">';

        topStructures.forEach(function (structure) {
            const barWidth = maxUsage > 0 ? (structure.percentage / maxUsage * 100) : 0;
            const warningClass = structure.exceeds_threshold ? 'warning' : '';

            html += `
                <div class="usage-bar-container" style="margin-bottom:0;">
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:2px;">
                        <span class="usage-bar-label" title="${escapeHtml(structure.title)}" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:70%;">${escapeHtml(structure.title)}</span>
                        <span class="usage-bar-value">${structure.percentage.toFixed(1)}%</span>
                    </div>
                    <div class="usage-bar-wrapper" style="background:#f0f0f1; height:6px; border-radius:3px; overflow:hidden;">
                        <div class="usage-bar ${warningClass}" style="width: ${barWidth}%; height:100%; background:${structure.exceeds_threshold ? '#d63638' : '#2271b1'};"></div>
                    </div>
                </div>
            `;
        });

        html += '</div>';
        $container.html(html);
    }

    function renderRecentSelections(selections) {
        const $container = $('#recent-selections-container');

        if (!selections || selections.length === 0) {
            $container.html('<div class="empty-state"><p>' + __('暂无选择记录', 'yali-ai-writer') + '</p></div>');
            return;
        }

        let html = `
            <table class="selections-table">
                <thead>
                    <tr>
                        <th>${__('时间', 'yali-ai-writer')}</th>
                        <th>${__('内容角度', 'yali-ai-writer')}</th>
                        <th>${__('选中结构', 'yali-ai-writer')}</th>
                        <th>${__('选择方法', 'yali-ai-writer')}</th>
                        <th>${__('权重', 'yali-ai-writer')}</th>
                        <th>${__('调整', 'yali-ai-writer')}</th>
                    </tr>
                </thead>
                <tbody>
        `;

        selections.forEach(function (selection) {
            const methodClass = selection.selection_method || 'fallback';
            const methodLabel = getMethodLabel(selection.selection_method);

            let adjustmentHtml = '';
            if (selection.penalty_applied) {
                adjustmentHtml += '<span class="adjustment-badge penalty">' + __('惩罚', 'yali-ai-writer') + '</span>';
            }
            if (selection.boost_applied) {
                adjustmentHtml += '<span class="adjustment-badge boost">' + __('提升', 'yali-ai-writer') + '</span>';
            }
            if (!adjustmentHtml) {
                adjustmentHtml = '-';
            }

            html += `
                <tr>
                    <td>${selection.selected_at}</td>
                    <td>${escapeHtml(__(selection.content_angle, 'yali-ai-writer'))}</td>
                    <td>${escapeHtml(selection.structure_title)}</td>
                    <td><span class="method-badge ${methodClass}">${methodLabel}</span></td>
                    <td>${selection.selection_weight ? selection.selection_weight.toFixed(2) : '-'}</td>
                    <td>${adjustmentHtml}</td>
                </tr>
            `;
        });

        html += '</tbody></table>';
        $container.html(html);
    }

    // --- 性能对比 ---
    function loadPerformanceComparison() {
        return $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_performance_comparison',
                nonce: smartOptimization.nonce
            },
            success: function (response) {
                if (response.success) {
                    renderPerformanceComparison(response.data);
                } else {
                    $('#performance-comparison-container').html(
                        '<p class="notice notice-error">' + __('加载性能对比数据失败', 'yali-ai-writer') + '</p>'
                    );
                }
            }
        });
    }

    function renderPerformanceComparison(data) {
        const $container = $('#performance-comparison-container');

        // Calculate max values for progress bars
        const maxVisits = Math.max(data.ai_generated.avg_visits, data.data_driven.avg_visits, 1);
        const aiVisitsPct = (data.ai_generated.avg_visits / maxVisits) * 100;
        const ddVisitsPct = (data.data_driven.avg_visits / maxVisits) * 100;

        const maxPop = Math.max(data.ai_generated.avg_popularity, data.data_driven.avg_popularity, 1);
        const aiPopPct = (data.ai_generated.avg_popularity / maxPop) * 100;
        const ddPopPct = (data.data_driven.avg_popularity / maxPop) * 100;

        const html = `
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <!-- AI Generated Card -->
                <div class="yali-card" style="border-top: 4px solid #2271b1; padding: 20px; margin-bottom: 0;">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:20px; padding-bottom:15px; border-bottom:1px dashed #eee;">
                        <span class="dashicons dashicons-admin-customizer" style="font-size:24px; width:24px; height:24px; color:#2271b1;"></span>
                        <h4 style="margin:0; font-size:16px;">${__('AI 随机/生成结构', 'yali-ai-writer')}</h4>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                        <div class="stat-box">
                            <div style="font-size: 20px; font-weight: 600; color: #1d2327;">${data.ai_generated.count}</div>
                            <div style="font-size: 12px; color: #646970;">${__('结构总数', 'yali-ai-writer')}</div>
                        </div>
                        <div class="stat-box">
                            <div style="font-size: 20px; font-weight: 600; color: #1d2327;">${data.ai_generated.total_usage}</div>
                            <div style="font-size: 12px; color: #646970;">${__('总使用次数', 'yali-ai-writer')}</div>
                        </div>
                    </div>

                    <!-- Metrics with Bars -->
                    <div style="margin-bottom: 15px;">
                        <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                            <span style="color:#646970;">${__('平均访问量', 'yali-ai-writer')}</span>
                            <span style="font-weight:600;">${data.ai_generated.avg_visits.toFixed(1)}</span>
                        </div>
                        <div style="background:#f0f0f1; height:6px; border-radius:3px; overflow:hidden;">
                            <div style="width:${aiVisitsPct}%; background:#2271b1; height:100%;"></div>
                        </div>
                    </div>
                    <div>
                        <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                            <span style="color:#646970;">${__('平均受欢迎度', 'yali-ai-writer')}</span>
                            <span style="font-weight:600;">${data.ai_generated.avg_popularity.toFixed(1)}%</span>
                        </div>
                        <div style="background:#f0f0f1; height:6px; border-radius:3px; overflow:hidden;">
                            <div style="width:${aiPopPct}%; background:#72aee6; height:100%;"></div>
                        </div>
                    </div>
                </div>

                <!-- Data Driven Card -->
                <div class="yali-card" style="border-top: 4px solid #f0b849; padding: 20px; margin-bottom: 0;">
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:20px; padding-bottom:15px; border-bottom:1px dashed #eee;">
                        <span class="dashicons dashicons-chart-line" style="font-size:24px; width:24px; height:24px; color:#f0b849;"></span>
                        <h4 style="margin:0; font-size:16px;">${__('数据驱动结构', 'yali-ai-writer')}</h4>
                        <span class="yali-badge yali-badge-success" style="margin-left:auto;">${__('推荐', 'yali-ai-writer')}</span>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                        <div class="stat-box">
                            <div style="font-size: 20px; font-weight: 600; color: #1d2327;">${data.data_driven.count}</div>
                            <div style="font-size: 12px; color: #646970;">${__('结构总数', 'yali-ai-writer')}</div>
                        </div>
                        <div class="stat-box">
                            <div style="font-size: 20px; font-weight: 600; color: #1d2327;">${data.data_driven.total_usage}</div>
                            <div style="font-size: 12px; color: #646970;">${__('总使用次数', 'yali-ai-writer')}</div>
                        </div>
                    </div>

                    <!-- Metrics with Bars -->
                    <div style="margin-bottom: 15px;">
                        <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                            <span style="color:#646970;">${__('平均访问量', 'yali-ai-writer')}</span>
                            <span style="font-weight:600;">${data.data_driven.avg_visits.toFixed(1)}</span>
                        </div>
                        <div style="background:#f0f0f1; height:6px; border-radius:3px; overflow:hidden;">
                            <div style="width:${ddVisitsPct}%; background:#f0b849; height:100%;"></div>
                        </div>
                    </div>
                    <div>
                        <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                            <span style="color:#646970;">${__('平均受欢迎度', 'yali-ai-writer')}</span>
                            <span style="font-weight:600;">${data.data_driven.avg_popularity.toFixed(1)}%</span>
                        </div>
                        <div style="background:#f0f0f1; height:6px; border-radius:3px; overflow:hidden;">
                            <div style="width:${ddPopPct}%; background:#f5d58a; height:100%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $container.html(html);
    }

    // --- 手动操作 ---
    function runManualAnalysis() {
        const $btn = $('#manual-analyze-btn');
        const $spinner = $('#manual-action-spinner');
        const $result = $('#manual-action-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        showNotice(__('正在获取待处理文章列表...', 'yali-ai-writer'), 'info');

        // 先获取待处理文章列表（固定列表）
        $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_pending_analysis_count',
                nonce: smartOptimization.nonce
            },
            success: function (response) {
                if (response.success) {
                    const articles = response.data.articles || [];
                    const total = articles.length;

                    if (total === 0) {
                        $btn.prop('disabled', false);
                        $spinner.removeClass('is-active');
                        $result.html('');
                        showNotice(response.data.message, 'info');
                        return;
                    }

                    // 开始处理固定的文章列表
                    startProcessing(articles);
                } else {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $result.html('');
                    showNotice(__('获取列表失败: ', 'yali-ai-writer') + response.data.message, 'error');
                }
            },
            error: function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                $result.html('');
                showNotice(__('请求失败，请检查网络连接', 'yali-ai-writer'), 'error');
            }
        });

        // 开始处理固定的文章列表
        function startProcessing(articles) {
            const total = articles.length;
            let currentIndex = 0;
            let totalCreated = 0;
            let totalSkipped = 0;
            let errors = [];

            $result.html('<div style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #00a0d2;">' + __('正在分析高表现文章...', 'yali-ai-writer') + '<br>' +
                '<span id="analysis-progress">' + __('进度: ', 'yali-ai-writer') + '0 / ' + total + ' ' + __('篇', 'yali-ai-writer') + '</span></div>');

            // 处理下一篇文章
            function processNext() {
                // 检查是否处理完所有文章
                if (currentIndex >= total) {
                    finishAnalysis();
                    return;
                }

                const article = articles[currentIndex];
                const postId = article.post_id;

                $.ajax({
                    url: smartOptimization.ajaxurl,
                    type: 'POST',
                    timeout: 180000,
                    data: {
                        action: 'process_single_article',
                        nonce: smartOptimization.nonce,
                        post_id: postId
                    },
                    success: function (response) {
                        currentIndex++;

                        if (response.success) {
                            totalCreated++;
                            $('#analysis-progress').text(__('进度: ', 'yali-ai-writer') + currentIndex + ' / ' + total +
                                ' ' + __('篇', 'yali-ai-writer') + __('（已创建: ', 'yali-ai-writer') + totalCreated + ' ' + __('个结构）', 'yali-ai-writer'));
                        } else {
                            totalSkipped++;
                            errors.push(__('文章', 'yali-ai-writer') + postId + ': ' + (response.data.message || __('失败', 'yali-ai-writer')));
                            $('#analysis-progress').text(__('进度: ', 'yali-ai-writer') + currentIndex + ' / ' + total +
                                ' ' + __('篇', 'yali-ai-writer') + __('（已创建: ', 'yali-ai-writer') + totalCreated + __(' 个，跳过: ', 'yali-ai-writer') + totalSkipped + ' ' + __('个）', 'yali-ai-writer'));
                        }

                        // 继续处理下一篇
                        processNext();
                    },
                    error: function (xhr, status, error) {
                        currentIndex++;
                        totalSkipped++;

                        let errorMsg = status === 'timeout' ? __('超时', 'yali-ai-writer') : __('请求失败', 'yali-ai-writer');
                        errors.push(__('文章', 'yali-ai-writer') + postId + ': ' + errorMsg);

                        $('#analysis-progress').text(__('进度: ', 'yali-ai-writer') + currentIndex + ' / ' + total +
                            ' ' + __('篇', 'yali-ai-writer') + __('（已创建: ', 'yali-ai-writer') + totalCreated + __(' 个，跳过: ', 'yali-ai-writer') + totalSkipped + ' ' + __('个）', 'yali-ai-writer'));

                        // 继续处理下一篇
                        processNext();
                    }
                });
            }

            // 完成处理
            function finishAnalysis() {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');

                let message = '';
                let noticeClass = 'notice-success';

                if (totalCreated > 0) {
                    message = __('分析完成！已处理 ', 'yali-ai-writer') + total + __(' 篇文章，创建 ', 'yali-ai-writer') + totalCreated + __(' 个新结构。', 'yali-ai-writer');
                    if (totalSkipped > 0) {
                        message += __('（跳过 ', 'yali-ai-writer') + totalSkipped + __(' 篇）', 'yali-ai-writer');
                    }
                } else if (total > 0) {
                    message = __('分析完成，处理了 ', 'yali-ai-writer') + total + __(' 篇文章，但未创建新结构（可能已存在相似结构或提取失败）。', 'yali-ai-writer');
                    noticeClass = 'notice-warning';
                } else {
                    message = __('分析完成，没有待处理的高表现文章。', 'yali-ai-writer');
                    noticeClass = 'notice-info';
                }

                if (errors.length > 0 && errors.length <= 5) {
                    message += '<br><small>' + __('错误: ', 'yali-ai-writer') + errors.join('; ') + '</small>';
                    noticeClass = totalCreated > 0 ? 'notice-warning' : 'notice-error';
                } else if (errors.length > 5) {
                    message += '<br><small>' + __('共 ', 'yali-ai-writer') + errors.length + __(' 个错误', 'yali-ai-writer') + '</small>';
                    noticeClass = totalCreated > 0 ? 'notice-warning' : 'notice-error';
                }

                $result.html('');
                if (noticeClass === 'notice-success') {
                    showNotice(message, 'success');
                } else if (noticeClass === 'notice-warning') {
                    showNotice(message, 'warning');
                } else if (noticeClass === 'notice-error') {
                    showNotice(message, 'error');
                } else {
                    showNotice(message, 'info');
                }

                if (totalCreated > 0) {
                    loadDataDrivenStructures();
                }
            }

            // 开始处理第一篇
            processNext();
        }
    }

    function updatePopularityIndices() {
        const $btn = $('#manual-update-popularity-btn');
        const $spinner = $('#manual-action-spinner');
        const $result = $('#manual-action-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('');

        $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'update_popularity_indices',
                nonce: smartOptimization.nonce
            },
            success: function (response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    // 刷新数据
                    loadDataDrivenStructures();
                    loadDiversityData();
                } else {
                    showNotice(__('更新失败: ', 'yali-ai-writer') + response.data.message, 'error');
                }
            },
            error: function () {
                showNotice(__('请求失败，请检查网络连接', 'yali-ai-writer'), 'error');
            },
            complete: function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    }

    function clearCaches() {
        const $btn = $('#clear-cache-btn');
        const $spinner = $('#manual-action-spinner');
        const $result = $('#manual-action-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('');

        $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'clear_optimization_caches',
                nonce: smartOptimization.nonce
            },
            success: function (response) {
                if (response.success) {
                    showNotice(__('缓存已清除', 'yali-ai-writer'), 'success');
                } else {
                    showNotice(__('清除失败: ', 'yali-ai-writer') + response.data.message, 'error');
                }
            },
            error: function () {
                showNotice(__('请求失败，请检查网络连接', 'yali-ai-writer'), 'error');
            },
            complete: function () {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    }

    // --- 工具函数 ---
    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function getPopularityColor(index) {
        if (index >= 150) return '#00a32a';
        if (index >= 100) return '#72b300';
        if (index >= 60) return '#f0b90b';
        if (index > 0) return '#d63638';
        return '#999';
    }

    function getMethodLabel(method) {
        switch (method) {
            case 'exploration': return __('探索', 'yali-ai-writer');
            case 'exploitation': return __('利用', 'yali-ai-writer');
            case 'fallback': return __('回退', 'yali-ai-writer');
            default: return method || '-';
        }
    }

    function showNotice(message, type) {
        if (typeof window.yaliToast === 'function') {
            window.yaliToast(message, type);
        } else {
            // Fallback clear previous notices
            $('.temp-notice').remove();
            const alertClass = type === 'success' ? 'notice-success' :
                type === 'error' ? 'notice-error' :
                    type === 'warning' ? 'notice-warning' : 'notice-info';
            const html = `<div class="notice ${alertClass} temp-notice is-dismissible" style="margin: 10px 0;"><p>${message}</p></div>`;
            $('#smart-optimization-page h1').after(html);
            setTimeout(function () {
                $('.temp-notice').fadeOut(300, function () { $(this).remove(); });
            }, type === 'error' ? 5000 : 3000);
        }
    }
});
