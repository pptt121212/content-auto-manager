jQuery(document).ready(function($) {

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
    angleListContainer.on('click', 'li.angle-item', function() {
        currentAngle = $(this).data('angle');
        angleListContainer.find('li').removeClass('active');
        $(this).addClass('active');
        renderDetailView(currentAngle);
    });

    // 删除动态角度的事件处理器
    angleListContainer.on('click', '.delete-angle-btn', function(e) {
        e.stopPropagation(); // 防止触发角度选择
        const angleToDelete = $(this).data('angle');
        if (confirm(`确定要删除动态角度"${angleToDelete}"吗？\n\n该角度下的主题将随机重新分配到固定角度中，文章结构将被删除。\n\n此操作不可撤销。`)) {
            deleteDynamicAngle(angleToDelete, $(this));
        }
    });

    detailContainer.on('click', '#generate-structures-btn', function() {
        if (!currentAngle) return;
        generateStructuresForAngle($(this));
    });

    detailContainer.on('click', '.delete-structure-btn', function() {
        const structureId = $(this).data('id');
        if (confirm(`确定要删除ID为 ${structureId} 的结构吗？此操作不可撤销。`)) {
            deleteStructure(structureId, $(this));
        }
    });

    detailContainer.on('click', '.associate-structure-btn', function() {
        const structureId = $(this).data('id');
        const structureTitle = $(this).closest('.structure-card').find('.structure-title').text();
        openAssociatedArticlesModal(structureId, structureTitle);
    });

    modal.on('click', '#modal-close, .modal-overlay', function(e) {
        if (e.target === this || $(this).is('#modal-close')) {
            modal.fadeOut();
        }
    });

    // --- DATA & AJAX FUNCTIONS ---
    function loadInitialData() {
        // 检查必要的全局变量
        if (typeof articleStructures === 'undefined') {
            console.error('articleStructures 全局变量未定义');
            angleListContainer.html('<div class="notice notice-error"><p>JavaScript配置错误：articleStructures变量未定义</p></div>');
            return;
        }

        console.log('开始加载数据，AJAX URL:', articleStructures.ajaxurl);

        // Fetch angles, structures, and popularity stats in parallel
        $.when(
            $.ajax({
                url: articleStructures.ajaxurl,
                type: 'POST',
                data: { action: 'get_content_angles', nonce: articleStructures.nonce },
                error: function(xhr, status, error) {
                    console.error('获取内容角度失败:', status, error, xhr.responseText);
                }
            }),
            $.ajax({
                url: articleStructures.ajaxurl,
                type: 'POST',
                data: { action: 'get_article_structures', nonce: articleStructures.nonce },
                error: function(xhr, status, error) {
                    console.error('获取文章结构失败:', status, error, xhr.responseText);
                }
            }),
            $.ajax({
                url: articleStructures.ajaxurl,
                type: 'POST',
                data: { action: 'get_structure_popularity_stats', nonce: articleStructures.nonce },
                error: function(xhr, status, error) {
                    console.error('获取受欢迎度统计失败:', status, error, xhr.responseText);
                }
            })
        ).done(function(anglesResponse, structuresResponse, popularityResponse) {
            console.log('AJAX响应:', {anglesResponse, structuresResponse, popularityResponse});

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
                    
                    console.log('加载的内容角度:', allAngles);
                    console.log('固定角度:', fixedAngles);
                    console.log('动态角度:', dynamicAngles);
                } else {
                    // 兼容旧格式：直接是角度数组
                    allAngles = Array.isArray(angleData) ? angleData : [];
                    window.angleTypes = { fixed: allAngles, dynamic: [] };
                    console.log('加载的内容角度（兼容模式）:', allAngles);
                }
                renderAngleList();
            } else {
                console.error('内容角度加载失败:', anglesResponse[0].data);
                angleListContainer.html('<div class="notice notice-error"><p>加载内容角度失败: ' + (anglesResponse[0].data?.message || '未知错误') + '</p></div>');
            }
            if (structuresResponse[0].success) {
                allStructures = structuresResponse[0].data.structures;
                angleUsageTotals = structuresResponse[0].data.usage_totals || {};
                console.log('加载的文章结构:', allStructures);
            } else {
                console.error('文章结构加载失败:', structuresResponse[0].data);
            }
            if (popularityResponse[0].success) {
                popularityStats = popularityResponse[0].data;
                console.log('加载的受欢迎度统计:', popularityStats);
            }
            renderAngleList(); // 重新渲染以显示使用次数和受欢迎度
        }).fail(function() {
            console.error('数据加载完全失败');
            angleListContainer.html('<div class="notice notice-error"><p>初始化数据加载失败，请检查网络连接和服务器状态</p></div>');
        });
    }

    function generateStructuresForAngle(btn) {
        btn.prop('disabled', true).siblings('.spinner').addClass('is-active');
        
        btn.text('生成中...').prop('disabled', true);
        
        $.ajax({
            url: articleStructures.ajaxurl,
            type: 'POST',
            timeout: 150000, // 2.5分钟超时
            data: {
                action: 'generate_article_structures',
                nonce: articleStructures.nonce,
                angle: currentAngle
            },
            success: function(response) {
                if (response.success) {
                    showMessage(response.data.message, 'success');
                    // 只刷新当前角度的数据
                    setTimeout(() => {
                        loadInitialDataAndRenderDetail();
                    }, 1000);
                } else {
                    showMessage('生成失败: ' + response.data.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                if (status === 'timeout') {
                    showMessage('生成超时，请稍后刷新页面查看结果', 'warning');
                } else if (xhr.status === 403) {
                    showMessage('权限过期，请刷新页面重试', 'error');
                } else {
                    showMessage('生成失败，请检查网络连接', 'error');
                }
            },
            complete: function() {
                btn.prop('disabled', false).text('生成新结构').siblings('.spinner').removeClass('is-active');
            }
        });
    }

    function deleteStructure(id, btn) {
        btn.closest('.structure-card').css('opacity', '0.5');
        $.ajax({
            url: articleStructures.ajaxurl,
            type: 'POST',
            data: { action: 'delete_article_structure', nonce: articleStructures.nonce, id: id },
            success: function(response) {
                if (response.success) {
                    btn.closest('.structure-card').fadeOut(300, function() { $(this).remove(); });
                } else {
                    alert('删除失败: ' + response.data.message);
                    btn.closest('.structure-card').css('opacity', '1');
                }
            },
            error: function() {
                alert('请求失败');
                btn.closest('.structure-card').css('opacity', '1');
            }
        });
    }

    function openAssociatedArticlesModal(structureId, structureTitle) {
        modal.fadeIn();
        $('#modal-title').text(`“${structureTitle}” 关联的文章`);
        $('#modal-body').html('<span class="spinner is-active"></span>');

        $.ajax({
            url: articleStructures.ajaxurl,
            type: 'POST',
            data: { action: 'get_associated_articles', nonce: articleStructures.nonce, structure_id: structureId },
            success: function(response) {
                if (response.success) {
                    let content = '';
                    
                    // 显示统计信息
                    if (response.data.stats) {
                        const stats = response.data.stats;
                        content += `<div style="background: #f9f9f9; padding: 15px; margin-bottom: 20px; border-radius: 5px;">
                            <h4 style="margin-top: 0;">📊 结构表现统计</h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                                <div><strong>关联文章数：</strong> ${stats.total_articles}</div>
                                <div><strong>总外部访问：</strong> ${stats.total_visits}</div>
                                <div><strong>平均访问量：</strong> ${stats.avg_visits}</div>
                                <div><strong>受欢迎度指数：</strong> <span style="color: ${getPopularityColor(stats.popularity_index)}; font-weight: bold;">${stats.popularity_index}%</span></div>
                            </div>
                        </div>`;
                    }
                    
                    // 显示文章列表
                    if (response.data.articles && response.data.articles.length > 0) {
                        content += '<h4>📝 关联文章列表</h4>';
                        content += '<table style="width: 100%; border-collapse: collapse;">';
                        content += '<thead><tr style="background: #f0f0f0;"><th style="padding: 8px; text-align: left;">文章标题</th><th style="padding: 8px; text-align: center;">外部访问</th><th style="padding: 8px; text-align: center;">发布日期</th></tr></thead>';
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
                        content += '<p>暂无关联文章。</p>';
                    }
                    
                    $('#modal-body').html(content);
                } else {
                    $('#modal-body').html('<p>获取关联文章失败。</p>');
                }
            }
        });
    }

    function loadInitialDataAndRenderDetail() {
        // Special function to reload all data and then render the detail view for the current angle
        loadInitialData();
        // A small delay to allow the `allStructures` cache to be populated by the AJAX call
        setTimeout(function() {
            renderDetailView(currentAngle);
        }, 1000);
    }

    // --- RENDER FUNCTIONS ---
    function renderAngleList() {
        if (allAngles.length === 0) {
            angleListContainer.html('<p>未找到内容角度。</p>');
            return;
        }
        
        let listHtml = '<ul>';
        
        // 渲染固定角度
        if (window.angleTypes && window.angleTypes.fixed) {
            window.angleTypes.fixed.forEach(angle => {
                const usageTotal = angleUsageTotals[angle] || 0;
                listHtml += `<li data-angle="${escapeHtml(angle)}" class="angle-item angle-fixed">
                                ${escapeHtml(angle)} (${usageTotal})
                             </li>`;
            });
        }
        
        // 如果有动态角度，添加分隔线和动态角度
        if (window.angleTypes && window.angleTypes.dynamic && window.angleTypes.dynamic.length > 0) {
            listHtml += '<li class="angle-separator">— 动态角度 —</li>';
            
            window.angleTypes.dynamic.forEach(angle => {
                const usageTotal = angleUsageTotals[angle] || 0;
                listHtml += `<li data-angle="${escapeHtml(angle)}" class="angle-item angle-dynamic">
                                <span class="angle-content">${escapeHtml(angle)} (${usageTotal})</span>
                                <button class="delete-angle-btn" data-angle="${escapeHtml(angle)}" title="删除此动态角度及其所有结构">✕</button>
                             </li>`;
            });
        }
        
        listHtml += '</ul>';
        angleListContainer.html(listHtml);
    }

    function renderDetailView(angle) {
        let headerHtml = `
            <div class="structure-detail-header">
                <h2>“${escapeHtml(angle)}” 的结构列表</h2>
                <div>
                    <button id="generate-structures-btn" class="button button-primary">生成新结构</button>
                    <span class="spinner"></span>
                </div>
            </div>`;

        let structuresForAngle = allStructures[angle] || [];
        let bodyHtml = '<div class="structure-cards-wrapper">';
        if (structuresForAngle.length === 0) {
            bodyHtml += '<div class="notice notice-info"><p>此内容角度下暂无文章结构。请点击上方按钮生成。</p></div>';
        } else {
            structuresForAngle.forEach(structure => {
                const stats = popularityStats[structure.id] || {};
                const popularityIndex = stats.popularity_index || 0;
                const articleCount = stats.article_count || 0;
                const totalVisits = stats.total_visits || 0;
                const avgVisits = stats.avg_visits || 0;
                
                // 根据受欢迎度指数设置颜色（新算法）
                let popularityColor = '#999';
                let popularityLabel = '无数据';
                if (popularityIndex > 0) {
                    if (popularityIndex >= 150) {
                        popularityColor = '#00a32a'; // 绿色 - 很受欢迎
                        popularityLabel = '很受欢迎';
                    } else if (popularityIndex >= 100) {
                        popularityColor = '#72b300'; // 浅绿色 - 受欢迎
                        popularityLabel = '受欢迎';
                    } else if (popularityIndex >= 60) {
                        popularityColor = '#f0b90b'; // 黄色 - 一般
                        popularityLabel = '一般';
                    } else {
                        popularityColor = '#d63638'; // 红色 - 不太受欢迎
                        popularityLabel = '不太受欢迎';
                    }
                }
                
                bodyHtml += `
                    <div class="structure-card">
                        <div class="structure-title">${escapeHtml(structure.title)}</div>
                        <div class="structure-content">${formatStructureContent(structure.structure)}</div>
                        <div class="structure-meta-actions">
                            <div class="structure-meta">
                                <span class="usage-count">使用次数: ${structure.usage_count || 0}</span>
                                <span class="popularity-index" style="color: ${popularityColor}; font-weight: bold; margin-left: 15px;">
                                    📊 受欢迎度: ${popularityIndex}% (${popularityLabel})
                                </span>
                                ${articleCount > 0 ? `<span class="article-stats" style="color: #666; font-size: 12px; margin-left: 15px;">
                                    ${articleCount}篇文章 · 总访问${totalVisits}次 · 平均${avgVisits}次
                                </span>` : ''}
                            </div>
                            <div class="structure-actions">
                                <button class="button associate-structure-btn" data-id="${structure.id}">关联文章</button>
                                <button class="button button-link-delete delete-structure-btn" data-id="${structure.id}">删除</button>
                            </div>
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
        // 移除已存在的消息
        $('.temp-message').remove();
        
        const alertClass = type === 'success' ? 'notice-success' : 
                          type === 'error' ? 'notice-error' : 
                          type === 'warning' ? 'notice-warning' : 'notice-info';
        
        const messageHtml = `<div class="notice ${alertClass} temp-message" style="margin: 10px 0; padding: 12px; border-left: 4px solid; border-radius: 3px;">
            <p style="margin: 0; font-weight: 500;">${message}</p>
        </div>`;
        
        // 在页面顶部显示消息
        $('.wrap h1').after(messageHtml);
        
        // 3秒后自动消失（错误消息5秒）
        const timeout = type === 'error' ? 5000 : 3000;
        setTimeout(function() {
            $('.temp-message').fadeOut(300, function() {
                $(this).remove();
            });
        }, timeout);
    }

    function deleteDynamicAngle(angle, btnElement) {
        btnElement.prop('disabled', true).text('删除中...');

        $.ajax({
            url: articleStructures.ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_dynamic_angle',
                angle: angle,
                nonce: articleStructures.nonce
            },
            success: function(response) {
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
                        detailContainer.html('<div class="structure-detail-placeholder"><p>请从左侧选择一个内容角度</p></div>');
                    }
                    
                    // 重新渲染角度列表
                    renderAngleList();
                    
                    const message = response.data.message || `动态角度"${angle}"已删除，相关主题已重新分配`;
                    showMessage(message, 'success');
                } else {
                    showMessage('删除失败: ' + (response.data?.message || '未知错误'), 'error');
                    btnElement.prop('disabled', false).text('✕');
                }
            },
            error: function(xhr, status, error) {
                console.error('删除动态角度时发生错误:', status, error, xhr.responseText);
                showMessage('删除时发生网络错误', 'error');
                btnElement.prop('disabled', false).text('✕');
            }
        });
    }
});
