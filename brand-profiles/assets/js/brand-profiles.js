document.addEventListener('DOMContentLoaded', function() {
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
    uploadButton.addEventListener('click', function(e) {
        e.preventDefault();
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        mediaUploader = wp.media.frames.file_frame = wp.media({
            title: '选择图片',
            button: {
                text: '选择此图片'
            },
            multiple: false
        });
        mediaUploader.on('select', function() {
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
            return { valid: false, message: '标题是必填项（用于生成向量匹配文章）。' };
        }
        
        // 根据类型进行不同验证
        if (selectedType === 'custom_html') {
            // 自定义HTML类型：只需要标题和HTML代码
            const customHtml = customHtmlTextarea.value.trim();
            if (!customHtml) {
                return { valid: false, message: '自定义HTML代码是必填项。' };
            }
        } else if (selectedType === 'reference') {
            // 参考资料类型：需要标题和描述
            const description = document.getElementById('cam-brand-profile-reference-description').value.trim();
            if (!description) {
                return { valid: false, message: '参考资料描述是必填项。' };
            }
        } else {
            // 标准类型：需要标题和图片URL
            const imageUrl = imageUrlInput.value.trim();
            if (!imageUrl) {
                return { valid: false, message: '图片URL是必填项。' };
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
            previewContainer.innerHTML = '<em>在上方输入HTML代码，这里将显示预览效果</em>';
        }
    }

    previewButton.addEventListener('click', updatePreview);
    
    // 实时预览（防抖处理）
    customHtmlTextarea.addEventListener('input', function() {
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
                listContainer.innerHTML = '<div class="profile-empty-state"><h3>无法加载品牌资料</h3><p>请检查网络连接或刷新页面重试</p></div>';
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
                listContainer.innerHTML = '<div class="profile-empty-state"><h3>未找到匹配的品牌资料</h3><p>尝试选择其他类型或清空筛选条件</p></div>';
            } else {
                listContainer.innerHTML = '<div class="profile-empty-state"><h3>暂无品牌资料</h3><p>点击左侧表单添加您的第一个品牌资料</p></div>';
            }
            return;
        }

        listContainer.innerHTML = profiles.map(profile => {
            const profileType = profile.type || 'standard';
            const isCustomHtml = profileType === 'custom_html';
            const isReference = profileType === 'reference';
            
            // 确定类型显示名称
            let typeName;
            if (isCustomHtml) {
                typeName = '自定义HTML';
            } else if (isReference) {
                typeName = '参考资料';
            } else {
                typeName = '标准样式';
            }
            
            return `
                <div class="profile-item" data-id="${profile.id}" data-type="${profileType}">
                    <!-- 头部：标题和操作按钮 -->
                    <div class="profile-header">
                        <h3>${escapeHTML(profile.title)} <span class="profile-type-badge">${typeName}</span></h3>
                        <div class="profile-actions">
                            <button class="button edit-btn">编辑</button>
                            <button class="button delete-btn">删除</button>
                        </div>
                    </div>
                    
                    <!-- 主体：预览和内容 -->
                    <div class="profile-body">
                        <!-- 预览区域 -->
                        <div class="profile-preview">
                            ${isCustomHtml ? 
                                `<div class="profile-custom-html-preview">
                                    <div>
                                        ${profile.custom_html || '<em>自定义HTML内容</em>'}
                                    </div>
                                </div>` :
                                (isReference ? 
                                    `<div class="profile-reference-preview">
                                        <div class="reference-icon">
                                            📝<br><small>参考资料</small>
                                        </div>
                                    </div>` :
                                    `<img src="${profile.image_url}" class="profile-image" alt="${escapeHTML(profile.title)}">`
                                )
                            }
                        </div>
                        
                        <!-- 内容详情区域 -->
                        <div class="profile-details">
                            ${!isCustomHtml && !isReference ? `<div class="profile-description">${escapeHTML(profile.description)}</div>` : ''}
                            ${!isCustomHtml && !isReference && profile.link ? `<div class="profile-link"><a href="${profile.link}" target="_blank">${escapeHTML(profile.link)}</a></div>` : ''}
                            ${isReference ? `<div class="profile-description">${escapeHTML(profile.description)}</div>` : ''}
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
                显示 ${startItem}-${endItem} 项，共 ${totalItems} 项
            </div>
            <div class="pagination-controls">
        `;

        // 上一页按钮
        if (currentPage > 1) {
            paginationHTML += `<button class="pagination-btn" onclick="changePage(${currentPage - 1})">上一页</button>`;
        } else {
            paginationHTML += `<button class="pagination-btn" disabled>上一页</button>`;
        }

        // 页码按钮
        const maxVisiblePages = 5;
        let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
        let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
        
        if (endPage - startPage + 1 < maxVisiblePages) {
            startPage = Math.max(1, endPage - maxVisiblePages + 1);
        }

        if (startPage > 1) {
            paginationHTML += `<button class="pagination-btn" onclick="changePage(1)">1</button>`;
            if (startPage > 2) {
                paginationHTML += `<span class="pagination-ellipsis">...</span>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            const isCurrentPage = i === currentPage;
            paginationHTML += `<button class="pagination-btn ${isCurrentPage ? 'current' : ''}" onclick="changePage(${i})">${i}</button>`;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                paginationHTML += `<span class="pagination-ellipsis">...</span>`;
            }
            paginationHTML += `<button class="pagination-btn" onclick="changePage(${totalPages})">${totalPages}</button>`;
        }

        // 下一页按钮
        if (currentPage < totalPages) {
            paginationHTML += `<button class="pagination-btn" onclick="changePage(${currentPage + 1})">下一页</button>`;
        } else {
            paginationHTML += `<button class="pagination-btn" disabled>下一页</button>`;
        }

        paginationHTML += `</div>`;
        paginationContainer.innerHTML = paginationHTML;
    }

    // 切换页面
    window.changePage = function(page) {
        currentPage = page;
        renderFilteredProfiles();
    };

    // 筛选类型改变
    filterSelect.addEventListener('change', function() {
        currentFilter = this.value;
        currentPage = 1; // 重置到第一页
        renderFilteredProfiles();
    });

    // Form submission
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // 使用统一验证逻辑
        const validation = validateForm();
        if (!validation.valid) {
            alert(validation.message);
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
                alert(result.data.message);
                resetForm();
                loadProfiles();
            } else {
                alert('操作失败: ' + result.data.message);
            }
        });
    });

    // Edit and Delete buttons
    listContainer.addEventListener('click', function(e) {
        const target = e.target;
        const item = target.closest('.profile-item');
        if (!item) return;

        const id = item.dataset.id;

        if (target.classList.contains('edit-btn')) {
            // 获取品牌资料详细信息
            const profileType = item.dataset.type || 'standard';
            const profileTitle = item.querySelector('h3').textContent.replace(/ (自定义HTML|标准样式|参考资料)$/, ''); // 移除类型标记
            
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
                const description = item.querySelector('.profile-description') ? item.querySelector('.profile-description').textContent : '';
                const imageUrl = item.querySelector('.profile-image') ? item.querySelector('.profile-image').src : '';
                const link = item.querySelector('.profile-link a') ? item.querySelector('.profile-link a').href : '';
                
                document.getElementById('cam-brand-profile-description').value = description;
                imageUrlInput.value = imageUrl;
                document.getElementById('cam-brand-profile-link').value = link;
            }

            cancelButton.style.display = 'inline-block';
            window.scrollTo(0, 0);
        }

        if (target.classList.contains('delete-btn')) {
            if (confirm('确定要删除这个品牌资料吗？')) {
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
                        alert(result.data.message);
                        loadProfiles();
                    } else {
                        alert('删除失败: ' + result.data.message);
                    }
                });
            }
        }
    });

    // Cancel edit
    cancelButton.addEventListener('click', function() {
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
                alert('获取品牌资料详情失败');
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
        previewContainer.innerHTML = '<em>在上方输入HTML代码，这里将显示预览效果</em>';
    }

    function escapeHTML(str) {
        if (str === null || str === undefined) return '';
        return str.replace(/[&<>"'`]/g, function(match) {
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
