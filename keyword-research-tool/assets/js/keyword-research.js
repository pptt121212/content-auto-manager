jQuery(document).ready(function ($) {
    // Check for localized data first
    if (typeof keywordResearchToolData === 'undefined' || !keywordResearchToolData.ajaxurl || !keywordResearchToolData.nonce) {
        console.error('Keyword Research Tool: Missing or incomplete localization data (keywordResearchToolData).');
        console.error('typeof keywordResearchToolData:', typeof keywordResearchToolData);
        alert(wp.i18n.__('关键数据加载失败，插件无法正常工作。请联系管理员。\n\n请在浏览器控制台查看详细错误信息。', 'yali-ai-writer'));
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
            alert(wp.i18n.__('请输入一个基础关键词。', 'yali-ai-writer'));
            return;
        }

        // 获取选中的数据源
        const selectedDataSources = [];
        $('input[name="data_sources[]"]:checked').each(function () {
            selectedDataSources.push($(this).val());
        });

        // 获取深度挖掘开关状态（默认关闭）
        const deepMining = $('#deep-mining-toggle').is(':checked');
        const langSpecifics = $('#srt-language-specifics').val();

        if (selectedDataSources.length === 0) {
            alert(wp.i18n.__('请至少选择一个数据源。', 'yali-ai-writer'));
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
            switch (ds) {
                case 'default': return wp.i18n.__('谷歌', 'yali-ai-writer');
                case 'yt': return 'YouTube';
                case 'sh': return wp.i18n.__('购物', 'yali-ai-writer');
                case 'baidu': return wp.i18n.__('百度', 'yali-ai-writer');
                case 'duckduckgo': return 'DuckDuckGo';
                case 'wikipedia': return wp.i18n.__('维基百科', 'yali-ai-writer');
                case 'taobao': return wp.i18n.__('淘宝', 'yali-ai-writer');
                default: return ds;
            }
        }).join(', ');

        progressStatusText.text(wp.i18n.__('正在初始化挖掘任务 [{dataSourcesText}]...', 'yali-ai-writer').replace('{dataSourcesText}', dataSourcesText));
        progressBar.css('width', '0%').text('0%');

        // Initialize the mining task to get step count
        ajaxRequest(
            'keyword_research_mine',
            {
                keyword: baseKeyword,
                data_sources: selectedDataSources,
                deep_mining: deepMining,
                session_id: sessionId,
                lang_specifics: langSpecifics
            },
            function (response) { // Success Callback
                if (response.success) {
                    // Start segmented mining (Frontend will calculate exact steps)
                    startSegmentedMining(baseKeyword, sessionId, selectedDataSources, null, response.data.lang_specifics, deepMining);
                } else {
                    alert(wp.i18n.__('初始化挖掘任务失败: ', 'yali-ai-writer') + (response.data || wp.i18n.__('未知错误', 'yali-ai-writer')));
                    // --- Restore UI ---
                    startBtn.prop('disabled', false);
                    progressSection.hide();
                    progressBar.css('width', '0%').text('0%');
                    progressStatusText.text('');
                }
            },
            function () { // Error Callback
                alert(wp.i18n.__('初始化挖掘任务时请求失败。请检查网络。', 'yali-ai-writer'));
                // --- Restore UI ---
                startBtn.prop('disabled', false);
                progressSection.hide();
                progressBar.css('width', '0%').text('0%');
                progressStatusText.text('');
            }
        );
    }

    function startSegmentedMining(baseKeyword, sessionId, selectedDataSources, totalSteps, langSpecifics, deepMining) {
        let currentStep = 0;
        let completedSteps = 0;
        const allSteps = [];

        // 根据语言设置获取对应的挖掘词
        // langSpecifics 格式: "cn-zh-CN" (国家-语言-地区) 或 "us-en" (国家-语言)
        const langCode = langSpecifics ? langSpecifics.split('-')[1] || langSpecifics.split('-')[0] : 'en';

        // 多语言挖掘词配置
        const miningTerms = {
            // 中文 (简体)
            'zh': {
                questionPrefixes: ['如何', '什么', '为什么', '哪里', '什么时候', '哪个', '最佳', '对比', '价格', '购买', '评测'],
                geoQuestions: ['是什么', '如何', '教程', '原理', '意义', '作用', '步骤', '含义'],
                intentModifiers: ['区别', '最佳', '推荐', '对比', 'vs', 'best', 'review', 'checklist']
            },
            // 英语
            'en': {
                questionPrefixes: ['how to', 'what is', 'why', 'where', 'when', 'which', 'best', 'vs', 'price', 'buy', 'review'],
                geoQuestions: ['what is', 'how to', 'tutorial', 'principle', 'meaning', 'guide', 'definition', 'steps', 'basics', 'introduction'],
                intentModifiers: ['vs', 'best', 'review', 'checklist', 'comparison', 'alternatives', 'top', 'guide']
            },
            // 日语
            'ja': {
                questionPrefixes: ['使い方', 'とは', 'なぜ', 'どこ', 'いつ', 'どちら', 'ベスト', '比較', '価格', '購入', 'レビュー'],
                geoQuestions: ['とは', '使い方', 'チュートリアル', '原理', '意味', 'ガイド', '定義', '手順', '基本', '紹介'],
                intentModifiers: ['違い', 'おすすめ', '比較', 'vs', 'best', 'review', 'checklist']
            },
            // 韩语
            'ko': {
                questionPrefixes: ['사용법', '란', '왜', '어디', '언제', '어느', '최고', '비교', '가격', '구매', '리뷰'],
                geoQuestions: ['란', '사용법', '튜토리얼', '원리', '의미', '가이드', '정의', '단계', '기초', '소개'],
                intentModifiers: ['차이', '추천', '비교', 'vs', 'best', 'review', 'checklist']
            },
            // 德语
            'de': {
                questionPrefixes: ['wie', 'was ist', 'warum', 'wo', 'wann', 'welche', 'beste', 'vs', 'preis', 'kaufen', 'bewertung'],
                geoQuestions: ['was ist', 'wie', 'tutorial', 'prinzip', 'bedeutung', 'anleitung', 'definition', 'schritte', 'grundlagen', 'einführung'],
                intentModifiers: ['vs', 'beste', 'bewertung', 'checkliste', 'vergleich', 'alternativen', 'top', 'anleitung']
            },
            // 法语
            'fr': {
                questionPrefixes: ['comment', 'qu\'est-ce que', 'pourquoi', 'où', 'quand', 'quel', 'meilleur', 'vs', 'prix', 'acheter', 'avis'],
                geoQuestions: ['qu\'est-ce que', 'comment', 'tutoriel', 'principe', 'signification', 'guide', 'définition', 'étapes', 'bases', 'introduction'],
                intentModifiers: ['vs', 'meilleur', 'avis', 'checklist', 'comparaison', 'alternatives', 'top', 'guide']
            },
            // 西班牙语
            'es': {
                questionPrefixes: ['cómo', 'qué es', 'por qué', 'dónde', 'cuándo', 'cuál', 'mejor', 'vs', 'precio', 'comprar', 'reseña'],
                geoQuestions: ['qué es', 'cómo', 'tutorial', 'principio', 'significado', 'guía', 'definición', 'pasos', 'básico', 'introducción'],
                intentModifiers: ['vs', 'mejor', 'reseña', 'checklist', 'comparación', 'alternativas', 'top', 'guía']
            },
            // 意大利语
            'it': {
                questionPrefixes: ['come', 'cosa è', 'perché', 'dove', 'quando', 'quale', 'migliore', 'vs', 'prezzo', 'acquistare', 'recensione'],
                geoQuestions: ['cosa è', 'come', 'tutorial', 'principio', 'significato', 'guida', 'definizione', 'passi', 'basi', 'introduzione'],
                intentModifiers: ['vs', 'migliore', 'recensione', 'checklist', 'confronto', 'alternative', 'top', 'guida']
            },
            // 荷兰语
            'nl': {
                questionPrefixes: ['hoe', 'wat is', 'waarom', 'waar', 'wanneer', 'welke', 'beste', 'vs', 'prijs', 'kopen', 'review'],
                geoQuestions: ['wat is', 'hoe', 'tutorial', 'principe', 'betekenis', 'gids', 'definitie', 'stappen', 'basis', 'introductie'],
                intentModifiers: ['vs', 'beste', 'review', 'checklist', 'vergelijking', 'alternatieven', 'top', 'gids']
            },
            // 葡萄牙语 (巴西)
            'pt': {
                questionPrefixes: ['como', 'o que é', 'por que', 'onde', 'quando', 'qual', 'melhor', 'vs', 'preço', 'comprar', 'avaliação'],
                geoQuestions: ['o que é', 'como', 'tutorial', 'princípio', 'significado', 'guia', 'definição', 'passos', 'básico', 'introdução'],
                intentModifiers: ['vs', 'melhor', 'avaliação', 'checklist', 'comparação', 'alternativas', 'top', 'guia']
            },
            // 阿拉伯语
            'ar': {
                questionPrefixes: ['كيف', 'ما هو', 'لماذا', 'أين', 'متى', 'أي', 'أفضل', 'ضد', 'السعر', 'شراء', 'مراجعة'],
                geoQuestions: ['ما هو', 'كيف', 'شرح', 'مبدأ', 'معنى', 'دليل', 'تعريف', 'خطوات', 'أساسيات', 'مقدمة'],
                intentModifiers: ['ضد', 'أفضل', 'مراجعة', 'قائمة', 'مقارنة', 'بدائل', 'أفضل', 'دليل']
            },
            // 印尼语
            'id': {
                questionPrefixes: ['cara', 'apa itu', 'mengapa', 'di mana', 'kapan', 'yang mana', 'terbaik', 'vs', 'harga', 'beli', 'ulasan'],
                geoQuestions: ['apa itu', 'cara', 'tutorial', 'prinsip', 'arti', 'panduan', 'definisi', 'langkah', 'dasar', 'pengenalan'],
                intentModifiers: ['vs', 'terbaik', 'ulasan', 'checklist', 'perbandingan', 'alternatif', 'top', 'panduan']
            }
        };

        // 获取当前语言的挖掘词，如果没有则使用英语
        const terms = miningTerms[langCode] || miningTerms['en'];

        // Generate steps PER ENGINE for parallel execution
        selectedDataSources.forEach(source => {
            // Step 1: Base keyword (Always performed)
            allSteps.push({
                data_source: source,
                step_type: 'base',
                step_param: '',
                description: wp.i18n.__('基础关键词挖掘', 'yali-ai-writer')
            });

            if (deepMining) {
                // Deep Mining: Execute all expansion steps

                // Step 2: Space extension
                allSteps.push({
                    data_source: source,
                    step_type: 'space',
                    step_param: '',
                    description: wp.i18n.__('空格扩展挖掘', 'yali-ai-writer')
                });

                // Step 3: Question prefixes
                terms.questionPrefixes.forEach(function (prefix) {
                    allSteps.push({
                        data_source: source,
                        step_type: 'question',
                        step_param: prefix,
                        description: wp.i18n.__('问题前缀-', 'yali-ai-writer') + prefix
                    });
                });

                // Step 4: Letter suffixes (a-z)
                const alphabet = 'abcdefghijklmnopqrstuvwxyz'.split('');
                alphabet.forEach(function (letter) {
                    allSteps.push({
                        data_source: source,
                        step_type: 'letter',
                        step_param: letter,
                        description: wp.i18n.__('字母后缀-', 'yali-ai-writer') + letter
                    });
                });

                // Step 5: Letter prefixes (a-z)
                alphabet.forEach(function (letter) {
                    allSteps.push({
                        data_source: source,
                        step_type: 'letter_prefix',
                        step_param: letter,
                        description: wp.i18n.__('字母前缀-', 'yali-ai-writer') + letter
                    });
                });

                // Step 6: GEO Questions
                terms.geoQuestions.forEach(function (q) {
                    allSteps.push({
                        data_source: source,
                        step_type: 'geo_question',
                        step_param: q,
                        description: wp.i18n.__('GEO挖掘-', 'yali-ai-writer') + q
                    });
                });

                // Step 7: Intent Modifiers
                terms.intentModifiers.forEach(function (m) {
                    allSteps.push({
                        data_source: source,
                        step_type: 'intent_modifier',
                        step_param: m,
                        description: wp.i18n.__('意图挖掘-', 'yali-ai-writer') + m
                    });
                });
            }
        });

        const totalStepsCount = allSteps.length;
        let activeTasks = 0;
        let stepPointer = 0;
        let hasError = false;
        let isFinalizing = false;
        const CONCURRENCY_LIMIT = 3;

        const sourceMap = {
            'default': wp.i18n.__('谷歌', 'yali-ai-writer'),
            'yt': 'YouTube',
            'sh': wp.i18n.__('购物', 'yali-ai-writer'),
            'baidu': wp.i18n.__('百度', 'yali-ai-writer'),
            'duckduckgo': 'DuckDuckGo',
            'wikipedia': wp.i18n.__('维基百科', 'yali-ai-writer'),
            'taobao': wp.i18n.__('淘宝', 'yali-ai-writer')
        };

        const activeSourceNames = new Set();

        function runNextTask() {
            if (hasError || stepPointer >= totalStepsCount) {
                if (activeTasks === 0 && !hasError && !isFinalizing) {
                    isFinalizing = true;
                    finalizeMining(baseKeyword, sessionId);
                }
                return;
            }

            while (activeTasks < CONCURRENCY_LIMIT && stepPointer < totalStepsCount) {
                const step = allSteps[stepPointer];
                const currentIndex = ++stepPointer;
                activeTasks++;

                const engineName = sourceMap[step.data_source] || step.data_source;
                activeSourceNames.add(engineName);

                // Update Status Text
                updateStatusText(engineName, step.description, currentIndex, totalStepsCount);

                const stepData = {
                    keyword: baseKeyword,
                    session_id: sessionId,
                    data_source: step.data_source,
                    step_type: step.step_type,
                    step_param: step.step_param,
                    current_step: currentIndex,
                    total_steps: totalStepsCount,
                    lang_specifics: langSpecifics
                };

                ajaxRequest(
                    'keyword_research_segmented_mine',
                    stepData,
                    function (response) {
                        activeTasks--;
                        activeSourceNames.delete(engineName);

                        if (response.success) {
                            completedSteps++;
                            const progress = Math.round((completedSteps / totalStepsCount) * 100);
                            progressBar.css('width', progress + '%').text(progress + '%');
                            runNextTask();
                        } else {
                            handleTaskError(response.data || wp.i18n.__('未知错误', 'yali-ai-writer'));
                        }
                    },
                    function () {
                        activeTasks--;
                        activeSourceNames.delete(engineName);
                        handleTaskError(wp.i18n.__('网络请求失败', 'yali-ai-writer'));
                    }
                );
            }
        }

        function updateStatusText(currentEngine, description, current, total) {
            const engines = Array.from(activeSourceNames).join(', ');
            progressStatusText.text(wp.i18n.__('正在执行 [{engines}] - {description} ({current}/{total})', 'yali-ai-writer')
                .replace('{engines}', engines)
                .replace('{description}', description)
                .replace('{current}', current)
                .replace('{total}', total));
        }

        function handleTaskError(errorMsg) {
            if (hasError) return;
            hasError = true;
            alert(wp.i18n.__('执行步骤失败: ', 'yali-ai-writer') + errorMsg);
            startBtn.prop('disabled', false);
            progressSection.hide();
            progressBar.css('width', '0%').text('0%');
            progressStatusText.text('');
        }

        // Start processing tasks
        runNextTask();
    }

    function finalizeMining(baseKeyword, sessionId) {
        progressStatusText.text(wp.i18n.__('正在合并结果并去重...', 'yali-ai-writer'));
        progressBar.css('width', '90%').text('90%');

        ajaxRequest(
            'keyword_research_finalize_mine',
            {
                keyword: baseKeyword,
                session_id: sessionId
            },
            function (response) { // Success Callback
                if (response.success) {
                    // Update progress to 100%
                    progressBar.css('width', '100%').text('100%');
                    progressStatusText.text(response.data.message);

                    // De-duplicate and render results
                    const uniqueKeywords = [...new Set(response.data.keywords)];
                    resultsCountSpan.text(wp.i18n.__('(共 {count} 个)', 'yali-ai-writer').replace('{count}', uniqueKeywords.length));
                    renderResults(uniqueKeywords);
                    resultsSection.show();
                } else {
                    alert(wp.i18n.__('合并结果失败: ', 'yali-ai-writer') + (response.data || wp.i18n.__('未知错误', 'yali-ai-writer')));
                }
                // --- Restore UI ---
                startBtn.prop('disabled', false);
                progressSection.hide();
                progressBar.css('width', '0%').text('0%');
                progressStatusText.text('');
            },
            function () { // Error Callback
                alert(wp.i18n.__('合并结果请求失败。请检查网络。', 'yali-ai-writer'));
                // --- Restore UI ---
                startBtn.prop('disabled', false);
                progressSection.hide();
                progressBar.css('width', '0%').text('0%');
                progressStatusText.text('');
            }
        );
    }

    startBtn.on('click', function () {
        startOrchestration();
    });


    // --- Trend Analysis Queue System ---
    let trendQueue = [];
    let isTrendRequestRunning = false;

    function processTrendQueueSafe() {
        if (isTrendRequestRunning || trendQueue.length === 0) {
            return;
        }

        isTrendRequestRunning = true;
        const task = trendQueue.shift();
        const { btn, cell, keyword } = task;

        cell.addClass('loading');

        // Capture the underlying promise from ajaxRequest
        const jqXHR = ajaxRequest(
            'keyword_research_trend',
            { keyword: keyword },
            function (response) {
                cell.removeClass('loading');
                if (response.success && response.data) {
                    const trend = response.data;
                    let trendHTML = wp.i18n.__('平均热度: ', 'yali-ai-writer') + `<strong class="yali-text-success">${trend.average_interest.toFixed(2)}</strong>`;
                    cell.html(`<div class="trend-data">${trendHTML}</div>`);
                } else {
                    const errorMessage = response.data || wp.i18n.__('获取失败', 'yali-ai-writer');
                    cell.html(`<span class="error yali-text-danger">${errorMessage}</span>`);
                }
            },
            function () {
                cell.removeClass('loading');
                cell.html('<span class="error yali-text-danger">' + wp.i18n.__('请求错误', 'yali-ai-writer') + '</span>');
            }
        );

        // Ensure queue progression regardless of what happens in the wrapper's callbacks
        if (jqXHR && typeof jqXHR.always === 'function') {
            jqXHR.always(function () {
                setTimeout(function () {
                    isTrendRequestRunning = false;
                    processTrendQueueSafe();
                }, 800);
            });
        } else {
            // Fallback if wrapper doesn't return the promise: intercept and hope wrapper triggers callbacks
            setTimeout(function () {
                isTrendRequestRunning = false;
                processTrendQueueSafe();
            }, 3000); // Failsafe timeout
        }
    }

    resultsTbody.on('click', '.analyze-trend-btn', function () {
        const btn = $(this);
        const cell = btn.closest('.trend-cell');
        const keyword = btn.data('keyword');

        // Prevent adding same task multiple times if already loading or queued
        if (cell.hasClass('loading') || cell.hasClass('queued')) {
            return;
        }

        cell.addClass('queued');
        trendQueue.push({ btn, cell, keyword });
        processTrendQueueSafe();
    });

    // --- Other UI Handlers (remain the same) ---
    resultsTbody.on('change', 'input[type="checkbox"]', function () {
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

    copyBtn.on('click', function () {
        if (selectedKeywordsOutput.val().trim() === '') {
            alert(wp.i18n.__('没有可以复制的关键词。', 'yali-ai-writer'));
            return;
        }
        selectedKeywordsOutput.get(0).select();
        document.execCommand('copy');
        copyFeedback.text(wp.i18n.__('已成功复制到剪贴板！', 'yali-ai-writer')).show().fadeOut(3000);
    });

    clearBtn.on('click', function () {
        selectedKeywords.clear();
        updateSelectedKeywordsOutput();
        resultsTbody.find('input[type="checkbox"]').prop('checked', false);
        updateSelectAllCheckboxState();
    });

    selectAllCheckbox.on('click', function () {
        const isChecked = $(this).is(':checked');
        resultsTbody.find('input[type="checkbox"]').prop('checked', isChecked).trigger('change');
    });

    // 修复全选/取消全选逻辑
    selectAllBtn.on('click', function () {
        selectAllCheckbox.prop('checked', true);
        resultsTbody.find('input[type="checkbox"]').prop('checked', true).trigger('change');
    });
    deselectAllBtn.on('click', function () {
        selectAllCheckbox.prop('checked', false);
        resultsTbody.find('input[type="checkbox"]').prop('checked', false).trigger('change');
    });

    // --- Helper Functions ---
    function renderResults(keywords) {
        let html = '';
        if (!keywords || keywords.length === 0) {
            html = '<tr><td colspan="3">' + wp.i18n.__('未找到相关关键词。', 'yali-ai-writer') + '</td></tr>';
        } else {
            keywords.forEach(keyword => {
                const escapedKeyword = escapeHTML(keyword);
                html += `
                    <tr>
                        <th scope="row" class="yali-table-checkbox-cell">
                            <label class="yali-checkbox-label" style="justify-content: center;">
                                <input type="checkbox" name="keyword[]" value="${escapedKeyword}">
                                <span class="yali-checkbox-custom"></span>
                            </label>
                        </th>
                        <td class="keyword-column">${escapedKeyword}</td>
                        <td class="trend-cell">
                            <button type="button" class="yali-btn yali-btn-secondary yali-btn-small analyze-trend-btn" data-keyword="${escapedKeyword}" title="${wp.i18n.__('分析趋势', 'yali-ai-writer')}">
                                <span class="dashicons dashicons-chart-bar"></span>
                            </button>
                            <span class="yali-loading-spinner" style="display:none; width: 16px; height: 16px; border: 2px solid #e2e8f0; border-top-color: #3b82f6; border-radius: 50%; animation: yali-spin 1s linear infinite;"></span>
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
        return str.replace(/[&<>'"/]/g, function (m) { return map[m]; });
    }
});