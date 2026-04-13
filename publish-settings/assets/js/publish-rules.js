document.addEventListener('DOMContentLoaded', function() {
    // 分类模式切换
    const categoryMode = document.getElementById('category_mode');
    const manualRow = document.getElementById('manual_category_row');
    const autoRow = document.getElementById('auto_category_row');

    if (categoryMode) {
        const toggleCategoryRows = () => {
            if (categoryMode.value === 'manual') {
                manualRow.style.display = 'table-row';
                autoRow.style.display = 'none';
            } else {
                manualRow.style.display = 'none';
                autoRow.style.display = 'table-row';
            }
        };
        categoryMode.addEventListener('change', toggleCategoryRows);
        toggleCategoryRows(); // 初始化
    }

    // 图片提示词模式切换
    const imagePromptMode = document.getElementById('image_prompt_mode');
    const customImagePromptRow = document.getElementById('custom_image_prompt_row');
    
    if (imagePromptMode && customImagePromptRow) {
        const toggleCustomImagePromptRow = () => {
            if (imagePromptMode.value === 'default') {
                customImagePromptRow.style.display = 'table-row';
            } else {
                customImagePromptRow.style.display = 'none';
            }
        };
        imagePromptMode.addEventListener('change', toggleCustomImagePromptRow);
        toggleCustomImagePromptRow(); // 初始化
    }

    // 发布状态切换 (显示/隐藏间隔)
    const postStatus = document.getElementById('post_status');
    const intervalRow = document.getElementById('publish_interval_row');

    if (postStatus) {
        const toggleIntervalRow = () => {
            if (postStatus.value === 'publish') {
                intervalRow.style.display = 'table-row';
            } else {
                intervalRow.style.display = 'none';
            }
        };
        postStatus.addEventListener('change', toggleIntervalRow);
        toggleIntervalRow(); // 初始化
    }

    // 自动配图选项切换
    const autoImageInsertion = document.getElementById('auto_image_insertion');
    const autoImageOptions = document.getElementById('auto_image_options');

    if (autoImageInsertion) {
        const toggleAutoImageOptions = () => {
            autoImageOptions.style.display = autoImageInsertion.checked ? 'block' : 'none';
        };
        autoImageInsertion.addEventListener('change', toggleAutoImageOptions);
        toggleAutoImageOptions();
    }

    // 品牌资料选项切换
    const enableBrandProfile = document.getElementById('enable_brand_profile_insertion');
    const brandProfileOptions = document.getElementById('brand_profile_options');

    if (enableBrandProfile) {
        const toggleBrandProfileOptions = () => {
            brandProfileOptions.style.display = enableBrandProfile.checked ? 'block' : 'none';
        };
        enableBrandProfile.addEventListener('change', toggleBrandProfileOptions);
        toggleBrandProfileOptions();
    }

    // 内链选项与向量聚类入口切换
    const enableInternalLinking = document.getElementById('enable_internal_linking');
    const vectorClusteringLink = document.getElementById('vector_clustering_link_container');

    if (enableInternalLinking && vectorClusteringLink) {
        const toggleVectorClustering = () => {
            vectorClusteringLink.style.display = enableInternalLinking.checked ? 'block' : 'none';
        };
        enableInternalLinking.addEventListener('change', toggleVectorClustering);
        toggleVectorClustering();
    }

    // 编辑器AI助手链接切换
    const enableEditorAssistant = document.getElementById('enable_editor_assistant');
    const editorAssistantLink = document.getElementById('editor_assistant_link_container');

    if (enableEditorAssistant && editorAssistantLink) {
        const toggleEditorAssistant = () => {
            editorAssistantLink.style.display = enableEditorAssistant.checked ? 'block' : 'none';
        };
        enableEditorAssistant.addEventListener('change', toggleEditorAssistant);
        toggleEditorAssistant();
    }

    // 参考资料选项切换
    const enableReferenceMaterial = document.getElementById('enable_reference_material');
    const aiReferenceSelectOptions = document.getElementById('ai_reference_select_options');

    if (enableReferenceMaterial) {
        const toggleAiReferenceSelectOptions = () => {
            if (enableReferenceMaterial.checked) {
                aiReferenceSelectOptions.style.display = 'block';
            } else {
                aiReferenceSelectOptions.style.display = 'none';
                const aiReferenceSelect = document.getElementById('enable_ai_reference_select');
                if (aiReferenceSelect) aiReferenceSelect.checked = false;
            }
        };
        enableReferenceMaterial.addEventListener('change', toggleAiReferenceSelectOptions);
        toggleAiReferenceSelectOptions();
    }

    // 文章结构入口切换
    const normalizeOutput = document.getElementById('normalize_output');
    const structureModeOptions = document.getElementById('structure_mode_options');
    const articleStructureLink = document.getElementById('article_structure_link_container');

    if (normalizeOutput && structureModeOptions) {
        const toggleStructureOptions = () => {
            structureModeOptions.style.display = normalizeOutput.checked ? 'block' : 'none';
        };
        normalizeOutput.addEventListener('change', toggleStructureOptions);
        toggleStructureOptions();
    }

    // 结构模式单选按钮切换 - 控制文章结构管理链接的显示
    const structureModeRadios = document.querySelectorAll('input[name="structure_mode"]');
    if (structureModeRadios.length > 0 && articleStructureLink) {
        const toggleStructureModeLink = () => {
            const selectedMode = document.querySelector('input[name="structure_mode"]:checked');
            articleStructureLink.style.display = (selectedMode && selectedMode.value === 'generic') ? 'block' : 'none';
        };
        structureModeRadios.forEach(radio => {
            radio.addEventListener('change', toggleStructureModeLink);
        });
        toggleStructureModeLink();
    }
});
