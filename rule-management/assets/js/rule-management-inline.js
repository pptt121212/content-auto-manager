/**
 * Rule Management Inline Scripts
 * Extracted from rule-management.php for WordPress.org compliance
 * Uses wp.i18n.__() for translations
 */

(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // --- 变量定义 ---
        var ruleTypeRadios = document.querySelectorAll('input[name="rule_type"]');
        var randomSelectionRow = document.getElementById('condition-random-selection');
        var fixedArticlesRow = document.getElementById('condition-fixed-articles');
        var uploadTextRow = document.getElementById('condition-upload-text');
        var importKeywordsRow = document.getElementById('condition-import-keywords');
        var randomCategoriesRow = document.getElementById('condition-random-categories');
        var articleSearchInput = document.getElementById('article-search-input');
        var articleSearchButton = document.getElementById('article-search-button');
        var searchResultsDiv = document.getElementById('search-results');
        var selectedArticlesList = document.getElementById('selected-articles-list');
        var selectedArticlesInput = document.getElementById('selected-articles-input');
        var catChecklistContainer = document.getElementById('category-checklist-container');
        var selectAllCatsBtn = document.getElementById('select-all-cats');
        var deselectAllCatsBtn = document.getElementById('deselect-all-cats');
        var selectAllRandomCatsBtn = document.getElementById('select-all-random-cats');
        var deselectAllRandomCatsBtn = document.getElementById('deselect-all-random-cats');
        var uploadTextInput = document.getElementById('upload_text_content');
        var currentCountSpan = document.getElementById('current-count');
        var keywordsInput = document.getElementById('keywords_content');
        var currentKeywordsCountSpan = document.getElementById('current-keywords-count');
        var collectUrlRewriteRow = document.getElementById('condition-collect-url-rewrite');
        var collectUrlInput = document.getElementById('collect_url_content');
        var currentUrlCountSpan = document.getElementById('current-url-count');
        var rowItemCount = document.getElementById('row-item-count');
        var rowReferenceMaterial = document.getElementById('row-reference-material');

        // 获取 localized data
        var ruleData = window.ruleManagementData || {};

        // 初始化已选文章
        var selectedArticles = [];
        var initialSelectedArticles = selectedArticlesInput ? selectedArticlesInput.value : '';
        if (initialSelectedArticles) {
            var articleIds = initialSelectedArticles.split(',').map(function(id) { return parseInt(id); });
            articleIds.forEach(function(id) {
                if (!isNaN(id)) {
                    selectedArticles.push({ id: id, title: '文章 #' + id });
                }
            });
            renderSelectedArticles();

            if (articleIds.length > 0) {
                fetchArticleTitles(articleIds);
            }
        }

        // --- 功能函数 ---

        // 获取文章标题
        function fetchArticleTitles(articleIds) {
            var data = new URLSearchParams();
            data.append('action', 'yali_ai_writer_get_article_titles');
            data.append('nonce', ruleData.nonce || '');
            data.append('article_ids', articleIds.join(','));

            fetch(ruleData.ajaxUrl || ajaxurl, {
                method: 'POST',
                body: data
            })
            .then(function(response) { return response.json(); })
            .then(function(result) {
                if (result.success && result.data.articles) {
                    result.data.articles.forEach(function(article) {
                        var item = selectedArticles.find(function(sa) { return sa.id === article.id; });
                        if (item) {
                            item.title = article.title;
                        }
                    });
                    renderSelectedArticles();
                }
            });
        }

        // 切换规则类型显示
        function toggleConditions() {
            var selectedType = document.querySelector('input[name="rule_type"]:checked');
            if (!selectedType) return;

            var typeValue = selectedType.value;

            if (randomSelectionRow) randomSelectionRow.style.display = 'none';
            if (fixedArticlesRow) fixedArticlesRow.style.display = 'none';
            if (uploadTextRow) uploadTextRow.style.display = 'none';
            if (importKeywordsRow) importKeywordsRow.style.display = 'none';
            if (randomCategoriesRow) randomCategoriesRow.style.display = 'none';
            if (collectUrlRewriteRow) collectUrlRewriteRow.style.display = 'none';
            // 默认显示规则循环次数和参考资料（除了采集网址仿写规则）
            if (rowItemCount) rowItemCount.style.display = '';
            if (rowReferenceMaterial) rowReferenceMaterial.style.display = '';

            switch(typeValue) {
                case 'random_selection':
                    if (randomSelectionRow) randomSelectionRow.style.display = '';
                    break;
                case 'fixed_articles':
                    if (fixedArticlesRow) fixedArticlesRow.style.display = '';
                    break;
                case 'upload_text':
                    if (uploadTextRow) uploadTextRow.style.display = '';
                    break;
                case 'import_keywords':
                    if (importKeywordsRow) importKeywordsRow.style.display = '';
                    // 导入关键词规则也支持参考资料
                    break;
                case 'random_categories':
                    if (randomCategoriesRow) randomCategoriesRow.style.display = '';
                    break;
                case 'collect_url_rewrite':
                    if (collectUrlRewriteRow) collectUrlRewriteRow.style.display = '';
                    // 采集网址仿写规则不需要循环次数和参考资料
                    if (rowItemCount) rowItemCount.style.display = 'none';
                    if (rowReferenceMaterial) rowReferenceMaterial.style.display = 'none';
                    break;
            }
        }

        // 渲染已选文章
        function renderSelectedArticles() {
            if (!selectedArticlesList) return;
            selectedArticlesList.innerHTML = '';
            selectedArticles.forEach(function(article) {
                var li = document.createElement('li');
                li.innerHTML = '<span class="article-title">' + escapeHtml(article.title) + '</span>' +
                    '<button type="button" class="button remove-article" data-id="' + article.id + '">' + wp.i18n.__('移除', 'yali-ai-writer') + '</button>';
                selectedArticlesList.appendChild(li);
            });
            updateSelectedArticlesInput();
        }

        // 更新已选文章输入框
        function updateSelectedArticlesInput() {
            if (selectedArticlesInput) {
                selectedArticlesInput.value = selectedArticles.map(function(a) { return a.id; }).join(',');
            }
        }

        // HTML 转义
        function escapeHtml(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // 搜索文章
        function searchArticles() {
            if (!articleSearchInput || !searchResultsDiv) return;
            var keyword = articleSearchInput.value.trim();
            if (!keyword) return;

            searchResultsDiv.innerHTML = '<p>' + wp.i18n.__('正在搜索...', 'yali-ai-writer') + '</p>';

            var data = new URLSearchParams();
            data.append('action', 'yali_ai_writer_search_articles');
            data.append('nonce', ruleData.nonce || '');
            data.append('search_term', keyword);

            fetch(ruleData.ajaxUrl || ajaxurl, {
                method: 'POST',
                body: data
            })
            .then(function(response) { return response.json(); })
            .then(function(result) {
                if (result.success && result.data.articles && result.data.articles.length > 0) {
                    searchResultsDiv.innerHTML = '';
                    result.data.articles.forEach(function(article) {
                        var div = document.createElement('div');
                        div.className = 'search-result-item';
                        div.innerHTML = '<span>' + escapeHtml(article.title) + '</span>' +
                            '<button type="button" class="button add-article" data-id="' + article.id + '" data-title="' + escapeHtml(article.title) + '">' + wp.i18n.__('添加', 'yali-ai-writer') + '</button>';
                        searchResultsDiv.appendChild(div);
                    });
                } else {
                    searchResultsDiv.innerHTML = '<p>' + wp.i18n.__('未找到文章。', 'yali-ai-writer') + '</p>';
                }
            })
            .catch(function() {
                searchResultsDiv.innerHTML = '<p>' + wp.i18n.__('搜索失败，请重试', 'yali-ai-writer') + '</p>';
            });
        }

        // 更新文本计数
        function updateTextCount() {
            if (!uploadTextInput || !currentCountSpan) return;
            var count = uploadTextInput.value.length;
            currentCountSpan.textContent = count;
        }

        // 更新关键词计数
        function updateKeywordsCount() {
            if (!keywordsInput || !currentKeywordsCountSpan) return;
            var lines = keywordsInput.value.split('\n').filter(function(line) { return line.trim(); });
            currentKeywordsCountSpan.textContent = lines.length;
        }

        // 更新 URL 计数
        function updateUrlCount() {
            if (!collectUrlInput || !currentUrlCountSpan) return;
            var lines = collectUrlInput.value.split('\n').filter(function(line) { return line.trim(); });
            currentUrlCountSpan.textContent = lines.length;
        }

        // 更新参考资料字符计数
        function updateReferenceMaterialCount() {
            var refInput = document.getElementById('reference_material');
            var countSpan = document.getElementById('reference-material-count');
            if (refInput && countSpan) {
                countSpan.textContent = refInput.value.length;
            }
        }

        // --- 事件监听 ---

        // 规则类型切换
        ruleTypeRadios.forEach(function(radio) {
            radio.addEventListener('change', toggleConditions);
        });

        // 搜索按钮
        if (articleSearchButton) {
            articleSearchButton.addEventListener('click', searchArticles);
        }

        // 搜索框回车
        if (articleSearchInput) {
            articleSearchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    searchArticles();
                }
            });
        }

        // 添加文章（事件委托）
        if (searchResultsDiv) {
            searchResultsDiv.addEventListener('click', function(e) {
                if (e.target.classList.contains('add-article')) {
                    var id = parseInt(e.target.dataset.id);
                    var title = e.target.dataset.title;
                    if (!selectedArticles.find(function(a) { return a.id === id; })) {
                        selectedArticles.push({ id: id, title: title });
                        renderSelectedArticles();
                    }
                }
            });
        }

        // 移除文章（事件委托）
        if (selectedArticlesList) {
            selectedArticlesList.addEventListener('click', function(e) {
                if (e.target.classList.contains('remove-article')) {
                    var id = parseInt(e.target.dataset.id);
                    selectedArticles = selectedArticles.filter(function(a) { return a.id !== id; });
                    renderSelectedArticles();
                }
            });
        }

        // 全选/取消全选分类
        if (selectAllCatsBtn && catChecklistContainer) {
            selectAllCatsBtn.addEventListener('click', function() {
                catChecklistContainer.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
                    cb.checked = true;
                });
            });
        }

        if (deselectAllCatsBtn && catChecklistContainer) {
            deselectAllCatsBtn.addEventListener('click', function() {
                catChecklistContainer.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
                    cb.checked = false;
                });
            });
        }

        // 随机分类全选/取消全选
        var randomCatContainer = document.getElementById('random-category-checklist-container');
        if (selectAllRandomCatsBtn && randomCatContainer) {
            selectAllRandomCatsBtn.addEventListener('click', function() {
                randomCatContainer.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
                    cb.checked = true;
                });
            });
        }

        if (deselectAllRandomCatsBtn && randomCatContainer) {
            deselectAllRandomCatsBtn.addEventListener('click', function() {
                randomCatContainer.querySelectorAll('input[type="checkbox"]').forEach(function(cb) {
                    cb.checked = false;
                });
            });
        }

        // 文本输入计数
        if (uploadTextInput) {
            uploadTextInput.addEventListener('input', updateTextCount);
        }

        // 关键词输入计数
        if (keywordsInput) {
            keywordsInput.addEventListener('input', updateKeywordsCount);
        }

        // URL 输入计数
        if (collectUrlInput) {
            collectUrlInput.addEventListener('input', updateUrlCount);
        }

        // 参考资料输入计数
        var refInput = document.getElementById('reference_material');
        if (refInput) {
            refInput.addEventListener('input', updateReferenceMaterialCount);
        }

        // --- URL 内容采集功能 ---
        var fetchContentBtn = document.getElementById('fetch-url-content-btn');
        var fetchUrlInput = document.getElementById('fetch_url_input');
        var fetchStatusDiv = document.getElementById('fetch-status');

        if (fetchContentBtn && fetchUrlInput) {
            fetchContentBtn.addEventListener('click', function() {
                var url = fetchUrlInput.value.trim();
                if (!url) {
                    if (fetchStatusDiv) {
                        fetchStatusDiv.textContent = wp.i18n.__('请输入网址', 'yali-ai-writer');
                    }
                    return;
                }

                // 简单 URL 验证
                if (!url.match(/^https?:\/\//)) {
                    if (fetchStatusDiv) {
                        fetchStatusDiv.textContent = wp.i18n.__('请输入有效的网址', 'yali-ai-writer');
                    }
                    return;
                }

                // 显示加载状态
                fetchContentBtn.disabled = true;
                fetchContentBtn.textContent = wp.i18n.__('采集中...', 'yali-ai-writer');
                if (fetchStatusDiv) {
                    fetchStatusDiv.textContent = wp.i18n.__('正在采集内容，请稍候...', 'yali-ai-writer');
                    fetchStatusDiv.className = '';
                }

                // 发送 AJAX 请求
                var data = new URLSearchParams();
                data.append('action', 'yali_ai_writer_fetch_url_content');
                data.append('nonce', ruleData.nonce || '');
                data.append('url', url);

                fetch(ruleData.ajaxUrl || ajaxurl, {
                    method: 'POST',
                    body: data
                })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!fetchStatusDiv) return;

                    if (data.success) {
                        var content = data.data.content;
                        if (content && uploadTextInput) {
                            uploadTextInput.value = content;
                            updateTextCount();
                            fetchStatusDiv.textContent = wp.i18n.__('内容采集成功！已截取前3000个字符', 'yali-ai-writer');
                            fetchStatusDiv.classList.add('yali-text-success');
                        } else {
                            fetchStatusDiv.textContent = wp.i18n.__('采集的内容为空', 'yali-ai-writer');
                            fetchStatusDiv.classList.add('yali-text-warning');
                        }
                    } else {
                        fetchStatusDiv.textContent = wp.i18n.__('采集失败：', 'yali-ai-writer') + (data.data.message || '');
                        fetchStatusDiv.classList.add('yali-text-danger');
                    }
                })
                .catch(function(error) {
                    console.error('Fetch error:', error);
                    if (fetchStatusDiv) {
                        fetchStatusDiv.textContent = wp.i18n.__('采集失败：', 'yali-ai-writer') + wp.i18n.__('网络错误', 'yali-ai-writer');
                        fetchStatusDiv.classList.add('yali-text-danger');
                    }
                })
                .finally(function() {
                    fetchContentBtn.disabled = false;
                    fetchContentBtn.textContent = wp.i18n.__('采集内容', 'yali-ai-writer');
                });
            });
        }

        // --- 初始化 ---
        toggleConditions();
        updateTextCount();
        updateKeywordsCount();
        updateReferenceMaterialCount();
        updateUrlCount();
    });

})();
