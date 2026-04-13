<div class="wrap" id="editor-assistant-settings-page">
    <h1 class="yali-page-title"><span class="dashicons dashicons-admin-settings"></span> <?php _e('编辑器AI助手配置', 'yali-ai-writer'); ?></h1>

    <div style="margin-bottom: 20px;">
        <a href="?page=yali-ai-writer-publish-rules" class="yali-btn yali-btn-secondary">
            <span class="dashicons dashicons-arrow-left-alt"></span> <?php _e('返回发布规则', 'yali-ai-writer'); ?>
        </a>
    </div>

    <div class="yali-card">
        <div class="yali-notice yali-notice-info">
            <p><strong><?php _e('说明：', 'yali-ai-writer'); ?></strong> <?php _e('您可以在此管理编辑器AI助手的快捷生成提示词。', 'yali-ai-writer'); ?></p>
        </div>

        <!-- 主选项卡：文本生成 vs 图像生成 -->
        <div class="ea-main-tabs" style="display: flex; gap: 0; border-bottom: 2px solid #e2e8f0; margin-bottom: 20px;">
            <div class="ea-main-tab active" data-tab="text" style="padding: 12px 24px; font-weight: 600; color: #3b82f6; border-bottom: 2px solid #3b82f6; cursor: pointer; transition: all 0.2s;">
                ✍️ <?php _e('文本生成提示词', 'yali-ai-writer'); ?>
            </div>
            <div class="ea-main-tab" data-tab="image" style="padding: 12px 24px; font-weight: 500; color: #64748b; border-bottom: 2px solid transparent; cursor: pointer; transition: all 0.2s;">
                🖼️ <?php _e('图像生成提示词', 'yali-ai-writer'); ?>
            </div>
        </div>

        <!-- 文本生成提示词面板 -->
        <div id="text-prompts-panel">
            <div class="ea-tabs-header" id="ea-language-tabs">
                <!-- Tabs injected by JS -->
            </div>

            <div id="ea-prompts-container">
                <div style="padding:40px; text-align:center;"><span class="spinner is-active" style="float:none;"></span></div>
            </div>
        </div>

        <!-- 图像生成提示词面板 -->
        <div id="image-prompts-panel" style="display: none;">
            <div class="yali-notice yali-notice-warning" style="margin-bottom: 20px;">
                <p><?php _e('提示：以下配置用于编辑器中的图像生成功能。修改后请保存，系统会自动加载新的配置。', 'yali-ai-writer'); ?></p>
            </div>
            <div id="image-prompts-editor-container">
                <div style="padding:40px; text-align:center;"><span class="spinner is-active" style="float:none;"></span></div>
            </div>
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


