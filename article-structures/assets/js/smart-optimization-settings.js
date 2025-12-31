/**
 * 智能结构优化设置页面 JavaScript
 * 
 * 处理配置管理、数据加载和用户交互
 */
jQuery(document).ready(function($) {
    
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
        ).done(function() {
            console.log('所有数据加载完成');
        });
    }
    
    // --- 事件绑定 ---
    function bindEvents() {
        // 功能开关
        $('#smart-optimization-enabled').on('change', function() {
            const enabled = $(this).is(':checked');
            saveConfig('smart_optimization_enabled', enabled ? '1' : '0');
            updateToggleStatus(enabled);
        });
        
        // 保存配置
        $('#optimization-config-form').on('submit', function(e) {
            e.preventDefault();
            saveAllConfigs();
        });
        
        // 恢复默认
        $('#reset-config-btn').on('click', function() {
            if (confirm('确定要恢复所有配置为默认值吗？')) {
                resetConfigs();
            }
        });
        
        // 手动操作按钮
        $('#manual-analyze-btn').on('click', function() {
            runManualAnalysis();
        });
        
        $('#manual-update-popularity-btn').on('click', function() {
            updatePopularityIndices();
        });
        
        $('#clear-cache-btn').on('click', function() {
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
            success: function(response) {
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
        
        fields.forEach(function(field) {
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
            success: function(response) {
                if (response.success) {
                    showNotice('配置已保存', 'success');
                } else {
                    showNotice('保存失败: ' + response.data.message, 'error');
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
        $('#optimization-config-form').find('input[name]').each(function() {
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
            success: function(response) {
                if (response.success) {
                    showNotice('所有配置已保存', 'success');
                    currentConfigs = Object.assign(currentConfigs, configs);
                } else {
                    showNotice('保存失败: ' + response.data.message, 'error');
                }
            },
            error: function() {
                showNotice('保存失败，请检查网络连接', 'error');
            },
            complete: function() {
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
            success: function(response) {
                if (response.success) {
                    showNotice('配置已恢复为默认值', 'success');
                    loadConfigs();
                } else {
                    showNotice('重置失败: ' + response.data.message, 'error');
                }
            }
        });
    }
    
    function updateToggleStatus(enabled) {
        const $status = $('#toggle-status-text');
        if (enabled) {
            $status.text('已启用').removeClass('disabled').addClass('enabled');
        } else {
            $status.text('已禁用').removeClass('enabled').addClass('disabled');
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
            success: function(response) {
                if (response.success) {
                    coldStartPhases = response.data;
                    renderColdStartPhases(coldStartPhases);
                } else {
                    $('#cold-start-phases-container').html(
                        '<p class="notice notice-error">加载冷启动阶段数据失败</p>'
                    );
                }
            }
        });
    }
    
    function renderColdStartPhases(phases) {
        const $container = $('#cold-start-phases-container');
        
        if (!phases || Object.keys(phases).length === 0) {
            $container.html('<div class="empty-state"><span class="dashicons dashicons-info"></span><p>暂无冷启动阶段数据</p></div>');
            return;
        }
        
        let html = '<div class="cold-start-grid">';
        
        for (const angle in phases) {
            const data = phases[angle];
            const phaseClass = 'phase-' + data.phase;
            
            html += `
                <div class="phase-card ${phaseClass}">
                    <h4>${escapeHtml(angle)}</h4>
                    <div class="phase-info">
                        <div>
                            <span class="label">当前阶段:</span>
                            <span class="phase-badge ${phaseClass}">${escapeHtml(data.phase_name)}</span>
                        </div>
                        <div>
                            <span class="label">文章数量:</span>
                            <span class="value">${data.article_count}</span>
                        </div>
                        <div>
                            <span class="label">探索率:</span>
                            <span class="value">${(data.exploration_rate * 100).toFixed(0)}%</span>
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
            success: function(response) {
                if (response.success) {
                    renderDataDrivenStructures(response.data);
                } else {
                    $('#data-driven-structures-container').html(
                        '<p class="notice notice-error">加载数据驱动结构失败</p>'
                    );
                }
            }
        });
    }
    
    function renderDataDrivenStructures(structures) {
        const $container = $('#data-driven-structures-container');
        
        if (!structures || structures.length === 0) {
            $container.html('<div class="empty-state"><span class="dashicons dashicons-format-aside"></span><p>暂无数据驱动结构。系统会自动从受欢迎文章中提取结构。</p></div>');
            return;
        }
        
        let html = `
            <div class="data-driven-header">
                <span>结构标题</span>
                <span>来源文章</span>
                <span>受欢迎度</span>
                <span>使用次数</span>
                <span>提取时间</span>
            </div>
            <div class="data-driven-list">
        `;
        
        structures.forEach(function(structure) {
            const popularityColor = getPopularityColor(structure.popularity_index);
            
            html += `
                <div class="data-driven-item">
                    <div>
                        <div class="structure-title">${escapeHtml(structure.title)}</div>
                        <div class="source-article">
                            <span class="label">内容角度:</span> ${escapeHtml(structure.content_angle)}
                        </div>
                    </div>
                    <div class="source-article">
                        ${structure.source_article_title ? 
                            `<a href="${structure.source_article_url}" target="_blank">${escapeHtml(structure.source_article_title)}</a>` : 
                            '<span style="color:#999">-</span>'}
                    </div>
                    <div class="stat-value" style="color: ${popularityColor}">
                        ${structure.popularity_index.toFixed(1)}%
                        <span class="stat-label">受欢迎度</span>
                    </div>
                    <div class="stat-value">
                        ${structure.usage_count}
                        <span class="stat-label">使用次数</span>
                    </div>
                    <div class="stat-value">
                        ${structure.extracted_at || '-'}
                        <span class="stat-label">提取时间</span>
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
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
            success: function(response) {
                if (response.success) {
                    diversityData = response.data;
                    renderEntropyOverview(diversityData.entropy_overview);
                    renderUsageDistribution(diversityData.usage_distribution);
                    renderRecentSelections(diversityData.recent_selections);
                } else {
                    $('#entropy-overview-container').html('<p>加载失败</p>');
                    $('#usage-distribution-container').html('<p>加载失败</p>');
                    $('#recent-selections-container').html('<p>加载失败</p>');
                }
            }
        });
    }
    
    function renderEntropyOverview(entropyData) {
        const $container = $('#entropy-overview-container');
        
        if (!entropyData || Object.keys(entropyData).length === 0) {
            $container.html('<div class="empty-state"><p>暂无熵值数据</p></div>');
            return;
        }
        
        let html = '<div class="entropy-list">';
        
        for (const angle in entropyData) {
            const data = entropyData[angle];
            const statusClass = data.is_low ? 'warning' : 'normal';
            const statusIcon = data.is_low ? '⚠️' : '✓';
            
            html += `
                <div class="entropy-item">
                    <span class="angle-name">${escapeHtml(angle)}</span>
                    <span class="entropy-value ${statusClass}">
                        ${statusIcon} ${data.entropy.toFixed(2)}
                        ${data.is_low ? '<small>(低于阈值 ' + data.threshold + ')</small>' : ''}
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
            $container.html('<div class="empty-state"><p>暂无使用分布数据</p></div>');
            return;
        }
        
        // 取前10个最常用的结构
        const topStructures = distributionData.slice(0, 10);
        const maxUsage = Math.max(...topStructures.map(s => s.percentage));
        
        let html = '<div class="usage-chart">';
        
        topStructures.forEach(function(structure) {
            const barWidth = maxUsage > 0 ? (structure.percentage / maxUsage * 100) : 0;
            const warningClass = structure.exceeds_threshold ? 'warning' : '';
            
            html += `
                <div class="usage-bar-container">
                    <span class="usage-bar-label" title="${escapeHtml(structure.title)}">${escapeHtml(structure.title)}</span>
                    <div class="usage-bar-wrapper">
                        <div class="usage-bar ${warningClass}" style="width: ${barWidth}%"></div>
                    </div>
                    <span class="usage-bar-value">${structure.percentage.toFixed(1)}%</span>
                </div>
            `;
        });
        
        html += '</div>';
        $container.html(html);
    }
    
    function renderRecentSelections(selections) {
        const $container = $('#recent-selections-container');
        
        if (!selections || selections.length === 0) {
            $container.html('<div class="empty-state"><p>暂无选择记录</p></div>');
            return;
        }
        
        let html = `
            <table class="selections-table">
                <thead>
                    <tr>
                        <th>时间</th>
                        <th>内容角度</th>
                        <th>选中结构</th>
                        <th>选择方法</th>
                        <th>权重</th>
                        <th>调整</th>
                    </tr>
                </thead>
                <tbody>
        `;
        
        selections.forEach(function(selection) {
            const methodClass = selection.selection_method || 'fallback';
            const methodLabel = getMethodLabel(selection.selection_method);
            
            let adjustmentHtml = '';
            if (selection.penalty_applied) {
                adjustmentHtml += '<span class="adjustment-badge penalty">惩罚</span>';
            }
            if (selection.boost_applied) {
                adjustmentHtml += '<span class="adjustment-badge boost">提升</span>';
            }
            if (!adjustmentHtml) {
                adjustmentHtml = '-';
            }
            
            html += `
                <tr>
                    <td>${selection.selected_at}</td>
                    <td>${escapeHtml(selection.content_angle)}</td>
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
            success: function(response) {
                if (response.success) {
                    renderPerformanceComparison(response.data);
                } else {
                    $('#performance-comparison-container').html(
                        '<p class="notice notice-error">加载性能对比数据失败</p>'
                    );
                }
            }
        });
    }
    
    function renderPerformanceComparison(data) {
        const $container = $('#performance-comparison-container');
        
        const html = `
            <div class="performance-comparison">
                <div class="performance-card ai-generated">
                    <h4>🤖 AI 生成结构</h4>
                    <div class="performance-stats">
                        <div class="stat-item">
                            <div class="stat-number">${data.ai_generated.count}</div>
                            <div class="stat-label">结构数量</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">${data.ai_generated.total_usage}</div>
                            <div class="stat-label">总使用次数</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">${data.ai_generated.avg_visits.toFixed(1)}</div>
                            <div class="stat-label">平均访问量</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">${data.ai_generated.avg_popularity.toFixed(1)}%</div>
                            <div class="stat-label">平均受欢迎度</div>
                        </div>
                    </div>
                </div>
                <div class="performance-card data-driven">
                    <h4>📊 数据驱动结构</h4>
                    <div class="performance-stats">
                        <div class="stat-item">
                            <div class="stat-number">${data.data_driven.count}</div>
                            <div class="stat-label">结构数量</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">${data.data_driven.total_usage}</div>
                            <div class="stat-label">总使用次数</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">${data.data_driven.avg_visits.toFixed(1)}</div>
                            <div class="stat-label">平均访问量</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">${data.data_driven.avg_popularity.toFixed(1)}%</div>
                            <div class="stat-label">平均受欢迎度</div>
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
        $result.html('<div class="notice notice-info"><p>正在获取待处理文章列表...</p></div>');
        
        // 先获取待处理文章列表（固定列表）
        $.ajax({
            url: smartOptimization.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_pending_analysis_count',
                nonce: smartOptimization.nonce
            },
            success: function(response) {
                if (response.success) {
                    const articles = response.data.articles || [];
                    const total = articles.length;
                    
                    if (total === 0) {
                        $btn.prop('disabled', false);
                        $spinner.removeClass('is-active');
                        $result.html('<div class="notice notice-info"><p>' + response.data.message + '</p></div>');
                        return;
                    }
                    
                    // 开始处理固定的文章列表
                    startProcessing(articles);
                } else {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                    $result.html('<div class="notice notice-error"><p>获取列表失败: ' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $spinner.removeClass('is-active');
                $result.html('<div class="notice notice-error"><p>请求失败，请检查网络连接</p></div>');
            }
        });
        
        // 开始处理固定的文章列表
        function startProcessing(articles) {
            const total = articles.length;
            let currentIndex = 0;
            let totalCreated = 0;
            let totalSkipped = 0;
            let errors = [];
            
            $result.html('<div class="notice notice-info"><p>正在分析高表现文章...<br>' +
                '<span id="analysis-progress">进度: 0 / ' + total + ' 篇</span></p></div>');
            
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
                    success: function(response) {
                        currentIndex++;
                        
                        if (response.success) {
                            totalCreated++;
                            $('#analysis-progress').text('进度: ' + currentIndex + ' / ' + total + 
                                ' 篇（已创建: ' + totalCreated + ' 个结构）');
                        } else {
                            totalSkipped++;
                            errors.push('文章' + postId + ': ' + (response.data.message || '失败'));
                            $('#analysis-progress').text('进度: ' + currentIndex + ' / ' + total + 
                                ' 篇（已创建: ' + totalCreated + ' 个，跳过: ' + totalSkipped + ' 个）');
                        }
                        
                        // 继续处理下一篇
                        processNext();
                    },
                    error: function(xhr, status, error) {
                        currentIndex++;
                        totalSkipped++;
                        
                        let errorMsg = status === 'timeout' ? '超时' : '请求失败';
                        errors.push('文章' + postId + ': ' + errorMsg);
                        
                        $('#analysis-progress').text('进度: ' + currentIndex + ' / ' + total + 
                            ' 篇（已创建: ' + totalCreated + ' 个，跳过: ' + totalSkipped + ' 个）');
                        
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
                    message = '分析完成！已处理 ' + total + ' 篇文章，创建 ' + totalCreated + ' 个新结构。';
                    if (totalSkipped > 0) {
                        message += '（跳过 ' + totalSkipped + ' 篇）';
                    }
                } else if (total > 0) {
                    message = '分析完成，处理了 ' + total + ' 篇文章，但未创建新结构（可能已存在相似结构或提取失败）。';
                    noticeClass = 'notice-warning';
                } else {
                    message = '分析完成，没有待处理的高表现文章。';
                    noticeClass = 'notice-info';
                }
                
                if (errors.length > 0 && errors.length <= 5) {
                    message += '<br><small>错误: ' + errors.join('; ') + '</small>';
                    noticeClass = totalCreated > 0 ? 'notice-warning' : 'notice-error';
                } else if (errors.length > 5) {
                    message += '<br><small>共 ' + errors.length + ' 个错误</small>';
                    noticeClass = totalCreated > 0 ? 'notice-warning' : 'notice-error';
                }
                
                $result.html('<div class="notice ' + noticeClass + '"><p>' + message + '</p></div>');
                
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
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    // 刷新数据
                    loadDataDrivenStructures();
                    loadDiversityData();
                } else {
                    $result.html('<div class="notice notice-error"><p>更新失败: ' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>请求失败，请检查网络连接</p></div>');
            },
            complete: function() {
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
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p>缓存已清除</p></div>');
                } else {
                    $result.html('<div class="notice notice-error"><p>清除失败: ' + response.data.message + '</p></div>');
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>请求失败，请检查网络连接</p></div>');
            },
            complete: function() {
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
            case 'exploration': return '探索';
            case 'exploitation': return '利用';
            case 'fallback': return '回退';
            default: return method || '-';
        }
    }
    
    function showNotice(message, type) {
        // 移除已存在的消息
        $('.temp-notice').remove();
        
        const alertClass = type === 'success' ? 'notice-success' : 
                          type === 'error' ? 'notice-error' : 
                          type === 'warning' ? 'notice-warning' : 'notice-info';
        
        const html = `<div class="notice ${alertClass} temp-notice is-dismissible" style="margin: 10px 0;">
            <p>${message}</p>
        </div>`;
        
        $('#smart-optimization-page h1').after(html);
        
        // 3秒后自动消失
        setTimeout(function() {
            $('.temp-notice').fadeOut(300, function() {
                $(this).remove();
            });
        }, type === 'error' ? 5000 : 3000);
    }
});
