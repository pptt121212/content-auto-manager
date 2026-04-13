/**
 * 鸭梨AI助手 — Gutenberg 编辑器集成
 *
 * 功能特性：
 * 1. 工具栏图标 + Popover 提示词面板（含文字输入区）
 * 2. 点击提示词 → 立即关闭 Popover → 在文档中插入加载动画块
 * 3. 内容插入逻辑：
 *    - 单段落块有选区：在选区末尾拆分，AI 内容插入中间，原选中文字保留
 *    - 多块/非段落：在最后块下方插入加载块，再替换为内容块
 * 4. 文字读取使用 wp.data + wp.richText API
 */
(function () {
    'use strict';

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var addFilter = wp.hooks.addFilter;
    var createHOC = wp.compose.createHigherOrderComponent;
    var BlockControls = wp.blockEditor.BlockControls;
    var ToolbarButton = wp.components.ToolbarButton;
    var Popover = wp.components.Popover;
    var Spinner = wp.components.Spinner;
    var apiFetch = wp.apiFetch;
    var __ = wp.i18n.__;

    // ——— PHP 注入数据（与原插件 aich_ajax 结构一致）———
    var DATA = (typeof yaliEditorData !== 'undefined') ? yaliEditorData : {};
    var GROUPED_PROMPTS = DATA.prompts || [];  // 分组格式，用于显示分组标题
    var ALL_PROMPTS = DATA.allPrompts || [];  // 展平格式，用于按索引调用 API
    var ENABLED = DATA.enabled || false;
    var API_NS = '/content-auto-manager/v1/editor-assistant';
    var ICON_URL = DATA.iconUrl || '';

    // 若 allPrompts 未注入（旧版缓存），从分组格式展平
    if (!ALL_PROMPTS.length && GROUPED_PROMPTS.length) {
        GROUPED_PROMPTS.forEach(function (g) {
            (g.new_prompt || []).forEach(function (p) { ALL_PROMPTS.push(p); });
        });
    }

    var SiteIcon = ICON_URL
        ? el('img', { src: ICON_URL, width: 20, height: 20, alt: '鸭梨AI', style: { display: 'block' } })
        : el('span', { style: { fontSize: '14px' } }, '🤖');

    // ══════════════════════════════════════════════════════
    //  文字读取逻辑（与原插件 getSelectedContent 完全一致）
    // ══════════════════════════════════════════════════════

    function getSelectedBlockClientIds() {
        var ids = wp.data.select('core/block-editor').getMultiSelectedBlockClientIds();
        if (!ids || ids.length === 0) {
            var single = wp.data.select('core/block-editor').getSelectedBlockClientId();
            ids = single ? [single] : [];
        }
        return ids;
    }

    function getAdjustedSelections(ids) {
        var start = wp.data.select('core/block-editor').getSelectionStart();
        var end = wp.data.select('core/block-editor').getSelectionEnd();
        if (!start || !end) return [start || {}, end || {}];
        if (start.clientId === end.clientId) return [start, end];
        var s = start, e = end;
        if (ids.length > 0 && ids[0] === end.clientId) { s = end; e = start; }
        return [s, e];
    }

    function extractBlockContent(block) {
        var a = block.attributes || {};
        return a.content || a.citation || a.value || a.values || a.text || '';
    }

    function getAllBlockContentsRecursively(ids, selStart, selEnd) {
        var text = '';
        ids.forEach(function (id) {
            var block = wp.data.select('core/block-editor').getBlock(id);
            if (!block) return;
            var html = extractBlockContent(block);
            var richVal = wp.richText.create({ html: html });
            var from = 0, to = richVal.text.length;
            if (selStart && selStart.clientId === id && 'offset' in selStart) from = selStart.offset;
            if (selEnd && selEnd.clientId === id && 'offset' in selEnd) to = selEnd.offset;
            var slice = richVal.text.substring(from, to);
            if (slice) text += '\n' + slice;
            if (block.innerBlocks && block.innerBlocks.length > 0) {
                text += getAllBlockContentsRecursively(
                    block.innerBlocks.map(function (b) { return b.clientId; }),
                    selStart, selEnd
                );
            }
        });
        return text;
    }

    function getEditorSelectedText() {
        var ids = getSelectedBlockClientIds();
        if (!ids || ids.length === 0) return '';
        var adj = getAdjustedSelections(ids);
        return getAllBlockContentsRecursively(ids, adj[0], adj[1]).trim();
    }

    // ══════════════════════════════════════════════════════
    //  加载动画块（与原插件 wpAcpLoadingSpinner + loader 对应）
    // ══════════════════════════════════════════════════════
    var SPINNER_HTML = '<span class="yali-ai-loading"></span>';

    function createSpinnerBlock() {
        return wp.blocks.createBlock('core/paragraph', { content: SPINNER_HTML });
    }

    // ══════════════════════════════════════════════════════
    //  插入逻辑（与原插件 createBlockForAutocompletion + autocomplete 完全一致）
    // ══════════════════════════════════════════════════════

    /**
     * 步骤1：在文档中插入加载动画块，返回其 clientId
     * 完全对应原插件 createBlockForAutocompletion 方法
     */
    function insertSpinnerInDocument(ids, selStart, selEnd) {
        if (!ids || ids.length === 0) return null;

        var adj = selEnd;  // 选区结束位置
        var lastId = adj && adj.clientId ? adj.clientId : ids[ids.length - 1];
        var lastBlock = wp.data.select('core/block-editor').getBlock(lastId);

        var spinner = createSpinnerBlock();

        // Case 1：多块选中 OR 非段落块 → 插到最后选中块下方（需检查树层级是否允许插入 core/paragraph）
        if (ids.length > 1 || !lastBlock || lastBlock.name !== 'core/paragraph') {
            var rootId = wp.data.select('core/block-editor').getBlockRootClientId(lastId);
            var index = wp.data.select('core/block-editor').getBlockIndex(lastId) + 1;

            if (!wp.data.select('core/block-editor').canInsertBlockType('core/paragraph', rootId)) {
                while (rootId) {
                    index = wp.data.select('core/block-editor').getBlockIndex(rootId) + 1;
                    rootId = wp.data.select('core/block-editor').getBlockRootClientId(rootId);
                    if (wp.data.select('core/block-editor').canInsertBlockType('core/paragraph', rootId)) {
                        break;
                    }
                }
            }
            wp.data.dispatch('core/block-editor').insertBlock(spinner, index, rootId);
            return spinner.clientId;
        }

        // Case 2：单段落块 → 检查当前父级是否允许插入段落（兜底，如被锁定）
        var rootIdCase2 = wp.data.select('core/block-editor').getBlockRootClientId(lastId);
        if (!wp.data.select('core/block-editor').canInsertBlockType('core/paragraph', rootIdCase2)) {
            while (rootIdCase2) {
                rootIdCase2 = wp.data.select('core/block-editor').getBlockRootClientId(rootIdCase2);
                if (wp.data.select('core/block-editor').canInsertBlockType('core/paragraph', rootIdCase2)) {
                    break;
                }
            }
            wp.data.dispatch('core/block-editor').insertBlock(spinner, undefined, rootIdCase2);
            return spinner.clientId;
        }

        // 继续 Case 2：在选区末尾拆分
        var html = extractBlockContent(lastBlock);
        var richVal = wp.richText.create({ html: html });
        var endOffset = richVal.text.length;  // 默认：块末尾
        var attrKey = (adj && adj.attributeKey) ? adj.attributeKey : 'content';

        if (adj && adj.clientId === lastId && 'offset' in adj) {
            endOffset = adj.offset;
        }

        var beforeVal = wp.richText.slice(richVal, 0, endOffset);
        var afterVal = wp.richText.slice(richVal, endOffset, richVal.text.length);
        var beforeHtml = wp.richText.toHTMLString({ value: beforeVal });
        var afterHtml = wp.richText.toHTMLString({ value: afterVal });

        var attrs = lastBlock.attributes;

        var beforeAttrs = Object.assign({}, attrs);
        beforeAttrs[attrKey] = beforeHtml;
        var beforeBlock = wp.blocks.createBlock(lastBlock.name, beforeAttrs);

        var spinnerAttrs = Object.assign({}, attrs);
        spinnerAttrs[attrKey] = SPINNER_HTML;
        spinner = wp.blocks.createBlock('core/paragraph', spinnerAttrs);

        var blocksToReplace = [beforeBlock, spinner];

        if (afterVal.text.trim().length > 0) {
            var afterAttrs = Object.assign({}, attrs);
            afterAttrs[attrKey] = afterHtml;
            blocksToReplace.push(wp.blocks.createBlock(lastBlock.name, afterAttrs));
        }

        wp.data.dispatch('core/block-editor').replaceBlock(lastId, blocksToReplace);
        return spinner.clientId;
    }

    /**
     * 步骤2：将生成的 HTML 解析为 Gutenberg 块，替换加载动画块
     * 完全对应原插件 autocomplete 方法
     */
    function replaceSpinnerWithContent(spinnerClientId, generatedHtml) {
        var container = document.createElement('div');
        // 与原插件一致：用 \n 替换多余换行
        container.innerHTML = generatedHtml.replace(/\n+/g, '\n');
        container.querySelectorAll(':empty').forEach(function (node) {
            if (node.parentNode) node.parentNode.removeChild(node);
        });

        var newBlocks = [];
        var children = Array.from(container.children);

        // 若无子元素（纯文本），按换行分段（与原插件 createParagraphBlock 逻辑一致）
        if (children.length === 0) {
            generatedHtml.split('\n').forEach(function (line) {
                var t = line.trim();
                if (t) newBlocks.push(wp.blocks.createBlock('core/paragraph', { content: t }));
            });
            if (!newBlocks.length) {
                newBlocks.push(wp.blocks.createBlock('core/paragraph', { content: generatedHtml }));
            }
        } else {
            // 与原插件 autocomplete 中的 element 类型判断完全一致
            children.forEach(function (child) {
                var tag = child.tagName.toUpperCase();
                var inner = child.innerHTML;
                if (tag === 'IMG') {
                    newBlocks.push(wp.blocks.createBlock('core/image', { url: child.getAttribute('src'), alt: child.getAttribute('alt') || '' }));
                } else if (/^H[1-6]$/.test(tag)) {
                    newBlocks.push(wp.blocks.createBlock('core/heading', { content: inner, level: parseInt(tag.replace('H', ''), 10) }));
                } else if (tag === 'P' || tag === 'SPAN' || tag === 'EM') {
                    newBlocks.push(wp.blocks.createBlock('core/paragraph', { content: inner }));
                } else if (tag === 'UL' || tag === 'OL' || tag === 'LI') {
                    newBlocks.push(wp.blocks.createBlock('core/list', { ordered: tag === 'OL', values: inner }));
                } else if (tag === 'STRONG') {
                    newBlocks.push(wp.blocks.createBlock('core/paragraph', { content: '<strong>' + inner + '</strong>' }));
                } else if (tag === 'B') {
                    newBlocks.push(wp.blocks.createBlock('core/paragraph', { content: '<b>' + inner + '</b>' }));
                } else {
                    newBlocks.push(wp.blocks.createBlock('core/paragraph', { content: inner || child.textContent }));
                }
            });
        }

        // 获取加载块的位置，插入生成的块，然后移除加载块
        // （与原插件 autocomplete 末尾的 insertBlocks + removeBlock 完全一致）
        var spinnerIndex = wp.data.select('core/block-editor').getBlockIndex(spinnerClientId);
        var spinnerRoot = wp.data.select('core/block-editor').getBlockRootClientId(spinnerClientId);
        wp.data.dispatch('core/block-editor').insertBlocks(newBlocks, spinnerIndex, spinnerRoot);
        wp.data.dispatch('core/block-editor').removeBlock(spinnerClientId);
    }

    /**
     * 步骤2（图像版）：将生成的图像插入为 image 块，替换加载动画块
     */
    function replaceSpinnerWithImage(spinnerClientId, imageUrl, imageAlt) {
        // 创建图像块
        var imageBlock = wp.blocks.createBlock('core/image', {
            url: imageUrl,
            alt: imageAlt || __('AI生成配图', 'yali-ai-writer'),
            caption: __('配图：', 'yali-ai-writer') + imageAlt,
            align: 'center'
        });

        // 获取加载块的位置
        var spinnerIndex = wp.data.select('core/block-editor').getBlockIndex(spinnerClientId);
        var spinnerRoot = wp.data.select('core/block-editor').getBlockRootClientId(spinnerClientId);

        // 插入图像块并移除加载块
        wp.data.dispatch('core/block-editor').insertBlocks([imageBlock], spinnerIndex, spinnerRoot);
        wp.data.dispatch('core/block-editor').removeBlock(spinnerClientId);

        // 显示成功提示
        wp.data.dispatch('core/notices').createSuccessNotice(
            __('配图生成成功', 'yali-ai-writer'),
            { isDismissible: true, id: 'yali-ai-image-success' }
        );
    }

    // ══════════════════════════════════════════════════════
    //  主执行函数：读取选区 → 关闭弹窗 → 插入 spinner → 调 API → 替换 spinner
    //  对应原插件 fetchRestApiGenerateContent 的完整流程
    // ══════════════════════════════════════════════════════
    function executePrompt(promptIndex, inputText, onClose) {
        // 1. 读取编辑器选区信息（保存选区信息，准备用于拆分块）
        var ids = getSelectedBlockClientIds();
        var adj = ids.length > 0 ? getAdjustedSelections(ids) : [null, null];
        var selStart = adj[0];
        var selEnd = adj[1];
        var text = inputText.trim();

        // 2. 立即关闭 Popover（与原插件：点击后即关闭弹窗）
        onClose();

        // 3. 在文档插入加载动画块
        var spinnerClientId = insertSpinnerInDocument(ids, selStart, selEnd);

        if (!spinnerClientId) {
            // 没有选中任何块，在文档末尾插入并继续
            var fallbackSpinner = createSpinnerBlock();
            wp.data.dispatch('core/block-editor').insertBlock(fallbackSpinner);
            spinnerClientId = fallbackSpinner.clientId;
        }

        // 4. 调用 REST API
        apiFetch({
            path: API_NS + '/generate',
            method: 'POST',
            data: { promptIndex: promptIndex, text: [text] },
        })
            .then(function (res) {
                if (!res.success) throw new Error(res.message || '生成失败');
                // 5. 替换加载块为生成内容
                if (res.is_image && res.image_url) {
                    // 图像生成结果
                    replaceSpinnerWithImage(spinnerClientId, res.image_url, res.image_description || '');
                } else {
                    // 文本生成结果
                    replaceSpinnerWithContent(spinnerClientId, res.text);
                }
            })
            .catch(function (err) {
                // 错误：移除加载块，显示错误通知
                try {
                    wp.data.dispatch('core/block-editor').removeBlock(spinnerClientId);
                } catch (e) { /* 块可能已被移除 */ }
                wp.data.dispatch('core/notices').createErrorNotice(
                    __('鸭梨AI助手：生成失败 — ', 'yali-ai-writer') + (err.message || __('请检查 API 设置', 'yali-ai-writer')),
                    { isDismissible: true, id: 'yali-ai-error' }
                );
            });
    }

    // ══════════════════════════════════════════════════════
    //  Popover 面板组件（含文字输入区，对应 PRO 模板 UI 功能）
    // ══════════════════════════════════════════════════════
    function PromptPanel(props) {
        var onClose = props.onClose;
        var clientId = props.clientId;

        var initText = getEditorSelectedText();
        var textState = useState(initText);
        var inputText = textState[0];
        var setInputText = textState[1];

        var searchState = useState('');
        var search = searchState[0];
        var setSearch = searchState[1];

        var errState = useState('');
        var errMsg = errState[0];
        var setErrMsg = errState[1];

        // 轮询检测编辑器新选区
        useEffect(function () {
            var tid = setInterval(function () {
                var fresh = getEditorSelectedText();
                if (fresh && fresh !== inputText) { setInputText(fresh); }
            }, 400);
            return function () { clearInterval(tid); };
        }, [inputText]);

        var hasText = inputText.trim().length > 0;
        var filteredFlat = search
            ? ALL_PROMPTS.filter(function (p) { return p.prompt_title.toLowerCase().indexOf(search.toLowerCase()) !== -1; })
            : null;  // null = 显示分组模式

        function handleSelect(flatIndex) {
            if (!inputText.trim()) {
                setErrMsg(__('请在上方输入主题，或在编辑器中选中文字', 'yali-ai-writer'));
                return;
            }
            setErrMsg('');
            executePrompt(flatIndex, inputText, onClose);
        }

        return el('div', { className: 'yali-ai-popover-panel' },

            // 顶部文字区：自动显示选中内容，也可手动输入
            el('div', { className: 'yali-ai-input-area' },
                el('div', { className: 'yali-ai-input-label' },
                    el('span', { className: 'yali-ai-dot ' + (hasText ? 'selected' : 'empty') }),
                    hasText
                        ? __('已选中文字（', 'yali-ai-writer') + inputText.trim().length + __(' 字）', 'yali-ai-writer')
                        : __('输入主题或关键词', 'yali-ai-writer')
                ),
                el('textarea', {
                    className: 'yali-ai-input-textarea' + (hasText ? ' has-selection' : ''),
                    value: inputText,
                    rows: 3,
                    placeholder: __('在此输入主题、关键词或粘贴要处理的文字…', 'yali-ai-writer'),
                    onChange: function (e) { setInputText(e.target.value); setErrMsg(''); },
                }),

                // 错误提示（移到输入区内，紧挨输入框下方）
                errMsg && el('div', { className: 'yali-ai-error-message' }, errMsg)
            ),

            // 搜索框
            el('div', { className: 'yali-ai-search' },
                el('input', {
                    type: 'text',
                    placeholder: __('搜索提示词...', 'yali-ai-writer'),
                    value: search,
                    onChange: function (e) { setSearch(e.target.value); },
                    className: 'yali-ai-search-input',
                })
            ),

            // 提示词列表：搜索时显示平铺，否则显示分组（文本生成和图像生成分开）
            el('div', { className: 'yali-ai-prompt-list' },
                filteredFlat
                    // 搜索模式：展平显示
                    ? (filteredFlat.length === 0
                        ? el('div', { className: 'yali-ai-empty' }, __('未找到匹配的提示词', 'yali-ai-writer'))
                        : filteredFlat.map(function (prompt, i) {
                            var flatIndex = ALL_PROMPTS.indexOf(prompt);
                            return el('div', {
                                key: i,
                                className: 'yali-ai-prompt-item' + (prompt.is_image_generation ? ' yali-ai-image-prompt' : ''),
                                onClick: function () { handleSelect(flatIndex); },
                                title: prompt.prompt_desc || '',
                            }, prompt.prompt_title);
                        })
                    )
                    // 分组模式：文本生成和图像生成分开显示
                    : el(Fragment, null,
                        // 文本生成组
                        el('div', { className: 'yali-ai-group-header' }, __('✍️ 文本生成', 'yali-ai-writer')),
                        ALL_PROMPTS.map(function (prompt, index) {
                            if (prompt.is_image_generation) return null;
                            return el('div', {
                                key: 'text-' + index,
                                className: 'yali-ai-prompt-item',
                                onClick: function () { handleSelect(index); },
                                title: prompt.prompt_desc || '',
                            }, prompt.prompt_title);
                        }),
                        // 图像生成组
                        el('div', { className: 'yali-ai-group-header', style: { marginTop: '12px' } }, __('🖼️ 图像生成', 'yali-ai-writer')),
                        ALL_PROMPTS.map(function (prompt, index) {
                            if (!prompt.is_image_generation) return null;
                            return el('div', {
                                key: 'image-' + index,
                                className: 'yali-ai-prompt-item yali-ai-image-prompt',
                                onClick: function () { handleSelect(index); },
                                title: prompt.prompt_desc || '',
                            }, prompt.prompt_title);
                        })
                    )
            )
        );
    }

    // ══════════════════════════════════════════════════════
    //  工具栏按钮 + Popover（与原插件 u 组件完全一致）
    // ══════════════════════════════════════════════════════
    function YaliAIToolbarButton(props) {
        var clientId = props.clientId;
        var openState = useState(false);
        var isOpen = openState[0];
        var setIsOpen = openState[1];

        if (!ENABLED || !ALL_PROMPTS || ALL_PROMPTS.length === 0) return null;

        return el(Fragment, null,
            el(BlockControls, { group: 'block' },
                el(ToolbarButton, {
                    icon: SiteIcon,
                    label: __('鸭梨AI助手', 'yali-ai-writer'),
                    onClick: function () { setIsOpen(function (prev) { return !prev; }); },
                    isPressed: isOpen,
                }),
                isOpen && el(Popover, {
                    placement: 'bottom-start',
                    onClose: function () { setIsOpen(false); },
                    className: 'yali-ai-popover',
                    focusOnMount: false,
                },
                    el(PromptPanel, {
                        clientId: clientId,
                        onClose: function () { setIsOpen(false); },
                    })
                )
            )
        );
    }

    // ══════════════════════════════════════════════════════
    //  HOC：向所有块注入工具栏（与原插件完全一致，无 isSelected 判断）
    // ══════════════════════════════════════════════════════
    var withYaliAI = createHOC(function (BlockEdit) {
        return function (props) {
            return el(Fragment, null,
                el(BlockEdit, props),
                el(YaliAIToolbarButton, { clientId: props.clientId })
            );
        };
    }, 'withYaliAIAssistant');

    addFilter('editor.BlockEdit', 'yali-ai/toolbar-button', withYaliAI);

})();
