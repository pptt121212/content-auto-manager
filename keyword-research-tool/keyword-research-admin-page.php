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

<div class="wrap" id="keyword-research-tool-app">
    <h1 class="wp-heading-inline">关键词研究工具</h1>
    <hr class="wp-header-end">

    <div id="poststuff">
        <div id="post-body" class="metabox-holder columns-2">
            <!-- Main content -->
            <div id="post-body-content">
                <div class="meta-box-sortables ui-sortable">
                    <div class="postbox">
                        <h2 class="hndle"><span><span class="dashicons dashicons-search"></span> 关键词挖掘</span></h2>
                        <div class="inside">
                            <div id="keyword-input-section">
                                <p>输入一个基础关键词，然后点击“开始挖掘”。</p>
                                <input type="text" id="base-keywords-input" class="large-text" placeholder="例如：wordpress插件" />
                                <div class="controls">
                                    <select id="srt-language-specifics" name="country">
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
                                    <div id="data-source-options" style="display: inline-block; margin-left: 10px;">
                                        <label style="margin-right: 10px;">
                                            <input type="checkbox" name="data_sources[]" value="default" checked> 谷歌
                                        </label>
                                        <label style="margin-right: 10px;">
                                            <input type="checkbox" name="data_sources[]" value="yt"> YouTube
                                        </label>
                                        <label style="margin-right: 10px;">
                                            <input type="checkbox" name="data_sources[]" value="sh"> 购物
                                        </label>
                                        <label style="margin-right: 10px;">
                                            <input type="checkbox" name="data_sources[]" value="baidu"> 百度
                                        </label>
                                        <label style="margin-right: 10px;">
                                            <input type="checkbox" name="data_sources[]" value="duckduckgo"> DuckDuckGo
                                        </label>
                                        <label style="margin-right: 10px;">
                                            <input type="checkbox" name="data_sources[]" value="wikipedia"> 维基百科
                                        </label>
                                        <label style="margin-right: 10px;">
                                            <input type="checkbox" name="data_sources[]" value="taobao"> 淘宝
                                        </label>
                                      </div>
                                    <div id="depth-options" style="display: inline-block; margin-left: 10px;">
                                        <label for="mining-depth">挖掘深度:</label>
                                        <select id="mining-depth" name="depth" disabled style="margin-left: 5px;">
                                            <option value="1" selected>1 (固定)</option>
                                        </select>
                                    </div>
                                    <button type="button" id="start-mining-btn" class="button button-primary">
                                        <span class="dashicons dashicons-hammer"></span> 开始挖掘
                                    </button>
                                    <span class="spinner"></span>
                                </div>
                                <div id="progress-section" style="display: none; margin-top: 15px;">
                                    <p id="progress-status-text"></p>
                                    <div style="background: #eee; border: 1px solid #ccc; padding: 2px;">
                                        <div id="progress-bar" style="background: #0073aa; width: 0%; height: 20px; text-align: center; color: white; line-height: 20px;">0%</div>
                                    </div>
                                </div>
                            </div>
                            <div id="keyword-results-section" style="display:none;">
                                <h3>挖掘结果 <span id="results-count" style="font-weight: normal; font-size: 14px; color: #555;"></span></h3>
                                <div class="tablenav top">
                                    <div class="alignleft actions">
                                        <button id="select-all-results" class="button">全选</button>
                                        <button id="deselect-all-results" class="button">取消全选</button>
                                    </div>
                                </div>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th scope="col" id="cb" class="manage-column column-cb check-column">
                                                <label class="screen-reader-text" for="cb-select-all-1">全选</label>
                                                <input id="cb-select-all-1" type="checkbox">
                                            </th>
                                            <th scope="col" id="keyword" class="manage-column">关键词</th>
                                            <th scope="col" id="trend" class="manage-column">趋势分析</th>
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
            <div id="postbox-container-1" class="postbox-container">
                <div class="meta-box-sortables">
                    <div class="postbox">
                        <h2 class="hndle"><span><span class="dashicons dashicons-clipboard"></span> 备用关键词</span></h2>
                        <div class="inside">
                            <p>选中的关键词将出现在这里。</p>
                            <textarea id="selected-keywords-output" rows="15" class="large-text" placeholder="选中的关键词将显示在此处..."></textarea>
                            <div class="controls">
                                <button id="copy-selected-btn" class="button button-primary">
                                    <span class="dashicons dashicons-admin-page"></span> 一键复制
                                </button>
                                <button id="clear-selected-btn" class="button">
                                    <span class="dashicons dashicons-trash"></span> 清空
                                </button>
                            </div>
                            <p id="copy-feedback"></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>