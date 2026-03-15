<div class="wrap" id="editor-assistant-settings-page">
    <h1 class="yali-page-title"><span class="dashicons dashicons-admin-settings"></span> <?php _e('编辑器AI助手配置', 'yali-ai-writer'); ?></h1>

    <div style="margin-bottom: 20px;">
        <a href="?page=yali-ai-writer-publish-rules" class="yali-btn yali-btn-secondary">
            <span class="dashicons dashicons-arrow-left-alt"></span> <?php _e('返回发布规则', 'yali-ai-writer'); ?>
        </a>
    </div>

    <div class="yali-card">
        <div class="yali-notice yali-notice-info">
            <p><strong><?php _e('说明：', 'yali-ai-writer'); ?></strong> <?php _e('您可以在此管理不同语言环境下的快捷生成提示词。[[text_1]] 为用户在编辑器中选中的文本占位符，请勿删除。', 'yali-ai-writer'); ?></p>
        </div>

        <div class="ea-tabs-header" id="ea-language-tabs">
            <!-- Tabs injected by JS -->
        </div>

        <div id="ea-prompts-container">
            <div style="padding:40px; text-align:center;"><span class="spinner is-active" style="float:none;"></span></div>
        </div>
    </div>

    <div class="ea-sticky-footer">
        <div>
            <button class="yali-btn yali-btn-secondary" id="ea-restore-defaults" type="button">
                <span class="dashicons dashicons-update-alt"></span> <?php _e('恢复默认配置', 'yali-ai-writer'); ?>
            </button>
        </div>
        <div>
            <span id="ea-save-status" style="color: #10b981; font-weight: 500; margin-right: 15px; display: none;">
                <span class="dashicons dashicons-yes"></span> <?php _e('保存成功！', 'yali-ai-writer'); ?>
            </span>
            <button class="yali-btn yali-btn-primary" id="ea-save-prompts" type="button" style="height: 40px; padding: 0 30px;">
                <?php _e('保存配置', 'yali-ai-writer'); ?>
            </button>
        </div>
    </div>
