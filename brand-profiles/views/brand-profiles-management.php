<div class="wrap yali-plugin-wrapper" id="brand-profiles-page">
    <h1 class="yali-page-title"><span class="dashicons dashicons-id-alt"></span> <?php _e('品牌资料管理', 'yali-ai-writer'); ?></h1>

    <div id="cam-brand-profiles-container" class="yali-grid-layout" style="grid-template-columns: 350px 1fr !important;">
        <!-- Left Column: Form -->
        <div class="form-container">
            <div class="yali-card">
                <div class="yali-card-header">
                    <span><?php _e('添加/编辑品牌资料', 'yali-ai-writer'); ?></span>
                </div>
                
                <form id="cam-brand-profile-form">
                    <input type="hidden" id="cam-brand-profile-id" name="id">
                    
                    <div class="yali-form-group">
                        <label for="cam-brand-profile-title" class="yali-form-label"><?php _e('标题', 'yali-ai-writer'); ?> <span class="yali-text-danger">*</span></label>
                        <input type="text" id="cam-brand-profile-title" name="title" required class="yali-input" placeholder="<?php esc_attr_e('例如：产品介绍、品牌故事...', 'yali-ai-writer'); ?>">
                        <span class="yali-desc"><?php _e('用于生成向量匹配文章，请尽量准确简洁。', 'yali-ai-writer'); ?></span>
                    </div>

                    <div class="yali-form-group">
                        <label for="cam-brand-profile-type" class="yali-form-label"><?php _e('物料类型', 'yali-ai-writer'); ?></label>
                        <select id="cam-brand-profile-type" name="type" class="yali-select">
                            <option value="standard"><?php _e('标准样式 (图片+链接)', 'yali-ai-writer'); ?></option>
                            <option value="custom_html"><?php _e('自定义HTML (公众号卡片等)', 'yali-ai-writer'); ?></option>
                            <option value="reference"><?php _e('参考资料 (仅用于AI参考)', 'yali-ai-writer'); ?></option>
                        </select>
                    </div>

                    <!-- 标准样式字段 -->
                    <div id="standard-fields">
                        <div class="yali-form-group">
                            <label for="cam-brand-profile-description" class="yali-form-label"><?php _e('描述/文案 (可选)', 'yali-ai-writer'); ?></label>
                            <textarea id="cam-brand-profile-description" name="description" rows="4" class="yali-input" placeholder="<?php esc_attr_e('输入品牌描述...', 'yali-ai-writer'); ?>"></textarea>
                        </div>
                        <div class="yali-form-group">
                            <label for="cam-brand-profile-image-url" class="yali-form-label"><?php _e('图片URL', 'yali-ai-writer'); ?> <span class="yali-text-danger">*</span></label>
                            <div class="yali-input-group" style="display:flex; gap:10px;">
                                <input type="text" id="cam-brand-profile-image-url" name="image_url" class="yali-input" placeholder="https://..." style="flex:1;">
                                <button type="button" id="cam-upload-image-button" class="yali-btn yali-btn-secondary"><?php _e('选择图片', 'yali-ai-writer'); ?></button>
                            </div>
                        </div>
                        <div class="yali-form-group">
                            <label for="cam-brand-profile-link" class="yali-form-label"><?php _e('跳转链接 (可选)', 'yali-ai-writer'); ?></label>
                            <input type="text" id="cam-brand-profile-link" name="link" class="yali-input" placeholder="https://...">
                        </div>
                    </div>

                    <!-- 自定义HTML字段 -->
                    <div id="custom-html-fields" style="display:none;">
                        <div class="yali-form-group">
                            <label for="cam-brand-profile-custom-html" class="yali-form-label"><?php _e('HTML代码', 'yali-ai-writer'); ?> <span class="yali-text-danger">*</span></label>
                            <textarea id="cam-brand-profile-custom-html" name="custom_html" rows="10" class="yali-input yali-textarea-code" placeholder="<?php esc_attr_e('粘贴HTML代码...', 'yali-ai-writer'); ?>"></textarea>
                            <div style="margin-top:10px; text-align:right;">
                                <button type="button" id="cam-preview-html-button" class="yali-btn yali-btn-small yali-btn-secondary"><?php _e('更新预览', 'yali-ai-writer'); ?></button>
                            </div>
                        </div>
                        <div class="yali-form-group">
                            <label class="yali-form-label"><?php _e('效果预览', 'yali-ai-writer'); ?></label>
                            <div id="custom-html-preview" class="yali-panel" style="min-height:100px; padding:15px; background:#f9f9f9; display:flex; align-items:center; justify-content:center;">
                                <em class="yali-text-muted"><?php _e('在上方输入HTML代码，这里将显示预览效果', 'yali-ai-writer'); ?></em>
                            </div>
                        </div>
                    </div>

                    <!-- 参考资料字段 -->
                    <div id="reference-fields" style="display:none;">
                        <div class="yali-form-group">
                            <label for="cam-brand-profile-reference-description" class="yali-form-label"><?php _e('资料内容', 'yali-ai-writer'); ?> <span class="yali-text-danger">*</span></label>
                            <textarea id="cam-brand-profile-reference-description" name="reference_description" rows="10" class="yali-input" placeholder="<?php esc_attr_e('输入作为参考资料的文本内容...', 'yali-ai-writer'); ?>"></textarea>
                            <p class="yali-desc"><?php _e('此内容仅用于AI写作参考，不会直接显示在文章中。', 'yali-ai-writer'); ?></p>
                        </div>
                    </div>

                    <div class="yali-card-footer" style="padding: 20px 0 0 0; margin-top: 20px; border-top: 1px solid var(--yali-border);">
                        <button type="submit" class="yali-btn yali-btn-primary"><?php _e('保存资料', 'yali-ai-writer'); ?></button>
                        <button type="button" id="cam-cancel-edit-button" class="yali-btn yali-btn-secondary" style="display:none;"><?php _e('取消编辑', 'yali-ai-writer'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column: List -->
        <div class="list-container">
            <div class="yali-card">
                 <div class="yali-card-header">
                    <span><?php _e('资料列表', 'yali-ai-writer'); ?></span>
                    <div class="list-controls">
                        <select id="cam-filter-type" class="yali-select" style="min-width:120px;">
                            <option value=""><?php _e('所有类型', 'yali-ai-writer'); ?></option>
                            <option value="standard"><?php _e('标准样式', 'yali-ai-writer'); ?></option>
                            <option value="custom_html"><?php _e('自定义HTML', 'yali-ai-writer'); ?></option>
                            <option value="reference"><?php _e('参考资料', 'yali-ai-writer'); ?></option>
                        </select>
                    </div>
                </div>
                
                <div id="cam-brand-profiles-list" class="yali-flex-wrap" style="flex-direction:column; gap:16px;">
                    <!-- 列表将通过JS加载 -->
                    <div style="padding:40px; text-align:center;">
                        <span class="spinner is-active" style="float:none;"></span>
                    </div>
                </div>
                
                <div id="cam-pagination" class="yali-pagination"></div>
            </div>
        </div>
    </div>
</div>
