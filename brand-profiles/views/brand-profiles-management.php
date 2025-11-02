<h1>品牌资料管理</h1>

<div id="cam-brand-profiles-container">
    <div class="form-container">
        <h2>添加/编辑品牌资料</h2>
        <form id="cam-brand-profile-form">
            <input type="hidden" id="cam-brand-profile-id" name="id">
            <div class="form-group">
                <label for="cam-brand-profile-title">标题 (必填)</label>
                <input type="text" id="cam-brand-profile-title" name="title" required>
                <p class="description">标题用于计算物料向量，匹配相关文章。</p>
            </div>
            <div class="form-group">
                <label for="cam-brand-profile-type">物料类型</label>
                <select id="cam-brand-profile-type" name="type" required>
                    <option value="standard">标准样式 (图片+描述+链接)</option>
                    <option value="custom_html">自定义HTML代码</option>
                    <option value="reference">参考资料 (标题+描述)</option>
                </select>
                <p class="description">选择品牌物料的展示类型。参考资料类型用于文章生成时的参考信息。</p>
            </div>

            <!-- 标准样式字段 -->
            <div id="standard-fields">
                <div class="form-group">
                    <label for="cam-brand-profile-image-url">图片URL</label>
                    <div class="image-input-group">
                        <input type="text" id="cam-brand-profile-image-url" name="image_url">
                        <button type="button" class="button" id="cam-upload-image-button">上传图片</button>
                    </div>
                     <p class="description">请输入完整的图片URL或点击上传按钮从媒体库选择。</p>
                </div>
                <div class="form-group">
                    <label for="cam-brand-profile-description">描述</label>
                    <textarea id="cam-brand-profile-description" name="description" rows="4"></textarea>
                </div>
                <div class="form-group">
                    <label for="cam-brand-profile-link">链接</label>
                    <input type="text" id="cam-brand-profile-link" name="link">
                </div>
            </div>

            <!-- 自定义HTML字段 -->
            <div id="custom-html-fields" style="display: none;">
                <div class="form-group">
                    <label for="cam-brand-profile-custom-html">自定义HTML代码</label>
                    <textarea id="cam-brand-profile-custom-html" name="custom_html" rows="10" style="font-family: 'Courier New', monospace; font-size: 14px;"></textarea>
                    <p class="description">
                        输入完全自定义的HTML代码。<br>
                        • 宽度将自动适应文章区域，请避免使用固定宽度<br>
                        • 建议使用相对单位（%、em、rem）而非固定像素<br>
                        • 可以包含内联CSS样式或class类名<br>
                        • HTML代码将直接插入文章中，请确保代码安全性
                    </p>
                </div>
                <div class="form-group">
                    <label>预览效果</label>
                    <div id="custom-html-preview" style="border: 1px solid #ddd; padding: 15px; background: #f9f9f9; min-height: 50px;">
                        <em>在上方输入HTML代码，这里将显示预览效果</em>
                    </div>
                    <button type="button" class="button" id="cam-preview-html-button">刷新预览</button>
                </div>
            </div>

            <!-- 参考资料字段 -->
            <div id="reference-fields" style="display: none;">
                <div class="form-group">
                    <label for="cam-brand-profile-reference-description">参考资料描述 (必填)</label>
                    <textarea id="cam-brand-profile-reference-description" name="reference_description" rows="6"></textarea>
                    <p class="description">
                        描述内容将在文章生成时作为参考资料插入到提示词模板中。<br>
                        • 请提供对文章生成有帮助的背景信息、知识要点或创作指导<br>
                        • 内容应与上方标题相关，便于向量匹配<br>
                        • 建议字数在100-500字之间
                    </p>
                </div>
            </div>
            <div class="form-group submit-group">
                <button type="submit" class="button button-primary">保存资料</button>
                <button type="button" class="button" id="cam-cancel-edit-button" style="display: none;">取消编辑</button>
            </div>
        </form>
    </div>

    <div class="list-container">
        <div class="list-header">
            <h2>已有品牌资料</h2>
            <div class="list-controls">
                <select id="cam-filter-type" class="filter-select">
                    <option value="">全部类型</option>
                    <option value="standard">标准样式</option>
                    <option value="custom_html">自定义HTML</option>
                    <option value="reference">参考资料</option>
                </select>
            </div>
        </div>
        <div id="cam-brand-profiles-list"></div>
        <div id="cam-pagination" class="pagination-container"></div>
    </div>
</div>
