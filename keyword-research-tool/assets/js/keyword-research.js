jQuery(document).ready(function($) {
    // Check for localized data first
    if (typeof keywordResearchToolData === 'undefined' || !keywordResearchToolData.ajaxurl || !keywordResearchToolData.nonce) {
        console.error('Keyword Research Tool: Missing or incomplete localization data (keywordResearchToolData).');
        alert('关键数据加载失败，插件无法正常工作。请联系管理员。');
        return;
    }

    // DOM Elements
    const app = $('#keyword-research-tool-app');
    const startBtn = app.find('#start-mining-btn');
    const baseKeywordsInput = app.find('#base-keywords-input');
    const resultsSection = app.find('#keyword-results-section');
    const resultsTbody = app.find('#keyword-results-tbody');
    const resultsCountSpan = app.find('#results-count');
    const selectedKeywordsOutput = app.find('#selected-keywords-output');
    const copyBtn = app.find('#copy-selected-btn');
    const clearBtn = app.find('#clear-selected-btn');
    const copyFeedback = app.find('#copy-feedback');
    const selectAllCheckbox = app.find('#cb-select-all-1');
    const selectAllBtn = app.find('#select-all-results');
    const deselectAllBtn = app.find('#deselect-all-results');
    const progressSection = app.find('#progress-section');
    const progressBar = app.find('#progress-bar');
    const progressStatusText = app.find('#progress-status-text');

    let selectedKeywords = new Set();

    // --- AJAX Function (Safe Version) ---
    function ajaxRequest(action, data, successCallback, errorCallback) {
        const requestData = $.extend({}, {
            action: action,
            _ajax_nonce: keywordResearchToolData.nonce
        }, data);

        $.ajax({
            url: keywordResearchToolData.ajaxurl,
            type: 'POST',
            data: requestData,
            success: successCallback,
            error: errorCallback
        });
    }

    // --- Main Orchestration Logic for Segmented Mining ---
    function startOrchestration() {
        const baseKeyword = baseKeywordsInput.val().trim();
        if (baseKeyword === '') {
            alert('请输入一个基础关键词。');
            return;
        }

        // 获取选中的数据源
        const selectedDataSources = [];
        $('input[name="data_sources[]"]:checked').each(function() {
            selectedDataSources.push($(this).val());
        });
        
        // 获取挖掘深度（当前固定为1）
        const depth = $('#mining-depth').val();
        const langSpecifics = $('#srt-language-specifics').val();
        
        if (selectedDataSources.length === 0) {
            alert('请至少选择一个数据源。');
            return;
        }

        // --- Reset UI ---
        startBtn.prop('disabled', true);
        resultsSection.hide();
        resultsTbody.empty();
        resultsCountSpan.text('');
        selectedKeywords.clear();
        updateSelectedKeywordsOutput();
        progressSection.show();

        // 生成会话ID
        const sessionId = 'session_' + Date.now() + '_' + Math.floor(Math.random() * 10000);
        
        // --- Start mining process ---
        // First, initialize the mining task to get total steps
        const dataSourcesText = selectedDataSources.map(ds => {
            switch(ds) {
                case 'default': return '谷歌';
                case 'yt': return 'YouTube';
                case 'sh': return '购物';
                case 'baidu': return '百度';
                case 'duckduckgo': return 'DuckDuckGo';
                case 'wikipedia': return '维基百科';
                case 'taobao': return '淘宝';
                  default: return ds;
            }
        }).join(', ');
        
        progressStatusText.text(`正在初始化挖掘任务 [${dataSourcesText}]...`);
        progressBar.css('width', '0%').text('0%');
        
        // Initialize the mining task to get step count
        ajaxRequest(
            'keyword_research_mine',
            { 
                keyword: encodeURIComponent(baseKeyword),
                data_sources: selectedDataSources,
                depth: depth,
                session_id: sessionId,
                lang_specifics: langSpecifics
            },
            function(response) { // Success Callback
                if (response.success) {
                    progressStatusText.text(`初始化完成，共${response.data.total_steps}个步骤，开始分段挖掘...`);
                    
                    // Start segmented mining
                    startSegmentedMining(baseKeyword, sessionId, selectedDataSources, response.data.total_steps, response.data.lang_specifics);
                } else {
                    alert(`初始化挖掘任务失败: ${response.data || '未知错误'}`);
                    // --- Restore UI ---
                    startBtn.prop('disabled', false);
                    progressSection.hide();
                    progressBar.css('width', '0%').text('0%');
                    progressStatusText.text('');
                }
            },
            function() { // Error Callback
                alert(`初始化挖掘任务时请求失败。请检查网络。`);
                // --- Restore UI ---
                startBtn.prop('disabled', false);
                progressSection.hide();
                progressBar.css('width', '0%').text('0%');
                progressStatusText.text('');
            }
        );
    }
    
    function startSegmentedMining(baseKeyword, sessionId, selectedDataSources, totalSteps, langSpecifics) {
        let currentStep = 0;
        let completedSteps = 0;
        const allSteps = [];
        
        // Generate all steps for all data sources
        selectedDataSources.forEach(function(dataSource) {
            // Step 1: Base keyword
            allSteps.push({
                data_source: dataSource,
                step_type: 'base',
                step_param: '',
                description: '基础关键词挖掘'
            });
            
            // Step 2: Space extension
            allSteps.push({
                data_source: dataSource,
                step_type: 'space',
                step_param: '',
                description: '空格扩展挖掘'
            });
            
            // Step 3: Question prefixes
            const questionPrefixes = ['如何', '什么', '为什么', '哪里', '什么时候', '哪个', '最佳', '对比', '价格', '购买', '评测'];
            questionPrefixes.forEach(function(prefix) {
                allSteps.push({
                    data_source: dataSource,
                    step_type: 'question',
                    step_param: prefix,
                    description: `问题前缀-${prefix}`
                });
            });
            
            // Step 4: Letter suffixes
            const alphabet = 'abcdefghijklmnopqrstuvwxyz'.split('');
            alphabet.forEach(function(letter) {
                allSteps.push({
                    data_source: dataSource,
                    step_type: 'letter',
                    step_param: letter,
                    description: `字母后缀-${letter}`
                });
            });
        });
        
        // Process each step sequentially with a small delay to prevent overwhelming the server
        function processNextStep() {
            if (completedSteps >= allSteps.length) {
                // All steps completed, finalize the mining
                finalizeMining(baseKeyword, sessionId);
                return;
            }
            
            const step = allSteps[completedSteps];
            completedSteps++;
            
            const stepData = {
                keyword: encodeURIComponent(baseKeyword),
                session_id: sessionId,
                data_source: step.data_source,
                step_type: step.step_type,
                step_param: step.step_param,
                current_step: completedSteps,
                total_steps: totalSteps,
                lang_specifics: langSpecifics
            };
            
            // Update progress display
            const dataSourceName = step.data_source === 'default' ? '谷歌' :
                                 step.data_source === 'yt' ? 'YouTube' :
                                 step.data_source === 'sh' ? '购物' :
                                 step.data_source === 'baidu' ? '百度' :
                                 step.data_source === 'duckduckgo' ? 'DuckDuckGo' :
                                 step.data_source === 'wikipedia' ? '维基百科' :
                                 step.data_source === 'taobao' ? '淘宝' : step.data_source;
            progressStatusText.text(`正在执行 [${dataSourceName}] - ${step.description} (${completedSteps}/${totalSteps})`);
            
            // Make AJAX request for this step
            ajaxRequest(
                'keyword_research_segmented_mine',
                stepData,
                function(response) { // Success Callback
                    if (response.success) {
                        // Update progress bar
                        const progress = Math.round((completedSteps / totalSteps) * 100);
                        progressBar.css('width', progress + '%').text(progress + '%');
                        
                        // Continue to next step
                        setTimeout(processNextStep, 100); // Small delay to prevent overwhelming
                    } else {
                        alert(`执行步骤失败: ${response.data || '未知错误'}`);
                        // --- Restore UI ---
                        startBtn.prop('disabled', false);
                        progressSection.hide();
                        progressBar.css('width', '0%').text('0%');
                        progressStatusText.text('');
                    }
                },
                function() { // Error Callback
                    alert(`执行步骤请求失败。请检查网络。`);
                    // --- Restore UI ---
                    startBtn.prop('disabled', false);
                    progressSection.hide();
                    progressBar.css('width', '0%').text('0%');
                    progressStatusText.text('');
                }
            );
        }
        
        // Start processing steps
        processNextStep();
    }
    
    function finalizeMining(baseKeyword, sessionId) {
        progressStatusText.text('正在合并结果并去重...');
        progressBar.css('width', '90%').text('90%');
        
        ajaxRequest(
            'keyword_research_finalize_mine',
            { 
                keyword: encodeURIComponent(baseKeyword),
                session_id: sessionId
            },
            function(response) { // Success Callback
                if (response.success) {
                    // Update progress to 100%
                    progressBar.css('width', '100%').text('100%');
                    progressStatusText.text(response.data.message);
                    
                    // De-duplicate and render results
                    const uniqueKeywords = [...new Set(response.data.keywords)];
                    resultsCountSpan.text(`(共 ${uniqueKeywords.length} 个)`);
                    renderResults(uniqueKeywords);
                    resultsSection.show();
                } else {
                    alert(`合并结果失败: ${response.data || '未知错误'}`);
                }
                // --- Restore UI ---
                startBtn.prop('disabled', false);
                progressSection.hide();
                progressBar.css('width', '0%').text('0%');
                progressStatusText.text('');
            },
            function() { // Error Callback
                alert(`合并结果请求失败。请检查网络。`);
                // --- Restore UI ---
                startBtn.prop('disabled', false);
                progressSection.hide();
                progressBar.css('width', '0%').text('0%');
                progressStatusText.text('');
            }
        );
    }

    startBtn.on('click', startOrchestration);


    // --- Trend Analysis (remains the same) ---
    resultsTbody.on('click', '.analyze-trend-btn', function() {
        const btn = $(this);
        const cell = btn.closest('.trend-cell');
        const keyword = btn.data('keyword');
        cell.addClass('loading');
        ajaxRequest(
            'keyword_research_trend',
            { keyword: encodeURIComponent(keyword) },
            function(response) {
                cell.removeClass('loading');
                if (response.success && response.data) {
                    const trend = response.data;
                    let trendHTML = `平均热度: <strong>${trend.average_interest.toFixed(2)}</strong>`;
                    cell.html(`<div class="trend-data">${trendHTML}</div>`);
                } else {
                    const errorMessage = response.data || '获取失败';
                    cell.html(`<span class="error">${errorMessage}</span>`);
                }
            },
            function() {
                cell.removeClass('loading');
                cell.html('<span class="error">请求错误</span>');
            }
        );
    });

    // --- Other UI Handlers (remain the same) ---
    resultsTbody.on('change', 'input[type="checkbox"]', function() {
        const checkbox = $(this);
        const keyword = checkbox.val();
        if (checkbox.is(':checked')) {
            selectedKeywords.add(keyword);
        } else {
            selectedKeywords.delete(keyword);
        }
        updateSelectedKeywordsOutput();
        updateSelectAllCheckboxState();
    });

    copyBtn.on('click', function() {
        if (selectedKeywordsOutput.val().trim() === '') {
            alert('没有可以复制的关键词。');
            return;
        }
        selectedKeywordsOutput.get(0).select();
        document.execCommand('copy');
        copyFeedback.text('已成功复制到剪贴板！').show().fadeOut(3000);
    });

    clearBtn.on('click', function() {
        selectedKeywords.clear();
        updateSelectedKeywordsOutput();
        resultsTbody.find('input[type="checkbox"]').prop('checked', false);
        updateSelectAllCheckboxState();
    });
    
    selectAllCheckbox.on('click', function() {
        const isChecked = $(this).is(':checked');
        resultsTbody.find('input[type="checkbox"]').prop('checked', isChecked).trigger('change');
    });

    // 修复全选/取消全选逻辑
    selectAllBtn.on('click', function() {
        selectAllCheckbox.prop('checked', true);
        resultsTbody.find('input[type="checkbox"]').prop('checked', true).trigger('change');
    });
    deselectAllBtn.on('click', function() {
        selectAllCheckbox.prop('checked', false);
        resultsTbody.find('input[type="checkbox"]').prop('checked', false).trigger('change');
    });

    // --- Helper Functions ---
    function renderResults(keywords) {
        let html = '';
        if (!keywords || keywords.length === 0) {
            html = '<tr><td colspan="3">未找到相关关键词。</td></tr>';
        } else {
            keywords.forEach(keyword => {
                const escapedKeyword = escapeHTML(keyword);
                html += `
                    <tr>
                        <th scope="row" class="check-column">
                            <input type="checkbox" name="keyword[]" value="${escapedKeyword}">
                        </th>
                        <td class="keyword-column">${escapedKeyword}</td>
                        <td class="trend-cell">
                            <span class="dashicons dashicons-chart-bar analyze-trend-btn" data-keyword="${escapedKeyword}"></span>
                            <span class="spinner"></span>
                        </td>
                    </tr>
                `;
            });
        }
        resultsTbody.html(html);
        updateSelectAllCheckboxState();
    }

    function updateSelectedKeywordsOutput() {
        selectedKeywordsOutput.val(Array.from(selectedKeywords).join('\n'));
    }
    
    function updateSelectAllCheckboxState() {
        const allCheckboxes = resultsTbody.find('input[type="checkbox"]');
        const checkedCount = allCheckboxes.filter(':checked').length;
        if (allCheckboxes.length > 0) {
            selectAllCheckbox.prop('checked', checkedCount === allCheckboxes.length);
        } else {
            selectAllCheckbox.prop('checked', false);
        }
    }

    function escapeHTML(str) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
            '/': '&#x2F;'
        };
        return str.replace(/[&<>'"/]/g, function(m) { return map[m]; });
    }
});