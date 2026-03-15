jQuery(document).ready(function ($) {

    const __ = wp.i18n.__;

    // --- CACHE ---
    let allStructures = {};
    let allAngles = [];
    let angleUsageTotals = {};
    let popularityStats = {};
    let currentAngle = null;

    // --- SELECTORS ---
    const angleListContainer = $('#angle-list');
    const detailContainer = $('#structure-detail-container');
    const modal = $('#associated-articles-modal');

    // --- INITIALIZATION ---
    loadInitialData();

    // --- EVENT HANDLERS ---
    angleListContainer.on('click', 'li.angle-item', function () {
        currentAngle = $(this).data('angle');
        angleListContainer.find('li').removeClass('active');
        $(this).addClass('active');
        renderDetailView(currentAngle);
    });

    // 删除动态角度的事件处理器
    angleListContainer.on('click', '.delete-angle-btn', function (e) {
        e.stopPropagation(); // 防止触发角度选择
        const angleToDelete = $(this).data('angle');
        // 翻译角度名称用于显示
        const translatedAngleToDelete = wp.i18n.__(angleToDelete, 'yali-ai-writer');
        const msg = __('确定要删除动态角度 "%s" 吗？', 'yali-ai-writer').replace('%s', translatedAngleToDelete) + '\n\n' + __('该角度下的主题将随机重新分配到固定角度中，文章结构将被删除。', 'yali-ai-writer') + '\n\n' + __('此操作不可撤销。', 'yali-ai-writer');
        if (confirm(msg)) {
            deleteDynamicAngle(angleToDelete, $(this));
        }
    });

    detailContainer.on('click', '#generate-structures-btn', function () {
        if (!currentAngle) return;
        generateStructuresForAngle($(this));
    });

    detailContainer.on('click', '.delete-structure-btn', function () {
        const structureId = $(this).data('id');
        if (confirm(__('确定要删除ID为 %s 的结构吗？此操作不可撤销。', 'yali-ai-writer').replace('%s', structureId))) {
            deleteStructure(structureId, $(this));
        }
    });

    detailContainer.on('click', '.associate-structure-btn', function () {
        const structureId = $(this).data('id');
        const structureTitle = $(this).closest('.structure-card').find('.structure-title').text();
        openAssociatedArticlesModal(structureId, structureTitle);
    });

    modal.on('click', '#modal-close, .modal-overlay', function (e) {
        if (e.target === this || $(this).is('#modal-close')) {
            modal.fadeOut();
        }
    });

    // --- DATA & AJAX FUNCTIONS ---
    function loadInitialData() {
        // 检查必要的全局变量
        if (typeof articleStructures === 'undefined') {
            console.error(__('articleStructures 全局变量未定义', 'yali-ai-writer'));
            angleListContainer.html('<div class="notice notice-error"><p>' + __('JavaScript配置错误：articleStructures变量未定义', 'yali-ai-writer') + '</p></div>');
            return;
        }

        // Fetch angles, structures, and popularity stats in parallel
        $.when(
            $.ajax({
                url: articleStructures.ajaxurl,
                type: 'POST',
                data: { action: 'get_content_angles', nonce: articleStructures.nonce },
                error: function (xhr, status, error) {
                    console.error(__('获取内容角度失败:', 'yali-ai-writer'), status, error, xhr.responseText);
                }
            }),
            $.ajax({
                url: articleStructures.ajaxurl,
                type: 'POST',
                data: { action: 'get_article_structures', nonce: articleStructures.nonce },
                error: function (xhr, status, error) {
                    console.error(__('获取文章结构失败:', 'yali-ai-writer'), status, error, xhr.responseText);
                }
            }),
            $.ajax({
                url: articleStructures.ajaxurl,
                type: 'POST',
                data: { action: 'get_structure_popularity_stats', nonce: articleStructures.nonce },
                error: function (xhr, status, error) {
                    console.error(__('获取受欢迎度统计失败:', 'yali-ai-writer'), status, error, xhr.responseText);
                }
            })
        ).done(function (anglesResponse, structuresResponse, popularityResponse) {
            if (anglesResponse[0].success) {
                // 处理新的角度数据结构：固定角度 + 动态角度
                const angleData = anglesResponse[0].data;
                if (typeof angleData === 'object' && angleData.fixed_angles) {
                    // 新格式：包含固定角度和动态角度
                    const fixedAngles = angleData.fixed_angles || [];
                    const dynamicAngles = angleData.dynamic_angles || [];
                    allAngles = [...fixedAngles, ...dynamicAngles];

                    // 存储角度类型信息
                    window.angleTypes = {
                        fixed: fixedAngles,
                        dynamic: dynamicAngles
                    };

                    // 构建显示名映射（来自服务端的本地化名称）
                    window.angleDisplayNames = {};
                    if (angleData.fixed_angles_localized) {
                        angleData.fixed_angles_localized.forEach(item => {
                            window.angleDisplayNames[item.key] = item.display_name;
                        });
                    }
                    if (angleData.dynamic_angles_localized) {
                        angleData.dynamic_angles_localized.forEach(item => {
                            window.angleDisplayNames[item.key] = item.display_name;
                        });
                    }
                } else {
                    // 兼容旧格式：直接是角度数组
                    allAngles = Array.isArray(angleData) ? angleData : [];
                    window.angleTypes = { fixed: allAngles, dynamic: [] };
                }
                renderAngleList();
            } else {
                console.error(__('内容角度加载失败:', 'yali-ai-writer'), anglesResponse[0].data);
                angleListContainer.html('<div class="notice notice-error"><p>' + __('加载内容角度失败: ', 'yali-ai-writer') + (anglesResponse[0].data?.message || __('未知错误', 'yali-ai-writer')) + '</p></div>');
            }
            if (structuresResponse[0].success) {
                allStructures = structuresResponse[0].data.structures;
                angleUsageTotals = structuresResponse[0].data.usage_totals || {};
            } else {
                console.error(__('文章结构加载失败:', 'yali-ai-writer'), structuresResponse[0].data);
            }
            if (popularityResponse[0].success) {
                popularityStats = popularityResponse[0].data;
            }
            renderAngleList(); // 重新渲染以显示使用次数和受欢迎度
        }).fail(function () {
            console.error(__('数据加载完全失败', 'yali-ai-writer'));
            angleListContainer.html('<div class="notice notice-error"><p>' + __('初始化数据加载失败，请检查网络连接和服务器状态', 'yali-ai-writer') + '</p></div>');
        });
    }

    function generateStructuresForAngle(btn) {
        btn.prop('disabled', true).siblings('.spinner').addClass('is-active');

        btn.prop('disabled', true).css('opacity', '0.7');

        $.ajax({
            url: articleStructures.ajaxurl,
            type: 'POST',
            timeout: 150000, // 2.5分钟超时
            data: {
                action: 'generate_article_structures',
                nonce: articleStructures.nonce,
                angle: currentAngle
            },
            success: function (response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    // 只刷新当前角度的数据
                    setTimeout(() => {
                        loadInitialDataAndRenderDetail();
                    }, 1000);
                } else {
                    showMessage(__('生成失败: ', 'yali-ai-writer') + response.data.message, 'error');
                }
            },
            error: function (xhr, status, error) {
                if (status === 'timeout') {
                    showMessage(__('生成超时，请稍后刷新页面查看结果', 'yali-ai-writer'), 'warning');
                } else if (xhr.status === 403) {
                    showMessage(__('权限过期，请刷新页面重试', 'yali-ai-writer'), 'error');
                } else {
                    showMessage(__('生成失败，请检查网络连接', 'yali-ai-writer'), 'error');
                }
            },
            complete: function () {
                btn.prop('disabled', false).css('opacity', '').siblings('.spinner').removeClass('is-active');
            }
        });
    }

    function deleteStructure(id, btn) {
        btn.closest('.structure-card').css('opacity', '0.5');
        $.ajax({
            url: articleStructures.ajaxurl,
            type: 'POST',
            data: { action: 'delete_article_structure', nonce: articleStructures.nonce, id: id },
            success: function (response) {
                if (response.success) {
                    btn.closest('.structure-card').fadeOut(300, function () { $(this).remove(); });
                } else {
                    showMessage(__('删除失败: ', 'yali-ai-writer') + response.data.message, 'error');
                    btn.closest('.structure-card').css('opacity', '1');
                }
            },
            error: function () {
                showMessage(__('请求失败', 'yali-ai-writer'), 'error');
                btn.closest('.structure-card').css('opacity', '1');
            }
        });
    }

    function openAssociatedArticlesModal(structureId, structureTitle) {
        modal.fadeIn();
        $('#modal-title').text('"' + structureTitle + '" ' + __('关联的文章', 'yali-ai-writer'));
        $('#modal-body').html('<span class="spinner is-active"></span>');

        $.ajax({
            url: articleStructures.ajaxurl,
            type: 'POST',
            data: { action: 'get_associated_articles', nonce: articleStructures.nonce, structure_id: structureId },
            success: function (response) {
                if (response.success) {
                    let content = '';

                    // 显示统计信息
                    if (response.data.stats) {
                        const stats = response.data.stats;
                        content += `<div style="background: #f9f9f9; padding: 15px; margin-bottom: 20px; border-radius: 5px;">
                            <h4 style="margin-top: 0;">${__('📊 结构表现统计', 'yali-ai-writer')}</h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                                <div><strong>${__('关联文章数：', 'yali-ai-writer')}</strong> ${stats.total_articles}</div>
                                <div><strong>${__('总外部访问：', 'yali-ai-writer')}</strong> ${stats.total_visits}</div>
                                <div><strong>${__('平均访问量：', 'yali-ai-writer')}</strong> ${stats.avg_visits}</div>
                                <div><strong>${__('受欢迎度指数：', 'yali-ai-writer')}</strong> <span style="color: ${getPopularityColor(stats.popularity_index)}; font-weight: bold;">${stats.popularity_index}%</span></div>
                            </div>
                        </div>`;
                    }

                    // 显示文章列表
                    if (response.data.articles && response.data.articles.length > 0) {
                        content += '<h4>' + __('📝 关联文章列表', 'yali-ai-writer') + '</h4>';
                        content += '<table style="width: 100%; border-collapse: collapse;">';
                        content += '<thead><tr style="background: #f0f0f0;"><th style="padding: 8px; text-align: left;">' + __('文章标题', 'yali-ai-writer') + '</th><th style="padding: 8px; text-align: center;">' + __('外部访问', 'yali-ai-writer') + '</th><th style="padding: 8px; text-align: center;">' + __('发布日期', 'yali-ai-writer') + '</th></tr></thead>';
                        content += '<tbody>';
                        response.data.articles.forEach(post => {
                            content += `<tr style="border-bottom: 1px solid #ddd;">
                                <td style="padding: 8px;"><a href="${post.url}" target="_blank">${escapeHtml(post.title)}</a></td>
                                <td style="padding: 8px; text-align: center; font-weight: bold; color: #2271b1;">${post.external_visits || 0}</td>
                                <td style="padding: 8px; text-align: center; color: #666;">${post.date || '-'}</td>
                            </tr>`;
                        });
                        content += '</tbody></table>';
                    } else {
                        content += '<p>' + __('暂无关联文章。', 'yali-ai-writer') + '</p>';
                    }

                    $('#modal-body').html(content);
                } else {
                    $('#modal-body').html('<p>' + __('获取关联文章失败。', 'yali-ai-writer') + '</p>');
                }
            }
        });
    }

    function loadInitialDataAndRenderDetail() {
        // Special function to reload all data and then render the detail view for the current angle
        loadInitialData();
        // A small delay to allow the `allStructures` cache to be populated by the AJAX call
        setTimeout(function () {
            renderDetailView(currentAngle);
        }, 1000);
    }

    // --- RENDER FUNCTIONS ---
    function renderAngleList() {
        if (allAngles.length === 0) {
            angleListContainer.html('<p style="padding:20px; color:#666; text-align:center;">' + __('未找到内容角度。', 'yali-ai-writer') + '</p>');
            return;
        }

        let listHtml = '<ul style="margin:0; padding:0; list-style:none;">';

        // 渲染固定角度
        if (window.angleTypes && window.angleTypes.fixed) {
            window.angleTypes.fixed.forEach(angle => {
                const usageTotal = angleUsageTotals[angle] || 0;
                // 优先使用i18n翻译，回退到服务端的本地化名称
                const translatedAngle = wp.i18n.__(angle, 'yali-ai-writer') !== angle
                    ? wp.i18n.__(angle, 'yali-ai-writer')
                    : ((window.angleDisplayNames && window.angleDisplayNames[angle]) || angle);

                listHtml += `<li data-angle="${escapeHtml(angle)}" class="angle-item angle-fixed" style="padding:12px 20px; border-bottom:1px solid #eee; cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                                <span style="font-weight:500;">${escapeHtml(translatedAngle)}</span>
                                <span class="count" style="background:#eee; color:#666; border-radius:10px; padding:2px 8px; font-size:12px;">${usageTotal}</span>
                             </li>`;
            });
        }

        // 如果有动态角度，添加分隔线和动态角度
        if (window.angleTypes && window.angleTypes.dynamic && window.angleTypes.dynamic.length > 0) {
            listHtml += '<li class="angle-separator" style="background:#f9f9f9; padding:8px 15px; font-size:12px; color:#888; text-align:center; border-bottom:1px solid #eee;">' + __('— 动态角度 —', 'yali-ai-writer') + '</li>';

            window.angleTypes.dynamic.forEach(angle => {
                const usageTotal = angleUsageTotals[angle] || 0;
                // 对于动态角度，通常没翻译，尝试i18n，否则用服务端名称
                const translatedAngle = wp.i18n.__(angle, 'yali-ai-writer') !== angle
                    ? wp.i18n.__(angle, 'yali-ai-writer')
                    : ((window.angleDisplayNames && window.angleDisplayNames[angle]) || angle);

                listHtml += `<li data-angle="${escapeHtml(angle)}" class="angle-item angle-dynamic" style="padding:12px 20px; border-bottom:1px solid #eee; pointer; display:flex; justify-content:space-between; align-items:center; position:relative;">
                                <span class="angle-content" style="font-weight:500;">${escapeHtml(translatedAngle)}</span>
                                <div style="display:flex; align-items:center; gap:5px;">
                                    <span class="count" style="background:#fff3cd; color:#856404; border-radius:10px; padding:2px 8px; font-size:12px;">${usageTotal}</span>
                                    <button class="delete-angle-btn" data-angle="${escapeHtml(angle)}" title="${__('删除此动态角度', 'yali-ai-writer')}" style="background:none; border:none; color:#dc3545; cursor:pointer; font-size:16px; line-height:1; padding:0 5px;">&times;</button>
                                </div>
                             </li>`;
            });
        }

        listHtml += '</ul>';
        angleListContainer.html(listHtml);
    }

    function renderDetailView(angle) {
        // 优先使用i18n翻译，回退到服务端的本地化名称
        const translatedAngle = wp.i18n.__(angle, 'yali-ai-writer') !== angle
            ? wp.i18n.__(angle, 'yali-ai-writer')
            : ((window.angleDisplayNames && window.angleDisplayNames[angle]) || angle);
        let headerHtml = `
            <div class="structure-detail-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid #eee;">
                <h2 style="margin:0; font-size:18px;">"<span style="color:#2271b1;">${escapeHtml(translatedAngle)}</span>" ${__('结构列表', 'yali-ai-writer')}</h2>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="spinner"></span>
                    <button id="generate-structures-btn" class="yali-btn yali-btn-primary">
                        <span class="dashicons dashicons-plus-alt2" style="line-height:1.3"></span> ${__('生成新结构', 'yali-ai-writer')}
                    </button>
                </div>
            </div>`;

        let structuresForAngle = allStructures[angle] || [];
        let bodyHtml = '<div class="structure-cards-wrapper" style="display:flex; flex-direction:column; gap:15px;">';

        if (structuresForAngle.length === 0) {
            bodyHtml += `
                <div class="yali-notice yali-notice-info">
                    <p>${__('此内容角度下暂无文章结构。请点击右上角按钮生成新的结构。', 'yali-ai-writer')}</p>
                </div>`;
        } else {
            structuresForAngle.forEach(structure => {
                const stats = popularityStats[structure.id] || {};
                const popularityIndex = stats.popularity_index || 0;
                const articleCount = stats.article_count || 0;
                const totalVisits = stats.total_visits || 0;
                const avgVisits = stats.avg_visits || 0;

                // 根据受欢迎度指数设置颜色和标签
                let badgeClass = 'neutral'; // gray
                let popularityLabel = __('无数据', 'yali-ai-writer');

                if (popularityIndex > 0) {
                    if (popularityIndex >= 150) {
                        badgeClass = 'success'; // green
                        popularityLabel = __('🔥 很受欢迎', 'yali-ai-writer');
                    } else if (popularityIndex >= 100) {
                        badgeClass = 'success';
                        popularityLabel = __('👍 受欢迎', 'yali-ai-writer');
                    } else if (popularityIndex >= 60) {
                        badgeClass = 'warning'; // yellow/orange
                        popularityLabel = __('👌 一般', 'yali-ai-writer');
                    } else {
                        badgeClass = 'danger'; // red
                        popularityLabel = __('📉 不太受欢迎', 'yali-ai-writer');
                    }
                }

                const metaArticleInfo = articleCount > 0 ? `<span>${articleCount} ${__('篇文章', 'yali-ai-writer')} · ${__('总访问', 'yali-ai-writer')} ${totalVisits} · ${__('平均', 'yali-ai-writer')} ${avgVisits}</span>` : '';

                bodyHtml += `
                    <div class="yali-panel structure-card" style="position:relative;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:15px;">
                            <h3 class="structure-title" style="margin:0; font-size:16px; font-weight:600; color:#333;">${escapeHtml(structure.title)}</h3>
                            <div class="structure-actions" style="display:flex; gap:8px;">
                                <button class="yali-btn yali-btn-small yali-btn-secondary associate-structure-btn" data-id="${structure.id}">
                                    <span class="dashicons dashicons-admin-links"></span> ${__('关联文章', 'yali-ai-writer')}
                                </button>
                                <button class="yali-btn yali-btn-small yali-btn-danger delete-structure-btn" data-id="${structure.id}">
                                    <span class="dashicons dashicons-trash"></span> ${__('删除', 'yali-ai-writer')}
                                </button>
                            </div>
                        </div>
                        
                        <div class="structure-content" style="background:#f9f9f9; padding:15px; border-radius:4px; margin-bottom:15px; font-size:13px; line-height:1.6; color:#555;">
                            ${formatStructureContent(structure.structure)}
                        </div>
                        
                        <div class="structure-meta" style="display:flex; justify-content:space-between; align-items:center; font-size:12px; color:#777; border-top:1px solid #eee; padding-top:10px;">
                            <div style="display:flex; gap:15px; align-items:center;">
                                <span>${__('使用次数: ', 'yali-ai-writer')} <strong>${structure.usage_count || 0}</strong></span>
                                <span class="yali-badge yali-badge-${badgeClass}">${popularityLabel} (${popularityIndex}%)</span>
                            </div>
                            ${metaArticleInfo}
                        </div>
                    </div>`;
            });
        }
        bodyHtml += '</div>';

        detailContainer.html(headerHtml + bodyHtml);
    }

    // --- UTILITY FUNCTIONS ---
    function escapeHtml(str) {
        return str ? String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;") : '';
    }

    function getPopularityColor(popularityIndex) {
        if (popularityIndex >= 150) {
            return '#00a32a'; // 绿色 - 很受欢迎
        } else if (popularityIndex >= 100) {
            return '#72b300'; // 浅绿色 - 受欢迎
        } else if (popularityIndex >= 60) {
            return '#f0b90b'; // 黄色 - 一般
        } else if (popularityIndex > 0) {
            return '#d63638'; // 红色 - 不太受欢迎
        } else {
            return '#999'; // 灰色 - 无数据
        }
    }

    function formatStructureContent(structure) {
        if (!structure) return '';

        // 如果structure是数组，格式化为列表
        if (Array.isArray(structure)) {
            let listItems = structure.map(item => {
                return `<div style="display:block; margin-bottom:12px; padding:10px 15px; background-color:#f8f9fa; border-left:4px solid #007cba; border-radius:3px; font-size:14px; line-height:1.5;">
                    <strong style="color:#007cba;">&bull;</strong> ${escapeHtml(item)}
                </div>`;
            }).join('');
            return listItems;
        }

        // 如果structure是字符串，处理纯文本格式
        if (typeof structure === 'string') {
            try {
                // 首先尝试解析为JSON数组（兼容旧数据）
                const parsed = JSON.parse(structure);
                if (Array.isArray(parsed)) {
                    return formatStructureContent(parsed);
                }
            } catch (e) {
                // 不是JSON，按纯文本处理

                // 检查是否包含HTML标签
                if (structure.includes('<section>')) {
                    // 处理旧的HTML标签格式
                    return structure
                        .replace(/<\/section>/g, '</div>')
                        .replace(/<section>/g, '<div style="display:block; margin-bottom:12px; padding:10px 15px; background-color:#f8f9fa; border-left:4px solid #007cba; border-radius:3px; font-size:14px; line-height:1.5;"><strong style="color:#007cba;">&bull;</strong> ')
                        .replace(/\n/g, '');
                }

                // 处理纯文本格式（按换行符分割）
                if (structure.includes('\n')) {
                    let lines = structure.split('\n').filter(line => line.trim());
                    if (lines.length > 0) {
                        return lines.map(line =>
                            `<div style="display:block; margin-bottom:12px; padding:10px 15px; background-color:#f8f9fa; border-left:4px solid #007cba; border-radius:3px; font-size:14px; line-height:1.5;">
                                <strong style="color:#007cba;">&bull;</strong> ${escapeHtml(line.trim())}
                            </div>`
                        ).join('');
                    }
                }

                // 单行文本，直接显示
                return `<div style="display:block; margin-bottom:12px; padding:10px 15px; background-color:#f8f9fa; border-left:4px solid #007cba; border-radius:3px; font-size:14px; line-height:1.5;">
                    <strong style="color:#007cba;">&bull;</strong> ${escapeHtml(structure)}
                </div>`;
            }
        }

        // 其他情况，返回空字符串
        return '';
    }

    // 消息提示函数
    function showMessage(message, type = 'info') {
        if (typeof window.yaliToast === 'function') {
            window.yaliToast(message, type);
        } else {
            // Fallback
            $('.temp-message').remove();

            const alertClass = type === 'success' ? 'notice-success yali-notice-success' :
                type === 'error' ? 'notice-error yali-notice-error' :
                    type === 'warning' ? 'notice-warning yali-notice-warning' : 'notice-info yali-notice-info';

            const messageHtml = `<div class="notice ${alertClass} yali-notice temp-message" style="margin: 10px 0;">
                <p style="margin: 0; font-weight: 500;">${message}</p>
            </div>`;

            $('.wrap h1').after(messageHtml);

            const timeout = type === 'error' ? 5000 : 3000;
            setTimeout(function () {
                $('.temp-message').fadeOut(300, function () {
                    $(this).remove();
                });
            }, timeout);
        }
    }

    function deleteDynamicAngle(angle, btnElement) {
        btnElement.prop('disabled', true).css('opacity', '0.7');

        $.ajax({
            url: articleStructures.ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_dynamic_angle',
                angle: angle,
                nonce: articleStructures.nonce
            },
            success: function (response) {
                if (response.success) {
                    // 从本地数据中移除该角度
                    if (window.angleTypes && window.angleTypes.dynamic) {
                        window.angleTypes.dynamic = window.angleTypes.dynamic.filter(a => a !== angle);
                        allAngles = allAngles.filter(a => a !== angle);
                    }

                    // 从结构数据中移除该角度
                    delete allStructures[angle];
                    delete angleUsageTotals[angle];

                    // 如果当前选中的是被删除的角度，清空详情视图
                    if (currentAngle === angle) {
                        currentAngle = null;
                        detailContainer.html('<div class="structure-detail-placeholder"><p>' + __('请从左侧选择一个内容角度', 'yali-ai-writer') + '</p></div>');
                    }

                    // 重新渲染角度列表
                    renderAngleList();

                    const message = response.data.message || __('动态角度已删除，相关主题已重新分配', 'yali-ai-writer');
                    showMessage(message, 'success');
                } else {
                    showMessage(__('删除失败: ', 'yali-ai-writer') + (response.data?.message || __('未知错误', 'yali-ai-writer')), 'error');
                    btnElement.prop('disabled', false).css('opacity', '');
                }
            },
            error: function (xhr, status, error) {
                console.error(__('删除时发生网络错误', 'yali-ai-writer'), status, error, xhr.responseText);
                showMessage(__('删除时发生网络错误', 'yali-ai-writer'), 'error');
                btnElement.prop('disabled', false).css('opacity', '');
            }
        });
    }
});