</div>

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
    const apiContext = window.editorAssistantSettings;
    if (!apiContext) return;

    let defaultPrompts = {};
    let savedPrompts = {};
    let currentLang = 'zh';

    const tabsContainer = document.getElementById('ea-language-tabs');
    const promptsContainer = document.getElementById('ea-prompts-container');
    const saveBtn = document.getElementById('ea-save-prompts');
    const restoreBtn = document.getElementById('ea-restore-defaults');
    const statusMsg = document.getElementById('ea-save-status');

    // 语言名称映射
    const langNames = {
        'en': 'English',
        'zh': '中文 (Chinese)',
        'ja': '日本語 (Japanese)',
        'ko': '한국어 (Korean)',
        'es': 'Español (Spanish)',
        'fr': 'Français (French)',
        'de': 'Deutsch (German)',
        'ru': 'Русский (Russian)',
        'pt': 'Português',
        'it': 'Italiano'
    };

    // Load data from server
    function loadData() {
        promptsContainer.innerHTML = '<div style="padding:40px; text-align:center;"><span class="spinner is-active" style="float:none;"></span></div>';
        
        jQuery.ajax({
            url: apiContext.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_editor_assistant_prompts',
                nonce: apiContext.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    defaultPrompts = response.data.default_prompts || {};
                    savedPrompts = response.data.saved_prompts || {};
                    
                    // Fallback to structure initialization if deeply empty
                    if (Array.isArray(savedPrompts) && savedPrompts.length === 0) {
                        savedPrompts = JSON.parse(JSON.stringify(defaultPrompts));
                    }
                    
                    // Default to 'zh' if available, else first key
                    const availableLangs = Object.keys(savedPrompts);
                    if (availableLangs.length > 0) {
                        if (availableLangs.includes('zh')) currentLang = 'zh';
                        else currentLang = availableLangs[0];
                    }
                    
                    renderTabs();
                    renderPrompts();
                } else {
                    promptsContainer.innerHTML = '<div style="color:red; padding: 20px;">' + (response.data ? response.data.message : '<?php echo esc_js(__("加载失败", "yali-ai-writer")); ?>') + '</div>';
                }
            },
            error: function() {
                promptsContainer.innerHTML = '<div style="color:red; padding: 20px;"><?php echo esc_js(__("网络请求失败", "yali-ai-writer")); ?></div>';
            }
        });
    }

    function renderTabs() {
        tabsContainer.innerHTML = '';
        const langs = Object.keys(savedPrompts);
        
        langs.forEach(lang => {
            const btn = document.createElement('div');
            btn.className = 'ea-tab' + (lang === currentLang ? ' active' : '');
            btn.textContent = langNames[lang] || lang;
            btn.onclick = () => {
                currentLang = lang;
                renderTabs();
                renderPrompts();
            };
            tabsContainer.appendChild(btn);
        });
    }

    function renderPrompts() {
        promptsContainer.innerHTML = '';
        
        const langData = savedPrompts[currentLang] || [];
        
        if (langData.length === 0) {
            promptsContainer.innerHTML = '<div style="padding:20px; color:#666;">' + '<?php echo esc_js(__("此语言下暂无提示词配置。", "yali-ai-writer")); ?>' + '</div>';
            return;
        }

        langData.forEach((prompt, index) => {
            // Container
            const card = document.createElement('div');
            card.className = 'ea-prompt-card';
            card.dataset.index = index;

            // Header
            const header = document.createElement('div');
            header.className = 'ea-prompt-header';
            header.innerHTML = '<h3 class="ea-prompt-title"><span class="dashicons dashicons-edit"></span> ' + '<?php echo esc_js(__("提示词", "yali-ai-writer")); ?>' + ' #' + (index + 1) + '</h3>';
            card.appendChild(header);

            // Title Input
            const titleGroup = document.createElement('div');
            titleGroup.className = 'ea-field-group';
            titleGroup.innerHTML = '<label>' + '<?php echo esc_js(__("菜单显示标题", "yali-ai-writer")); ?>' + '</label>' +
                                  '<input type="text" class="ea-input-title" value="' + escapeHtml(prompt.prompt_title || '') + '">';
            card.appendChild(titleGroup);

            // Description Input
            const descGroup = document.createElement('div');
            descGroup.className = 'ea-field-group';
            descGroup.innerHTML = '<label>' + '<?php echo esc_js(__("功能描述说明", "yali-ai-writer")); ?>' + '</label>' +
                                  '<input type="text" class="ea-input-desc" value="' + escapeHtml(prompt.prompt_desc || '') + '">';
            card.appendChild(descGroup);

            // Prompt Content Input
            const contentGroup = document.createElement('div');
            contentGroup.className = 'ea-field-group';
            contentGroup.innerHTML = '<label>' + '<?php echo esc_js(__("发给AI的实际指令（Prompt）", "yali-ai-writer")); ?>' + '</label>' +
                                     '<textarea class="ea-input-content">' + escapeHtml(prompt.prompt_content || '') + '</textarea>';
            card.appendChild(contentGroup);

            // Word Count
            const wordCountVal = (prompt.word && prompt.word.value) ? prompt.word.value : 400;
            const wordGroup = document.createElement('div');
            wordGroup.className = 'ea-field-group ea-number-input';
            wordGroup.innerHTML = '<label style="margin-bottom:0;">' + '<?php echo esc_js(__("预计生成字数", "yali-ai-writer")); ?>' + '</label>' +
                                  '<input type="number" class="ea-input-word" value="' + escapeHtml(wordCountVal) + '" min="50" max="3000">';
            card.appendChild(wordGroup);

            // Event listeners to update datastore on change
            card.querySelector('.ea-input-title').addEventListener('input', (e) => { savedPrompts[currentLang][index].prompt_title = e.target.value; });
            card.querySelector('.ea-input-desc').addEventListener('input', (e) => { savedPrompts[currentLang][index].prompt_desc = e.target.value; });
            card.querySelector('.ea-input-content').addEventListener('input', (e) => { savedPrompts[currentLang][index].prompt_content = e.target.value; });
            card.querySelector('.ea-input-word').addEventListener('input', (e) => { 
                if(!savedPrompts[currentLang][index].word) {
                    savedPrompts[currentLang][index].word = { type: 'fixed' };
                }
                savedPrompts[currentLang][index].word.value = parseInt(e.target.value) || 400; 
            });

            promptsContainer.appendChild(card);
        });
    }

    function escapeHtml(text) {
        if (text === null || typeof text === 'undefined') return '';
        return String(text)
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    // Save Data action
    saveBtn.addEventListener('click', function() {
        const btnText = saveBtn.innerText;
        saveBtn.innerText = '<?php echo esc_js(__("保存中...", "yali-ai-writer")); ?>';
        saveBtn.disabled = true;

        jQuery.ajax({
            url: apiContext.ajaxurl,
            type: 'POST',
            data: {
                action: 'save_editor_assistant_prompts',
                nonce: apiContext.nonce,
                prompts: JSON.stringify(savedPrompts)
            },
            success: function(response) {
                saveBtn.innerText = btnText;
                saveBtn.disabled = false;
                
                if (response.success) {
                    statusMsg.style.display = 'inline-block';
                    setTimeout(() => { statusMsg.style.display = 'none'; }, 3000);
                } else {
                    alert('<?php echo esc_js(__("保存失败：", "yali-ai-writer")); ?>' + (response.data ? response.data.message : '<?php echo esc_js(__("未知错误", "yali-ai-writer")); ?>'));
                }
            },
            error: function() {
                saveBtn.innerText = btnText;
                saveBtn.disabled = false;
                alert('<?php echo esc_js(__("网络请求失败，请稍后重试。", "yali-ai-writer")); ?>');
            }
        });
    });

    // Restore Defaults action
    restoreBtn.addEventListener('click', function() {
        if(confirm('<?php echo esc_js(__("确定要恢复默认配置吗？这将覆盖您当前所有语言的修改！", "yali-ai-writer")); ?>')) {
            // Deep copy default prompts
            savedPrompts = JSON.parse(JSON.stringify(defaultPrompts));
            renderPrompts();
            alert('<?php echo esc_js(__("已恢复默认配置，请点击【保存配置】按钮使其生效。", "yali-ai-writer")); ?>');
        }
    });

    // Init
    loadData();
});
</script>
