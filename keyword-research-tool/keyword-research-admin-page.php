<?php
/**
 * Keyword Research Tool Admin Page
 */

if (!defined('ABSPATH')) {
    exit;
}

// 引入免费API处理类
require_once plugin_dir_path(__FILE__) . 'free_keyword_apis.php';

?>

<div class="wrap yali-plugin-wrapper" id="keyword-research-tool-app">
    <h1 class="yali-page-title"><span class="dashicons dashicons-search"></span> <?php _e('关键词研究工具', 'yali-ai-writer'); ?></h1>

    <div id="keyword-research-layout" class="yali-grid-layout">
        <!-- Main content -->
        <div class="yali-main-content">
            <div class="yali-card">
                <h2 class="hndle yali-card-header">
                    <span><span class="dashicons dashicons-search"></span> <?php _e('关键词挖掘', 'yali-ai-writer'); ?></span>
                </h2>
                <div class="inside">
                    <div id="keyword-input-section">
                        <p class="description yali-desc" style="margin-bottom: 10px;"><?php _e('输入一个基础关键词，然后点击“开始挖掘”。', 'yali-ai-writer'); ?></p>
                        <div class="yali-flex-row" style="margin-bottom: 15px;">
                            <input type="text" id="base-keywords-input" class="large-text yali-input" placeholder="<?php esc_attr_e('例如：wordpress插件', 'yali-ai-writer'); ?>" style="flex: 1;" />
                        </div>
                        <div class="yali-panel">
                            <div class="yali-form-group">
                                <label for="srt-language-specifics" class="yali-form-label"><?php _e('目标语言/地区:', 'yali-ai-writer'); ?></label>
                                <select id="srt-language-specifics" name="country" class="yali-select">
                                <optgroup label="North america">
                                    <option value="us-en">United States</option>
                                    <option value="ca-en">Canada</option>
                                </optgroup>
                                <optgroup label="Europe">
                                    <option value="uk-en">United Kingdom</option>
                                    <option value="nl-nl">Netherlands</option>
                                    <option value="de-de">Germany</option>
                                    <option value="fr-fr">France</option>
                                    <option value="es-es">Spain</option>
                                    <option value="it-it">Italy</option>
                                </optgroup>
                                <optgroup label="Asia">
                                    <option value="cn-zh-CN" selected>China (Simplified)</option>
                                    <option value="jp-ja">Japan</option>
                                    <option value="kr-ko">South Korea</option>
                                    <option value="id-id">Indonesia (Indonesian)</option>
                                </optgroup>
                                <optgroup label="South Asia">
                                    <option value="in-en">India (English)</option>
                                </optgroup>
                                <optgroup label="Latin America">
                                    <option value="br-pt">Brazil (Portuguese)</option>
                                    <option value="mx-es">Mexico (Spanish)</option>
                                </optgroup>
                                <optgroup label="Middle East">
                                    <option value="ae-ar">United Arab Emirates (Arabic)</option>
                                </optgroup>
                                <optgroup label="Other">
                                    <option value="au-en">Australia</option>
                                </optgroup>
                            </select>
                            <div id="data-source-options" class="yali-divider-dashed yali-form-group">
                                <label class="yali-form-label"><?php _e('数据来源:', 'yali-ai-writer'); ?></label>
                                <div class="yali-flex-wrap yali-gap-10">
                                    <label class="yali-checkbox-label">
                                        <input type="checkbox" name="data_sources[]" value="default" checked> <?php _e('谷歌', 'yali-ai-writer'); ?>
                                    </label>
                                    <label class="yali-checkbox-label">
                                        <input type="checkbox" name="data_sources[]" value="yt"> <?php _e('YouTube', 'yali-ai-writer'); ?>
                                    </label>
                                    <label class="yali-checkbox-label">
                                        <input type="checkbox" name="data_sources[]" value="sh"> <?php _e('购物', 'yali-ai-writer'); ?>
                                    </label>
                                    <label class="yali-checkbox-label">
                                        <input type="checkbox" name="data_sources[]" value="baidu"> <?php _e('百度', 'yali-ai-writer'); ?>
                                    </label>
                                    <label class="yali-checkbox-label">
                                        <input type="checkbox" name="data_sources[]" value="duckduckgo"> <?php _e('DuckDuckGo', 'yali-ai-writer'); ?>
                                    </label>
                                    <label class="yali-checkbox-label">
                                        <input type="checkbox" name="data_sources[]" value="wikipedia"> <?php _e('维基百科', 'yali-ai-writer'); ?>
                                    </label>
                                    <label class="yali-checkbox-label">
                                        <input type="checkbox" name="data_sources[]" value="taobao"> <?php _e('淘宝', 'yali-ai-writer'); ?>
                                    </label>
                                    <label class="yali-checkbox-label">
                                        <input type="checkbox" name="data_sources[]" value="360"> <?php _e('360', 'yali-ai-writer'); ?>
                                    </label>
                                    <label class="yali-checkbox-label">
                                        <input type="checkbox" name="data_sources[]" value="bing"> <?php _e('必应', 'yali-ai-writer'); ?>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="yali-flex-between" style="margin-top: 20px;">
                                <div id="deep-mining-options">
                                    <div class="yali-form-group mb-0">
                                        <label class="yali-checkbox-label" style="display: flex; align-items: center; gap: 8px;">
                                            <input type="checkbox" id="deep-mining-toggle" name="deep_mining" value="1" style="margin: 0;">
                                            <span class="yali-form-label" style="margin: 0;"><?php _e('深度挖掘', 'yali-ai-writer'); ?></span>
                                            <span class="description" style="font-size: 12px; color: #666;">(<?php _e('开启后将进行前后缀扩展挖掘', 'yali-ai-writer'); ?>)</span>
                                        </label>
                                    </div>
                                </div>
                                <button type="button" id="start-mining-btn" class="yali-btn yali-btn-primary">
                                    <span class="dashicons dashicons-hammer"></span> <?php _e('开始挖掘', 'yali-ai-writer'); ?>
                                </button>
                            </div>
                            </div>
                        </div>
                        <div id="progress-section" style="display: none; margin-top: 15px;">
                            <p id="progress-status-text" class="yali-desc"></p>
                            <div class="yali-progress-container">
                                <div id="progress-bar" class="yali-progress-bar">0%</div>
                            </div>
                        </div>
                    </div>
                    <div id="keyword-results-section" style="display:none; margin-top: 20px;">
                        <h3><?php _e('挖掘结果', 'yali-ai-writer'); ?> <span id="results-count" style="font-weight: normal; font-size: 14px; color: #555;"></span></h3>
                        <div class="yali-flex-row yali-gap-10" style="margin-bottom: 15px;">
                            <button type="button" id="select-all-results" class="yali-btn yali-btn-secondary yali-btn-small"><?php _e('全选', 'yali-ai-writer'); ?></button>
                            <button type="button" id="deselect-all-results" class="yali-btn yali-btn-secondary yali-btn-small"><?php _e('取消全选', 'yali-ai-writer'); ?></button>
                        </div>
                        <div class="yali-table-container">
                            <table class="yali-table">
                                <thead>
                                    <tr>
                                        <th scope="col" id="cb" class="yali-table-checkbox-cell" style="width: 40px; text-align: center;">
                                            <label class="yali-checkbox-label" style="justify-content: center;" for="cb-select-all-1">
                                                <input id="cb-select-all-1" type="checkbox">
                                                <span class="yali-checkbox-custom"></span>
                                            </label>
                                            <span class="screen-reader-text"><?php _e('全选', 'yali-ai-writer'); ?></span>
                                        </th>
                                        <th scope="col" id="keyword"><?php _e('关键词', 'yali-ai-writer'); ?></th>
                                        <th scope="col" id="trend" style="width: 150px;"><?php _e('趋势分析', 'yali-ai-writer'); ?></th>
                                    </tr>
                                </thead>
                                <tbody id="keyword-results-tbody">
                                    <!-- Results will be populated by JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="yali-sidebar">
            <div class="yali-card">
                <h2 class="hndle yali-card-header">
                    <span><span class="dashicons dashicons-clipboard"></span> <?php _e('备用关键词', 'yali-ai-writer'); ?></span>
                </h2>
                <div class="inside">
                    <p class="yali-desc" style="margin-bottom: 10px;"><?php _e('选中的关键词将出现在这里。', 'yali-ai-writer'); ?></p>
                    <textarea id="selected-keywords-output" rows="15" class="large-text yali-input" placeholder="<?php esc_attr_e('选中的关键词将显示在此处...', 'yali-ai-writer'); ?>" style="height: 300px; resize: vertical;"></textarea>
                    <div class="yali-flex-row" style="margin-top: 15px;">
                        <button id="copy-selected-btn" class="yali-btn yali-btn-primary" style="flex: 1;">
                            <span class="dashicons dashicons-admin-page"></span> <?php _e('一键复制', 'yali-ai-writer'); ?>
                        </button>
                        <button id="clear-selected-btn" class="yali-btn yali-btn-danger">
                            <span class="dashicons dashicons-trash"></span> <?php _e('清空', 'yali-ai-writer'); ?>
                        </button>
                    </div>
                    <p id="copy-feedback"></p>
                </div>
            </div>
        </div>
    </div>
</div>