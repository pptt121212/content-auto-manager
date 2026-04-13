(function () {
    'use strict';

    /**
     * 鸭梨AI助手 — 经典编辑器（TinyMCE）集成
     */

    tinymce.PluginManager.add('yali_classic_plugin', function (editor, url) {
        var DATA = (typeof contentAutoEditorData !== 'undefined') ? contentAutoEditorData : {};
        var prompts = DATA.prompts || [];
        var nonce = DATA.nonce || '';
        var apiUrl = DATA.apiUrl || '';
        var iconUrl = DATA.iconUrl || '';
        var i18n = DATA.i18n || {
            no_selection: '请先在编辑器中选中要处理的文字。',
            generate_failed: '生成失败，请检查 API 设置',
            button_title: '鸭梨AI助手',
            button_tooltip: '鸭梨AI助手 — AI 写作辅助',
            generate_failed_short: '生成失败',
            network_error: '网络错误',
            alert_prefix: '鸭梨AI助手: ',
        };

        // 构建菜单，将文本生成和图像生成分开
        var textPrompts = [];
        var imagePrompts = [];
        
        prompts.forEach(function (group, groupIndex) {
            group.new_prompt.forEach(function (prompt, promptIndex) {
                var flatIndex = 0;
                for (var g = 0; g < groupIndex; g++) {
                    flatIndex += prompts[g].new_prompt.length;
                }
                flatIndex += promptIndex;

                var menuItem = {
                    text: prompt.prompt_title,
                    image: iconUrl || '',
                    onclick: function () {
                        handlePromptClick(flatIndex, prompt);
                    }
                };

                if (prompt.is_image_generation) {
                    imagePrompts.push(menuItem);
                } else {
                    textPrompts.push(menuItem);
                }
            });
        });

        // 构建分组菜单
        var menu = [];
        
        // 文本生成组
        if (textPrompts.length > 0) {
            menu.push({
                text: '✍️ 文本生成',
                disabled: true,
                classes: 'yali-ai-menu-header'
            });
            textPrompts.forEach(function (item) {
                menu.push(item);
            });
        }
        
        // 图像生成组
        if (imagePrompts.length > 0) {
            menu.push({
                text: '🖼️ 图像生成',
                disabled: true,
                classes: 'yali-ai-menu-header'
            });
            imagePrompts.forEach(function (item) {
                menu.push(item);
            });
        }

        editor.addButton('yali_classic_plugin', {
            title: i18n.button_title,
            image: iconUrl || '',
            tooltip: i18n.button_tooltip,
            type: 'menubutton',
            menu: menu,
        });

        async function handlePromptClick(index, promptItem) {
            console.log('Yali AI: Interaction started - Append Mode');

            var selectedContent = editor.selection.getContent({ format: 'text' });
            if (!selectedContent || selectedContent.trim() === '') {
                var node = editor.selection.getNode();
                if (node && node.textContent.trim() !== '') {
                    selectedContent = node.textContent;
                } else {
                    alert(i18n.alert_prefix + i18n.no_selection);
                    return;
                }
            }

            // 1. 确定插入位置和标签 (识别当前 Block)
            var selectedEnd = editor.selection.getEnd();
            var parentLi = editor.dom.getParent(selectedEnd, 'LI');
            var parentP = editor.dom.getParent(selectedEnd, 'P');
            var containerNode = parentLi || parentP || selectedEnd;
            var tagName = parentLi ? 'li' : 'p';

            var loaderId = 'yali-load-' + Math.random().toString(36).substring(2, 9);

            // 创建加载节点
            var loaderNode = editor.dom.create(tagName, {
                'id': loaderId,
                'class': 'aich-loading',
                'data-mce-bogus': '1'
            }, '\u00a0'); // &nbsp;

            // 智能插入：如果是在块元素内，则插入到块元素之后
            if (containerNode && containerNode.parentNode) {
                editor.dom.insertAfter(loaderNode, containerNode);
            } else {
                // 后备：直接在选区后插入
                var rng = editor.selection.getRng();
                rng.collapse(false);
                rng.insertNode(loaderNode);
            }

            // 验证并滚动
            var verifiedNode = editor.dom.get(loaderId);
            if (verifiedNode) {
                editor.dom.addClass(verifiedNode, 'yali-active');
                verifiedNode.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            // 2. 发起请求
            try {
                const response = await fetch(apiUrl + '/generate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                    body: JSON.stringify({ promptIndex: index, text: [selectedContent] }),
                });

                if (!response.ok) throw new Error('HTTP ' + response.status);
                const data = await response.json();
                if (!data || !data.success) throw new Error(data.message || i18n.generate_failed_short);

                // 3. 内容回填
                if (data.is_image && data.image_url) {
                    // 图像生成结果
                    var imageHtml = '<img src="' + data.image_url + '" alt="' + (data.image_description || '') + '" style="max-width:100%;height:auto;display:block;margin:20px auto;" />';
                    var finalNode = editor.dom.get(loaderId) || loaderNode;
                    if (finalNode && finalNode.parentNode) {
                        editor.dom.setOuterHTML(finalNode, imageHtml);
                    } else {
                        editor.insertContent(imageHtml);
                    }
                    editor.undoManager.add();
                    editor.nodeChanged();
                } else {
                    // 文本生成结果
                    var finalNode = editor.dom.get(loaderId) || loaderNode;
                    if (finalNode && finalNode.parentNode) {
                        finalNode.removeAttribute('id');
                        finalNode.removeAttribute('class');
                        finalNode.removeAttribute('data-mce-bogus');
                        // 核心：回填 AI 结果
                        editor.dom.setHTML(finalNode, data.text);
                    } else {
                        editor.insertContent(data.text);
                    }

                    editor.undoManager.add();
                    editor.nodeChanged();
                }
            } catch (err) {
                console.error('Yali AI Error:', err);
                var errorNode = editor.dom.get(loaderId);
                if (errorNode) editor.dom.remove(errorNode);
                alert(i18n.alert_prefix + (err.message || i18n.generate_failed));
            }
        }
    });

})();
