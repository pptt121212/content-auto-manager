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
                action: 'yali_ai_writer_get_editor_assistant_prompts',
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
                    promptsContainer.innerHTML = '<div style="color:red; padding: 20px;">' + (response.data ? response.data.message : wp.i18n.__('加载失败', 'yali-ai-writer')) + '</div>';
                }
            },
            error: function() {
                promptsContainer.innerHTML = '<div style="color:red; padding: 20px;">' + wp.i18n.__('网络请求失败', 'yali-ai-writer') + '</div>';
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
            promptsContainer.innerHTML = '<div style="padding:20px; color:#666;">' + wp.i18n.__('此语言下暂无提示词配置。', 'yali-ai-writer') + '</div>';
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
            header.innerHTML = '<h3 class="ea-prompt-title"><span class="dashicons dashicons-edit"></span> ' + wp.i18n.__('提示词', 'yali-ai-writer') + ' #' + (index + 1) + '</h3>';
            card.appendChild(header);

            // Title Input
            const titleGroup = document.createElement('div');
            titleGroup.className = 'ea-field-group';
            titleGroup.innerHTML = '<label>' + wp.i18n.__('菜单显示标题', 'yali-ai-writer') + '</label>' +
                                  '<input type="text" class="ea-input-title" value="' + escapeHtml(prompt.prompt_title || '') + '">';
            card.appendChild(titleGroup);

            // Description Input
            const descGroup = document.createElement('div');
            descGroup.className = 'ea-field-group';
            descGroup.innerHTML = '<label>' + wp.i18n.__('功能描述说明', 'yali-ai-writer') + '</label>' +
                                  '<input type="text" class="ea-input-desc" value="' + escapeHtml(prompt.prompt_desc || '') + '">';
            card.appendChild(descGroup);

            // Prompt Content Input
            const contentGroup = document.createElement('div');
            contentGroup.className = 'ea-field-group';
            contentGroup.innerHTML = '<label>' + wp.i18n.__('发给AI的实际指令（Prompt）', 'yali-ai-writer') + '</label>' +
                                     '<textarea class="ea-input-content">' + escapeHtml(prompt.prompt_content || '') + '</textarea>';
            card.appendChild(contentGroup);

            // Word Count
            const wordCountVal = (prompt.word && prompt.word.value) ? prompt.word.value : 400;
            const wordGroup = document.createElement('div');
            wordGroup.className = 'ea-field-group ea-number-input';
            wordGroup.innerHTML = '<label style="margin-bottom:0;">' + wp.i18n.__('预计生成字数', 'yali-ai-writer') + '</label>' +
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
        saveBtn.innerText = wp.i18n.__('保存中...', 'yali-ai-writer');
        saveBtn.disabled = true;

        jQuery.ajax({
            url: apiContext.ajaxurl,
            type: 'POST',
            data: {
                action: 'yali_ai_writer_save_editor_assistant_prompts',
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
                    alert(wp.i18n.__('保存失败：', 'yali-ai-writer') + (response.data ? response.data.message : wp.i18n.__('未知错误', 'yali-ai-writer')));
                }
            },
            error: function() {
                saveBtn.innerText = btnText;
                saveBtn.disabled = false;
                alert(wp.i18n.__('网络请求失败，请稍后重试。', 'yali-ai-writer'));
            }
        });
    });

    // Restore Defaults action
    restoreBtn.addEventListener('click', function() {
        if(confirm(wp.i18n.__('确定要恢复默认配置吗？这将覆盖您当前所有语言的修改！', 'yali-ai-writer'))) {
            // Deep copy default prompts
            savedPrompts = JSON.parse(JSON.stringify(defaultPrompts));
            renderPrompts();
            alert(wp.i18n.__('已恢复默认配置，请点击【保存配置】按钮使其生效。', 'yali-ai-writer'));
        }
    });

    // 主选项卡切换
    const mainTabs = document.querySelectorAll('.ea-main-tab');
    const textPanel = document.getElementById('text-prompts-panel');
    const imagePanel = document.getElementById('image-prompts-panel');
    let imagePromptsLoaded = false;

    mainTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const targetTab = this.dataset.tab;
            
            // 更新选项卡样式
            mainTabs.forEach(t => {
                t.classList.remove('active');
                t.style.color = '#64748b';
                t.style.borderBottomColor = 'transparent';
                t.style.fontWeight = '500';
            });
            this.classList.add('active');
            this.style.color = '#3b82f6';
            this.style.borderBottomColor = '#3b82f6';
            this.style.fontWeight = '600';
            
            // 切换面板
            if (targetTab === 'text') {
                textPanel.style.display = 'block';
                imagePanel.style.display = 'none';
            } else {
                textPanel.style.display = 'none';
                imagePanel.style.display = 'block';
                if (!imagePromptsLoaded) {
                    loadImagePrompts();
                }
            }
        });
    });

    // 加载图像提示词
    function loadImagePrompts() {
        const container = document.getElementById('image-prompts-editor-container');
        container.innerHTML = '<div style="padding:40px; text-align:center;"><span class="spinner is-active" style="float:none;"></span></div>';
        
        jQuery.ajax({
            url: apiContext.ajaxurl,
            type: 'POST',
            data: {
                action: 'yali_ai_writer_get_image_prompts_config',
                nonce: apiContext.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    renderImagePromptsEditor(response.data.config);
                    imagePromptsLoaded = true;
                } else {
                    container.innerHTML = '<div style="color:red; padding: 20px;">' + (response.data ? response.data.message : '加载失败') + '</div>';
                }
            },
            error: function() {
                container.innerHTML = '<div style="color:red; padding: 20px;">' + wp.i18n.__('网络请求失败', 'yali-ai-writer') + '</div>';
            }
        });
    }

    // 渲染图像提示词编辑器
    function renderImagePromptsEditor(config) {
        const container = document.getElementById('image-prompts-editor-container');
        
        let html = '<div style="margin-bottom: 20px;">';
        html += '<button type="button" id="save-image-prompts" class="yali-btn yali-btn-primary">' + wp.i18n.__('保存图像提示词', 'yali-ai-writer') + '</button>';
        html += '<span id="image-save-status" style="color: #10b981; font-weight: 500; margin-left: 15px; display: none;"><span class="dashicons dashicons-yes"></span> ' + wp.i18n.__('保存成功！', 'yali-ai-writer') + '</span>';
        html += '<button type="button" id="restore-image-defaults" class="yali-btn yali-btn-secondary" style="margin-left: 10px;">' + wp.i18n.__('恢复默认', 'yali-ai-writer') + '</button>';
        html += '</div>';
        
        html += '<textarea id="image-prompts-json" rows="30" style="width: 100%; font-family: monospace; font-size: 12px; line-height: 1.4; padding: 15px; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc;">' + escapeHtml(JSON.stringify(config, null, 2)) + '</textarea>';
        
        html += '<div style="margin-top: 20px; padding: 20px; background: #f0f9ff; border-radius: 8px; border-left: 4px solid #0ea5e9;">';
        html += '<h4 style="margin-top: 0; color: #0369a1;">' + wp.i18n.__('提示词结构说明', 'yali-ai-writer') + '</h4>';
        html += '<ul style="list-style-type: disc; margin-left: 20px; margin-top: 10px; line-height: 1.8;">';
        html += '<li><code>prompt_title</code> - ' + wp.i18n.__('显示在编辑器菜单中的标题', 'yali-ai-writer') + '</li>';
        html += '<li><code>prompt_content</code> - ' + wp.i18n.__('发送给AI的完整提示词指令', 'yali-ai-writer') + '</li>';
        html += '<li><code>prompt_desc</code> - ' + wp.i18n.__('功能描述说明', 'yali-ai-writer') + '</li>';
        html += '<li><code>is_image_generation</code> - ' + wp.i18n.__('标记是否为图像生成提示词', 'yali-ai-writer') + '</li>';
        html += '<li><code>image_style</code> - ' + wp.i18n.__('图像风格标识（default/flowchart/content_match/text_overlay）', 'yali-ai-writer') + '</li>';
        html += '</ul>';
        html += '<p style="margin-top: 15px; color: #64748b; font-size: 13px;">' + wp.i18n.__('提示：修改后保存即可生效，无需重启服务器。', 'yali-ai-writer') + '</p>';
        html += '</div>';
        
        container.innerHTML = html;
        
        // 绑定保存事件
        document.getElementById('save-image-prompts').addEventListener('click', saveImagePrompts);
        
        // 绑定恢复默认事件
        document.getElementById('restore-image-defaults').addEventListener('click', function() {
            if(confirm(wp.i18n.__('确定要恢复默认的图像提示词配置吗？这将覆盖您当前的修改！', 'yali-ai-writer'))) {
                loadImagePrompts();
            }
        });
    }

    // 保存图像提示词
    function saveImagePrompts() {
        const btn = document.getElementById('save-image-prompts');
        const status = document.getElementById('image-save-status');
        const jsonText = document.getElementById('image-prompts-json').value;
        
        // 验证JSON
        try {
            JSON.parse(jsonText);
        } catch (e) {
            alert(wp.i18n.__('JSON格式错误: ', 'yali-ai-writer') + e.message);
            return;
        }
        
        const originalText = btn.innerText;
        btn.innerText = wp.i18n.__('保存中...', 'yali-ai-writer');
        btn.disabled = true;
        
        jQuery.ajax({
            url: apiContext.ajaxurl,
            type: 'POST',
            data: {
                action: 'yali_ai_writer_save_image_prompts_config',
                nonce: apiContext.nonce,
                config: jsonText
            },
            success: function(response) {
                btn.innerText = originalText;
                btn.disabled = false;
                
                if (response.success) {
                    status.style.display = 'inline-block';
                    setTimeout(() => { status.style.display = 'none'; }, 3000);
                } else {
                    alert(wp.i18n.__('保存失败: ', 'yali-ai-writer') + (response.data ? response.data.message : wp.i18n.__('未知错误', 'yali-ai-writer')));
                }
            },
            error: function() {
                btn.innerText = originalText;
                btn.disabled = false;
                alert(wp.i18n.__('网络请求失败', 'yali-ai-writer'));
            }
        });
    }

    // Init
    loadData();
});
