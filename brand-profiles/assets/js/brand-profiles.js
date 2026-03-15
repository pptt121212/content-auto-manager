/**
 * 显示消息提醒（使用系统全局的 yaliToast）
 * @param {string} message - 消息内容
 * @param {string} type - 消息类型: 'success', 'error', 'warning'
 */
function showBrandNotice(message, type = 'success') {
    if (typeof window.yaliToast === 'function') {
        window.yaliToast(message, type);
    } else {
        // 降级方案：使用 alert
        alert(message);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('cam-brand-profile-form');
    const listContainer = document.getElementById('cam-brand-profiles-list');
    const profileIdInput = document.getElementById('cam-brand-profile-id');
    const cancelButton = document.getElementById('cam-cancel-edit-button');
    const uploadButton = document.getElementById('cam-upload-image-button');
    const imageUrlInput = document.getElementById('cam-brand-profile-image-url');
    const typeSelect = document.getElementById('cam-brand-profile-type');
    const standardFields = document.getElementById('standard-fields');
    const customHtmlFields = document.getElementById('custom-html-fields');
    const referenceFields = document.getElementById('reference-fields');
    const customHtmlTextarea = document.getElementById('cam-brand-profile-custom-html');
    const previewContainer = document.getElementById('custom-html-preview');
    const previewButton = document.getElementById('cam-preview-html-button');
    const filterSelect = document.getElementById('cam-filter-type');
    const paginationContainer = document.getElementById('cam-pagination');

    // 分页和筛选状态
    let currentPage = 1;
    let itemsPerPage = 20;
    let currentFilter = '';
    let allProfiles = [];

    let mediaUploader;

    // Media Uploader
    uploadButton.addEventListener('click', function (e) {
        e.preventDefault();
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: wp.i18n.__('选择图片', 'yali-ai-writer'),
            button: {
                text: wp.i18n.__('选择此图片', 'yali-ai-writer')
            },
            multiple: false
        });
        mediaUploader.on('select', function () {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            imageUrlInput.value = attachment.url;
        });
        mediaUploader.open();
    });

    // 统一的表单验证逻辑
    function validateForm() {
        const title = document.getElementById('cam-brand-profile-title').value.trim();
        const selectedType = typeSelect.value;

        // 标题始终必填（用于生成向量）
        if (!title) {
            return { valid: false, message: wp.i18n.__('标题是必填项（用于生成向量匹配文章）。', 'yali-ai-writer') };
        }

        // 根据类型进行不同验证
        if (selectedType === 'custom_html') {
            // 自定义HTML类型：只需要标题和HTML代码
            const customHtml = customHtmlTextarea.value.trim();
            if (!customHtml) {
                return { valid: false, message: wp.i18n.__('自定义HTML代码是必填项。', 'yali-ai-writer') };
            }
        } else if (selectedType === 'reference') {
            // 参考资料类型：需要标题和描述
            const description = document.getElementById('cam-brand-profile-reference-description').value.trim();
            if (!description) {
                return { valid: false, message: wp.i18n.__('参考资料描述是必填项。', 'yali-ai-writer') };
            }
        } else {
            // 标准类型：需要标题和图片URL
            const imageUrl = imageUrlInput.value.trim();
            if (!imageUrl) {
                return { valid: false, message: wp.i18n.__('图片URL是必填项。', 'yali-ai-writer') };
            }
        }

        return { valid: true };
    }

    // 物料类型切换
    function toggleFields() {
        const selectedType = typeSelect.value;

        // 隐藏所有字段区域
        standardFields.style.display = 'none';
        customHtmlFields.style.display = 'none';
        referenceFields.style.display = 'none';

        // 根据选择的类型显示对应字段
        if (selectedType === 'custom_html') {
            customHtmlFields.style.display = 'block';
        } else if (selectedType === 'reference') {
            referenceFields.style.display = 'block';
        } else {
            standardFields.style.display = 'block';
        }
    }

    typeSelect.addEventListener('change', toggleFields);

    // HTML预览功能
    function updatePreview() {
        const htmlCode = customHtmlTextarea.value.trim();
        if (htmlCode) {
            // 添加自适应样式包装
            const wrappedHtml = `
                <div style="max-width: 100%; overflow: hidden; word-wrap: break-word;">
                    ${htmlCode}
                </div>
            `;
            previewContainer.innerHTML = wrappedHtml;
        } else {
            previewContainer.innerHTML = '<em>' + wp.i18n.__('在上方输入HTML代码，这里将显示预览效果', 'yali-ai-writer') + '</em>';
        }
    }

    previewButton.addEventListener('click', updatePreview);

    // 实时预览（防抖处理）
    customHtmlTextarea.addEventListener('input', function () {
        clearTimeout(this.previewTimeout);
        this.previewTimeout = setTimeout(updatePreview, 500);
    });

    // Fetch and display profiles
    function loadProfiles() {
        const data = new URLSearchParams();
        data.append('action', 'cam_get_brand_profiles');
        data.append('nonce', brandProfilesManager.nonce);

        fetch(brandProfilesManager.ajaxurl, {
            method: 'POST',
            body: data
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    allProfiles = result.data || [];
                    renderFilteredProfiles();
                } else {
                    listContainer.innerHTML = '<div class="profile-empty-state"><h3>' + wp.i18n.__('无法加载品牌资料', 'yali-ai-writer') + '</h3><p>' + wp.i18n.__('请检查网络连接或刷新页面重试', 'yali-ai-writer') + '</p></div>';
                }
            });
    }

    // 筛选和分页渲染
    function renderFilteredProfiles() {
        // 应用筛选
        let filteredProfiles = allProfiles;
        if (currentFilter) {
            filteredProfiles = allProfiles.filter(profile => {
                const profileType = profile.type || 'standard';
                return profileType === currentFilter;
            });
        }

        // 计算分页
        const totalItems = filteredProfiles.length;
        const totalPages = Math.ceil(totalItems / itemsPerPage);
        const startIndex = (currentPage - 1) * itemsPerPage;
        const endIndex = Math.min(startIndex + itemsPerPage, totalItems);
        const currentPageProfiles = filteredProfiles.slice(startIndex, endIndex);

        // 渲染列表
        renderProfiles(currentPageProfiles);

        // 渲染分页
        renderPagination(totalItems, totalPages, currentPage);
    }

    // Render profiles
    function renderProfiles(profiles) {
        if (!profiles || profiles.length === 0) {
            if (currentFilter) {
                listContainer.innerHTML = '<div class="profile-empty-state"><h3>' + wp.i18n.__('未找到匹配的品牌资料', 'yali-ai-writer') + '</h3><p>' + wp.i18n.__('尝试选择其他类型或清空筛选条件', 'yali-ai-writer') + '</p></div>';
            } else {
                listContainer.innerHTML = '<div class="profile-empty-state"><h3>' + wp.i18n.__('暂无品牌资料', 'yali-ai-writer') + '</h3><p>' + wp.i18n.__('点击左侧表单添加您的第一个品牌资料', 'yali-ai-writer') + '</p></div>';
            }
            return;
        }

        listContainer.innerHTML = profiles.map(profile => {
            const profileType = profile.type || 'standard';
            const isCustomHtml = profileType === 'custom_html';
            const isReference = profileType === 'reference';

            // 确定类型显示名称和徽章样式
            let typeName;
            let badgeClass = 'yali-badge-neutral';

            if (isCustomHtml) {
                typeName = wp.i18n.__('自定义HTML', 'yali-ai-writer');
                badgeClass = 'yali-badge-warning';
            } else if (isReference) {
                typeName = wp.i18n.__('参考资料', 'yali-ai-writer');
                badgeClass = 'yali-badge-success';
            } else {
                typeName = wp.i18n.__('标准样式', 'yali-ai-writer');
                badgeClass = 'yali-badge-neutral';
            }

            return `
                <div class="profile-item yali-panel" data-id="${profile.id}" data-type="${profileType}" style="padding:0; overflow:hidden; border:1px solid var(--yali-border); background:white;">
                    <!-- 头部：标题和操作按钮 -->
                    <div class="yali-card-header" style="padding:15px 20px; background:#f8fafc; border-bottom:1px solid var(--yali-border); margin-bottom:0;">
                        <div style="font-size:16px; font-weight:600; display:flex; align-items:center; gap:10px;">
                            ${escapeHTML(profile.title)} 
                            <span class="yali-badge ${badgeClass}">${typeName}</span>
                        </div>
                        <div class="profile-actions" style="display:flex; gap:8px;">
                            <button class="yali-btn yali-btn-small yali-btn-secondary edit-btn">
                                <span class="dashicons dashicons-edit"></span> ${wp.i18n.__('编辑', 'yali-ai-writer')}
                            </button>
                            <button class="yali-btn yali-btn-small yali-btn-danger delete-btn">
                                <span class="dashicons dashicons-trash"></span> ${wp.i18n.__('删除', 'yali-ai-writer')}
                            </button>
                        </div>
                    </div>
                    
                    <!-- 主体：预览和内容 -->
                    <div class="profile-body" style="padding:20px; display:flex; gap:20px;">
                        <!-- 预览区域 -->
                        <div class="profile-preview" style="flex:0 0 120px; width:120px; display:flex; align-items:flex-start; justify-content:center;">
                            ${isCustomHtml ?
                    `<div class="profile-custom-html-preview" style="width:100%; height:80px; background:#fff7ed; border:1px dashed #fdba74; border-radius:4px; display:flex; align-items:center; justify-content:center; flex-direction:column; color:#ea580c;">
                                    <span class="dashicons dashicons-html" style="font-size:32px; width:32px; height:32px;"></span>
                                    <small style="margin-top:5px;">${wp.i18n.__('HTML 组件', 'yali-ai-writer')}</small>
                                </div>` :
                    (isReference ?
                        `<div class="profile-reference-preview" style="width:100%; height:80px; background:#f0f9ff; border:1px dashed #bae6fd; border-radius:4px; display:flex; align-items:center; justify-content:center; flex-direction:column; color:#0284c7;">
                                        <span class="dashicons dashicons-book-alt" style="font-size:32px; width:32px; height:32px;"></span>
                                        <small style="margin-top:5px;">${wp.i18n.__('参考资料', 'yali-ai-writer')}</small>
                                    </div>` :
                        `<div style="width:100%; padding-top:100%; position:relative; overflow:hidden; border-radius:4px; border:1px solid #eee;">
                                    <img src="${profile.image_url}" class="profile-image" alt="${escapeHTML(profile.title)}" style="position:absolute; top:0; left:0; width:100%; height:100%; object-fit:cover;">
                                </div>`
                    )
                }
                        </div>
                        
                        <!-- 内容详情区域 -->
                        <div class="profile-details" style="flex:1;">
                            ${!isCustomHtml && !isReference ?
                    `<div class="profile-description" style="color:#64748b; font-size:14px; line-height:1.6; margin-bottom:10px;">${escapeHTML(profile.description) || '<em class="yali-text-muted">（' + wp.i18n.__('无描述', 'yali-ai-writer') + '）</em>'}</div>` : ''}
                            
                            ${!isCustomHtml && !isReference && profile.link ?
                    `<div class="profile-link" style="margin-top:10px;">
                                    <a href="${profile.link}" target="_blank" class="yali-link" style="display:inline-flex; align-items:center; gap:5px;">
                                        <span class="dashicons dashicons-external"></span> ${escapeHTML(profile.link)}
                                    </a>
                                </div>` : ''}
                            
                            ${isReference ?
                    `<div class="profile-description" style="color:#64748b; font-size:14px; line-height:1.6; background:#f8fafc; padding:10px; border-radius:4px; border:1px solid #e2e8f0; max-height:150px; overflow-y:auto;">
                                    ${escapeHTML(profile.description)}
                                </div>` : ''}
                                
                             ${isCustomHtml ?
                    `<div style="color:#64748b; font-size:13px;">
                                    <div class="yali-badge yali-badge-neutral" style="margin-bottom:8px;">${wp.i18n.__('HTML 代码预览', 'yali-ai-writer')}</div>
                                    <div style="font-family:monospace; background:#f1f5f9; padding:10px; border-radius:4px; max-height:100px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                        ${escapeHTML(profile.custom_html).substring(0, 100)}${profile.custom_html.length > 100 ? '...' : ''}
                                    </div>
                                </div>` : ''}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    // 渲染分页组件
    function renderPagination(totalItems, totalPages, currentPage) {
        if (totalItems === 0) {
            paginationContainer.innerHTML = '';
            return;
        }

        const startItem = totalItems > 0 ? (currentPage - 1) * itemsPerPage + 1 : 0;
        const endItem = Math.min(currentPage * itemsPerPage, totalItems);

        let paginationHTML = `
            <div class="pagination-info">
                ${wp.i18n.sprintf(wp.i18n.__('显示 %1$s-%2$s 项，共 %3$s 项', 'yali-ai-writer'), startItem, endItem, totalItems)}
            </div>
            <div class="pagination-controls">
        `;

        // 上一页按钮
        if (currentPage > 1) {
            paginationHTML += `<button class="page-numbers prev yali-btn-secondary" onclick="changePage(${currentPage - 1})">&laquo; ${wp.i18n.__('上一页', 'yali-ai-writer')}</button>`;
        } else {
            paginationHTML += `<button class="page-numbers prev" disabled style="opacity:0.5; cursor:not-allowed;">&laquo; ${wp.i18n.__('上一页', 'yali-ai-writer')}</button>`;
        }

        // 页码按钮
        const maxVisiblePages = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
        let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);

        if (endPage - startPage + 1 < maxVisiblePages) {
            startPage = Math.max(1, endPage - maxVisiblePages + 1);
        }

        if (startPage > 1) {
            paginationHTML += `<button class="page-numbers yali-btn-secondary" onclick="changePage(1)">1</button>`;
            if (startPage > 2) {
                paginationHTML += `<span class="page-numbers dots">...</span>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            const isCurrentPage = i === currentPage;
            paginationHTML += `<button class="page-numbers ${isCurrentPage ? 'current' : 'yali-btn-secondary'}" onclick="changePage(${i})">${i}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                paginationHTML += `<span class="page-numbers dots">...</span>`;
            }
            paginationHTML += `<button class="page-numbers yali-btn-secondary" onclick="changePage(${totalPages})">${totalPages}</button>`;
        }

        // 下一页按钮
        if (currentPage < totalPages) {
            paginationHTML += `<button class="page-numbers next yali-btn-secondary" onclick="changePage(${currentPage + 1})">${wp.i18n.__('下一页', 'yali-ai-writer')} &raquo;</button>`;
        } else {
            paginationHTML += `<button class="page-numbers next" disabled style="opacity:0.5; cursor:not-allowed;">${wp.i18n.__('下一页', 'yali-ai-writer')} &raquo;</button>`;
        }

        paginationHTML += `</div>`;
        paginationContainer.innerHTML = paginationHTML;
    }

    // 切换页面
    window.changePage = function (page) {
        currentPage = page;
        renderFilteredProfiles();
    };

    // 筛选类型改变
    filterSelect.addEventListener('change', function () {
        currentFilter = this.value;
        currentPage = 1; // 重置到第一页
        renderFilteredProfiles();
    });

    // Form submission
    form.addEventListener('submit', function (e) {
        e.preventDefault();

        // 使用统一验证逻辑
        const validation = validateForm();
        if (!validation.valid) {
            showBrandNotice(validation.message, 'warning');
            return;
        }

        const formData = new FormData(form);
        const id = profileIdInput.value;
        const action = id ? 'cam_update_brand_profile' : 'cam_add_brand_profile';

        const data = new URLSearchParams();
        for (const pair of formData) {
            data.append(pair[0], pair[1]);
        }
        data.append('action', action);
        data.append('nonce', brandProfilesManager.nonce);

        fetch(brandProfilesManager.ajaxurl, {
            method: 'POST',
            body: data
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showBrandNotice(result.data.message, 'success');
                    resetForm();
                    loadProfiles();
                } else {
                    showBrandNotice(wp.i18n.__('操作失败: ', 'yali-ai-writer') + result.data.message, 'error');
                }
            });
    });

    // Edit and Delete buttons
    // Edit and Delete buttons
    listContainer.addEventListener('click', function (e) {
        // 使用 closest 查找按钮，因为点击目标可能是按钮内部的图标或文字
        const editBtn = e.target.closest('.edit-btn');
        const deleteBtn = e.target.closest('.delete-btn');

        // 任何一个按钮被点击，都需要找到对应的 item
        const targetBtn = editBtn || deleteBtn;
        if (!targetBtn) return;

        const item = targetBtn.closest('.profile-item');
        if (!item) return;

        const id = item.dataset.id;

        // 防止事件冒泡（虽然这里可能不需要，但好习惯）
        e.stopPropagation();

        if (editBtn) {
            // 获取品牌资料详细信息
            const profileType = item.dataset.type || 'standard';
            // 获取标题，移除徽章部分的内容
            const titleEl = item.querySelector('.yali-card-header > div');
            // 克隆节点以获取纯文本，或者只处理文本节点
            let profileTitle = '';
            if (titleEl) {
                // 简单的提取：取第一个文本节点或移除 badge 后取文本
                // 由于结构是 "Title <span class=badge>...</span>"，我们可以取整个 textContent 然后把 badge 的 textContent 删掉？
                // 或者更稳健的方法：
                const clone = titleEl.cloneNode(true);
                const badge = clone.querySelector('.yali-badge');
                if (badge) badge.remove();
                profileTitle = clone.textContent.trim();
            }

            profileIdInput.value = id;
            document.getElementById('cam-brand-profile-title').value = profileTitle;
            typeSelect.value = profileType;

            // 触发字段切换
            toggleFields();

            if (profileType === 'custom_html' || profileType === 'reference') {
                // 自定义HTML类型和参考资料类型 - 需要从服务器获取完整数据
                fetchProfileDetails(id);
            } else {
                // 标准类型 - 从列表中获取数据
                const descriptionEl = item.querySelector('.profile-description');
                const description = descriptionEl && !descriptionEl.querySelector('em') ? descriptionEl.textContent.trim() : '';

                const imageUrlEl = item.querySelector('.profile-image');
                const imageUrl = imageUrlEl ? imageUrlEl.src : '';

                const linkEl = item.querySelector('.profile-link a');
                const link = linkEl ? linkEl.href : '';

                document.getElementById('cam-brand-profile-description').value = description;
                imageUrlInput.value = imageUrl;
                document.getElementById('cam-brand-profile-link').value = link;
            }

            cancelButton.style.display = 'inline-block';

            // 滚动到表单顶部
            document.querySelector('.form-container').scrollIntoView({ behavior: 'smooth' });
        }

        if (deleteBtn) {
            if (confirm(wp.i18n.__('确定要删除这个品牌资料吗？', 'yali-ai-writer'))) {
                const data = new URLSearchParams();
                data.append('action', 'cam_delete_brand_profile');
                data.append('id', id);
                data.append('nonce', brandProfilesManager.nonce);

                fetch(brandProfilesManager.ajaxurl, {
                    method: 'POST',
                    body: data
                })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            showBrandNotice(result.data.message, 'success');
                            loadProfiles();
                        } else {
                            showBrandNotice(wp.i18n.__('删除失败: ', 'yali-ai-writer') + result.data.message, 'error');
                        }
                    });
            }
        }
    });

    // Cancel edit
    cancelButton.addEventListener('click', function () {
        resetForm();
    });

    // 获取品牌资料详细信息（用于编辑自定义HTML类型）
    function fetchProfileDetails(id) {
        const data = new URLSearchParams();
        data.append('action', 'cam_get_brand_profile_details');
        data.append('id', id);
        data.append('nonce', brandProfilesManager.nonce);

        fetch(brandProfilesManager.ajaxurl, {
            method: 'POST',
            body: data
        })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    const profile = result.data;
                    if (profile.type === 'custom_html') {
                        customHtmlTextarea.value = profile.custom_html || '';
                        updatePreview();
                    } else if (profile.type === 'reference') {
                        document.getElementById('cam-brand-profile-reference-description').value = profile.description || '';
                    }
                } else {
                    showBrandNotice(wp.i18n.__('获取品牌资料详情失败', 'yali-ai-writer'), 'error');
                }
            });
    }

    function resetForm() {
        form.reset();
        profileIdInput.value = '';
        cancelButton.style.display = 'none';

        // 重置类型选择和字段显示
        typeSelect.value = 'standard';
        toggleFields();

        // 清空预览
        previewContainer.innerHTML = '<em>' + wp.i18n.__('在上方输入HTML代码，这里将显示预览效果', 'yali-ai-writer') + '</em>';
    }

    function escapeHTML(str) {
        if (str === null || str === undefined) return '';
        return str.replace(/[&<>"'`]/g, function (match) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
                '`': '&#x60;'
            }[match];
        });
    }

    // Initial setup
    toggleFields(); // 初始化字段显示状态

    // 清空分页容器
    paginationContainer.innerHTML = '';

    // Initial load
    loadProfiles();
});
